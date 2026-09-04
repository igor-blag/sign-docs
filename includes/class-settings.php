<?php
/**
 * Plugin settings for stamp defaults.
 *
 * @package SignDocs
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class Sign_Docs_Settings
{
    public const OPTION_NAME = 'sign_docs_settings';
    public const UPLOAD_CAPABILITY = 'sign_docs_upload_documents';

    public const ROW_KEYS = array('header', 'meta', 'signer', 'org');

    public const QR_POSITIONS = array('right', 'below');

    /**
     * @return array<string,string>
     */
    public static function defaults(): array
    {
        return array(
            'signer_name' => '',
            'signer_position' => '',
            'signer_organization' => get_bloginfo('name'),
            'stamp_corner' => 'top-left',
            'stamp_color' => '#2e7d32',
            'stamp_opacity' => '1',
            'stamp_font_size' => '8.4',
            'stamp_border_enabled' => '1',
            'stamp_padding' => '5',
            'stamp_qr_gap' => '5',
            'stamp_qr_padding' => '5',
            'stamp_qr_size' => '54',
            'stamp_qr_ec_level' => 'h',
            'stamp_line_spacing' => '1.25',
            'stamp_rows' => 'header,meta,signer,org',
            'stamp_qr_enabled' => '1',
            'stamp_qr_position' => 'right',
            'qr_logo_enabled' => '1',
            'stamp_page' => 'first',
            'stamp_footer_enabled' => '1',
            'stamp_footer_border_enabled' => '1',
            'stamp_footer_font_size' => '6.4',
            'stamp_footer_opacity' => '1',
            'stamp_footer_position' => 'bottom',
            'button_primary_color' => '#32373c',
            'button_primary_text_color' => '#ffffff',
            'button_outline_color' => '#32373c',
            'button_border_radius' => '9999',
            'ai_autofill_enabled' => '0',
        );
    }

    /**
     * @return array<string,string>
     */
    public static function get(): array
    {
        $settings = get_option(self::OPTION_NAME, array());
        $settings = is_array($settings) ? $settings : array();

        return array_merge(self::defaults(), array_map('strval', $settings));
    }

    public static function register(): void
    {
        self::ensure_administrator_capability();

        register_setting(
            'sign_docs_settings',
            self::OPTION_NAME,
            array(
                'type' => 'array',
                'sanitize_callback' => array(self::class, 'sanitize'),
                'default' => self::defaults(),
            )
        );
    }

    /**
     * @param mixed $value
     * @return array<string,string>
     */
    public static function sanitize($value): array
    {
        $value = is_array($value) ? $value : array();
        self::sync_upload_users(isset($value['upload_user_ids']) ? $value['upload_user_ids'] : array());

        $color = isset($value['stamp_color']) ? sanitize_hex_color((string) $value['stamp_color']) : '';
        $opacity = isset($value['stamp_opacity']) ? (float) $value['stamp_opacity'] : 1.0;
        $font_size = isset($value['stamp_font_size']) ? (float) $value['stamp_font_size'] : 8.4;
        $corner = isset($value['stamp_corner']) ? sanitize_key((string) $value['stamp_corner']) : 'top-left';
        $stamp_border_enabled = ! empty($value['stamp_border_enabled']) ? '1' : '0';
        $stamp_padding = isset($value['stamp_padding']) ? (float) $value['stamp_padding'] : 5.0;
        $stamp_qr_gap = isset($value['stamp_qr_gap']) ? (float) $value['stamp_qr_gap'] : 5.0;
        $stamp_qr_padding = isset($value['stamp_qr_padding']) ? (float) $value['stamp_qr_padding'] : 5.0;
        $stamp_qr_size = isset($value['stamp_qr_size']) ? (float) $value['stamp_qr_size'] : 54.0;
        $stamp_qr_ec_level = isset($value['stamp_qr_ec_level']) ? sanitize_key((string) $value['stamp_qr_ec_level']) : 'h';
        if (! in_array($stamp_qr_ec_level, array('l', 'm', 'q', 'h'), true)) {
            $stamp_qr_ec_level = 'h';
        }
        $stamp_line_spacing = isset($value['stamp_line_spacing']) ? (float) $value['stamp_line_spacing'] : 1.25;
        $stamp_rows = self::sanitize_stamp_rows(isset($value['stamp_rows']) ? $value['stamp_rows'] : array());
        $stamp_qr_enabled = ! empty($value['stamp_qr_enabled']) ? '1' : '0';
        $qr_position = isset($value['stamp_qr_position']) ? sanitize_key((string) $value['stamp_qr_position']) : 'right';
        if (! in_array($qr_position, self::QR_POSITIONS, true)) {
            $qr_position = 'right';
        }
        $qr_logo_enabled = ! empty($value['qr_logo_enabled']) ? '1' : '0';
        $stamp_page = isset($value['stamp_page']) ? sanitize_key((string) $value['stamp_page']) : 'first';
        if (! in_array($stamp_page, array('first', 'last', 'both'), true)) {
            $stamp_page = 'first';
        }
        $stamp_footer_enabled = ! empty($value['stamp_footer_enabled']) ? '1' : '0';
        $stamp_footer_border_enabled = ! empty($value['stamp_footer_border_enabled']) ? '1' : '0';
        $stamp_footer_font_size = isset($value['stamp_footer_font_size']) ? (float) $value['stamp_footer_font_size'] : 6.4;
        $stamp_footer_opacity = isset($value['stamp_footer_opacity']) ? (float) $value['stamp_footer_opacity'] : 1.0;
        $stamp_footer_position = isset($value['stamp_footer_position']) ? sanitize_key((string) $value['stamp_footer_position']) : 'bottom';
        if (! in_array($stamp_footer_position, array('top', 'bottom'), true)) {
            $stamp_footer_position = 'bottom';
        }
        $button_primary_color = isset($value['button_primary_color']) ? sanitize_hex_color((string) $value['button_primary_color']) : '';
        $button_primary_text_color = isset($value['button_primary_text_color']) ? sanitize_hex_color((string) $value['button_primary_text_color']) : '';
        $button_outline_color = isset($value['button_outline_color']) ? sanitize_hex_color((string) $value['button_outline_color']) : '';
        $button_border_radius = isset($value['button_border_radius']) ? (int) $value['button_border_radius'] : 9999;
        $ai_autofill_enabled = ! empty($value['ai_autofill_enabled']) ? '1' : '0';

        if (! in_array($corner, array('top-left', 'top-right', 'bottom-left', 'bottom-right'), true)) {
            $corner = 'top-left';
        }

        return array(
            'signer_name' => isset($value['signer_name']) ? sanitize_text_field((string) $value['signer_name']) : '',
            'signer_position' => isset($value['signer_position']) ? sanitize_text_field((string) $value['signer_position']) : '',
            'signer_organization' => isset($value['signer_organization']) ? sanitize_text_field((string) $value['signer_organization']) : '',
            'stamp_corner' => $corner,
            'stamp_color' => $color ?: '#2e7d32',
            'stamp_opacity' => (string) min(1, max(0.1, $opacity)),
            'stamp_font_size' => (string) min(12, max(6, $font_size)),
            'stamp_border_enabled' => $stamp_border_enabled,
            'stamp_padding' => (string) round(min(16, max(2, $stamp_padding)), 1),
            'stamp_qr_gap' => (string) round(min(20, max(0, $stamp_qr_gap)), 1),
            'stamp_qr_padding' => (string) round(min(12, max(0, $stamp_qr_padding)), 1),
            'stamp_qr_size' => (string) round(min(120, max(20, $stamp_qr_size)), 1),
            'stamp_qr_ec_level' => $stamp_qr_ec_level,
            'stamp_line_spacing' => (string) round(min(2, max(1, $stamp_line_spacing)), 2),
            'stamp_rows' => $stamp_rows,
            'stamp_qr_enabled' => $stamp_qr_enabled,
            'stamp_qr_position' => $qr_position,
            'qr_logo_enabled' => $qr_logo_enabled,
            'stamp_page' => $stamp_page,
            'stamp_footer_enabled' => $stamp_footer_enabled,
            'stamp_footer_border_enabled' => $stamp_footer_border_enabled,
            'stamp_footer_font_size' => (string) round(min(12, max(5, $stamp_footer_font_size)), 1),
            'stamp_footer_opacity' => (string) min(1, max(0.1, $stamp_footer_opacity)),
            'stamp_footer_position' => $stamp_footer_position,
            'button_primary_color' => $button_primary_color ?: '#32373c',
            'button_primary_text_color' => $button_primary_text_color ?: '#ffffff',
            'button_outline_color' => $button_outline_color ?: '#32373c',
            'button_border_radius' => (string) min(9999, max(0, $button_border_radius)),
            'ai_autofill_enabled' => $ai_autofill_enabled,
        );
    }

    /**
     * @return array<string,string>
     */
    public static function row_labels(): array
    {
        return array(
            'header' => __('Заголовок «Документ подписан…»', 'sign-docs'),
            'meta' => __('Дата, ID и SHA-256', 'sign-docs'),
            'signer' => __('Должность и ФИО подписанта', 'sign-docs'),
            'org' => __('Организация (подразделение)', 'sign-docs'),
        );
    }

    /**
     * @return array<string,string>
     */
    public static function ec_level_labels(): array
    {
        return array(
            'l' => __('Низкая (L) — крупные модули', 'sign-docs'),
            'm' => __('Средняя (M)', 'sign-docs'),
            'q' => __('Высокая (Q)', 'sign-docs'),
            'h' => __('Максимальная (H) — мелкие модули', 'sign-docs'),
        );
    }

    /**
     * @param mixed $raw
     */
    public static function sanitize_stamp_rows($raw): string
    {
        $items = array();
        if (is_array($raw)) {
            $items = $raw;
        } elseif (is_string($raw)) {
            $items = explode(',', $raw);
        }

        $rows = array();
        foreach ($items as $item) {
            $key = sanitize_key((string) $item);
            if (! in_array($key, self::ROW_KEYS, true) || in_array($key, $rows, true)) {
                continue;
            }
            $rows[] = $key;
        }

        if (empty($rows)) {
            $rows = self::ROW_KEYS;
        }

        return implode(',', $rows);
    }

    /**
     * @return array<int,string>
     */
    public static function stamp_rows(): array
    {
        $settings = self::get();
        $rows = array();
        foreach (explode(',', (string) $settings['stamp_rows']) as $key) {
            $key = sanitize_key($key);
            if (in_array($key, self::ROW_KEYS, true)) {
                $rows[] = $key;
            }
        }

        return $rows;
    }

    /**
     * @return array<int,string>
     */
    public static function ordered_rows(): array
    {
        $active = self::stamp_rows();
        $rest = array_diff(self::ROW_KEYS, $active);

        return array_merge($active, array_values($rest));
    }

    public static function enqueue_assets(string $hook_suffix): void
    {
        if ('sign-docs_page_sign-docs-settings' !== $hook_suffix) {
            return;
        }

        self::enqueue_stamp_assets();

        $style_path = SIGN_DOCS_PLUGIN_DIR . 'assets/css/admin-settings.css';
        wp_enqueue_style(
            'sign-docs-admin-settings',
            SIGN_DOCS_PLUGIN_URL . 'assets/css/admin-settings.css',
            array(),
            file_exists($style_path) ? SIGN_DOCS_VERSION . '-' . (string) filemtime($style_path) : SIGN_DOCS_VERSION
        );

        $builder_path = SIGN_DOCS_PLUGIN_DIR . 'assets/js/admin-stamp-builder.js';
        wp_enqueue_script(
            'sign-docs-admin-settings',
            SIGN_DOCS_PLUGIN_URL . 'assets/js/admin-stamp-builder.js',
            array('sign-docs-stamp-layout', 'sign-docs-stamp-ui', 'sign-docs-qrcode'),
            file_exists($builder_path) ? SIGN_DOCS_VERSION . '-' . (string) filemtime($builder_path) : SIGN_DOCS_VERSION,
            true
        );

        wp_localize_script(
            'sign-docs-admin-settings',
            'SignDocsStampBuilder',
            array(
                'fonts' => array(
                    'regular' => SIGN_DOCS_PLUGIN_URL . 'assets/vendor/GolosText-Regular.ttf',
                    'medium' => SIGN_DOCS_PLUGIN_URL . 'assets/vendor/GolosText-Medium.ttf',
                ),
                'siteIconUrl' => Sign_Docs_Site_Icon::url(),
                'sample' => array(
                    'post_id' => '0000',
                    'sha256_hash' => str_repeat('a', 64),
                    'verification_url' => home_url('/signed/0000/'),
                ),
            )
        );
    }

    public static function enqueue_stamp_assets(): void
    {
        $layout_path = SIGN_DOCS_PLUGIN_DIR . 'assets/js/stamp-layout.js';
        wp_enqueue_script(
            'sign-docs-stamp-layout',
            SIGN_DOCS_PLUGIN_URL . 'assets/js/stamp-layout.js',
            array(),
            file_exists($layout_path) ? SIGN_DOCS_VERSION . '-' . (string) filemtime($layout_path) : SIGN_DOCS_VERSION,
            true
        );

        $ui_path = SIGN_DOCS_PLUGIN_DIR . 'assets/js/stamp-ui.js';
        wp_enqueue_script(
            'sign-docs-stamp-ui',
            SIGN_DOCS_PLUGIN_URL . 'assets/js/stamp-ui.js',
            array(),
            file_exists($ui_path) ? SIGN_DOCS_VERSION . '-' . (string) filemtime($ui_path) : SIGN_DOCS_VERSION,
            true
        );

        $qrcode_path = SIGN_DOCS_PLUGIN_DIR . 'assets/vendor/qrcode.min.js';
        wp_enqueue_script(
            'sign-docs-qrcode',
            SIGN_DOCS_PLUGIN_URL . 'assets/vendor/qrcode.min.js',
            array(),
            file_exists($qrcode_path) ? (string) filemtime($qrcode_path) : SIGN_DOCS_VERSION,
            true
        );
    }

    public static function current_user_can_upload_documents(): bool
    {
        return current_user_can(self::UPLOAD_CAPABILITY);
    }

    public static function ensure_administrator_capability(): void
    {
        $role = get_role('administrator');
        if (null !== $role && ! $role->has_cap(self::UPLOAD_CAPABILITY)) {
            $role->add_cap(self::UPLOAD_CAPABILITY);
        }
    }

    public static function render_page(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to manage options.', 'sign-docs'));
        }

        $settings = self::get();
        $upload_users = self::upload_users();
        $row_labels = self::row_labels();
        $active_rows = self::stamp_rows();
        $ordered_rows = self::ordered_rows();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Настройки Sign Docs', 'sign-docs'); ?></h1>
            <p>
                <?php echo esc_html__('Здесь хранятся постоянные реквизиты штампа. Они будут подставляться при загрузке PDF и фиксироваться в meta конкретного документа.', 'sign-docs'); ?>
            </p>

            <h2><?php echo esc_html__('Справочники', 'sign-docs'); ?></h2>
            <p><?php echo esc_html__('Классификаторы открываются в стандартных экранах WordPress.', 'sign-docs'); ?></p>
            <ul>
                <li><a href="<?php echo esc_url(self::taxonomy_admin_url('sign_doc_category')); ?>"><?php echo esc_html__('Категории документов', 'sign-docs'); ?></a></li>
                <li><a href="<?php echo esc_url(self::taxonomy_admin_url('sign_doc_type')); ?>"><?php echo esc_html__('Типы документов', 'sign-docs'); ?></a></li>
                <li><a href="<?php echo esc_url(self::taxonomy_admin_url('sign_doc_department')); ?>"><?php echo esc_html__('Структурные подразделения', 'sign-docs'); ?></a></li>
                <li><a href="<?php echo esc_url(self::taxonomy_admin_url('sign_doc_institution')); ?>"><?php echo esc_html__('Издавшие органы', 'sign-docs'); ?></a></li>
            </ul>

            <form method="post" action="options.php">
                <?php settings_fields('sign_docs_settings'); ?>

                <div class="sign-docs-builder">
                    <div class="sign-docs-builder__fields">
                        <h2><?php echo esc_html__('Конструктор штампа', 'sign-docs'); ?></h2>
                        <p class="description">
                            <?php echo esc_html__('Соберите штамп, который накладывается на первую страницу при подписании PDF. Строки с датой, ID, SHA-256 и ссылкой проверки заполняются для каждого документа автоматически.', 'sign-docs'); ?>
                        </p>

                        <table class="form-table" role="presentation">
                            <tbody>
                                <tr>
                                    <th scope="row"><?php echo esc_html__('Реквизиты подписанта', 'sign-docs'); ?></th>
                                    <td>
                                        <p>
                                            <label for="sign-docs-default-signer-name">
                                                <?php echo esc_html__('ФИО', 'sign-docs'); ?>
                                            </label><br>
                                            <input id="sign-docs-default-signer-name" name="<?php echo esc_attr(self::OPTION_NAME); ?>[signer_name]" type="text" class="regular-text" value="<?php echo esc_attr($settings['signer_name']); ?>">
                                        </p>
                                        <p>
                                            <label for="sign-docs-default-signer-position">
                                                <?php echo esc_html__('Должность', 'sign-docs'); ?>
                                            </label><br>
                                            <input id="sign-docs-default-signer-position" name="<?php echo esc_attr(self::OPTION_NAME); ?>[signer_position]" type="text" class="regular-text" value="<?php echo esc_attr($settings['signer_position']); ?>">
                                        </p>
                                        <p>
                                            <label for="sign-docs-default-signer-organization">
                                                <?php echo esc_html__('Организация (по умолчанию)', 'sign-docs'); ?>
                                            </label><br>
                                            <input id="sign-docs-default-signer-organization" name="<?php echo esc_attr(self::OPTION_NAME); ?>[signer_organization]" type="text" class="regular-text" value="<?php echo esc_attr($settings['signer_organization']); ?>">
                                        </p>
                                        <p class="description"><?php echo esc_html__('Эти реквизиты попадают в строки «Подписант» и «Организация».', 'sign-docs'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php echo esc_html__('Строки штампа', 'sign-docs'); ?></th>
                                    <td>
                                        <input id="sign-docs-stamp-rows-value" type="hidden" name="<?php echo esc_attr(self::OPTION_NAME); ?>[stamp_rows]" value="<?php echo esc_attr(implode(',', $active_rows)); ?>">
                                        <ul id="sign-docs-stamp-rows" class="sign-docs-stamp-rows">
                                            <?php foreach ($ordered_rows as $key) : ?>
                                                <?php $label = $row_labels[$key] ?? $key; ?>
                                                <li data-row-key="<?php echo esc_attr($key); ?>"<?php echo in_array($key, $active_rows, true) ? ' class="is-active"' : ''; ?>>
                                                    <label>
                                                        <input
                                                            type="checkbox"
                                                            name="<?php echo esc_attr(self::OPTION_NAME); ?>[stamp_rows_enabled][]"
                                                            value="<?php echo esc_attr($key); ?>"
                                                            <?php checked(in_array($key, $active_rows, true)); ?>
                                                        >
                                                        <span class="sign-docs-stamp-rows__label"><?php echo esc_html($label); ?></span>
                                                    </label>
                                                    <span class="sign-docs-stamp-rows__actions">
                                                        <button type="button" class="button button-small sign-docs-stamp-rows__up" title="<?php echo esc_attr__('Выше', 'sign-docs'); ?>">&uarr;</button>
                                                        <button type="button" class="button button-small sign-docs-stamp-rows__down" title="<?php echo esc_attr__('Ниже', 'sign-docs'); ?>">&darr;</button>
                                                    </span>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                        <p class="description"><?php echo esc_html__('Отметьте строки, которые должны отображаться, и расставьте их стрелками в нужном порядке. Выключенные строки остаются в списке и возвращаются на место при повторном включении.', 'sign-docs'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php echo esc_html__('QR-код проверки', 'sign-docs'); ?></th>
                                    <td>
                                        <label for="sign-docs-default-stamp-qr-enabled">
                                            <input id="sign-docs-default-stamp-qr-enabled" name="<?php echo esc_attr(self::OPTION_NAME); ?>[stamp_qr_enabled]" type="checkbox" value="1" <?php checked($settings['stamp_qr_enabled'], '1'); ?>>
                                            <?php echo esc_html__('Показывать QR-код со ссылкой на страницу проверки', 'sign-docs'); ?>
                                        </label>
                                        <p class="description"><?php echo esc_html__('Если штамп остаётся без текстовых строк и без QR, на первую страницу ничего не накладывается.', 'sign-docs'); ?></p>

                                        <fieldset style="margin-top:10px;">
                                            <legend class="screen-reader-text"><?php echo esc_html__('Положение QR-кода', 'sign-docs'); ?></legend>
                                            <label style="display:inline-block; margin:0 18px 4px 0;">
                                                <input type="radio" name="<?php echo esc_attr(self::OPTION_NAME); ?>[stamp_qr_position]" value="right" <?php checked($settings['stamp_qr_position'], 'right'); ?>>
                                                <?php echo esc_html__('Справа от текста', 'sign-docs'); ?>
                                            </label>
                                            <label style="display:inline-block; margin:0 0 4px;">
                                                <input type="radio" name="<?php echo esc_attr(self::OPTION_NAME); ?>[stamp_qr_position]" value="below" <?php checked($settings['stamp_qr_position'], 'below'); ?>>
                                                <?php echo esc_html__('Под текстом', 'sign-docs'); ?>
                                            </label>
                                        </fieldset>

                                        <label for="sign-docs-default-qr-logo-enabled" style="display:block; margin:8px 0 0;">
                                            <input id="sign-docs-default-qr-logo-enabled" name="<?php echo esc_attr(self::OPTION_NAME); ?>[qr_logo_enabled]" type="checkbox" value="1" <?php checked($settings['qr_logo_enabled'], '1'); ?>>
                                            <?php echo esc_html__('Накладывать favicon/логотип сайта на модули QR-кода', 'sign-docs'); ?>
                                        </label>

                                        <div class="sign-docs-qr-grid" style="margin-top:12px;">
                                            <div class="sign-docs-qr-grid__item">
                                                <label for="sign-docs-default-stamp-qr-size"><?php echo esc_html__('Размер (ширина), pt', 'sign-docs'); ?></label>
                                                <input id="sign-docs-default-stamp-qr-size" name="<?php echo esc_attr(self::OPTION_NAME); ?>[stamp_qr_size]" type="number" min="20" max="120" step="1" class="small-text" value="<?php echo esc_attr($settings['stamp_qr_size']); ?>">
                                            </div>
                                            <div class="sign-docs-qr-grid__item">
                                                <label for="sign-docs-default-stamp-qr-ec-level"><?php echo esc_html__('Плотность (число квадратиков)', 'sign-docs'); ?></label>
                                                <select id="sign-docs-default-stamp-qr-ec-level" name="<?php echo esc_attr(self::OPTION_NAME); ?>[stamp_qr_ec_level]">
                                                    <?php foreach (self::ec_level_labels() as $value => $label) : ?>
                                                        <option value="<?php echo esc_attr($value); ?>" <?php selected($settings['stamp_qr_ec_level'], $value); ?>><?php echo esc_html($label); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="sign-docs-qr-grid__item">
                                                <label for="sign-docs-default-stamp-qr-gap"><?php echo esc_html__('Зазор до текста, pt', 'sign-docs'); ?></label>
                                                <input id="sign-docs-default-stamp-qr-gap" name="<?php echo esc_attr(self::OPTION_NAME); ?>[stamp_qr_gap]" type="number" min="0" max="20" step="0.5" class="small-text" value="<?php echo esc_attr($settings['stamp_qr_gap']); ?>">
                                            </div>
                                            <div class="sign-docs-qr-grid__item">
                                                <label for="sign-docs-default-stamp-qr-padding"><?php echo esc_html__('Отступ от рамки, pt', 'sign-docs'); ?></label>
                                                <input id="sign-docs-default-stamp-qr-padding" name="<?php echo esc_attr(self::OPTION_NAME); ?>[stamp_qr_padding]" type="number" min="0" max="12" step="0.5" class="small-text" value="<?php echo esc_attr($settings['stamp_qr_padding']); ?>">
                                            </div>
                                        </div>

                                        <p class="description">
                                            <?php echo esc_html__('Размер задаёт физическую ширину QR-кода на листе. Плотность меняет число модулей одной и той же ссылки: выше — мельче модули и надёжнее коррекция, ниже — крупнее и «проще» для печати. При логотипе плотность не опускается ниже средней (M).', 'sign-docs'); ?>
                                        </p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="sign-docs-default-stamp-corner"><?php echo esc_html__('Угол штампа на листе', 'sign-docs'); ?></label></th>
                                    <td><?php self::render_corner_select(self::OPTION_NAME . '[stamp_corner]', 'sign-docs-default-stamp-corner', $settings['stamp_corner']); ?></td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="sign-docs-default-stamp-color"><?php echo esc_html__('Цвет штампа', 'sign-docs'); ?></label></th>
                                    <td><input id="sign-docs-default-stamp-color" name="<?php echo esc_attr(self::OPTION_NAME); ?>[stamp_color]" type="color" value="<?php echo esc_attr($settings['stamp_color']); ?>"></td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="sign-docs-default-stamp-opacity"><?php echo esc_html__('Прозрачность', 'sign-docs'); ?></label></th>
                                    <td>
                                        <input id="sign-docs-default-stamp-opacity" name="<?php echo esc_attr(self::OPTION_NAME); ?>[stamp_opacity]" type="range" min="0.1" max="1" step="0.05" value="<?php echo esc_attr($settings['stamp_opacity']); ?>">
                                        <span id="sign-docs-stamp-opacity-label"><?php echo esc_html((string) round((float) $settings['stamp_opacity'] * 100)); ?>%</span>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="sign-docs-default-stamp-font-size"><?php echo esc_html__('Размер шрифта', 'sign-docs'); ?></label></th>
                                    <td>
                                        <input id="sign-docs-default-stamp-font-size" name="<?php echo esc_attr(self::OPTION_NAME); ?>[stamp_font_size]" type="number" min="6" max="12" step="0.1" class="small-text" value="<?php echo esc_attr($settings['stamp_font_size']); ?>">
                                        <span>pt</span>
                                        <p class="description"><?php echo esc_html__('Базовый размер шрифта в основном штампе. Для длинных ФИО или названий можно уменьшить значение.', 'sign-docs'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="sign-docs-default-stamp-padding"><?php echo esc_html__('Отступ текста от рамки', 'sign-docs'); ?></label></th>
                                    <td>
                                        <input id="sign-docs-default-stamp-padding" name="<?php echo esc_attr(self::OPTION_NAME); ?>[stamp_padding]" type="number" min="2" max="16" step="0.5" class="small-text" value="<?php echo esc_attr($settings['stamp_padding']); ?>">
                                        <span>pt</span>
                                        <p class="description"><?php echo esc_html__('Поле между рамкой и текстовыми строками. На QR-код не влияет.', 'sign-docs'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="sign-docs-default-stamp-line-spacing"><?php echo esc_html__('Межстрочный интервал', 'sign-docs'); ?></label></th>
                                    <td>
                                        <input id="sign-docs-default-stamp-line-spacing" name="<?php echo esc_attr(self::OPTION_NAME); ?>[stamp_line_spacing]" type="number" min="1" max="2" step="0.05" class="small-text" value="<?php echo esc_attr($settings['stamp_line_spacing']); ?>">
                                        <span>&times;</span>
                                        <p class="description"><?php echo esc_html__('Множитель межстрочного расстояния относительно размера шрифта. Значение 1.25 — компактный текст, больше — просторнее.', 'sign-docs'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php echo esc_html__('Рамка штампа', 'sign-docs'); ?></th>
                                    <td>
                                        <label for="sign-docs-default-stamp-border-enabled">
                                            <input id="sign-docs-default-stamp-border-enabled" name="<?php echo esc_attr(self::OPTION_NAME); ?>[stamp_border_enabled]" type="checkbox" value="1" <?php checked($settings['stamp_border_enabled'], '1'); ?>>
                                            <?php echo esc_html__('Показывать рамку вокруг штампа', 'sign-docs'); ?>
                                        </label>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="sign-docs-builder__preview">
                        <h2><?php echo esc_html__('Предпросмотр', 'sign-docs'); ?></h2>
                        <p class="description"><?php echo esc_html__('Лист A4 и штамп в выбранном углу. Дата и контрольная строка показаны на примере, реальные значения подставляются при подписании.', 'sign-docs'); ?></p>
                        <div class="sign-docs-builder__sheet">
                            <canvas id="sign-docs-stamp-builder-canvas" aria-hidden="true"></canvas>
                        </div>
                        <div id="sign-docs-stamp-zoom" class="sign-docs-builder__zoom" hidden>
                            <p class="description"><?php echo esc_html__('Увеличенный штамп', 'sign-docs'); ?></p>
                            <div class="sign-docs-builder__zoom-inner">
                                <canvas id="sign-docs-stamp-zoom-canvas" aria-hidden="true"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <h2><?php echo esc_html__('Остальные настройки', 'sign-docs'); ?></h2>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><?php echo esc_html__('Формирование копии', 'sign-docs'); ?></th>
                            <td>
                                <p style="margin-top:0;">
                                    <label for="sign-docs-default-stamp-page"><?php echo esc_html__('Штамп на странице', 'sign-docs'); ?></label>
                                    <select id="sign-docs-default-stamp-page" name="<?php echo esc_attr(self::OPTION_NAME); ?>[stamp_page]">
                                        <option value="first" <?php selected($settings['stamp_page'], 'first'); ?>><?php echo esc_html__('Первая', 'sign-docs'); ?></option>
                                        <option value="last" <?php selected($settings['stamp_page'], 'last'); ?>><?php echo esc_html__('Последняя', 'sign-docs'); ?></option>
                                        <option value="both" <?php selected($settings['stamp_page'], 'both'); ?>><?php echo esc_html__('Первая и последняя', 'sign-docs'); ?></option>
                                    </select>
                                </p>

                                <label for="sign-docs-stamp-footer-enabled">
                                    <input id="sign-docs-stamp-footer-enabled" name="<?php echo esc_attr(self::OPTION_NAME); ?>[stamp_footer_enabled]" type="checkbox" value="1" <?php checked($settings['stamp_footer_enabled'], '1'); ?>>
                                    <?php echo esc_html__('Печатать ссылку проверки на остальных страницах', 'sign-docs'); ?>
                                </label>

                                <div id="sign-docs-footer-options" class="sign-docs-footer-grid" style="margin-top:12px;">
                                    <div class="sign-docs-footer-grid__item">
                                        <label for="sign-docs-default-stamp-footer-border-enabled">
                                            <input id="sign-docs-default-stamp-footer-border-enabled" name="<?php echo esc_attr(self::OPTION_NAME); ?>[stamp_footer_border_enabled]" type="checkbox" value="1" <?php checked($settings['stamp_footer_border_enabled'], '1'); ?>>
                                            <?php echo esc_html__('Рамка', 'sign-docs'); ?>
                                        </label>
                                    </div>
                                    <div class="sign-docs-footer-grid__item">
                                        <label for="sign-docs-default-stamp-footer-position"><?php echo esc_html__('Расположение', 'sign-docs'); ?></label>
                                        <select id="sign-docs-default-stamp-footer-position" name="<?php echo esc_attr(self::OPTION_NAME); ?>[stamp_footer_position]">
                                            <option value="bottom" <?php selected($settings['stamp_footer_position'], 'bottom'); ?>><?php echo esc_html__('Низ страницы', 'sign-docs'); ?></option>
                                            <option value="top" <?php selected($settings['stamp_footer_position'], 'top'); ?>><?php echo esc_html__('Верх страницы', 'sign-docs'); ?></option>
                                        </select>
                                    </div>
                                    <div class="sign-docs-footer-grid__item">
                                        <label for="sign-docs-default-stamp-footer-font-size"><?php echo esc_html__('Размер шрифта, pt', 'sign-docs'); ?></label>
                                        <input id="sign-docs-default-stamp-footer-font-size" name="<?php echo esc_attr(self::OPTION_NAME); ?>[stamp_footer_font_size]" type="number" min="5" max="12" step="0.1" class="small-text" value="<?php echo esc_attr($settings['stamp_footer_font_size']); ?>">
                                    </div>
                                    <div class="sign-docs-footer-grid__item">
                                        <label for="sign-docs-default-stamp-footer-opacity"><?php echo esc_html__('Прозрачность', 'sign-docs'); ?></label>
                                        <input id="sign-docs-default-stamp-footer-opacity" name="<?php echo esc_attr(self::OPTION_NAME); ?>[stamp_footer_opacity]" type="number" min="0.1" max="1" step="0.05" class="small-text" value="<?php echo esc_attr($settings['stamp_footer_opacity']); ?>">
                                    </div>
                                </div>

                                <p class="description">
                                    <?php echo esc_html__('Основной штамп с QR-кодом накладывается на выбранную страницу. В режиме «Первая и последняя» одинаковый штамп ставится на первую и последнюю страницы. Если ссылка проверки печатается, на остальных страницах показывается компактная строка с SHA-256 и адресом проверки.', 'sign-docs'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__('Автозаполнение реквизитов', 'sign-docs'); ?></th>
                            <td>
                                <label for="sign-docs-ai-autofill-enabled">
                                    <input id="sign-docs-ai-autofill-enabled" name="<?php echo esc_attr(self::OPTION_NAME); ?>[ai_autofill_enabled]" type="checkbox" value="1" <?php checked($settings['ai_autofill_enabled'], '1'); ?>>
                                    <?php echo esc_html__('Автоматически предлагать реквизиты документа при выборе PDF', 'sign-docs'); ?>
                                </label>
                                <p class="description"><?php echo esc_html__('Используется настроенный AI connector WordPress. В модель отправляется только текст первой страницы PDF, а подпись и публикация остаются ручным действием администратора.', 'sign-docs'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__('Права на загрузку документов', 'sign-docs'); ?></th>
                            <td>
                                <?php if (empty($upload_users)) : ?>
                                    <p class="description"><?php echo esc_html__('Пользователи с ролью редактора не найдены.', 'sign-docs'); ?></p>
                                <?php else : ?>
                                    <fieldset>
                                        <legend class="screen-reader-text"><?php echo esc_html__('Редакторы, которым разрешена загрузка документов Sign Docs', 'sign-docs'); ?></legend>
                                        <?php foreach ($upload_users as $user) : ?>
                                            <label style="display:block; margin:0 0 6px;">
                                                <input
                                                    name="<?php echo esc_attr(self::OPTION_NAME); ?>[upload_user_ids][]"
                                                    type="checkbox"
                                                    value="<?php echo esc_attr((string) $user->ID); ?>"
                                                    <?php checked(user_can($user, self::UPLOAD_CAPABILITY)); ?>
                                                >
                                                <?php echo esc_html($user->display_name . ' (' . $user->user_login . ')'); ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </fieldset>
                                    <p class="description"><?php echo esc_html__('Администраторы имеют это право всегда. Отметьте редакторов, которым можно добавлять документы через Sign Docs.', 'sign-docs'); ?></p>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="sign-docs-button-primary-color"><?php echo esc_html__('Цвет основной кнопки блока', 'sign-docs'); ?></label></th>
                            <td><input id="sign-docs-button-primary-color" name="<?php echo esc_attr(self::OPTION_NAME); ?>[button_primary_color]" type="color" value="<?php echo esc_attr($settings['button_primary_color']); ?>"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="sign-docs-button-primary-text-color"><?php echo esc_html__('Цвет текста основной кнопки', 'sign-docs'); ?></label></th>
                            <td><input id="sign-docs-button-primary-text-color" name="<?php echo esc_attr(self::OPTION_NAME); ?>[button_primary_text_color]" type="color" value="<?php echo esc_attr($settings['button_primary_text_color']); ?>"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="sign-docs-button-outline-color"><?php echo esc_html__('Цвет контурной кнопки блока', 'sign-docs'); ?></label></th>
                            <td><input id="sign-docs-button-outline-color" name="<?php echo esc_attr(self::OPTION_NAME); ?>[button_outline_color]" type="color" value="<?php echo esc_attr($settings['button_outline_color']); ?>"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="sign-docs-button-border-radius"><?php echo esc_html__('Скругление кнопок блока', 'sign-docs'); ?></label></th>
                            <td>
                                <input id="sign-docs-button-border-radius" name="<?php echo esc_attr(self::OPTION_NAME); ?>[button_border_radius]" type="number" min="0" max="9999" step="1" class="small-text" value="<?php echo esc_attr($settings['button_border_radius']); ?>">
                                <span>px</span>
                                <p class="description"><?php echo esc_html__('Значение 9999 делает кнопки округлыми, как pill-вариант блока «Кнопки».', 'sign-docs'); ?></p>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <?php submit_button(__('Сохранить настройки', 'sign-docs')); ?>
            </form>
        </div>
        <?php
    }

    public static function render_corner_select(string $name, string $id, string $selected): void
    {
        $options = array(
            'top-left' => __('Верхний левый', 'sign-docs'),
            'top-right' => __('Верхний правый', 'sign-docs'),
            'bottom-left' => __('Нижний левый', 'sign-docs'),
            'bottom-right' => __('Нижний правый', 'sign-docs'),
        );
        ?>
        <select id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>">
            <?php foreach ($options as $value => $label) : ?>
                <option value="<?php echo esc_attr($value); ?>" <?php selected($selected, $value); ?>><?php echo esc_html($label); ?></option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    private static function taxonomy_admin_url(string $taxonomy): string
    {
        return admin_url('edit-tags.php?taxonomy=' . $taxonomy . '&post_type=' . Sign_Docs_Post_Type::POST_TYPE);
    }

    /**
     * @param mixed $selected_user_ids
     */
    private static function sync_upload_users($selected_user_ids): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        self::ensure_administrator_capability();

        $selected = array();
        if (is_array($selected_user_ids)) {
            foreach ($selected_user_ids as $user_id) {
                $user_id = absint($user_id);
                if ($user_id > 0) {
                    $selected[$user_id] = true;
                }
            }
        }

        foreach (self::upload_users() as $user) {
            if (user_can($user, 'manage_options')) {
                continue;
            }

            if (isset($selected[(int) $user->ID])) {
                $user->add_cap(self::UPLOAD_CAPABILITY);
            } else {
                $user->remove_cap(self::UPLOAD_CAPABILITY);
            }
        }
    }

    /**
     * @return WP_User[]
     */
    private static function upload_users(): array
    {
        $users = get_users(
            array(
                'role__in' => array('editor'),
                'orderby' => 'display_name',
                'order' => 'ASC',
                'fields' => 'all',
            )
        );

        return is_array($users) ? $users : array();
    }
}
