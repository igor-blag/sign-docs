<?php
/**
 * Configurable document title templates.
 *
 * @package SignDocs
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class Sign_Docs_Title_Template
{
    public const OPTION_NAME = 'sign_docs_title_rules';

    /**
     * @return array<string,string>
     */
    public static function fields(): array
    {
        return array(
            'document_type_label' => __('Тип документа', 'sign-docs'),
            'document_date' => __('Дата', 'sign-docs'),
            'document_number' => __('Номер', 'sign-docs'),
            'document_subject' => __('Предмет документа', 'sign-docs'),
            'academic_year' => __('Год / период', 'sign-docs'),
            'document_institution' => __('Издавший орган', 'sign-docs'),
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function defaults(): array
    {
        return array(
            array(
                'category' => 'local-act',
                'type_slug' => 'local-order',
                'parts' => array('document_type_label', 'document_date', 'document_number', 'document_subject'),
                'subject_quotes' => '1',
                'separator' => ' ',
                'enabled' => '1',
            ),
            array(
                'category' => 'local-act',
                'type_slug' => 'local-program',
                'parts' => array('document_subject', 'academic_year'),
                'subject_quotes' => '0',
                'separator' => ', ',
                'enabled' => '1',
            ),
        );
    }

    public static function register(): void
    {
        register_setting(
            'sign_docs_title_rules',
            self::OPTION_NAME,
            array(
                'type' => 'array',
                'sanitize_callback' => array(self::class, 'sanitize'),
                'default' => self::defaults(),
            )
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function get_rules(): array
    {
        $rules = get_option(self::OPTION_NAME, null);
        if (! is_array($rules)) {
            return self::defaults();
        }

        return self::sanitize($rules);
    }

    /**
     * @param mixed $value
     * @return array<int,array<string,mixed>>
     */
    public static function sanitize($value): array
    {
        $raw_rules = is_array($value) && isset($value['rules']) && is_array($value['rules'])
            ? $value['rules']
            : (is_array($value) ? $value : array());

        $allowed_fields = array_keys(self::fields());
        $rules = array();

        foreach ($raw_rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $parts = array();
            $raw_parts = isset($rule['parts']) && is_array($rule['parts']) ? $rule['parts'] : array();
            foreach ($raw_parts as $part) {
                $part = sanitize_key((string) $part);
                if (in_array($part, $allowed_fields, true)) {
                    $parts[] = $part;
                }
            }

            $type_slug = isset($rule['type_slug']) ? sanitize_title((string) $rule['type_slug']) : '';
            if ('' === $type_slug || empty($parts)) {
                continue;
            }

            $separator = isset($rule['separator']) ? sanitize_text_field((string) $rule['separator']) : ' ';

            $rules[] = array(
                'category' => isset($rule['category']) ? sanitize_key((string) $rule['category']) : '',
                'type_slug' => $type_slug,
                'parts' => array_values(array_unique($parts)),
                'subject_quotes' => ! empty($rule['subject_quotes']) ? '1' : '0',
                'separator' => '' === $separator ? ' ' : $separator,
                'enabled' => ! empty($rule['enabled']) ? '1' : '0',
            );
        }

        return $rules;
    }

    /**
     * @param array<string,mixed> $args
     */
    public static function compose(array $args): string
    {
        $rule = self::match_rule($args);
        if (null === $rule) {
            return self::fallback_title($args);
        }

        $values = array(
            'document_type_label' => isset($args['document_type_label']) ? sanitize_text_field((string) $args['document_type_label']) : '',
            'document_date' => isset($args['document_date']) ? sanitize_text_field((string) $args['document_date']) : '',
            'document_number' => isset($args['document_number']) ? sanitize_text_field((string) $args['document_number']) : '',
            'document_subject' => self::format_subject((string) ($args['document_subject'] ?? ''), '1' === $rule['subject_quotes']),
            'academic_year' => isset($args['academic_year']) ? sanitize_text_field((string) $args['academic_year']) : '',
            'document_institution' => isset($args['document_institution']) ? sanitize_text_field((string) $args['document_institution']) : '',
        );

        $parts = array();
        foreach ((array) $rule['parts'] as $field) {
            $value = trim((string) ($values[$field] ?? ''));
            if ('' !== $value) {
                $parts[] = $value;
            }
        }

        if (empty($parts)) {
            return self::fallback_title($args);
        }

        return self::normalize(implode((string) $rule['separator'], $parts));
    }

    /**
     * @return array<string,mixed>
     */
    public static function client_config(): array
    {
        return array(
            'fields' => self::fields(),
            'rules' => self::get_rules(),
        );
    }

    public static function render_page(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to manage options.', 'sign-docs'));
        }

        $rules = self::get_rules();
        $fields = self::fields();
        $categories = Sign_Docs_Admin::terms_for_select('sign_doc_category');
        $types = Sign_Docs_Admin::document_type_terms_for_select();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Шаблоны названий документов', 'sign-docs'); ?></h1>
            <p><?php echo esc_html__('Настройки определяют, из каких реквизитов собирается краткое и полное название при загрузке PDF.', 'sign-docs'); ?></p>

            <form method="post" action="options.php">
                <?php settings_fields('sign_docs_title_rules'); ?>
                <table class="widefat striped" style="max-width: 1200px;">
                    <thead>
                        <tr>
                            <th style="width: 180px;"><?php echo esc_html__('Категория', 'sign-docs'); ?></th>
                            <th style="width: 220px;"><?php echo esc_html__('Тип документа', 'sign-docs'); ?></th>
                            <th><?php echo esc_html__('Компоненты', 'sign-docs'); ?></th>
                            <th style="width: 110px;"><?php echo esc_html__('Кавычки', 'sign-docs'); ?></th>
                            <th style="width: 120px;"><?php echo esc_html__('Разделитель', 'sign-docs'); ?></th>
                            <th style="width: 90px;"><?php echo esc_html__('Активно', 'sign-docs'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $row_count = max(count($rules) + 1, 4);
                        for ($index = 0; $index < $row_count; $index++) :
                            $rule = $rules[$index] ?? array(
                                'category' => '',
                                'type_slug' => '',
                                'parts' => array(),
                                'subject_quotes' => '0',
                                'separator' => ' ',
                                'enabled' => '1',
                            );
                            ?>
                            <tr>
                                <td>
                                    <select name="<?php echo esc_attr(self::OPTION_NAME); ?>[rules][<?php echo esc_attr((string) $index); ?>][category]">
                                        <option value=""><?php echo esc_html__('Любая', 'sign-docs'); ?></option>
                                        <?php foreach ($categories as $slug => $name) : ?>
                                            <option value="<?php echo esc_attr($slug); ?>" <?php selected((string) $rule['category'], (string) $slug); ?>><?php echo esc_html($name); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <select name="<?php echo esc_attr(self::OPTION_NAME); ?>[rules][<?php echo esc_attr((string) $index); ?>][type_slug]">
                                        <option value=""><?php echo esc_html__('Не использовать строку', 'sign-docs'); ?></option>
                                        <?php foreach ($types as $type) : ?>
                                            <option value="<?php echo esc_attr((string) $type['slug']); ?>" <?php selected((string) $rule['type_slug'], (string) $type['slug']); ?>><?php echo esc_html((string) $type['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <?php foreach ($fields as $field => $label) : ?>
                                        <label style="display:inline-block; margin:0 12px 6px 0;">
                                            <input name="<?php echo esc_attr(self::OPTION_NAME); ?>[rules][<?php echo esc_attr((string) $index); ?>][parts][]" type="checkbox" value="<?php echo esc_attr($field); ?>" <?php checked(in_array($field, (array) $rule['parts'], true)); ?>>
                                            <?php echo esc_html($label); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </td>
                                <td>
                                    <label>
                                        <input name="<?php echo esc_attr(self::OPTION_NAME); ?>[rules][<?php echo esc_attr((string) $index); ?>][subject_quotes]" type="checkbox" value="1" <?php checked((string) $rule['subject_quotes'], '1'); ?>>
                                        <?php echo esc_html__('Тема', 'sign-docs'); ?>
                                    </label>
                                </td>
                                <td>
                                    <input name="<?php echo esc_attr(self::OPTION_NAME); ?>[rules][<?php echo esc_attr((string) $index); ?>][separator]" type="text" class="small-text" value="<?php echo esc_attr((string) $rule['separator']); ?>">
                                </td>
                                <td>
                                    <input name="<?php echo esc_attr(self::OPTION_NAME); ?>[rules][<?php echo esc_attr((string) $index); ?>][enabled]" type="checkbox" value="1" <?php checked((string) $rule['enabled'], '1'); ?>>
                                </td>
                            </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
                <p class="description"><?php echo esc_html__('Порядок компонентов сейчас берется из порядка галочек в таблице. Пустая строка с типом "Не использовать строку" игнорируется.', 'sign-docs'); ?></p>
                <?php submit_button(__('Сохранить матрицу', 'sign-docs')); ?>
            </form>
        </div>
        <?php
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>|null
     */
    private static function match_rule(array $args): ?array
    {
        $category = isset($args['document_category']) ? sanitize_key((string) $args['document_category']) : '';
        $type_slug = isset($args['document_type_slug']) ? sanitize_title((string) $args['document_type_slug']) : '';

        if ('' === $type_slug && ! empty($args['document_type_term_id'])) {
            $term = get_term(absint($args['document_type_term_id']), 'sign_doc_type');
            if ($term instanceof WP_Term) {
                $type_slug = $term->slug;
            }
        }

        foreach (self::get_rules() as $rule) {
            if ('1' !== (string) $rule['enabled']) {
                continue;
            }

            if ((string) $rule['type_slug'] !== $type_slug) {
                continue;
            }

            if ('' !== (string) $rule['category'] && (string) $rule['category'] !== $category) {
                continue;
            }

            return $rule;
        }

        return null;
    }

    /**
     * @param array<string,mixed> $args
     */
    private static function fallback_title(array $args): string
    {
        $subject = isset($args['document_subject']) ? (string) $args['document_subject'] : '';
        $with_quotes = ! isset($args['include_subject_quotes_in_title']) || '0' !== (string) $args['include_subject_quotes_in_title'];

        return self::format_subject($subject, $with_quotes);
    }

    private static function format_subject(string $subject, bool $with_quotes): string
    {
        $subject = trim($subject, " \t\n\r\0\x0B\"'«»");
        if ('' === $subject) {
            return '';
        }

        return $with_quotes ? '«' . $subject . '»' : $subject;
    }

    private static function normalize(string $title): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $title));
    }
}
