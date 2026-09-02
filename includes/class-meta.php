<?php
/**
 * Document meta contract.
 *
 * @package SignDocs
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class Sign_Docs_Meta
{
    public const META_KEYS = array(
        'full_title' => 'string',
        'document_comment' => 'string',
        'original_file_path' => 'string',
        'original_file_url' => 'string',
        'stamped_file_path' => 'string',
        'stamped_file_url' => 'string',
        'stamped_file_hash' => 'string',
        'sha256_hash' => 'string',
        'signed_at' => 'string',
        'signer_name' => 'string',
        'signer_position' => 'string',
        'signer_organization' => 'string',
        'signer_user_id' => 'integer',
        'verification_url' => 'string',
        'qr_code_data' => 'string',
        'prepared_at' => 'string',
        'completed_at' => 'string',
        'completed_by_user_id' => 'integer',
        'completed_ip' => 'string',
        'completed_user_agent' => 'string',
        'document_status' => 'string',
        'document_version' => 'integer',
        'replaces_post_id' => 'integer',
        'replaced_by_post_id' => 'integer',
        'replacement_note' => 'string',
        'source_filename' => 'string',
        'file_size' => 'integer',
        'mime_type' => 'string',
        'file_basename' => 'string',
        'document_category' => 'string',
        'document_type_label' => 'string',
        'document_type_term_id' => 'integer',
        'document_institution' => 'string',
        'document_date' => 'string',
        'document_number' => 'string',
        'document_subject' => 'string',
        'academic_year' => 'string',
        'stamp_position' => 'string',
        'stamp_corner' => 'string',
        'stamp_color' => 'string',
        'stamp_opacity' => 'string',
        'stamp_font_size' => 'string',
        'stamp_border_enabled' => 'string',
        'stamp_placement_mode' => 'string',
        'stamp_manual_x' => 'string',
        'stamp_manual_y' => 'string',
        'qr_logo_enabled' => 'string',
    );

    private const REST_META_KEYS = array(
        'full_title',
        'document_comment',
        'signed_at',
        'signer_name',
        'signer_position',
        'signer_organization',
        'verification_url',
        'document_status',
        'document_version',
        'replaces_post_id',
        'replaced_by_post_id',
        'replacement_note',
        'source_filename',
        'file_size',
        'mime_type',
        'document_category',
        'document_type_label',
        'document_type_term_id',
        'document_institution',
        'document_date',
        'document_number',
        'document_subject',
        'academic_year',
        'stamp_position',
        'stamp_corner',
        'stamp_color',
        'stamp_opacity',
        'stamp_font_size',
        'stamp_border_enabled',
        'stamp_placement_mode',
        'stamp_manual_x',
        'stamp_manual_y',
        'qr_logo_enabled',
    );

    public static function register(): void
    {
        foreach (self::META_KEYS as $key => $type) {
            register_post_meta(
                Sign_Docs_Post_Type::POST_TYPE,
                $key,
                array(
                    'type' => $type,
                    'single' => true,
                    'show_in_rest' => in_array($key, self::REST_META_KEYS, true),
                    'auth_callback' => static function ($allowed, string $meta_key, int $post_id): bool {
                        return $post_id > 0 && current_user_can('edit_post', $post_id);
                    },
                    'sanitize_callback' => self::sanitize_callback($key, $type),
                )
            );
        }
    }

    private static function sanitize_callback(string $key, string $type): callable
    {
        if ('integer' === $type) {
            return 'absint';
        }

        if (in_array($key, array('full_title', 'document_comment', 'replacement_note'), true)) {
            return 'sanitize_textarea_field';
        }

        return 'sanitize_text_field';
    }

    public static function get(int $post_id, string $key): string
    {
        if (! array_key_exists($key, self::META_KEYS)) {
            return '';
        }

        $value = get_post_meta($post_id, $key, true);

        return is_scalar($value) ? (string) $value : '';
    }
}
