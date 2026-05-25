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
        'sha256_hash' => 'string',
        'signed_at' => 'string',
        'signer_name' => 'string',
        'signer_position' => 'string',
        'signer_organization' => 'string',
        'signer_user_id' => 'integer',
        'verification_url' => 'string',
        'qr_code_data' => 'string',
        'document_status' => 'string',
        'document_version' => 'integer',
        'source_filename' => 'string',
        'file_size' => 'integer',
        'mime_type' => 'string',
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
        'stamp_width_mm' => 'integer',
        'stamp_border_enabled' => 'string',
        'stamp_placement_mode' => 'string',
        'stamp_manual_x' => 'string',
        'stamp_manual_y' => 'string',
        'qr_logo_enabled' => 'string',
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
                    'show_in_rest' => true,
                    'auth_callback' => static function (): bool {
                        return current_user_can('edit_posts');
                    },
                    'sanitize_callback' => self::sanitize_callback($type),
                )
            );
        }
    }

    private static function sanitize_callback(string $type): callable
    {
        if ('integer' === $type) {
            return 'absint';
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
