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
            'verification_preview_enabled' => '1',
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
        if (! in_array($stamp_footer_position, array('top', 'bottom', 'both'), true)) {
            $stamp_footer_position = 'bottom';
        }
        $button_primary_color = isset($value['button_primary_color']) ? sanitize_hex_color((string) $value['button_primary_color']) : '';
        $button_primary_text_color = isset($value['button_primary_text_color']) ? sanitize_hex_color((string) $value['button_primary_text_color']) : '';
        $button_outline_color = isset($value['button_outline_color']) ? sanitize_hex_color((string) $value['button_outline_color']) : '';
        $button_border_radius = isset($value['button_border_radius']) ? (int) $value['button_border_radius'] : 9999;
        $ai_autofill_enabled = ! empty($value['ai_autofill_enabled']) ? '1' : '0';
        $verification_preview_enabled = ! empty($value['verification_preview_enabled']) ? '1' : '0';
        $verification_preview_pages = isset($value['verification_preview_pages']) ? absint($value['verification_preview_pages']) : 0;

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
            'verification_preview_enabled' => $verification_preview_enabled,
            'verification_preview_pages' => (string) min(100, $verification_preview_pages),
        );
    }

    /**
     * @return array<string,string>
     */
    public static function row_labels(): array
    {
        return array(
            'header' => __('Header "Document signed…"', 'sign-docs'),
            'meta' => __('Date, ID and SHA-256', 'sign-docs'),
            'signer' => __('Signer position and name', 'sign-docs'),
            'org' => __('Organization (department)', 'sign-docs'),
        );
    }

    /**
     * @return array<string,string>
     */
    public static function ec_level_labels(): array
    {
        return array(
            'l' => __('Low (L) — large modules', 'sign-docs'),
            'm' => __('Medium (M)', 'sign-docs'),
            'q' => __('High (Q)', 'sign-docs'),
            'h' => __('Maximum (H) — small modules', 'sign-docs'),
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
            array('wp-i18n'),
            file_exists($layout_path) ? SIGN_DOCS_VERSION . '-' . (string) filemtime($layout_path) : SIGN_DOCS_VERSION,
            true
        );
        wp_set_script_translations('sign-docs-stamp-layout', 'sign-docs', SIGN_DOCS_PLUGIN_DIR . 'languages');

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
            <h1><?php echo esc_html__('Sign Docs Settings', 'sign-docs'); ?></h1>
            <p>
                <?php echo esc_html__('Permanent stamp details are stored here. They are filled in when a PDF is uploaded and saved in the meta of the individual document.', 'sign-docs'); ?>
            </p>

            <h2><?php echo esc_html__('Reference lists', 'sign-docs'); ?></h2>
            <p><?php echo esc_html__('The classifiers open in the standard WordPress screens.', 'sign-docs'); ?></p>
            <ul>
                <li><a href="<?php echo esc_url(self::taxonomy_admin_url('sign_doc_category')); ?>"><?php echo esc_html__('Document categories', 'sign-docs'); ?></a></li>
                <li><a href="<?php echo esc_url(self::taxonomy_admin_url('sign_doc_type')); ?>"><?php echo esc_html__('Document types', 'sign-docs'); ?></a></li>
                <li><a href="<?php echo esc_url(self::taxonomy_admin_url('sign_doc_department')); ?>"><?php echo esc_html__('Departments', 'sign-docs'); ?></a></li>
                <li><a href="<?php echo esc_url(self::taxonomy_admin_url('sign_doc_institution')); ?>"><?php echo esc_html__('Issuing authorities', 'sign-docs'); ?></a></li>
            </ul>

            <form method="post" action="options.php">
                <?php settings_fields('sign_docs_settings'); ?>

                <div class="sign-docs-builder">
                    <div class="sign-docs-builder__fields">
                        <h2><?php echo esc_html__('Stamp builder', 'sign-docs'); ?></h2>
                        <p class="description">
                            <?php echo esc_html__('Assemble the stamp placed on the first page when a PDF is signed. The rows with the date, ID, SHA-256 and verification link are filled in automatically for every document.', 'sign-docs'); ?>
                        </p>

                        <table class="form-table" role="presentation">
                            <tbody>
                                <tr>
                                    <th scope="row"><?php echo esc_html__('Signer details', 'sign-docs'); ?></th>
                                    <td>
                                        <p>
                                            <label for="sign-docs-default-signer-name">
                                                <?php echo esc_html__('Full name', 'sign-docs'); ?>
                                            </label><br>
                                            <input id="sign-docs-default-signer-name" name="<?php echo esc_attr(self::OPTION_NAME); ?>[signer_name]" type="text" class="regular-text" value="<?php echo esc_attr($settings['signer_name']); ?>">
                                        </p>
                                        <p>
                                            <label for="sign-docs-default-signer-position">
                                                <?php echo esc_html__('Position', 'sign-docs'); ?>
                                            </label><br>
                                            <input id="sign-docs-default-signer-position" name="<?php echo esc_attr(self::OPTION_NAME); ?>[signer_position]" type="text" class="regular-text" value="<?php echo esc_attr($settings['signer_position']); ?>">
                                        </p>
                                        <p>
                                            <label for="sign-docs-default-signer-organization">
                                                <?php echo esc_html__('Organization (default)', 'sign-docs'); ?>
                                            </label><br>
                                            <input id="sign-docs-default-signer-organization" name="<?php echo esc_attr(self::OPTION_NAME); ?>[signer_organization]" type="text" class="regular-text" value="<?php echo esc_attr($settings['signer_organization']); ?>">
                                        </p>
                                        <p class="description"><?php echo esc_html__('These details are placed into the "Signer" and "Organization" rows.', 'sign-docs'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php echo esc_html__('Stamp rows', 'sign-docs'); ?></th>
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
                                                        <button type="button" class="button button-small sign-docs-stamp-rows__up" title="<?php echo esc_attr__('Move up', 'sign-docs'); ?>">&uarr;</button>
                                                        <button type="button" class="button button-small sign-docs-stamp-rows__down" title="<?php echo esc_attr__('Move down', 'sign-docs'); ?>">&darr;</button>
                                                    </span>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                        <p class="description"><?php echo esc_html__('Select the rows to display and reorder them with the arrows. Disabled rows stay in the list and return to their place when re-enabled.', 'sign-docs'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php echo esc_html__('Verification QR code', 'sign-docs'); ?></th>
                                    <td>
                                        <label for="sign-docs-default-stamp-qr-enabled">
                                            <input id="sign-docs-default-stamp-qr-enabled" name="<?php echo esc_attr(self::OPTION_NAME); ?>[stamp_qr_enabled]" type="checkbox" value="1" <?php checked($settings['stamp_qr_enabled'], '1'); ?>>
                                            <?php echo esc_html__('Show a QR code linking to the verification page', 'sign-docs'); ?>
                                        </label>
                                        <p class="description"><?php echo esc_html__('If the stamp has neither text rows nor a QR code, nothing is placed on the first page.', 'sign-docs'); ?></p>

                                        <fieldset style="margin-top:10px;">
                                            <legend class="screen-reader-text"><?php echo esc_html__('QR code position', 'sign-docs'); ?></legend>
                                            <label style="display:inline-block; margin:0 18px 4px 0;">
                                                <input type="radio" name="<?php echo esc_attr(self::OPTION_NAME); ?>[stamp_qr_position]" value="right" <?php checked($settings['stamp_qr_position'], 'right'); ?>>
                                                <?php echo esc_html__('Right of the text', 'sign-docs'); ?>
                                            </label>
                                            <label style="display:inline-block; margin:0 0 4px;">
                                                <input type="radio" name="<?php echo esc_attr(self::OPTION_NAME); ?>[stamp_qr_position]" value="below" <?php checked($settings['stamp_qr_position'], 'below'); ?>>
                                                <?php echo esc_html__('Below the text', 'sign-docs'); ?>
                                            </label>
                                        </fieldset>

                                        <label for="sign-docs-default-qr-logo-enabled" style="display:block; margin:8px 0 0;">
                                            <input id="sign-docs-default-qr-logo-enabled" name="<?php echo esc_attr(self::OPTION_NAME); ?>[qr_logo_enabled]" type="checkbox" value="1" <?php checked($settings['qr_logo_enabled'], '1'); ?>>
                                            <?php echo esc_html__('Overlay the site favicon/logo on the QR code modules', 'sign-docs'); ?>
                                        </label>

                                        <div class="sign-docs-qr-grid" style="margin-top:12px;">
                                            <div class="sign-docs-qr-grid__item">
                                                <label for="sign-docs-default-stamp-qr-size"><?php echo esc_html__('Size (width), pt', 'sign-docs'); ?></label>
                                                <input id="sign-docs-default-stamp-qr-size" name="<?php echo esc_attr(self::OPTION_NAME); ?>[stamp_qr_size]" type="number" min="20" max="120" step="1" class="small-text" value="<?php echo esc_attr($settings['stamp_qr_size']); ?>">
                                            </div>
                                            <div class="sign-docs-qr-grid__item">
                                                <label for="sign-docs-default-stamp-qr-ec-level"><?php echo esc_html__('Density (number of modules)', 'sign-docs'); ?></label>
                                                <select id="sign-docs-default-stamp-qr-ec-level" name="<?php echo esc_attr(self::OPTION_NAME); ?>[stamp_qr_ec_level]">
                                                    <?php foreach (self::ec_level_labels() as $value => $label) : ?>
                                                        <option value="<?php echo esc_attr($value); ?>" <?php selected($settings['stamp_qr_ec_level'], $value); ?>><?php echo esc_html($label); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="sign-docs-qr-grid__item">
                                                <label for="sign-docs-default-stamp-qr-gap"><?php echo esc_html__('Gap to the text, pt', 'sign-docs'); ?></label>
                                                <input id="sign-docs-default-stamp-qr-gap" name="<?php echo esc_attr(self::OPTION_NAME); ?>[stamp_qr_gap]" type="number" min="0" max="20" step="0.5" class="small-text" value="<?php echo esc_attr($settings['stamp_qr_gap']); ?>">
                                            </div>
                                            <div class="sign-docs-qr-grid__item">
                                                <label for="sign-docs-default-stamp-qr-padding"><?php echo esc_html__('Padding from the border, pt', 'sign-docs'); ?></label>
                                                <input id="sign-docs-default-stamp-qr-padding" name="<?php echo esc_attr(self::OPTION_NAME); ?>[stamp_qr_padding]" type="number" min="0" max="12" step="0.5" class="small-text" value="<?php echo esc_attr($settings['stamp_qr_padding']); ?>">
                                            </div>
                                        </div>

                                        <p class="description">
                                            <?php echo esc_html__('The size sets the physical width of the QR code on the sheet. Density changes the number of modules for the same link: higher makes modules finer with more reliable error correction, lower makes them coarser and easier to print. With a logo the density never drops below medium (M).', 'sign-docs'); ?>
                                        </p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="sign-docs-default-stamp-corner"><?php echo esc_html__('Stamp corner on the sheet', 'sign-docs'); ?></label></th>
                                    <td><?php self::render_corner_select(self::OPTION_NAME . '[stamp_corner]', 'sign-docs-default-stamp-corner', $settings['stamp_corner']); ?></td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="sign-docs-default-stamp-color"><?php echo esc_html__('Stamp color', 'sign-docs'); ?></label></th>
                                    <td><input id="sign-docs-default-stamp-color" name="<?php echo esc_attr(self::OPTION_NAME); ?>[stamp_color]" type="color" value="<?php echo esc_attr($settings['stamp_color']); ?>"></td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="sign-docs-default-stamp-opacity"><?php echo esc_html__('Opacity', 'sign-docs'); ?></label></th>
                                    <td>
                                        <input id="sign-docs-default-stamp-opacity" name="<?php echo esc_attr(self::OPTION_NAME); ?>[stamp_opacity]" type="range" min="0.1" max="1" step="0.05" value="<?php echo esc_attr($settings['stamp_opacity']); ?>">
                                        <span id="sign-docs-stamp-opacity-label"><?php echo esc_html((string) round((float) $settings['stamp_opacity'] * 100)); ?>%</span>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="sign-docs-default-stamp-font-size"><?php echo esc_html__('Font size', 'sign-docs'); ?></label></th>
                                    <td>
                                        <input id="sign-docs-default-stamp-font-size" name="<?php echo esc_attr(self::OPTION_NAME); ?>[stamp_font_size]" type="number" min="6" max="12" step="0.1" class="small-text" value="<?php echo esc_attr($settings['stamp_font_size']); ?>">
                                        <span>pt</span>
                                        <p class="description"><?php echo esc_html__('Base font size of the main stamp. Lower it for long names or titles.', 'sign-docs'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="sign-docs-default-stamp-padding"><?php echo esc_html__('Text padding from the border', 'sign-docs'); ?></label></th>
                                    <td>
                                        <input id="sign-docs-default-stamp-padding" name="<?php echo esc_attr(self::OPTION_NAME); ?>[stamp_padding]" type="number" min="2" max="16" step="0.5" class="small-text" value="<?php echo esc_attr($settings['stamp_padding']); ?>">
                                        <span>pt</span>
                                        <p class="description"><?php echo esc_html__('Space between the border and the text rows. It does not affect the QR code.', 'sign-docs'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="sign-docs-default-stamp-line-spacing"><?php echo esc_html__('Line spacing', 'sign-docs'); ?></label></th>
                                    <td>
                                        <input id="sign-docs-default-stamp-line-spacing" name="<?php echo esc_attr(self::OPTION_NAME); ?>[stamp_line_spacing]" type="number" min="1" max="2" step="0.05" class="small-text" value="<?php echo esc_attr($settings['stamp_line_spacing']); ?>">
                                        <span>&times;</span>
                                        <p class="description"><?php echo esc_html__('Line distance multiplier relative to the font size. 1.25 is compact text, larger values give more breathing room.', 'sign-docs'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php echo esc_html__('Stamp border', 'sign-docs'); ?></th>
                                    <td>
                                        <label for="sign-docs-default-stamp-border-enabled">
                                            <input id="sign-docs-default-stamp-border-enabled" name="<?php echo esc_attr(self::OPTION_NAME); ?>[stamp_border_enabled]" type="checkbox" value="1" <?php checked($settings['stamp_border_enabled'], '1'); ?>>
                                            <?php echo esc_html__('Show a border around the stamp', 'sign-docs'); ?>
                                        </label>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="sign-docs-builder__preview">
                        <h2><?php echo esc_html__('Preview', 'sign-docs'); ?></h2>
                        <p class="description"><?php echo esc_html__('An A4 sheet with the stamp in the selected corner. The date and the control line are examples; real values are inserted when signing.', 'sign-docs'); ?></p>
                        <div class="sign-docs-builder__sheet">
                            <canvas id="sign-docs-stamp-builder-canvas" aria-hidden="true"></canvas>
                        </div>
                        <div id="sign-docs-stamp-zoom" class="sign-docs-builder__zoom" hidden>
                            <p class="description"><?php echo esc_html__('Zoomed stamp', 'sign-docs'); ?></p>
                            <div class="sign-docs-builder__zoom-inner">
                                <canvas id="sign-docs-stamp-zoom-canvas" aria-hidden="true"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <h2><?php echo esc_html__('Other settings', 'sign-docs'); ?></h2>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><?php echo esc_html__('Public copy', 'sign-docs'); ?></th>
                            <td>
                                <p style="margin-top:0;">
                                    <label for="sign-docs-default-stamp-page"><?php echo esc_html__('Stamp page', 'sign-docs'); ?></label>
                                    <select id="sign-docs-default-stamp-page" name="<?php echo esc_attr(self::OPTION_NAME); ?>[stamp_page]">
                                        <option value="first" <?php selected($settings['stamp_page'], 'first'); ?>><?php echo esc_html__('First', 'sign-docs'); ?></option>
                                        <option value="last" <?php selected($settings['stamp_page'], 'last'); ?>><?php echo esc_html__('Last', 'sign-docs'); ?></option>
                                        <option value="both" <?php selected($settings['stamp_page'], 'both'); ?>><?php echo esc_html__('First and last', 'sign-docs'); ?></option>
                                    </select>
                                </p>

                                <label for="sign-docs-stamp-footer-enabled">
                                    <input id="sign-docs-stamp-footer-enabled" name="<?php echo esc_attr(self::OPTION_NAME); ?>[stamp_footer_enabled]" type="checkbox" value="1" <?php checked($settings['stamp_footer_enabled'], '1'); ?>>
                                    <?php echo esc_html__('Print the verification link on the remaining pages', 'sign-docs'); ?>
                                </label>

                                <div id="sign-docs-footer-options" class="sign-docs-footer-grid" style="margin-top:12px;">
                                    <div class="sign-docs-footer-grid__item">
                                        <label for="sign-docs-default-stamp-footer-border-enabled">
                                            <input id="sign-docs-default-stamp-footer-border-enabled" name="<?php echo esc_attr(self::OPTION_NAME); ?>[stamp_footer_border_enabled]" type="checkbox" value="1" <?php checked($settings['stamp_footer_border_enabled'], '1'); ?>>
                                            <?php echo esc_html__('Border', 'sign-docs'); ?>
                                        </label>
                                    </div>
                                    <div class="sign-docs-footer-grid__item">
                                        <label for="sign-docs-default-stamp-footer-position"><?php echo esc_html__('Position', 'sign-docs'); ?></label>
                                        <select id="sign-docs-default-stamp-footer-position" name="<?php echo esc_attr(self::OPTION_NAME); ?>[stamp_footer_position]">
                                            <option value="bottom" <?php selected($settings['stamp_footer_position'], 'bottom'); ?>><?php echo esc_html__('Bottom of the page', 'sign-docs'); ?></option>
                                            <option value="top" <?php selected($settings['stamp_footer_position'], 'top'); ?>><?php echo esc_html__('Top of the page', 'sign-docs'); ?></option>
                                            <option value="both" <?php selected($settings['stamp_footer_position'], 'both'); ?>><?php echo esc_html__('Top and bottom', 'sign-docs'); ?></option>
                                        </select>
                                    </div>
                                    <div class="sign-docs-footer-grid__item">
                                        <label for="sign-docs-default-stamp-footer-font-size"><?php echo esc_html__('Font size, pt', 'sign-docs'); ?></label>
                                        <input id="sign-docs-default-stamp-footer-font-size" name="<?php echo esc_attr(self::OPTION_NAME); ?>[stamp_footer_font_size]" type="number" min="5" max="12" step="0.1" class="small-text" value="<?php echo esc_attr($settings['stamp_footer_font_size']); ?>">
                                    </div>
                                    <div class="sign-docs-footer-grid__item">
                                        <label for="sign-docs-default-stamp-footer-opacity"><?php echo esc_html__('Opacity', 'sign-docs'); ?></label>
                                        <input id="sign-docs-default-stamp-footer-opacity" name="<?php echo esc_attr(self::OPTION_NAME); ?>[stamp_footer_opacity]" type="number" min="0.1" max="1" step="0.05" class="small-text" value="<?php echo esc_attr($settings['stamp_footer_opacity']); ?>">
                                    </div>
                                </div>

                                <p class="description">
                                    <?php echo esc_html__('The main stamp with the QR code is placed on the selected page. In the "First and last" mode the same stamp is placed on the first and last pages. When the verification link is printed, the remaining pages show a compact line with the SHA-256 and the verification address.', 'sign-docs'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__('Document autofill', 'sign-docs'); ?></th>
                            <td>
                                <label for="sign-docs-ai-autofill-enabled">
                                    <input id="sign-docs-ai-autofill-enabled" name="<?php echo esc_attr(self::OPTION_NAME); ?>[ai_autofill_enabled]" type="checkbox" value="1" <?php checked($settings['ai_autofill_enabled'], '1'); ?>>
                                    <?php echo esc_html__('Automatically suggest document details when a PDF is selected', 'sign-docs'); ?>
                                </label>
                                <p class="description"><?php echo esc_html__('Uses the configured WordPress AI connector. Only the first-page text of the PDF is sent to the model; signing and publishing remain manual administrator actions.', 'sign-docs'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__('Document preview', 'sign-docs'); ?></th>
                            <td>
                                <label for="sign-docs-verification-preview-enabled">
                                    <input id="sign-docs-verification-preview-enabled" name="<?php echo esc_attr(self::OPTION_NAME); ?>[verification_preview_enabled]" type="checkbox" value="1" <?php checked($settings['verification_preview_enabled'], '1'); ?>>
                                    <?php echo esc_html__('Show a preview of the signed document on the verification page', 'sign-docs'); ?>
                                </label>
                                <p style="margin:8px 0 0;">
                                    <label for="sign-docs-verification-preview-pages"><?php echo esc_html__('Number of preview pages', 'sign-docs'); ?></label>
                                    <input id="sign-docs-verification-preview-pages" name="<?php echo esc_attr(self::OPTION_NAME); ?>[verification_preview_pages]" type="number" min="0" max="100" step="1" class="small-text" value="<?php echo esc_attr($settings['verification_preview_pages']); ?>">
                                    <span><?php echo esc_html__('0 — all pages', 'sign-docs'); ?></span>
                                </p>
                                <p class="description"><?php echo esc_html__('The limit speeds up page loading for documents with many pages.', 'sign-docs'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__('Document upload permissions', 'sign-docs'); ?></th>
                            <td>
                                <?php if (empty($upload_users)) : ?>
                                    <p class="description"><?php echo esc_html__('No users with the editor role were found.', 'sign-docs'); ?></p>
                                <?php else : ?>
                                    <fieldset>
                                        <legend class="screen-reader-text"><?php echo esc_html__('Editors allowed to upload Sign Docs documents', 'sign-docs'); ?></legend>
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
                                    <p class="description"><?php echo esc_html__('Administrators always have this right. Select the editors who may add documents through Sign Docs.', 'sign-docs'); ?></p>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="sign-docs-button-primary-color"><?php echo esc_html__('Block primary button color', 'sign-docs'); ?></label></th>
                            <td><input id="sign-docs-button-primary-color" name="<?php echo esc_attr(self::OPTION_NAME); ?>[button_primary_color]" type="color" value="<?php echo esc_attr($settings['button_primary_color']); ?>"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="sign-docs-button-primary-text-color"><?php echo esc_html__('Block primary button text color', 'sign-docs'); ?></label></th>
                            <td><input id="sign-docs-button-primary-text-color" name="<?php echo esc_attr(self::OPTION_NAME); ?>[button_primary_text_color]" type="color" value="<?php echo esc_attr($settings['button_primary_text_color']); ?>"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="sign-docs-button-outline-color"><?php echo esc_html__('Block outline button color', 'sign-docs'); ?></label></th>
                            <td><input id="sign-docs-button-outline-color" name="<?php echo esc_attr(self::OPTION_NAME); ?>[button_outline_color]" type="color" value="<?php echo esc_attr($settings['button_outline_color']); ?>"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="sign-docs-button-border-radius"><?php echo esc_html__('Block button border radius', 'sign-docs'); ?></label></th>
                            <td>
                                <input id="sign-docs-button-border-radius" name="<?php echo esc_attr(self::OPTION_NAME); ?>[button_border_radius]" type="number" min="0" max="9999" step="1" class="small-text" value="<?php echo esc_attr($settings['button_border_radius']); ?>">
                                <span>px</span>
                                <p class="description"><?php echo esc_html__('A value of 9999 makes the buttons fully rounded, like the pill style of the "Buttons" block.', 'sign-docs'); ?></p>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <?php submit_button(__('Save settings', 'sign-docs')); ?>
            </form>
        </div>
        <?php
    }

    public static function render_corner_select(string $name, string $id, string $selected): void
    {
        $options = array(
            'top-left' => __('Top left', 'sign-docs'),
            'top-right' => __('Top right', 'sign-docs'),
            'bottom-left' => __('Bottom left', 'sign-docs'),
            'bottom-right' => __('Bottom right', 'sign-docs'),
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
