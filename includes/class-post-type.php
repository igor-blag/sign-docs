<?php
/**
 * Custom post type registration.
 *
 * @package SignDocs
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class Sign_Docs_Post_Type
{
    public const POST_TYPE = 'sign-docs';

    public static function register(): void
    {
        register_post_type(
            self::POST_TYPE,
            array(
                'labels' => array(
                    'name' => __('Documents', 'sign-docs'),
                    'singular_name' => __('Document', 'sign-docs'),
                    'add_new' => __('Add document', 'sign-docs'),
                    'add_new_item' => __('Add document', 'sign-docs'),
                    'edit_item' => __('Document card', 'sign-docs'),
                    'new_item' => __('New document', 'sign-docs'),
                    'view_item' => __('Open verification page', 'sign-docs'),
                    'search_items' => __('Search documents', 'sign-docs'),
                    'not_found' => __('No documents found', 'sign-docs'),
                    'menu_name' => __('Sign Docs', 'sign-docs'),
                ),
                'public' => true,
                'show_ui' => true,
                'show_in_menu' => true,
                'show_in_rest' => false,
                'menu_icon' => 'dashicons-media-document',
                'supports' => array('title', 'author'),
                'has_archive' => false,
                'rewrite' => false,
                'capability_type' => 'post',
                'map_meta_cap' => true,
            )
        );
    }
}
