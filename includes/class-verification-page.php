<?php
/**
 * Public verification page rendering.
 *
 * @package SignDocs
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class Sign_Docs_Verification_Page
{
    public static function register_rewrite_rules(): void
    {
        add_rewrite_rule(
            '^signed/([0-9]+)/?$',
            'index.php?p=$matches[1]&post_type=' . Sign_Docs_Post_Type::POST_TYPE,
            'top'
        );
    }

    public static function verification_permalink(string $permalink, WP_Post $post): string
    {
        if (Sign_Docs_Post_Type::POST_TYPE !== $post->post_type) {
            return $permalink;
        }

        return self::url((int) $post->ID);
    }

    public static function url(int $post_id): string
    {
        return home_url('/signed/' . (string) $post_id . '/');
    }

    public static function enqueue_assets(): void
    {
        if (! is_singular(Sign_Docs_Post_Type::POST_TYPE)) {
            return;
        }

        wp_enqueue_style(
            'sign-docs-public',
            SIGN_DOCS_PLUGIN_URL . 'assets/css/public.css',
            array(),
            SIGN_DOCS_VERSION
        );

        $script_path = SIGN_DOCS_PLUGIN_DIR . 'assets/js/public.js';
        wp_enqueue_script(
            'sign-docs-public',
            SIGN_DOCS_PLUGIN_URL . 'assets/js/public.js',
            array(),
            file_exists($script_path) ? (string) filemtime($script_path) : SIGN_DOCS_VERSION,
            true
        );
    }

    public static function render_content(string $content): string
    {
        if (! is_singular(Sign_Docs_Post_Type::POST_TYPE) || ! in_the_loop() || ! is_main_query()) {
            return $content;
        }

        $post_id = get_the_ID();

        if (! is_int($post_id)) {
            return $content;
        }

        $signed_at = Sign_Docs_Meta::get($post_id, 'signed_at');
        $hash = Sign_Docs_Meta::get($post_id, 'sha256_hash');
        $signer_name = Sign_Docs_Meta::get($post_id, 'signer_name');
        $signer_position = Sign_Docs_Meta::get($post_id, 'signer_position');
        $signer_organization = Sign_Docs_Meta::get($post_id, 'signer_organization');
        $document_status = Sign_Docs_Meta::get($post_id, 'document_status');
        $status = self::status_label($document_status);
        $version = Sign_Docs_Meta::get($post_id, 'document_version');
        $stamped_file_url = Sign_Docs_Meta::get($post_id, 'stamped_file_url');
        $stamped_file_hash = Sign_Docs_Meta::get($post_id, 'stamped_file_hash');
        $original_file_url = Sign_Docs_Meta::get($post_id, 'original_file_url');
        $verification_url = self::url($post_id);
        $replaces_post_id = Sign_Docs_Document_Service::valid_replaces_post_id(absint(Sign_Docs_Meta::get($post_id, 'replaces_post_id')));
        $replaced_by_post_id = Sign_Docs_Document_Service::valid_replaces_post_id(absint(Sign_Docs_Meta::get($post_id, 'replaced_by_post_id')));

        if ('needs_public_copy' === $document_status && ! current_user_can('edit_post', $post_id)) {
            status_header(404);

            return self::render_unavailable_notice();
        }

        if (Sign_Docs_Meta::get($post_id, 'verification_url') !== $verification_url) {
            update_post_meta($post_id, 'verification_url', $verification_url);
            update_post_meta($post_id, 'qr_code_data', $verification_url);
        }

        ob_start();
        ?>
        <section class="sign-docs-verification" aria-label="Проверка документа">
            <header class="sign-docs-verification__header">
                <p class="sign-docs-verification__eyebrow">Страница проверки документа</p>
                <h2><?php echo esc_html(get_the_title($post_id)); ?></h2>
            </header>

            <?php self::render_replacement_notices($replaces_post_id, $replaced_by_post_id); ?>

            <dl class="sign-docs-verification__details">
                <?php self::render_row('Дата и время подписи', $signed_at); ?>
                <?php self::render_row('Подписант', trim($signer_position . ' ' . $signer_name)); ?>
                <?php self::render_row('Организация', $signer_organization); ?>
                <?php self::render_row('Статус', $status); ?>
                <?php self::render_row('Версия', $version ?: '1'); ?>
                <?php self::render_row('SHA-256 исходного файла', $hash, 'sign-docs-verification__hash'); ?>
            </dl>

            <div class="sign-docs-verification__actions">
                <?php if ('' !== $stamped_file_url) : ?>
                    <a class="sign-docs-verification__button" href="<?php echo esc_url($stamped_file_url); ?>" target="_blank" rel="noopener">
                        Открыть PDF с отметкой
                    </a>
                <?php endif; ?>
                <?php if ('' === $stamped_file_url && 'unsigned' === $document_status && '' !== $original_file_url) : ?>
                    <a class="sign-docs-verification__button" href="<?php echo esc_url($original_file_url); ?>" target="_blank" rel="noopener">
                        Открыть PDF
                    </a>
                <?php endif; ?>
                <?php if ('' !== $stamped_file_url && '' !== $original_file_url && current_user_can('edit_post', $post_id)) : ?>
                    <a class="sign-docs-verification__button sign-docs-verification__button--secondary" href="<?php echo esc_url($original_file_url); ?>" target="_blank" rel="noopener">
                        Открыть исходный PDF
                    </a>
                <?php endif; ?>
                <span class="sign-docs-verification__qr-data"><?php echo esc_html($verification_url); ?></span>
            </div>
            <p class="sign-docs-verification__note">Контрольная проверка выполняется по SHA-256 исходного PDF и записи на сайте.</p>
            <?php self::render_file_checker($hash, $stamped_file_hash); ?>
        </section>
        <?php

        return (string) ob_get_clean();
    }

    private static function render_file_checker(string $original_hash, string $stamped_hash): void
    {
        if ('' === $original_hash && '' === $stamped_hash) {
            return;
        }

        ?>
        <div
            class="sign-docs-verification__checker"
            data-sign-docs-checker
            data-original-hash="<?php echo esc_attr(strtolower($original_hash)); ?>"
            data-stamped-hash="<?php echo esc_attr(strtolower($stamped_hash)); ?>"
        >
            <h3>Проверить PDF-файл</h3>
            <p>Файл проверяется в браузере. Он не загружается на сайт.</p>
            <label class="sign-docs-verification__file">
                <span>Выбрать PDF</span>
                <input type="file" accept="application/pdf,.pdf" data-sign-docs-checker-input>
            </label>
            <p class="sign-docs-verification__checker-result" data-sign-docs-checker-result aria-live="polite"></p>
        </div>
        <?php
    }

    private static function render_replacement_notices(int $replaces_post_id, int $replaced_by_post_id): void
    {
        if ($replaced_by_post_id > 0) {
            ?>
            <p class="sign-docs-verification__replacement sign-docs-verification__replacement--warning">
                Этот документ заменен новой редакцией:
                <a href="<?php echo esc_url(self::url($replaced_by_post_id)); ?>"><?php echo esc_html(get_the_title($replaced_by_post_id)); ?></a>.
            </p>
            <?php
        }

        if ($replaces_post_id > 0) {
            ?>
            <p class="sign-docs-verification__replacement">
                Этот документ заменяет предыдущую редакцию:
                <a href="<?php echo esc_url(self::url($replaces_post_id)); ?>"><?php echo esc_html(get_the_title($replaces_post_id)); ?></a>.
            </p>
            <?php
        }
    }

    private static function render_unavailable_notice(): string
    {
        ob_start();
        ?>
        <section class="sign-docs-verification" aria-label="Проверка документа">
            <header class="sign-docs-verification__header">
                <p class="sign-docs-verification__eyebrow">Страница проверки документа</p>
                <h2>Документ еще не опубликован</h2>
            </header>
            <p class="sign-docs-verification__note">Публичная копия документа еще не сформирована.</p>
        </section>
        <?php

        return (string) ob_get_clean();
    }

    private static function render_row(string $label, string $value, string $class_name = ''): void
    {
        if ('' === trim($value)) {
            return;
        }

        ?>
        <div class="sign-docs-verification__row <?php echo esc_attr($class_name); ?>">
            <dt><?php echo esc_html($label); ?></dt>
            <dd><?php echo esc_html($value); ?></dd>
        </div>
        <?php
    }

    private static function status_label(string $status): string
    {
        $labels = array(
            'active' => 'действующий',
            'unsigned' => 'Без подписи',
            'archive' => 'Архив',
            'archived' => 'Архив',
            'replaced' => 'заменен',
            'deleted' => 'Архив',
            'draft' => 'черновик',
            'needs_public_copy' => 'ожидает публичную копию',
        );

        return $labels[$status] ?? ($status ?: 'действующий');
    }
}
