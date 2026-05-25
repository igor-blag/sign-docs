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

        $signed_at = isset($args['signed_at']) && '' !== (string) $args['signed_at']
            ? sanitize_text_field((string) $args['signed_at'])
            : current_time('mysql');

        $post_id = wp_insert_post(
            array(
                'post_type' => Sign_Docs_Post_Type::POST_TYPE,
                'post_status' => 'publish',
                'post_title' => $title,
                'post_content' => isset($args['full_title']) && '' !== (string) $args['full_title'] ? sanitize_textarea_field((string) $args['full_title']) : $title,
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
            wp_delete_post($post_id, true);
            return new WP_Error('sign_docs_original_copy_failed', __('Failed to save the original PDF.', 'sign-docs'));
        }

        $hash = Sign_Docs_Storage::hash_file($paths['original_path']);
        if ('' === $hash) {
            wp_delete_post($post_id, true);
            return new WP_Error('sign_docs_hash_failed', __('Failed to calculate SHA-256 for the original PDF.', 'sign-docs'));
        }

        $defer_stamped = ! empty($args['defer_stamped']);
        $document_status = isset($args['document_status'])
            ? sanitize_key((string) $args['document_status'])
            : ($defer_stamped ? 'needs_public_copy' : 'active');
        if (! $defer_stamped && ! self::create_public_pdf($paths['stamped_path'], $paths['original_path'], $args, $post_id, $signed_at)) {
            wp_delete_post($post_id, true);
            return new WP_Error('sign_docs_stamped_copy_failed', __('Failed to save the public PDF copy.', 'sign-docs'));
        }

        $verification_url = Sign_Docs_Verification_Page::url($post_id);
        $source_filename = isset($args['source_filename']) ? sanitize_file_name((string) $args['source_filename']) : (string) wp_basename($source_path);

        $meta = array(
            'full_title' => isset($args['full_title']) && '' !== (string) $args['full_title'] ? sanitize_text_field((string) $args['full_title']) : $title,
            'original_file_path' => $paths['original_path'],
            'original_file_url' => $paths['original_url'],
            'stamped_file_path' => $defer_stamped ? '' : $paths['stamped_path'],
            'stamped_file_url' => $defer_stamped ? '' : $paths['stamped_url'],
            'sha256_hash' => $hash,
            'signed_at' => $signed_at,
            'signer_name' => isset($args['signer_name']) ? sanitize_text_field((string) $args['signer_name']) : '',
            'signer_position' => isset($args['signer_position']) ? sanitize_text_field((string) $args['signer_position']) : '',
            'signer_organization' => isset($args['signer_organization']) ? sanitize_text_field((string) $args['signer_organization']) : '',
            'signer_user_id' => get_current_user_id(),
            'verification_url' => is_string($verification_url) ? $verification_url : '',
            'qr_code_data' => is_string($verification_url) ? $verification_url : '',
            'document_status' => $document_status,
            'document_version' => isset($args['document_version']) ? absint($args['document_version']) : 1,
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

        return $post_id;
    }

    /**
     * @param array<string,mixed> $args
     * @return int|WP_Error
     */
    public static function prepare_from_local_pdf(string $source_path, array $args)
    {
        $args['defer_stamped'] = true;
        $args['document_status'] = 'needs_public_copy';

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

        $original_path = Sign_Docs_Meta::get($post_id, 'original_file_path');
        $signed_at = Sign_Docs_Meta::get($post_id, 'signed_at');
        $timestamp = strtotime($signed_at) ?: time();
        $paths = Sign_Docs_Storage::ensure_document_directories($post_id, $timestamp);

        if ('' === $original_path || ! is_readable($original_path)) {
            return new WP_Error('sign_docs_original_missing', __('Original PDF is missing.', 'sign-docs'));
        }

        if (! copy($stamped_source_path, $paths['stamped_path'])) {
            return new WP_Error('sign_docs_stamped_copy_failed', __('Failed to save the public PDF copy.', 'sign-docs'));
        }

        update_post_meta($post_id, 'stamped_file_path', $paths['stamped_path']);
        update_post_meta($post_id, 'stamped_file_url', $paths['stamped_url']);
        update_post_meta($post_id, 'document_status', 'active');
        wp_update_post(
            array(
                'ID' => $post_id,
                'post_status' => 'publish',
            )
        );

        return true;
    }

    /**
     * @param array<string,mixed> $args
     */
    private static function create_public_pdf(string $target_path, string $original_path, array $args, int $post_id, string $signed_at): bool
    {
        $verification_url = Sign_Docs_Verification_Page::url($post_id);
        $hash = Sign_Docs_Storage::hash_file($original_path);

        if ('' === $hash || ! is_string($verification_url)) {
            return false;
        }

        return Sign_Docs_Pdf_Certificate::generate(
            $target_path,
            array(
                'title' => isset($args['full_title']) && '' !== (string) $args['full_title'] ? (string) $args['full_title'] : (string) ($args['post_title'] ?? ''),
                'signed_at' => $signed_at,
                'signer' => trim((string) ($args['signer_position'] ?? '') . ' ' . (string) ($args['signer_name'] ?? '')),
                'organization' => (string) ($args['signer_organization'] ?? ''),
                'status' => (string) ($args['document_status'] ?? 'active'),
                'version' => (int) ($args['document_version'] ?? 1),
                'sha256_hash' => $hash,
                'verification_url' => $verification_url,
                'source_filename' => (string) ($args['source_filename'] ?? wp_basename($original_path)),
            )
        );
    }

    private static function detect_mime_type(string $source_path): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if (false !== $finfo) {
                $mime_type = finfo_file($finfo, $source_path);
                finfo_close($finfo);

                if (is_string($mime_type)) {
                    return $mime_type;
                }
            }
        }

        $check = wp_check_filetype((string) wp_basename($source_path), array('pdf' => 'application/pdf'));

        return isset($check['type']) && is_string($check['type']) ? $check['type'] : '';
    }

    /**
     * @param array<string,mixed> $args
     */
    private static function compose_title(array $args): string
    {
        $subject = isset($args['document_subject']) ? sanitize_text_field((string) $args['document_subject']) : '';
        $with_quotes = ! isset($args['include_subject_quotes_in_title']) || '0' !== (string) $args['include_subject_quotes_in_title'];
        $normalized_subject = trim($subject, " \t\n\r\0\x0B\"'«»");
        $title = '';
        if ('' !== $normalized_subject) {
            $title = $with_quotes ? '«' . $normalized_subject . '»' : $normalized_subject;
        }

        return trim((string) preg_replace('/\s+/', ' ', $title));
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
