<?php
/**
 * Plugin Name: Sign Docs
 * Description: Fixes signed PDF documents with a server-side SHA-256 hash and a public verification page.
 * Version: 0.1.0
 * Author: Igor Blagoveshchensky
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Text Domain: sign-docs
 *
 * @package SignDocs
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('SIGN_DOCS_VERSION', '0.1.0');
define('SIGN_DOCS_PLUGIN_FILE', __FILE__);
define('SIGN_DOCS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SIGN_DOCS_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once SIGN_DOCS_PLUGIN_DIR . 'includes/class-plugin.php';
require_once SIGN_DOCS_PLUGIN_DIR . 'includes/class-updater.php';

register_activation_hook(__FILE__, array('Sign_Docs_Plugin', 'activate'));
register_deactivation_hook(__FILE__, array('Sign_Docs_Plugin', 'deactivate'));

add_action(
    'plugins_loaded',
    static function (): void {
        Sign_Docs_Plugin::instance()->boot();
        Sign_Docs_Updater::init();
    }
);
