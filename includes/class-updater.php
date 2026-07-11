<?php
/**
 * GitHub releases updater for Sign Docs.
 *
 * @package SignDocs
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class Sign_Docs_Updater
{
    private const REPO_OWNER = 'igor-blag';
    private const REPO_NAME = 'sign-docs';
    private const ASSET_NAME = 'sign-docs.zip';

    public static function init(): void
    {
        $instance = new self();
        add_filter('pre_set_site_transient_update_plugins', array($instance, 'check_update'));
        add_filter('plugins_api', array($instance, 'plugin_info'), 20, 3);
    }

    private function plugin_slug(): string
    {
        return plugin_basename(SIGN_DOCS_PLUGIN_FILE);
    }

    private function repo_url(string $path = ''): string
    {
        return 'https://api.github.com/repos/' . self::REPO_OWNER . '/' . self::REPO_NAME . '/' . ltrim($path, '/');
    }

    public function check_update($transient)
    {
        if (! is_object($transient)) {
            return $transient;
        }

        $slug = $this->plugin_slug();
        $release = $this->fetch_latest_release();

        if (null === $release) {
            return $transient;
        }

        $latest_version = ltrim((string) ($release['tag_name'] ?? ''), 'v');
        $current_version = SIGN_DOCS_VERSION;

        if ('' === $latest_version || version_compare($latest_version, $current_version, '<=')) {
            return $transient;
        }

        $download_url = $this->find_asset_url($release);
        if ('' === $download_url) {
            return $transient;
        }

        $transient->response[$slug] = (object) array(
            'slug' => dirname($slug),
            'new_version' => $latest_version,
            'package' => $download_url,
            'url' => 'https://github.com/' . self::REPO_OWNER . '/' . self::REPO_NAME,
            'tested' => $GLOBALS['wp_version'] ?? '6.5',
        );

        return $transient;
    }

    public function plugin_info($result, string $action, $args)
    {
        if ('plugin_information' !== $action || ! is_object($args)) {
            return $result;
        }

        $slug = dirname($this->plugin_slug());
        if (! isset($args->slug) || $args->slug !== $slug) {
            return $result;
        }

        $release = $this->fetch_latest_release();
        if (null === $release) {
            return $result;
        }

        return (object) array(
            'name' => 'Sign Docs',
            'slug' => $slug,
            'version' => ltrim((string) ($release['tag_name'] ?? SIGN_DOCS_VERSION), 'v'),
            'author' => '<a href="https://github.com/' . self::REPO_OWNER . '">Igor Blagoveshchensky</a>',
            'homepage' => 'https://github.com/' . self::REPO_OWNER . '/' . self::REPO_NAME,
            'requires' => '6.5',
            'tested' => $GLOBALS['wp_version'] ?? '6.5',
            'requires_php' => '8.1',
            'downloaded' => 0,
            'last_updated' => (string) ($release['published_at'] ?? ''),
            'sections' => array(
                'description' => 'Fixes signed PDF documents with a server-side SHA-256 hash and a public verification page.',
                'changelog' => (string) ($release['body'] ?? ''),
            ),
            'download_link' => $this->find_asset_url($release),
        );
    }

    private function fetch_latest_release(): ?array
    {
        $cache_key = 'sign_docs_gh_release';
        $cached = get_site_transient($cache_key);

        if (is_array($cached) && isset($cached['tag_name'])) {
            return $cached;
        }

        $response = wp_remote_get(
            $this->repo_url('releases/latest'),
            array(
                'timeout' => 10,
                'headers' => array(
                    'Accept' => 'application/vnd.github.v3+json',
                    'User-Agent' => 'Sign-Docs-Plugin/' . SIGN_DOCS_VERSION,
                ),
            )
        );

        if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
            set_site_transient($cache_key, array('tag_name' => ''), HOUR_IN_SECONDS);
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (! is_array($data) || ! isset($data['tag_name'])) {
            return null;
        }

        set_site_transient($cache_key, $data, HOUR_IN_SECONDS);

        return $data;
    }

    private function find_asset_url(array $release): string
    {
        $assets = isset($release['assets']) && is_array($release['assets'])
            ? $release['assets']
            : array();

        foreach ($assets as $asset) {
            if (! is_array($asset)) {
                continue;
            }

            $name = (string) ($asset['name'] ?? '');
            if (self::ASSET_NAME === $name) {
                return (string) ($asset['browser_download_url'] ?? '');
            }
        }

        return '';
    }
}
