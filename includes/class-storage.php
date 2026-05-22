<?php
/**
 * File storage helpers.
 *
 * @package SignDocs
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class Sign_Docs_Storage
{
    public const BASE_DIR = 'sign-docs';

    /**
     * @return array{base_dir:string,base_url:string,assets_dir:string,assets_url:string}
     */
    public static function paths(): array
    {
        $uploads = wp_get_upload_dir();
        $base_dir = trailingslashit($uploads['basedir']) . self::BASE_DIR;
        $base_url = trailingslashit($uploads['baseurl']) . self::BASE_DIR;

        return array(
            'base_dir' => $base_dir,
            'base_url' => $base_url,
            'assets_dir' => $base_dir . '/assets',
            'assets_url' => $base_url . '/assets',
        );
    }

    public static function ensure_directories(): void
    {
        $paths = self::paths();

        foreach (array($paths['base_dir'], $paths['assets_dir']) as $directory) {
            if (! is_dir($directory)) {
                wp_mkdir_p($directory);
            }
        }

        self::write_index_file($paths['base_dir']);
        self::write_index_file($paths['assets_dir']);
    }

    /**
     * @return array{document_dir:string,original_path:string,stamped_path:string,original_url:string,stamped_url:string}
     */
    public static function document_paths(int $post_id, ?int $timestamp = null): array
    {
        $timestamp = $timestamp ?: time();
        $paths = self::paths();
        $year = wp_date('Y', $timestamp);
        $month = wp_date('m', $timestamp);
        $relative = $year . '/' . $month . '/' . $post_id;
        $document_dir = $paths['base_dir'] . '/' . $relative;
        $document_url = $paths['base_url'] . '/' . $relative;

        return array(
            'document_dir' => $document_dir,
            'original_path' => $document_dir . '/original.pdf',
            'stamped_path' => $document_dir . '/stamped.pdf',
            'original_url' => $document_url . '/original.pdf',
            'stamped_url' => $document_url . '/stamped.pdf',
        );
    }

    public static function ensure_document_directories(int $post_id, ?int $timestamp = null): array
    {
        $paths = self::document_paths($post_id, $timestamp);

        if (! is_dir($paths['document_dir'])) {
            wp_mkdir_p($paths['document_dir']);
        }

        self::write_index_file($paths['document_dir']);

        return $paths;
    }

    public static function hash_file(string $path): string
    {
        if (! is_readable($path) || ! is_file($path)) {
            return '';
        }

        $hash = hash_file('sha256', $path);

        return is_string($hash) ? $hash : '';
    }

    private static function write_index_file(string $directory): void
    {
        $index = trailingslashit($directory) . 'index.php';

        if (! file_exists($index)) {
            file_put_contents($index, "<?php\n// Silence is golden.\n");
        }
    }
}
