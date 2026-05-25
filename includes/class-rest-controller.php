<?php
/**
 * REST API for browser-based PDF stamping.
 *
 * @package SignDocs
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class Sign_Docs_REST_Controller
{
    private const NAMESPACE = 'sign-docs/v1';

    public static function register_routes(): void
    {
        register_rest_route(
            self::NAMESPACE,
            '/documents',
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array(self::class, 'documents'),
                'permission_callback' => array(self::class, 'can_read_documents'),
            )
        );

        register_rest_route(
            self::NAMESPACE,
            '/prepare',
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array(self::class, 'prepare'),
                'permission_callback' => array(self::class, 'can_upload'),
            )
        );

        register_rest_route(
            self::NAMESPACE,
            '/suggest-metadata',
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array(self::class, 'suggest_metadata'),
                'permission_callback' => array(self::class, 'can_upload'),
                'args' => array(
                    'first_page_text' => array(
                        'type' => 'string',
                        'required' => true,
                    ),
                    'source_filename' => array(
                        'type' => 'string',
                        'required' => false,
                    ),
                ),
            )
        );

        register_rest_route(
            self::NAMESPACE,
            '/complete',
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array(self::class, 'complete'),
                'permission_callback' => array(self::class, 'can_upload'),
            )
        );
    }

    public static function can_read_documents(): bool
    {
        return current_user_can('edit_posts');
    }

    public static function can_upload(): bool
    {
        return current_user_can('upload_files');
    }

    public static function suggest_metadata(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $suggestion = Sign_Docs_AI_Metadata::suggest(
            array(
                'first_page_text' => (string) $request->get_param('first_page_text'),
                'source_filename' => (string) $request->get_param('source_filename'),
            )
        );

        if (is_wp_error($suggestion)) {
            return $suggestion;
        }

        return new WP_REST_Response($suggestion);
    }

    public static function documents(WP_REST_Request $request): WP_REST_Response
    {
        $include = absint($request->get_param('include'));
        $page = max(1, absint($request->get_param('page')));
        $per_page = min(50, max(1, absint($request->get_param('per_page')) ?: 20));

        $query_args = array(
            'post_type' => Sign_Docs_Post_Type::POST_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => $per_page,
            'paged' => $page,
            'orderby' => 'date',
            'order' => 'DESC',
        );

        if ($include > 0) {
            $query_args['p'] = $include;
        }

        $search = sanitize_text_field((string) $request->get_param('search'));
        if ('' !== $search) {
            $query_args['s'] = $search;
        }

        $status = sanitize_key((string) $request->get_param('status'));
        if ('' !== $status) {
            $query_args['meta_query'] = array(
                array(
                    'key' => 'document_status',
                    'value' => $status,
                    'compare' => '=',
                ),
            );
        }

        $tax_query = array();
        foreach (array('category' => 'sign_doc_category', 'type' => 'sign_doc_type', 'department' => 'sign_doc_department') as $param => $taxonomy) {
            $term_id = absint($request->get_param($param));
            if ($term_id <= 0) {
                continue;
            }

            $tax_query[] = array(
                'taxonomy' => $taxonomy,
                'field' => 'term_id',
                'terms' => array($term_id),
            );
        }

        if (! empty($tax_query)) {
            $query_args['tax_query'] = count($tax_query) > 1
                ? array_merge(array('relation' => 'AND'), $tax_query)
                : $tax_query;
        }

        $query = new WP_Query($query_args);
        $items = array();

        foreach ($query->posts as $post) {
            if (! $post instanceof WP_Post) {
                continue;
            }

            $post_id = (int) $post->ID;
            $items[] = array(
                'id' => $post_id,
                'title' => get_the_title($post_id),
                'fullTitle' => Sign_Docs_Meta::get($post_id, 'full_title') ?: get_the_title($post_id),
                'verificationUrl' => Sign_Docs_Meta::get($post_id, 'verification_url') ?: Sign_Docs_Verification_Page::url($post_id),
                'stampedFileUrl' => Sign_Docs_Meta::get($post_id, 'stamped_file_url'),
                'originalFileUrl' => Sign_Docs_Meta::get($post_id, 'original_file_url'),
                'status' => Sign_Docs_Meta::get($post_id, 'document_status') ?: 'active',
                'statusLabel' => self::status_label(Sign_Docs_Meta::get($post_id, 'document_status')),
                'signedAt' => Sign_Docs_Meta::get($post_id, 'signed_at'),
                'documentVersion' => Sign_Docs_Meta::get($post_id, 'document_version') ?: '1',
                'sha256Hash' => Sign_Docs_Meta::get($post_id, 'sha256_hash'),
                'type' => self::term_names($post_id, 'sign_doc_type'),
                'department' => self::term_names($post_id, 'sign_doc_department'),
            );
        }

        $response = new WP_REST_Response(
            array(
                'items' => $items,
                'total' => (int) $query->found_posts,
                'totalPages' => (int) $query->max_num_pages,
                'page' => $page,
            )
        );

        return $response;
    }

    public static function prepare(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $files = $request->get_file_params();
        $settings = Sign_Docs_Settings::get();
        $stamp_border_enabled = $request->get_param('stamp_border_enabled');
        $qr_logo_enabled = $request->get_param('qr_logo_enabled');

        if (! isset($files['original_pdf']) || ! is_array($files['original_pdf'])) {
            return new WP_Error('sign_docs_missing_file', __('Choose a PDF file.', 'sign-docs'), array('status' => 400));
        }

        $file = $files['original_pdf'];
        if (UPLOAD_ERR_OK !== (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE)) {
            return new WP_Error('sign_docs_upload_failed', __('The file upload failed.', 'sign-docs'), array('status' => 400));
        }

        $post_id = Sign_Docs_Document_Service::prepare_from_local_pdf(
            (string) $file['tmp_name'],
            array(
                'post_title' => (string) $request->get_param('post_title'),
                'full_title' => (string) $request->get_param('full_title'),
                'document_comment' => (string) $request->get_param('document_comment'),
                'document_category' => (string) $request->get_param('document_category'),
                'document_type_label' => (string) $request->get_param('document_type_label'),
                'document_type_term_id' => absint($request->get_param('document_type_term_id')),
                'document_institution' => (string) $request->get_param('document_institution'),
                'document_date' => (string) $request->get_param('document_date'),
                'document_number' => (string) $request->get_param('document_number'),
                'document_subject' => (string) $request->get_param('document_subject'),
                'academic_year' => (string) $request->get_param('academic_year'),
                'signer_name' => (string) ($request->get_param('signer_name') ?: $settings['signer_name']),
                'signer_position' => (string) ($request->get_param('signer_position') ?: $settings['signer_position']),
                'signer_organization' => (string) ($request->get_param('signer_organization') ?: $settings['signer_organization']),
                'stamp_position' => (string) ($request->get_param('stamp_position') ?: 'top'),
                'stamp_corner' => (string) ($request->get_param('stamp_corner') ?: $settings['stamp_corner']),
                'stamp_color' => (string) ($request->get_param('stamp_color') ?: $settings['stamp_color']),
                'stamp_opacity' => (string) ($request->get_param('stamp_opacity') ?: $settings['stamp_opacity']),
                'stamp_font_size' => (string) ($request->get_param('stamp_font_size') ?: $settings['stamp_font_size']),
                'stamp_width_mm' => (string) ($request->get_param('stamp_width_mm') ?: $settings['stamp_width_mm']),
                'stamp_border_enabled' => null === $stamp_border_enabled ? $settings['stamp_border_enabled'] : (string) $stamp_border_enabled,
                'stamp_placement_mode' => (string) ($request->get_param('stamp_placement_mode') ?: 'corner'),
                'stamp_manual_x' => (string) $request->get_param('stamp_manual_x'),
                'stamp_manual_y' => (string) $request->get_param('stamp_manual_y'),
                'qr_logo_enabled' => null === $qr_logo_enabled ? $settings['qr_logo_enabled'] : (string) $qr_logo_enabled,
                'source_filename' => (string) ($file['name'] ?? 'document.pdf'),
            )
        );

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        $post_id = (int) $post_id;
        $stored_stamp_border_enabled = Sign_Docs_Meta::get($post_id, 'stamp_border_enabled');
        $stored_qr_logo_enabled = Sign_Docs_Meta::get($post_id, 'qr_logo_enabled');

        return new WP_REST_Response(
            array(
                'post_id' => $post_id,
                'verification_url' => Sign_Docs_Verification_Page::url($post_id),
                'sha256_hash' => Sign_Docs_Meta::get($post_id, 'sha256_hash'),
                'original_file_url' => Sign_Docs_Meta::get($post_id, 'original_file_url'),
                'signed_at' => Sign_Docs_Meta::get($post_id, 'signed_at'),
                'title' => Sign_Docs_Meta::get($post_id, 'full_title') ?: get_the_title($post_id),
                'signer' => trim(Sign_Docs_Meta::get($post_id, 'signer_position') . ' ' . Sign_Docs_Meta::get($post_id, 'signer_name')),
                'signer_name' => Sign_Docs_Meta::get($post_id, 'signer_name'),
                'signer_position' => Sign_Docs_Meta::get($post_id, 'signer_position'),
                'organization' => Sign_Docs_Meta::get($post_id, 'signer_organization'),
                'status' => 'active',
                'version' => Sign_Docs_Meta::get($post_id, 'document_version') ?: '1',
                'stamp_position' => sanitize_key((string) ($request->get_param('stamp_position') ?: 'top')),
                'stamp_corner' => Sign_Docs_Meta::get($post_id, 'stamp_corner') ?: 'top-left',
                'stamp_color' => Sign_Docs_Meta::get($post_id, 'stamp_color') ?: '#2e7d32',
                'stamp_opacity' => Sign_Docs_Meta::get($post_id, 'stamp_opacity') ?: '1',
                'stamp_font_size' => Sign_Docs_Meta::get($post_id, 'stamp_font_size') ?: '8.4',
                'stamp_width_mm' => Sign_Docs_Meta::get($post_id, 'stamp_width_mm') ?: '100',
                'stamp_border_enabled' => '' === $stored_stamp_border_enabled ? '1' : $stored_stamp_border_enabled,
                'stamp_placement_mode' => Sign_Docs_Meta::get($post_id, 'stamp_placement_mode') ?: 'corner',
                'stamp_manual_x' => Sign_Docs_Meta::get($post_id, 'stamp_manual_x'),
                'stamp_manual_y' => Sign_Docs_Meta::get($post_id, 'stamp_manual_y'),
                'qr_logo_enabled' => '' === $stored_qr_logo_enabled ? '1' : $stored_qr_logo_enabled,
            ),
            201
        );
    }

    public static function complete(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $post_id = absint($request->get_param('post_id'));
        if ($post_id <= 0 || ! current_user_can('edit_post', $post_id)) {
            return new WP_Error('sign_docs_invalid_post', __('Invalid signed document.', 'sign-docs'), array('status' => 403));
        }

        $files = $request->get_file_params();
        if (! isset($files['stamped_pdf']) || ! is_array($files['stamped_pdf'])) {
            return new WP_Error('sign_docs_missing_file', __('Stamped PDF is missing.', 'sign-docs'), array('status' => 400));
        }

        $file = $files['stamped_pdf'];
        if (UPLOAD_ERR_OK !== (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE)) {
            return new WP_Error('sign_docs_upload_failed', __('The file upload failed.', 'sign-docs'), array('status' => 400));
        }

        $result = Sign_Docs_Document_Service::complete_with_stamped_pdf($post_id, (string) $file['tmp_name']);

        if (is_wp_error($result)) {
            return $result;
        }

        return new WP_REST_Response(
            array(
                'post_id' => $post_id,
                'verification_url' => Sign_Docs_Verification_Page::url($post_id),
                'stamped_file_url' => Sign_Docs_Meta::get($post_id, 'stamped_file_url'),
            )
        );
    }

    private static function term_names(int $post_id, string $taxonomy): string
    {
        $terms = get_the_terms($post_id, $taxonomy);

        if (is_wp_error($terms) || empty($terms)) {
            return '';
        }

        return implode(
            ', ',
            array_map(
                static function (WP_Term $term): string {
                    return $term->name;
                },
                $terms
            )
        );
    }

    private static function status_label(string $status): string
    {
        $labels = array(
            'active' => __('Действующий', 'sign-docs'),
            'unsigned' => __('Без подписи', 'sign-docs'),
            'archive' => __('Архив', 'sign-docs'),
            'archived' => __('Архив', 'sign-docs'),
            'replaced' => __('Заменен', 'sign-docs'),
            'deleted' => __('Архив', 'sign-docs'),
            'draft' => __('Черновик', 'sign-docs'),
            'needs_public_copy' => __('Ожидает публичную копию', 'sign-docs'),
        );

        return $labels[$status] ?? ($status ?: __('Действующий', 'sign-docs'));
    }
}
