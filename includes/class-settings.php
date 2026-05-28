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
            'stamp_width_mm' => '100',
            'stamp_border_enabled' => '1',
            'qr_logo_enabled' => '1',
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
        $width_mm = isset($value['stamp_width_mm']) ? (int) $value['stamp_width_mm'] : 100;
        $corner = isset($value['stamp_corner']) ? sanitize_key((string) $value['stamp_corner']) : 'top-left';
        $stamp_border_enabled = ! empty($value['stamp_border_enabled']) ? '1' : '0';
        $qr_logo_enabled = ! empty($value['qr_logo_enabled']) ? '1' : '0';
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
            'stamp_width_mm' => (string) min(160, max(70, $width_mm)),
            'stamp_border_enabled' => $stamp_border_enabled,
            'qr_logo_enabled' => $qr_logo_enabled,
            'button_primary_color' => $button_primary_color ?: '#32373c',
            'button_primary_text_color' => $button_primary_text_color ?: '#ffffff',
            'button_outline_color' => $button_outline_color ?: '#32373c',
            'button_border_radius' => (string) min(9999, max(0, $button_border_radius)),
            'ai_autofill_enabled' => $ai_autofill_enabled,
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
        ?>
        <div class="wrap">
            <h1>Настройки Sign Docs</h1>
            <p>
                Здесь хранятся постоянные реквизиты штампа. Они будут подставляться при загрузке PDF и фиксироваться в meta конкретного документа.
            </p>

            <h2>Справочники</h2>
            <p>Классификаторы открываются в стандартных экранах WordPress.</p>
            <ul>
                <li><a href="<?php echo esc_url(self::taxonomy_admin_url('sign_doc_category')); ?>">Категории документов</a></li>
                <li><a href="<?php echo esc_url(self::taxonomy_admin_url('sign_doc_type')); ?>">Типы документов</a></li>
                <li><a href="<?php echo esc_url(self::taxonomy_admin_url('sign_doc_department')); ?>">Структурные подразделения</a></li>
                <li><a href="<?php echo esc_url(self::taxonomy_admin_url('sign_doc_institution')); ?>">Институции</a></li>
            </ul>

            <form method="post" action="options.php">
                <?php settings_fields('sign_docs_settings'); ?>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row">AI-автозаполнение</th>
                            <td>
                                <label for="sign-docs-ai-autofill-enabled">
                                    <input id="sign-docs-ai-autofill-enabled" name="<?php echo esc_attr(self::OPTION_NAME); ?>[ai_autofill_enabled]" type="checkbox" value="1" <?php checked($settings['ai_autofill_enabled'], '1'); ?>>
                                    Автоматически предлагать реквизиты документа при выборе PDF
                                </label>
                                <p class="description">Используется настроенный AI connector WordPress. В модель отправляется только текст первой страницы PDF, а подпись и публикация остаются ручным действием администратора.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Права на загрузку документов</th>
                            <td>
                                <?php if (empty($upload_users)) : ?>
                                    <p class="description">Пользователи с ролью редактора не найдены.</p>
                                <?php else : ?>
                                    <fieldset>
                                        <legend class="screen-reader-text">Редакторы, которым разрешена загрузка документов Sign Docs</legend>
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
                                    <p class="description">Администраторы имеют это право всегда. Отметьте редакторов, которым можно добавлять документы через Sign Docs.</p>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="sign-docs-default-signer-name">ФИО подписанта</label></th>
                            <td><input id="sign-docs-default-signer-name" name="<?php echo esc_attr(self::OPTION_NAME); ?>[signer_name]" type="text" class="regular-text" value="<?php echo esc_attr($settings['signer_name']); ?>"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="sign-docs-default-signer-position">Должность</label></th>
                            <td><input id="sign-docs-default-signer-position" name="<?php echo esc_attr(self::OPTION_NAME); ?>[signer_position]" type="text" class="regular-text" value="<?php echo esc_attr($settings['signer_position']); ?>"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="sign-docs-default-signer-organization">Организация</label></th>
                            <td><input id="sign-docs-default-signer-organization" name="<?php echo esc_attr(self::OPTION_NAME); ?>[signer_organization]" type="text" class="regular-text" value="<?php echo esc_attr($settings['signer_organization']); ?>"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="sign-docs-default-stamp-corner">Угол штампа</label></th>
                            <td><?php self::render_corner_select(self::OPTION_NAME . '[stamp_corner]', 'sign-docs-default-stamp-corner', $settings['stamp_corner']); ?></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="sign-docs-default-stamp-color">Цвет штампа</label></th>
                            <td><input id="sign-docs-default-stamp-color" name="<?php echo esc_attr(self::OPTION_NAME); ?>[stamp_color]" type="color" value="<?php echo esc_attr($settings['stamp_color']); ?>"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="sign-docs-default-stamp-opacity">Прозрачность</label></th>
                            <td>
                                <input id="sign-docs-default-stamp-opacity" name="<?php echo esc_attr(self::OPTION_NAME); ?>[stamp_opacity]" type="range" min="0.1" max="1" step="0.05" value="<?php echo esc_attr($settings['stamp_opacity']); ?>">
                                <span><?php echo esc_html((string) round((float) $settings['stamp_opacity'] * 100)); ?>%</span>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="sign-docs-default-stamp-font-size">Размер шрифта</label></th>
                            <td>
                                <input id="sign-docs-default-stamp-font-size" name="<?php echo esc_attr(self::OPTION_NAME); ?>[stamp_font_size]" type="number" min="6" max="12" step="0.1" class="small-text" value="<?php echo esc_attr($settings['stamp_font_size']); ?>">
                                <span>pt</span>
                                <p class="description">Базовый размер шрифта в основном штампе. Для длинных ФИО или названий можно уменьшить значение.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="sign-docs-default-stamp-width-mm">Длина штампа</label></th>
                            <td>
                                <input id="sign-docs-default-stamp-width-mm" name="<?php echo esc_attr(self::OPTION_NAME); ?>[stamp_width_mm]" type="number" min="70" max="160" step="1" class="small-text" value="<?php echo esc_attr($settings['stamp_width_mm']); ?>">
                                <span>мм</span>
                                <p class="description">Ширина рамки штампа. Фактическая ширина ограничивается размером страницы PDF.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Рамка штампа</th>
                            <td>
                                <label for="sign-docs-default-stamp-border-enabled">
                                    <input id="sign-docs-default-stamp-border-enabled" name="<?php echo esc_attr(self::OPTION_NAME); ?>[stamp_border_enabled]" type="checkbox" value="1" <?php checked($settings['stamp_border_enabled'], '1'); ?>>
                                    Показывать рамку вокруг основного штампа
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Favicon в QR-коде</th>
                            <td>
                                <label for="sign-docs-default-qr-logo-enabled">
                                    <input id="sign-docs-default-qr-logo-enabled" name="<?php echo esc_attr(self::OPTION_NAME); ?>[qr_logo_enabled]" type="checkbox" value="1" <?php checked($settings['qr_logo_enabled'], '1'); ?>>
                                    Накладывать favicon или логотип сайта на модули QR-кода
                                </label>
                                <p class="description">Логотип не является частью QR-стандарта. Плагин не закрывает центр QR-кода, а использует favicon как полупрозрачную текстуру поверх темных модулей и снижает уровень коррекции до M, чтобы матрица была компактнее. Для максимальной надежности сканирования эту опцию можно отключить.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="sign-docs-button-primary-color">Цвет основной кнопки блока</label></th>
                            <td><input id="sign-docs-button-primary-color" name="<?php echo esc_attr(self::OPTION_NAME); ?>[button_primary_color]" type="color" value="<?php echo esc_attr($settings['button_primary_color']); ?>"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="sign-docs-button-primary-text-color">Цвет текста основной кнопки</label></th>
                            <td><input id="sign-docs-button-primary-text-color" name="<?php echo esc_attr(self::OPTION_NAME); ?>[button_primary_text_color]" type="color" value="<?php echo esc_attr($settings['button_primary_text_color']); ?>"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="sign-docs-button-outline-color">Цвет контурной кнопки блока</label></th>
                            <td><input id="sign-docs-button-outline-color" name="<?php echo esc_attr(self::OPTION_NAME); ?>[button_outline_color]" type="color" value="<?php echo esc_attr($settings['button_outline_color']); ?>"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="sign-docs-button-border-radius">Скругление кнопок блока</label></th>
                            <td>
                                <input id="sign-docs-button-border-radius" name="<?php echo esc_attr(self::OPTION_NAME); ?>[button_border_radius]" type="number" min="0" max="9999" step="1" class="small-text" value="<?php echo esc_attr($settings['button_border_radius']); ?>">
                                <span>px</span>
                                <p class="description">Значение 9999 делает кнопки округлыми, как pill-вариант блока «Кнопки».</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <?php submit_button('Сохранить настройки'); ?>
            </form>
        </div>
        <?php
    }

    public static function render_corner_select(string $name, string $id, string $selected): void
    {
        $options = array(
            'top-left' => 'Верхний левый',
            'top-right' => 'Верхний правый',
            'bottom-left' => 'Нижний левый',
            'bottom-right' => 'Нижний правый',
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
