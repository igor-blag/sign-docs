<?php
/**
 * Main plugin coordinator.
 *
 * @package SignDocs
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

require_once SIGN_DOCS_PLUGIN_DIR . 'includes/class-post-type.php';
require_once SIGN_DOCS_PLUGIN_DIR . 'includes/class-taxonomies.php';
require_once SIGN_DOCS_PLUGIN_DIR . 'includes/class-meta.php';
require_once SIGN_DOCS_PLUGIN_DIR . 'includes/class-storage.php';
require_once SIGN_DOCS_PLUGIN_DIR . 'includes/class-site-icon.php';
require_once SIGN_DOCS_PLUGIN_DIR . 'includes/class-pdf-certificate.php';
require_once SIGN_DOCS_PLUGIN_DIR . 'includes/class-document-service.php';
require_once SIGN_DOCS_PLUGIN_DIR . 'includes/class-rest-controller.php';
require_once SIGN_DOCS_PLUGIN_DIR . 'includes/class-verification-page.php';
require_once SIGN_DOCS_PLUGIN_DIR . 'includes/class-settings.php';
require_once SIGN_DOCS_PLUGIN_DIR . 'includes/class-admin.php';
require_once SIGN_DOCS_PLUGIN_DIR . 'includes/class-blocks.php';

final class Sign_Docs_Plugin
{
    private static ?self $instance = null;

    private bool $booted = false;

    public static function instance(): self
    {
        if (! self::$instance instanceof self) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function activate(): void
    {
        Sign_Docs_Post_Type::register();
        Sign_Docs_Taxonomies::register();
        Sign_Docs_Storage::ensure_directories();
        flush_rewrite_rules();
    }

    public static function deactivate(): void
    {
        flush_rewrite_rules();
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        add_action('init', array(Sign_Docs_Post_Type::class, 'register'));
        add_action('init', array(Sign_Docs_Taxonomies::class, 'register'));
        add_action('init', array(Sign_Docs_Meta::class, 'register'));
        add_action('init', array(Sign_Docs_Verification_Page::class, 'register_rewrite_rules'));
        add_action('init', array(Sign_Docs_Blocks::class, 'register'));
        add_filter('post_type_link', array(Sign_Docs_Verification_Page::class, 'verification_permalink'), 10, 2);
        add_filter('the_content', array(Sign_Docs_Verification_Page::class, 'render_content'));
        add_action('wp_enqueue_scripts', array(Sign_Docs_Verification_Page::class, 'enqueue_assets'));
        add_action('wp_enqueue_scripts', array(Sign_Docs_Blocks::class, 'enqueue_public_assets'));
        add_filter('manage_sign-docs_posts_columns', array(Sign_Docs_Admin::class, 'columns'));
        add_action('manage_sign-docs_posts_custom_column', array(Sign_Docs_Admin::class, 'column_content'), 10, 2);
        add_action('restrict_manage_posts', array(Sign_Docs_Admin::class, 'taxonomy_filters'));
        add_action('add_meta_boxes_' . Sign_Docs_Post_Type::POST_TYPE, array(Sign_Docs_Admin::class, 'meta_boxes'));
        add_action('admin_init', array(Sign_Docs_Settings::class, 'register'));
        add_action('admin_init', array(Sign_Docs_Admin::class, 'redirect_add_new'));
        add_action('admin_menu', array(Sign_Docs_Admin::class, 'menu'));
        add_action('admin_menu', array(Sign_Docs_Admin::class, 'remove_taxonomy_menus'), 999);
        add_action('admin_post_sign_docs_upload', array(Sign_Docs_Admin::class, 'handle_upload'));
        add_action('admin_post_sign_docs_archive', array(Sign_Docs_Admin::class, 'handle_archive'));
        add_action('admin_enqueue_scripts', array(Sign_Docs_Admin::class, 'enqueue_assets'));
        add_action('post_submitbox_misc_actions', array(Sign_Docs_Admin::class, 'submitbox_archive_action'));
        add_action('admin_head-post.php', array(Sign_Docs_Admin::class, 'hide_trash_action'));
        add_filter('post_row_actions', array(Sign_Docs_Admin::class, 'row_actions'), 10, 2);
        add_filter('bulk_actions-edit-' . Sign_Docs_Post_Type::POST_TYPE, array(Sign_Docs_Admin::class, 'bulk_actions'));
        add_filter('pre_trash_post', array(Sign_Docs_Admin::class, 'archive_instead_of_trash'), 10, 2);
        add_filter('pre_delete_post', array(Sign_Docs_Admin::class, 'archive_instead_of_delete'), 10, 3);
        add_action('rest_api_init', array(Sign_Docs_REST_Controller::class, 'register_routes'));
        add_action('enqueue_block_editor_assets', array(Sign_Docs_Blocks::class, 'enqueue_editor_assets'));
    }

    private function __construct()
    {
    }
}
