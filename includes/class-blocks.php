<?php
/**
 * Gutenberg block registration.
 *
 * @package SignDocs
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class Sign_Docs_Blocks
{
    public static function register(): void
    {
        register_block_type(
            'sign-docs/document',
            array(
                'api_version' => 3,
                'attributes' => array(
                    'postId' => array('type' => 'number'),
                    'title' => array(
                        'type' => 'string',
                        'default' => '',
                    ),
                    'fullTitle' => array(
                        'type' => 'string',
                        'default' => '',
                    ),
                    'verificationUrl' => array(
                        'type' => 'string',
                        'default' => '',
                    ),
                    'stampedFileUrl' => array(
                        'type' => 'string',
                        'default' => '',
                    ),
                    'originalFileUrl' => array(
                        'type' => 'string',
                        'default' => '',
                    ),
                    'sha256Hash' => array(
                        'type' => 'string',
                        'default' => '',
                    ),
                    'linkText' => array(
                        'type' => 'string',
                        'default' => '',
                    ),
                    'openInNewTab' => array(
                        'type' => 'boolean',
                        'default' => false,
                    ),
                    'showIcon' => array(
                        'type' => 'boolean',
                        'default' => true,
                    ),
                    'showMeta' => array(
                        'type' => 'boolean',
                        'default' => false,
                    ),
                    'displayMode' => array(
                        'type' => 'string',
                        'default' => 'link',
                    ),
                    'interactionMode' => array(
                        'type' => 'string',
                        'default' => 'title-details',
                    ),
                    'statusLabel' => array(
                        'type' => 'string',
                        'default' => '',
                    ),
                    'signedAt' => array(
                        'type' => 'string',
                        'default' => '',
                    ),
                    'documentVersion' => array(
                        'type' => 'string',
                        'default' => '',
                    ),
                    'showDownloadButton' => array(
                        'type' => 'boolean',
                        'default' => false,
                    ),
                    'showEmbeddedPdf' => array(
                        'type' => 'boolean',
                        'default' => false,
                    ),
                ),
                'supports' => array(
                    'align' => array('left', 'center', 'right', 'wide', 'full'),
                    'className' => true,
                ),
                'render_callback' => array(self::class, 'render_document'),
            )
        );
    }

    public static function enqueue_editor_assets(): void
    {
        $asset_path = SIGN_DOCS_PLUGIN_DIR . 'assets/js/document-block.js';
        $pdf_lib_path = SIGN_DOCS_PLUGIN_DIR . 'assets/vendor/pdf-lib.min.js';
        $fontkit_path = SIGN_DOCS_PLUGIN_DIR . 'assets/vendor/fontkit.umd.min.js';
        $qr_path = SIGN_DOCS_PLUGIN_DIR . 'assets/vendor/qrcode.min.js';
        $regular_font_path = SIGN_DOCS_PLUGIN_DIR . 'assets/vendor/GolosText-Regular.ttf';
        $medium_font_path = SIGN_DOCS_PLUGIN_DIR . 'assets/vendor/GolosText-Medium.ttf';
        $has_vendor = file_exists($pdf_lib_path)
            && file_exists($fontkit_path)
            && file_exists($qr_path)
            && file_exists($regular_font_path)
            && file_exists($medium_font_path);

        if ($has_vendor) {
            wp_enqueue_script('sign-docs-pdf-lib', SIGN_DOCS_PLUGIN_URL . 'assets/vendor/pdf-lib.min.js', array(), (string) filemtime($pdf_lib_path), true);
            wp_enqueue_script('sign-docs-qrcode', SIGN_DOCS_PLUGIN_URL . 'assets/vendor/qrcode.min.js', array(), (string) filemtime($qr_path), true);
            wp_enqueue_script('sign-docs-fontkit', SIGN_DOCS_PLUGIN_URL . 'assets/vendor/fontkit.umd.min.js', array(), (string) filemtime($fontkit_path), true);
        }

        $dependencies = array(
            'wp-blocks',
            'wp-block-editor',
            'wp-components',
            'wp-element',
            'wp-i18n',
            'wp-api-fetch',
        );

        if ($has_vendor) {
            $dependencies[] = 'sign-docs-pdf-lib';
            $dependencies[] = 'sign-docs-qrcode';
            $dependencies[] = 'sign-docs-fontkit';
        }

        wp_enqueue_script(
            'sign-docs-document-block',
            SIGN_DOCS_PLUGIN_URL . 'assets/js/document-block.js',
            $dependencies,
            file_exists($asset_path) ? (string) filemtime($asset_path) : SIGN_DOCS_VERSION,
            true
        );

        self::enqueue_public_style();

        wp_localize_script(
            'sign-docs-document-block',
            'SignDocsBlock',
            array(
                'prepareUrl' => rest_url('sign-docs/v1/prepare'),
                'completeUrl' => rest_url('sign-docs/v1/complete'),
                'nonce' => wp_create_nonce('wp_rest'),
                'hasVendor' => $has_vendor,
                'siteIconUrl' => self::site_icon_url(),
                'documentsPath' => '/sign-docs/v1/documents',
                'defaults' => Sign_Docs_Settings::get(),
                'filters' => self::filters(),
                'fonts' => array(
                    'regular' => SIGN_DOCS_PLUGIN_URL . 'assets/vendor/GolosText-Regular.ttf',
                    'medium' => SIGN_DOCS_PLUGIN_URL . 'assets/vendor/GolosText-Medium.ttf',
                ),
            )
        );
    }

    public static function enqueue_public_assets(): void
    {
        $script_path = SIGN_DOCS_PLUGIN_DIR . 'assets/js/public.js';

        self::enqueue_public_style();

        wp_enqueue_script(
            'sign-docs-public',
            SIGN_DOCS_PLUGIN_URL . 'assets/js/public.js',
            array(),
            file_exists($script_path) ? (string) filemtime($script_path) : SIGN_DOCS_VERSION,
            true
        );
    }

    private static function site_icon_url(): string
    {
        return Sign_Docs_Site_Icon::url();
    }

    private static function enqueue_public_style(): void
    {
        static $inline_added = false;

        wp_enqueue_style(
            'sign-docs-public',
            SIGN_DOCS_PLUGIN_URL . 'assets/css/public.css',
            array(),
            SIGN_DOCS_VERSION
        );

        if ($inline_added) {
            return;
        }

        $settings = Sign_Docs_Settings::get();
        $radius = (int) ($settings['button_border_radius'] ?? 9999);
        $css = sprintf(
            ':root{--sign-docs-document-button-bg:%s;--sign-docs-document-button-color:%s;--sign-docs-document-button-outline:%s;--sign-docs-document-button-radius:%dpx;}',
            esc_html($settings['button_primary_color'] ?? '#32373c'),
            esc_html($settings['button_primary_text_color'] ?? '#ffffff'),
            esc_html($settings['button_outline_color'] ?? '#32373c'),
            min(9999, max(0, $radius))
        );

        wp_add_inline_style('sign-docs-public', $css);
        $inline_added = true;
    }

    /**
     * @param array<string,mixed> $attributes
     */
    public static function render_document(array $attributes): string
    {
        $post_id = isset($attributes['postId']) ? absint($attributes['postId']) : 0;

        if ($post_id <= 0 || Sign_Docs_Post_Type::POST_TYPE !== get_post_type($post_id) || 'publish' !== get_post_status($post_id)) {
            return '';
        }

        self::enqueue_public_style();

        $title = Sign_Docs_Meta::get($post_id, 'full_title') ?: get_the_title($post_id);
        $link_text = isset($attributes['linkText']) ? sanitize_text_field((string) $attributes['linkText']) : '';
        $display_mode = isset($attributes['displayMode']) ? sanitize_key((string) $attributes['displayMode']) : 'link';
        $display_mode = in_array($display_mode, array('link', 'button', 'card'), true) ? $display_mode : 'link';
        $interaction_mode = isset($attributes['interactionMode']) ? sanitize_key((string) $attributes['interactionMode']) : 'title-details';
        $interaction_mode = in_array($interaction_mode, array('title-details', 'buttons'), true) ? $interaction_mode : 'title-details';
        $show_icon = ! array_key_exists('showIcon', $attributes) || (bool) $attributes['showIcon'];
        $show_meta = ! empty($attributes['showMeta']);
        $open_in_new_tab = ! empty($attributes['openInNewTab']);
        $verification_url = Sign_Docs_Meta::get($post_id, 'verification_url') ?: Sign_Docs_Verification_Page::url($post_id);
        $stamped_file_url = Sign_Docs_Meta::get($post_id, 'stamped_file_url');
        $original_file_url = Sign_Docs_Meta::get($post_id, 'original_file_url');
        $sha256_hash = Sign_Docs_Meta::get($post_id, 'sha256_hash');
        $document_status = Sign_Docs_Meta::get($post_id, 'document_status') ?: 'active';
        $status = self::status_label($document_status);
        $signed_at = Sign_Docs_Meta::get($post_id, 'signed_at');
        $version = Sign_Docs_Meta::get($post_id, 'document_version') ?: '1';
        $show_download_button = ! empty($attributes['showDownloadButton']);
        $show_embedded_pdf = ! empty($attributes['showEmbeddedPdf']);
        $is_current_document = self::is_current_document_status($document_status);

        if ('' === trim($title) || '' === trim($verification_url)) {
            return '';
        }

        $wrapper_attributes = get_block_wrapper_attributes(
            array(
                'class' => 'sign-docs-document-link sign-docs-document-link--' . $display_mode . ($is_current_document ? '' : ' sign-docs-document-link--inactive'),
            )
        );
        $target = $open_in_new_tab ? ' target="_blank" rel="noopener noreferrer"' : '';
        $label = '' !== trim($link_text) ? $link_text : $title;
        $icon = $show_icon ? '<span class="sign-docs-document-link__icon" aria-hidden="true"></span>' : '';
        $meta = '';

        if ($show_meta) {
            $parts = array_filter(array($status, '' !== $signed_at ? $signed_at : '', 'v' . $version));
            $meta = '<span class="sign-docs-document-link__meta">' . esc_html(implode(' · ', $parts)) . '</span>';
        }

        $download = $is_current_document && '' !== $stamped_file_url ? sprintf(
            '<a class="sign-docs-document-link__download" href="%s" download>%s</a>',
            esc_url($stamped_file_url),
            esc_html__('Скачать', 'sign-docs')
        ) : '';
        $notice = $is_current_document ? '' : sprintf(
            '<span class="sign-docs-document-link__notice">%s</span>',
            esc_html__('Документ не является действующим. Используйте страницу проверки для уточнения статуса.', 'sign-docs')
        );

        $title_content = sprintf(
            '%s<span class="sign-docs-document-link__body"><span class="sign-docs-document-link__text">%s</span>%s</span>',
            $icon,
            esc_html($label),
            $meta
        );
        $details = self::details($title, $status, $signed_at, $version, $sha256_hash, $is_current_document ? $stamped_file_url : '', $is_current_document ? $original_file_url : '', $verification_url, 'title-details' === $interaction_mode ? $title_content : esc_html__('Сведения', 'sign-docs'), 'title-details' === $interaction_mode, $is_current_document);
        $embed = '';
        if ($is_current_document && $show_embedded_pdf && '' !== $stamped_file_url) {
            $embed = sprintf(
                '<div class="sign-docs-document-link__embed"><iframe title="%s" src="%s"></iframe></div>',
                esc_attr($title),
                esc_url($stamped_file_url)
            );
        }

        if ('buttons' === $interaction_mode) {
            return sprintf(
                '<div %s><div class="sign-docs-document-link__row"><span class="sign-docs-document-link__anchor sign-docs-document-link__anchor--static">%s</span>%s%s</div>%s%s</div>',
                $wrapper_attributes,
                $title_content,
                $download,
                $details,
                $notice,
                $embed
            );
        }

        return sprintf(
            '<div %s><div class="sign-docs-document-link__row">%s</div>%s%s</div>',
            $wrapper_attributes,
            $details,
            $notice,
            $embed
        );
    }

    private static function details(string $title, string $status, string $signed_at, string $version, string $hash, string $stamped_url, string $original_url, string $verification_url, string $summary, bool $summary_is_html, bool $is_current_document): string
    {
        $rows = array(
            __('Статус', 'sign-docs') => $status,
            __('Дата подписи', 'sign-docs') => $signed_at,
            __('Версия', 'sign-docs') => $version,
            __('SHA-256', 'sign-docs') => $hash,
        );
        $html = sprintf(
            '<details class="sign-docs-document-link__details%s"><summary class="%s">%s</summary><span class="sign-docs-document-link__popover"><strong>%s</strong><span class="sign-docs-document-link__popover-rows">',
            $summary_is_html ? ' sign-docs-document-link__details--title' : '',
            $summary_is_html ? 'sign-docs-document-link__anchor' : 'sign-docs-document-link__summary-button',
            $summary_is_html ? $summary : esc_html($summary),
            esc_html($title)
        );

        foreach ($rows as $label => $value) {
            if ('' === trim($value)) {
                continue;
            }

            $html .= sprintf(
                '<span><b>%s</b><em>%s</em></span>',
                esc_html($label),
                esc_html($value)
            );
        }

        $html .= '</span><span class="sign-docs-document-link__popover-actions">';
        if ('' !== $stamped_url) {
            $html .= sprintf('<a class="sign-docs-document-link__download" href="%s" download>%s</a>', esc_url($stamped_url), esc_html__('Скачать', 'sign-docs'));
        }
        if ($is_current_document && '' !== $original_url) {
            $html .= sprintf('<a href="%s">%s</a>', esc_url($original_url), esc_html__('Оригинал', 'sign-docs'));
        }
        $html .= sprintf('<a href="%s">%s</a>', esc_url($verification_url), esc_html__('Проверка', 'sign-docs'));
        $html .= '</span></span></details>';

        return $html;
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

    private static function is_current_document_status(string $status): bool
    {
        return in_array($status ?: 'active', array('active', 'unsigned'), true);
    }

    /**
     * @return array<string,array<int,array{id:int,name:string,slug:string}>>
     */
    private static function filters(): array
    {
        return array(
            'categories' => self::terms('sign_doc_category'),
            'types' => self::document_type_terms(),
            'departments' => self::terms('sign_doc_department'),
            'institutions' => self::terms('sign_doc_institution'),
        );
    }

    /**
     * @return array<int,array{id:int,name:string,slug:string}>
     */
    private static function terms(string $taxonomy): array
    {
        $terms = get_terms(
            array(
                'taxonomy' => $taxonomy,
                'hide_empty' => false,
                'orderby' => 'name',
            )
        );

        if (is_wp_error($terms) || ! is_array($terms)) {
            return array();
        }

        $items = array();
        foreach ($terms as $term) {
            if (! $term instanceof WP_Term) {
                continue;
            }

            $items[] = array(
                'id' => (int) $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
            );
        }

        return $items;
    }

    /**
     * @return array<int,array{id:int,name:string,slug:string,category:string}>
     */
    private static function document_type_terms(): array
    {
        $terms = get_terms(
            array(
                'taxonomy' => 'sign_doc_type',
                'hide_empty' => false,
                'orderby' => 'name',
            )
        );

        if (is_wp_error($terms) || ! is_array($terms)) {
            return array();
        }

        $parent_categories = array(
            'local-acts' => 'local-act',
            'external-regulations' => 'external-regulation',
            'other-documents' => 'other-document',
        );
        $parent_slugs_by_id = array();

        foreach ($terms as $term) {
            if ($term instanceof WP_Term && 0 === (int) $term->parent) {
                $parent_slugs_by_id[(int) $term->term_id] = $term->slug;
            }
        }

        $items = array();
        foreach ($terms as $term) {
            if (! $term instanceof WP_Term || 0 === (int) $term->parent) {
                continue;
            }

            $parent_slug = $parent_slugs_by_id[(int) $term->parent] ?? '';
            $items[] = array(
                'id' => (int) $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
                'category' => $parent_categories[$parent_slug] ?? 'other-document',
            );
        }

        usort(
            $items,
            static function (array $a, array $b): int {
                $category_order = array('local-act' => 0, 'external-regulation' => 1, 'other-document' => 2);
                $category_compare = ($category_order[$a['category']] ?? 99) <=> ($category_order[$b['category']] ?? 99);

                return 0 !== $category_compare ? $category_compare : strnatcasecmp((string) $a['name'], (string) $b['name']);
            }
        );

        return $items;
    }
}
