<?php
/**
 * Document signing workflow service.
 *
 * @package SignDocs
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class Sign_Docs_Document_Service
{
    private static bool $rollback_delete_in_progress = false;

    public static function is_rollback_delete_in_progress(): bool
    {
        return self::$rollback_delete_in_progress;
    }

    /**
     * @param array<string,mixed> $args
     * @return int|WP_Error
     */
    public static function create_from_local_pdf(string $source_path, array $args)
    {
        if (! is_readable($source_path) || ! is_file($source_path)) {
            return new WP_Error('sign_docs_source_unreadable', __('Source PDF is not readable.', 'sign-docs'));
        }

        $mime_type = self::detect_mime_type($source_path);
        if ('application/pdf' !== $mime_type) {
            return new WP_Error('sign_docs_invalid_mime', __('Only PDF files can be signed.', 'sign-docs'));
        }

        $title = isset($args['post_title']) ? sanitize_text_field((string) $args['post_title']) : '';
        if ('' === $title) {
            $title = self::compose_title($args);
        }
        if ('' === $title) {
            $title = sanitize_file_name((string) wp_basename($source_path));
        }
        $full_title = isset($args['full_title']) ? sanitize_textarea_field((string) $args['full_title']) : '';
        if ('' === trim($full_title)) {
            $full_title = $title;
        }
        $args['post_title'] = $title;
        $args['full_title'] = $full_title;
        $replaces_post_id = self::valid_replaces_post_id(isset($args['replaces_post_id']) ? absint($args['replaces_post_id']) : 0);
        $replacement_note = isset($args['replacement_note']) ? sanitize_text_field((string) $args['replacement_note']) : '';

        $signed_at = isset($args['signed_at']) && '' !== (string) $args['signed_at']
            ? sanitize_text_field((string) $args['signed_at'])
            : current_time('mysql');
        $post_status = isset($args['post_status']) ? sanitize_key((string) $args['post_status']) : 'publish';
        if (! in_array($post_status, array('publish', 'private', 'draft'), true)) {
            $post_status = 'publish';
        }

        $post_id = wp_insert_post(
            array(
                'post_type' => Sign_Docs_Post_Type::POST_TYPE,
                'post_status' => $post_status,
                'post_title' => $title,
                'post_content' => isset($args['document_comment']) ? sanitize_textarea_field((string) $args['document_comment']) : '',
                'post_author' => get_current_user_id(),
            ),
            true
        );

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        $post_id = (int) $post_id;
        $timestamp = strtotime($signed_at) ?: time();
        $paths = Sign_Docs_Storage::ensure_document_directories($post_id, $timestamp);

        if (! copy($source_path, $paths['original_path'])) {
            self::rollback_created_post($post_id);
            return new WP_Error('sign_docs_original_copy_failed', __('Failed to save the original PDF.', 'sign-docs'));
        }

        $hash = Sign_Docs_Storage::hash_file($paths['original_path']);
        if ('' === $hash) {
            self::rollback_created_post($post_id);
            return new WP_Error('sign_docs_hash_failed', __('Failed to calculate SHA-256 for the original PDF.', 'sign-docs'));
        }

        $defer_stamped = ! empty($args['defer_stamped']);
        $document_status = isset($args['document_status'])
            ? sanitize_key((string) $args['document_status'])
            : ($defer_stamped ? 'needs_public_copy' : 'active');
        if (! $defer_stamped && 'unsigned' !== $document_status) {
            self::rollback_created_post($post_id);
            return new WP_Error(
                'sign_docs_browser_signing_required',
                __('Signing requires browser PDF processing. Enable JavaScript and make sure the bundled PDF libraries are available.', 'sign-docs')
            );
        }
        if (! $defer_stamped && 'unsigned' === $document_status) {
            $defer_stamped = true;
        }

        $verification_url = Sign_Docs_Verification_Page::url($post_id);
        $source_filename = isset($args['source_filename']) ? sanitize_file_name((string) $args['source_filename']) : (string) wp_basename($source_path);

        $meta = array(
            'full_title' => $full_title,
            'document_comment' => isset($args['document_comment']) ? sanitize_textarea_field((string) $args['document_comment']) : '',
            'original_file_path' => $paths['original_path'],
            'original_file_url' => $paths['original_url'],
            'stamped_file_path' => $defer_stamped ? '' : $paths['stamped_path'],
            'stamped_file_url' => $defer_stamped ? '' : $paths['stamped_url'],
            'stamped_file_hash' => $defer_stamped ? '' : Sign_Docs_Storage::hash_file($paths['stamped_path']),
            'sha256_hash' => $hash,
            'signed_at' => $signed_at,
            'signer_name' => isset($args['signer_name']) ? sanitize_text_field((string) $args['signer_name']) : '',
            'signer_position' => isset($args['signer_position']) ? sanitize_text_field((string) $args['signer_position']) : '',
            'signer_organization' => isset($args['signer_organization']) ? sanitize_text_field((string) $args['signer_organization']) : '',
            'signer_user_id' => get_current_user_id(),
            'verification_url' => is_string($verification_url) ? $verification_url : '',
            'qr_code_data' => is_string($verification_url) ? $verification_url : '',
            'prepared_at' => $defer_stamped ? current_time('mysql') : '',
            'completed_at' => $defer_stamped ? '' : current_time('mysql'),
            'completed_by_user_id' => $defer_stamped ? 0 : get_current_user_id(),
            'completed_ip' => $defer_stamped ? '' : self::request_ip(),
            'completed_user_agent' => $defer_stamped ? '' : self::request_user_agent(),
            'document_status' => $document_status,
            'document_version' => self::document_version($args, $replaces_post_id),
            'replaces_post_id' => $replaces_post_id,
            'replaced_by_post_id' => 0,
            'replacement_note' => $replacement_note,
            'source_filename' => $source_filename,
            'file_size' => filesize($paths['original_path']) ?: 0,
            'mime_type' => $mime_type,
            'document_category' => isset($args['document_category']) ? sanitize_key((string) $args['document_category']) : '',
            'document_type_label' => isset($args['document_type_label']) ? sanitize_text_field((string) $args['document_type_label']) : '',
            'document_type_term_id' => isset($args['document_type_term_id']) ? absint($args['document_type_term_id']) : 0,
            'document_institution' => isset($args['document_institution']) ? sanitize_text_field((string) $args['document_institution']) : '',
            'document_date' => isset($args['document_date']) ? sanitize_text_field((string) $args['document_date']) : '',
            'document_number' => isset($args['document_number']) ? sanitize_text_field((string) $args['document_number']) : '',
            'document_subject' => isset($args['document_subject']) ? sanitize_text_field((string) $args['document_subject']) : '',
            'academic_year' => isset($args['academic_year']) ? sanitize_text_field((string) $args['academic_year']) : '',
            'stamp_position' => isset($args['stamp_position']) ? sanitize_key((string) $args['stamp_position']) : 'top',
            'stamp_corner' => isset($args['stamp_corner']) ? sanitize_key((string) $args['stamp_corner']) : 'top-left',
            'stamp_color' => isset($args['stamp_color']) ? (sanitize_hex_color((string) $args['stamp_color']) ?: '#2e7d32') : '#2e7d32',
            'stamp_opacity' => isset($args['stamp_opacity']) ? (string) min(1, max(0.1, (float) $args['stamp_opacity'])) : '1',
            'stamp_font_size' => isset($args['stamp_font_size']) ? (string) min(12, max(6, (float) $args['stamp_font_size'])) : '8.4',
            'stamp_width_mm' => isset($args['stamp_width_mm']) ? min(160, max(70, absint($args['stamp_width_mm']))) : 100,
            'stamp_border_enabled' => array_key_exists('stamp_border_enabled', $args) ? (! empty($args['stamp_border_enabled']) && '0' !== (string) $args['stamp_border_enabled'] ? '1' : '0') : '1',
            'stamp_placement_mode' => isset($args['stamp_placement_mode']) && 'manual' === sanitize_key((string) $args['stamp_placement_mode']) ? 'manual' : 'corner',
            'stamp_manual_x' => isset($args['stamp_manual_x']) ? (string) min(1, max(0, (float) $args['stamp_manual_x'])) : '',
            'stamp_manual_y' => isset($args['stamp_manual_y']) ? (string) min(1, max(0, (float) $args['stamp_manual_y'])) : '',
            'qr_logo_enabled' => array_key_exists('qr_logo_enabled', $args) ? (! empty($args['qr_logo_enabled']) && '0' !== (string) $args['qr_logo_enabled'] ? '1' : '0') : '1',
        );

        foreach ($meta as $key => $value) {
            update_post_meta($post_id, $key, $value);
        }

        self::assign_document_terms($post_id, $args, $meta);

        if (! $defer_stamped || 'needs_public_copy' !== $document_status) {
            self::apply_replacement($post_id);
        }

        return $post_id;
    }

    private static function rollback_created_post(int $post_id): void
    {
        self::$rollback_delete_in_progress = true;

        try {
            wp_delete_post($post_id, true);
        } finally {
            self::$rollback_delete_in_progress = false;
        }
    }

    /**
     * @param array<string,mixed> $args
     * @return int|WP_Error
     */
    public static function prepare_from_local_pdf(string $source_path, array $args)
    {
        $args['defer_stamped'] = true;
        $args['document_status'] = isset($args['document_status'])
            ? sanitize_key((string) $args['document_status'])
            : 'needs_public_copy';
        $args['post_status'] = 'needs_public_copy' === $args['document_status'] ? 'private' : 'publish';

        return self::create_from_local_pdf($source_path, $args);
    }

    /**
     * @return true|WP_Error
     */
    public static function complete_with_stamped_pdf(int $post_id, string $stamped_source_path)
    {
        if (Sign_Docs_Post_Type::POST_TYPE !== get_post_type($post_id)) {
            return new WP_Error('sign_docs_invalid_post', __('Invalid signed document.', 'sign-docs'));
        }

        if (! is_readable($stamped_source_path) || ! is_file($stamped_source_path)) {
            return new WP_Error('sign_docs_stamped_unreadable', __('Stamped PDF is not readable.', 'sign-docs'));
        }

        if ('application/pdf' !== self::detect_mime_type($stamped_source_path)) {
            return new WP_Error('sign_docs_invalid_mime', __('Only PDF files can be signed.', 'sign-docs'));
        }

        if ('needs_public_copy' !== Sign_Docs_Meta::get($post_id, 'document_status')) {
            return new WP_Error(
                'sign_docs_invalid_document_state',
                __('This document is not waiting for a public PDF copy.', 'sign-docs'),
                array('status' => 409)
            );
        }

        $original_path = Sign_Docs_Meta::get($post_id, 'original_file_path');
        $stored_hash = Sign_Docs_Meta::get($post_id, 'sha256_hash');
        $verification_url = Sign_Docs_Meta::get($post_id, 'verification_url');
        $qr_code_data = Sign_Docs_Meta::get($post_id, 'qr_code_data');
        $signed_at = Sign_Docs_Meta::get($post_id, 'signed_at');
        $timestamp = strtotime($signed_at) ?: time();
        $paths = Sign_Docs_Storage::ensure_document_directories($post_id, $timestamp);

        if ('' === $original_path || ! is_readable($original_path)) {
            return new WP_Error('sign_docs_original_missing', __('Original PDF is missing.', 'sign-docs'));
        }

        $current_original_hash = Sign_Docs_Storage::hash_file($original_path);
        if ('' === $stored_hash || '' === $current_original_hash || ! hash_equals($stored_hash, $current_original_hash)) {
            return new WP_Error(
                'sign_docs_original_hash_mismatch',
                __('Original PDF hash does not match the stored document hash.', 'sign-docs'),
                array('status' => 409)
            );
        }

        if ('' === $signed_at || '' === $verification_url || '' === $qr_code_data) {
            return new WP_Error(
                'sign_docs_incomplete_document_data',
                __('Document verification data is incomplete.', 'sign-docs'),
                array('status' => 409)
            );
        }

        if (! copy($stamped_source_path, $paths['stamped_path'])) {
            return new WP_Error('sign_docs_stamped_copy_failed', __('Failed to save the public PDF copy.', 'sign-docs'));
        }

        $stamped_hash = Sign_Docs_Storage::hash_file($paths['stamped_path']);
        if ('' === $stamped_hash) {
            return new WP_Error('sign_docs_stamped_hash_failed', __('Failed to calculate SHA-256 for the public PDF copy.', 'sign-docs'));
        }

        update_post_meta($post_id, 'stamped_file_path', $paths['stamped_path']);
        update_post_meta($post_id, 'stamped_file_url', $paths['stamped_url']);
        update_post_meta($post_id, 'stamped_file_hash', $stamped_hash);
        update_post_meta($post_id, 'completed_at', current_time('mysql'));
        update_post_meta($post_id, 'completed_by_user_id', get_current_user_id());
        update_post_meta($post_id, 'completed_ip', self::request_ip());
        update_post_meta($post_id, 'completed_user_agent', self::request_user_agent());
        update_post_meta($post_id, 'document_status', 'active');
        wp_update_post(
            array(
                'ID' => $post_id,
                'post_status' => 'publish',
            )
        );
        self::apply_replacement($post_id);

        return true;
    }

    public static function valid_replaces_post_id(int $post_id): int
    {
        if ($post_id <= 0 || Sign_Docs_Post_Type::POST_TYPE !== get_post_type($post_id)) {
            return 0;
        }

        return $post_id;
    }

    public static function apply_replacement(int $post_id): void
    {
        $replaces_post_id = self::valid_replaces_post_id(absint(Sign_Docs_Meta::get($post_id, 'replaces_post_id')));

        if ($replaces_post_id <= 0 || $replaces_post_id === $post_id) {
            return;
        }

        update_post_meta($replaces_post_id, 'document_status', 'replaced');
        update_post_meta($replaces_post_id, 'replaced_by_post_id', $post_id);
        update_post_meta($post_id, 'replaces_post_id', $replaces_post_id);
    }

    /**
     * @param array<string,mixed> $args
     */
    private static function document_version(array $args, int $replaces_post_id): int
    {
        if (isset($args['document_version']) && absint($args['document_version']) > 0) {
            return absint($args['document_version']);
        }

        if ($replaces_post_id > 0) {
            return max(1, absint(Sign_Docs_Meta::get($replaces_post_id, 'document_version'))) + 1;
        }

        return 1;
    }

    private static function detect_mime_type(string $source_path): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if (false !== $finfo) {
                $mime_type = finfo_file($finfo, $source_path);

                if (is_string($mime_type)) {
                    return $mime_type;
                }
            }
        }

        $check = wp_check_filetype((string) wp_basename($source_path), array('pdf' => 'application/pdf'));

        return isset($check['type']) && is_string($check['type']) ? $check['type'] : '';
    }

    private static function request_ip(): string
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field((string) wp_unslash($_SERVER['REMOTE_ADDR'])) : '';

        return substr($ip, 0, 100);
    }

    private static function request_user_agent(): string
    {
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field((string) wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';

        return substr($user_agent, 0, 500);
    }

    /**
     * @param array<string,mixed> $args
     */
    private static function compose_title(array $args): string
    {
        return Sign_Docs_Title_Template::compose($args);
    }

    /**
     * @param array<string,mixed> $args
     * @param array<string,mixed> $meta
     */
    private static function assign_document_terms(int $post_id, array $args, array $meta): void
    {
        if ('' !== $meta['document_category']) {
            $category = get_term_by('slug', (string) $meta['document_category'], 'sign_doc_category');
            if ($category instanceof WP_Term) {
                wp_set_object_terms($post_id, array((int) $category->term_id), 'sign_doc_category', false);
            }
        }

        if (! empty($meta['document_type_term_id'])) {
            wp_set_object_terms($post_id, array((int) $meta['document_type_term_id']), 'sign_doc_type', false);
        } elseif ('' !== $meta['document_type_label']) {
            wp_set_object_terms($post_id, (string) $meta['document_type_label'], 'sign_doc_type', false);
        }

        if ('' !== $meta['document_institution']) {
            wp_set_object_terms($post_id, (string) $meta['document_institution'], 'sign_doc_institution', false);
        }
    }
}
