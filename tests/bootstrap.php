<?php
/**
 * Minimal WordPress test double bootstrap for Sign Docs contract tests.
 *
 * @package SignDocs
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/wp/');
define('SIGN_DOCS_VERSION', '0.1.0-test');
define('SIGN_DOCS_PLUGIN_FILE', dirname(__DIR__) . '/sign-docs.php');
define('SIGN_DOCS_PLUGIN_DIR', dirname(__DIR__) . '/');
define('SIGN_DOCS_PLUGIN_URL', 'https://example.test/wp-content/plugins/sign-docs/');

final class WP_Error
{
    /** @var string */
    private $code;
    /** @var string */
    private $message;
    /** @var mixed */
    private $data;

    /**
     * @param mixed $data
     */
    public function __construct(string $code = '', string $message = '', $data = null)
    {
        $this->code = $code;
        $this->message = $message;
        $this->data = $data;
    }

    public function get_error_code(): string
    {
        return $this->code;
    }

    public function get_error_message(): string
    {
        return $this->message;
    }

    /**
     * @return mixed
     */
    public function get_error_data()
    {
        return $this->data;
    }
}

class WP_Post
{
    public int $ID = 0;
    public string $post_type = 'post';
    public string $post_status = 'publish';
    public string $post_title = '';
    public string $post_content = '';
    public int $post_author = 0;
}

class WP_Term
{
    public int $term_id = 0;
    public string $name = '';
}

class WP_User
{
    public int $ID = 0;
    public string $display_name = '';
    /** @var array<string,bool> */
    public array $caps = array();

    public function __construct(int $id = 0, string $name = '')
    {
        $this->ID = $id;
        $this->display_name = $name;
    }

    public function add_cap(string $cap): void
    {
        $this->caps[$cap] = true;
    }

    public function remove_cap(string $cap): void
    {
        unset($this->caps[$cap]);
    }
}

class WP_REST_Server
{
    public const READABLE = 'GET';
    public const CREATABLE = 'POST';
}

class WP_REST_Request
{
    /** @var array<string,mixed> */
    private array $params;
    /** @var array<string,mixed> */
    private array $files;

    /**
     * @param array<string,mixed> $params
     * @param array<string,mixed> $files
     */
    public function __construct(array $params = array(), array $files = array())
    {
        $this->params = $params;
        $this->files = $files;
    }

    /**
     * @return mixed
     */
    public function get_param(string $key)
    {
        return $this->params[$key] ?? null;
    }

    /**
     * @return array<string,mixed>
     */
    public function get_file_params(): array
    {
        return $this->files;
    }
}

class WP_REST_Response
{
    /** @var mixed */
    private $data;
    private int $status;

    /**
     * @param mixed $data
     */
    public function __construct($data = null, int $status = 200)
    {
        $this->data = $data;
        $this->status = $status;
    }

    /**
     * @return mixed
     */
    public function get_data()
    {
        return $this->data;
    }

    public function get_status(): int
    {
        return $this->status;
    }
}

final class Sign_Docs_Verification_Page
{
    public static function url(int $post_id): string
    {
        return 'https://example.test/sign-docs/' . (string) $post_id . '/';
    }
}

/**
 * Reset all mutable test doubles.
 */
function sign_docs_tests_reset(): void
{
    $GLOBALS['sign_docs_test_posts'] = array();
    $GLOBALS['sign_docs_test_meta'] = array();
    $GLOBALS['sign_docs_test_next_post_id'] = 1;
    $GLOBALS['sign_docs_test_current_user_id'] = 7;
    $GLOBALS['sign_docs_test_current_caps'] = array();
    $GLOBALS['sign_docs_test_registered_meta'] = array();
    $GLOBALS['sign_docs_test_terms'] = array();
    $GLOBALS['sign_docs_test_upload_base'] = sys_get_temp_dir() . '/sign-docs-tests-' . bin2hex(random_bytes(4));
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['HTTP_USER_AGENT'] = 'Sign Docs Tests';
}

function sign_docs_tests_rrmdir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    $items = scandir($dir);
    if (! is_array($items)) {
        return;
    }

    foreach ($items as $item) {
        if ('.' === $item || '..' === $item) {
            continue;
        }

        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            sign_docs_tests_rrmdir($path);
        } else {
            unlink($path);
        }
    }

    rmdir($dir);
}

function __(string $text, string $domain = ''): string
{
    return $text;
}

function esc_html__(string $text, string $domain = ''): string
{
    return $text;
}

function is_wp_error($thing): bool
{
    return $thing instanceof WP_Error;
}

function absint($value): int
{
    return abs((int) $value);
}

function sanitize_text_field($value): string
{
    return trim(strip_tags((string) $value));
}

function sanitize_textarea_field($value): string
{
    return trim(strip_tags((string) $value));
}

function sanitize_file_name($value): string
{
    $value = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $value);

    return trim((string) $value, '-');
}

function sanitize_key($value): string
{
    return preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $value)) ?: '';
}

function sanitize_title($value): string
{
    return sanitize_key(str_replace(' ', '-', (string) $value));
}

function sanitize_hex_color($value): string
{
    $value = (string) $value;

    return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtolower($value) : '';
}

function wp_basename(string $path): string
{
    return basename($path);
}

function wp_unslash($value)
{
    return is_string($value) ? stripslashes($value) : $value;
}

function current_time(string $type): string
{
    return '2026-05-20 12:34:56';
}

