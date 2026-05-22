<?php
/**
 * Cached PNG asset for the site icon used in QR codes.
 *
 * @package SignDocs
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class Sign_Docs_Site_Icon
{
    private const OPTION_NAME = 'sign_docs_site_icon_cache';

    public static function url(): string
    {
        $source = self::source();
        if (empty($source['url'])) {
            return '';
        }

        if (empty($source['path']) || ! is_readable($source['path'])) {
            return $source['url'];
        }

        $paths = Sign_Docs_Storage::paths();
        if (! is_dir($paths['assets_dir'])) {
            wp_mkdir_p($paths['assets_dir']);
        }

        $cache_path = trailingslashit($paths['assets_dir']) . 'site-icon.png';
        $source_mtime = (string) filemtime($source['path']);
        $cache = get_option(self::OPTION_NAME, array());
        $cache = is_array($cache) ? $cache : array();

        if (
            file_exists($cache_path)
            && (string) ($cache['source_id'] ?? '') === (string) $source['id']
            && (string) ($cache['source_mtime'] ?? '') === $source_mtime
        ) {
            return add_query_arg('v', (string) filemtime($cache_path), $paths['assets_url'] . '/site-icon.png');
        }

        if (! self::write_png($source['path'], $cache_path)) {
            return $source['url'];
        }

        update_option(
            self::OPTION_NAME,
            array(
                'source_id' => (string) $source['id'],
                'source_mtime' => $source_mtime,
            ),
            false
        );

        return add_query_arg('v', (string) filemtime($cache_path), $paths['assets_url'] . '/site-icon.png');
    }

    /**
     * @return array{id:int,path:string,url:string}
     */
    private static function source(): array
    {
        $icon_id = absint(get_option('site_icon'));
        if ($icon_id > 0) {
            return self::attachment_source($icon_id);
        }

        $logo_id = absint(get_theme_mod('custom_logo'));
        if ($logo_id > 0) {
            return self::attachment_source($logo_id);
        }

        return array(
            'id' => 0,
            'path' => '',
            'url' => '',
        );
    }

    /**
     * @return array{id:int,path:string,url:string}
     */
    private static function attachment_source(int $attachment_id): array
    {
        $path = get_attached_file($attachment_id);
        $url = wp_get_attachment_image_url($attachment_id, 'thumbnail');

        return array(
            'id' => $attachment_id,
            'path' => is_string($path) ? $path : '',
            'url' => is_string($url) ? $url : '',
        );
    }

    private static function write_png(string $source_path, string $target_path): bool
    {
        $editor = wp_get_image_editor($source_path);
        if (is_wp_error($editor)) {
            return false;
        }

        $saved = $editor->save($target_path, 'image/png');

        return is_array($saved) && file_exists($target_path);
    }
}