function get_current_user_id(): int
{
    return (int) ($GLOBALS['sign_docs_test_current_user_id'] ?? 0);
}

function wp_insert_post(array $postarr, bool $wp_error = false)
{
    $post = new WP_Post();
    $post->ID = (int) $GLOBALS['sign_docs_test_next_post_id']++;
    $post->post_type = (string) ($postarr['post_type'] ?? 'post');
    $post->post_status = (string) ($postarr['post_status'] ?? 'draft');
    $post->post_title = (string) ($postarr['post_title'] ?? '');
    $post->post_content = (string) ($postarr['post_content'] ?? '');
    $post->post_author = (int) ($postarr['post_author'] ?? 0);
    $GLOBALS['sign_docs_test_posts'][$post->ID] = $post;

    return $post->ID;
}

function wp_update_post(array $postarr)
{
    $post_id = absint($postarr['ID'] ?? 0);
    if ($post_id <= 0 || empty($GLOBALS['sign_docs_test_posts'][$post_id])) {
        return 0;
    }

    foreach ($postarr as $key => $value) {
        if ('ID' === $key) {
            continue;
        }
        $GLOBALS['sign_docs_test_posts'][$post_id]->{$key} = $value;
    }

    return $post_id;
}

function wp_delete_post(int $post_id, bool $force_delete = false)
{
    $post = $GLOBALS['sign_docs_test_posts'][$post_id] ?? null;
    unset($GLOBALS['sign_docs_test_posts'][$post_id], $GLOBALS['sign_docs_test_meta'][$post_id]);

    return $post;
}

function get_post_type(int $post_id): string
{
    return isset($GLOBALS['sign_docs_test_posts'][$post_id])
        ? $GLOBALS['sign_docs_test_posts'][$post_id]->post_type
        : '';
}

function get_post_status(int $post_id): string
{
    return isset($GLOBALS['sign_docs_test_posts'][$post_id])
        ? $GLOBALS['sign_docs_test_posts'][$post_id]->post_status
        : '';
}

function get_the_title(int $post_id): string
{
    return isset($GLOBALS['sign_docs_test_posts'][$post_id])
        ? $GLOBALS['sign_docs_test_posts'][$post_id]->post_title
        : '';
}

function update_post_meta(int $post_id, string $key, $value): bool
{
    $GLOBALS['sign_docs_test_meta'][$post_id][$key] = $value;

    return true;
}

function get_post_meta(int $post_id, string $key, bool $single = false)
{
    return $GLOBALS['sign_docs_test_meta'][$post_id][$key] ?? '';
}

function wp_check_filetype(string $filename, array $mimes): array
{
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    return array('type' => $mimes[$extension] ?? '');
}

function wp_get_upload_dir(): array
{
    $base = $GLOBALS['sign_docs_test_upload_base'] . '/uploads';

    return array(
        'basedir' => $base,
        'baseurl' => 'https://example.test/wp-content/uploads',
    );
}

function trailingslashit(string $path): string
{
    return rtrim($path, '/') . '/';
}

function wp_mkdir_p(string $target): bool
{
    return is_dir($target) || mkdir($target, 0777, true);
}

function wp_date(string $format, int $timestamp): string
{
    return gmdate($format, $timestamp);
}

function get_term_by(string $field, string $value, string $taxonomy)
{
    return false;
}

function wp_set_object_terms(int $object_id, $terms, string $taxonomy, bool $append = false)
{
    $GLOBALS['sign_docs_test_terms'][$object_id][$taxonomy] = $terms;

    return true;
}

function current_user_can(string $capability, ...$args): bool
{
    $caps = $GLOBALS['sign_docs_test_current_caps'] ?? array();

    if ('edit_post' === $capability) {
        return ! empty($caps['edit_post']) || ! empty($caps['edit_posts']) || ! empty($caps['manage_options']);
    }

    return ! empty($caps[$capability]);
}

function register_post_meta(string $post_type, string $meta_key, array $args): void
{
    $GLOBALS['sign_docs_test_registered_meta'][$meta_key] = array(
        'post_type' => $post_type,
        'args' => $args,
    );
}

function get_bloginfo(string $show): string
{
    return 'Test Site';
}

function get_option(string $option, $default = false)
{
    return $default;
}

function get_role(string $role)
{
    return new class {
        /** @var array<string,bool> */
        private array $caps = array();

        public function has_cap(string $cap): bool
        {
            return ! empty($this->caps[$cap]);
        }

        public function add_cap(string $cap): void
        {
            $this->caps[$cap] = true;
        }
    };
}

function get_users(array $args): array
{
    return array();
}

require_once SIGN_DOCS_PLUGIN_DIR . 'includes/class-post-type.php';
require_once SIGN_DOCS_PLUGIN_DIR . 'includes/class-meta.php';
require_once SIGN_DOCS_PLUGIN_DIR . 'includes/class-storage.php';
require_once SIGN_DOCS_PLUGIN_DIR . 'includes/class-title-template.php';
require_once SIGN_DOCS_PLUGIN_DIR . 'includes/class-document-service.php';
require_once SIGN_DOCS_PLUGIN_DIR . 'includes/class-settings.php';
require_once SIGN_DOCS_PLUGIN_DIR . 'includes/class-rest-controller.php';
require_once SIGN_DOCS_PLUGIN_DIR . 'includes/class-admin.php';
