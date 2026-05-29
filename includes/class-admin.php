<?php
/**
 * Admin table and upload screen.
 *
 * @package SignDocs
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class Sign_Docs_Admin
{
    public static function enqueue_assets(string $hook_suffix): void
    {
        if ('sign-docs_page_sign-docs-upload' !== $hook_suffix) {
            return;
        }

        $pdf_lib_path = SIGN_DOCS_PLUGIN_DIR . 'assets/vendor/pdf-lib.min.js';
        $fontkit_path = SIGN_DOCS_PLUGIN_DIR . 'assets/vendor/fontkit.umd.min.js';
        $golos_regular_path = SIGN_DOCS_PLUGIN_DIR . 'assets/vendor/GolosText-Regular.ttf';
        $golos_medium_path = SIGN_DOCS_PLUGIN_DIR . 'assets/vendor/GolosText-Medium.ttf';
        $qr_path = SIGN_DOCS_PLUGIN_DIR . 'assets/vendor/qrcode.min.js';
        $pdfjs_path = SIGN_DOCS_PLUGIN_DIR . 'assets/vendor/pdf.min.mjs';
        $pdfjs_worker_path = SIGN_DOCS_PLUGIN_DIR . 'assets/vendor/pdf.worker.min.mjs';
        $settings = Sign_Docs_Settings::get();
        $has_vendor = file_exists($pdf_lib_path)
            && file_exists($fontkit_path)
            && file_exists($golos_regular_path)
            && file_exists($golos_medium_path)
            && file_exists($qr_path);
        $has_pdfjs = file_exists($pdfjs_path) && file_exists($pdfjs_worker_path);

        if (! $has_vendor) {
            wp_enqueue_script(
                'sign-docs-admin-upload',
                SIGN_DOCS_PLUGIN_URL . 'assets/js/admin-upload.js',
                array(),
                SIGN_DOCS_VERSION,
                true
            );

            wp_localize_script(
                'sign-docs-admin-upload',
                'SignDocsUpload',
                array(
                    'prepareUrl' => rest_url('sign-docs/v1/prepare'),
                    'completeUrl' => rest_url('sign-docs/v1/complete'),
                    'suggestMetadataUrl' => rest_url('sign-docs/v1/suggest-metadata'),
                    'nonce' => wp_create_nonce('wp_rest'),
                    'hasVendor' => false,
                    'aiAutofillEnabled' => '1' === $settings['ai_autofill_enabled'],
                    'hasPdfJs' => $has_pdfjs,
                    'siteIconUrl' => self::site_icon_url(),
                    'pdfJs' => $has_pdfjs ? array(
                        'module' => SIGN_DOCS_PLUGIN_URL . 'assets/vendor/pdf.min.mjs',
                        'worker' => SIGN_DOCS_PLUGIN_URL . 'assets/vendor/pdf.worker.min.mjs',
                    ) : null,
                    'titleRules' => Sign_Docs_Title_Template::client_config(),
                )
            );

            return;
        }

        wp_enqueue_script('sign-docs-pdf-lib', SIGN_DOCS_PLUGIN_URL . 'assets/vendor/pdf-lib.min.js', array(), (string) filemtime($pdf_lib_path), true);
        wp_enqueue_script('sign-docs-qrcode', SIGN_DOCS_PLUGIN_URL . 'assets/vendor/qrcode.min.js', array(), (string) filemtime($qr_path), true);
        wp_enqueue_script('sign-docs-fontkit', SIGN_DOCS_PLUGIN_URL . 'assets/vendor/fontkit.umd.min.js', array(), (string) filemtime($fontkit_path), true);
        wp_enqueue_script(
            'sign-docs-admin-upload',
            SIGN_DOCS_PLUGIN_URL . 'assets/js/admin-upload.js',
            array('sign-docs-pdf-lib', 'sign-docs-qrcode', 'sign-docs-fontkit'),
            SIGN_DOCS_VERSION,
            true
        );

        wp_localize_script(
            'sign-docs-admin-upload',
            'SignDocsUpload',
                array(
                    'prepareUrl' => rest_url('sign-docs/v1/prepare'),
                    'completeUrl' => rest_url('sign-docs/v1/complete'),
                    'suggestMetadataUrl' => rest_url('sign-docs/v1/suggest-metadata'),
                    'nonce' => wp_create_nonce('wp_rest'),
                    'hasVendor' => true,
                    'aiAutofillEnabled' => '1' === $settings['ai_autofill_enabled'],
                    'hasPdfJs' => $has_pdfjs,
                    'siteIconUrl' => self::site_icon_url(),
                    'pdfJs' => $has_pdfjs ? array(
                        'module' => SIGN_DOCS_PLUGIN_URL . 'assets/vendor/pdf.min.mjs',
                        'worker' => SIGN_DOCS_PLUGIN_URL . 'assets/vendor/pdf.worker.min.mjs',
                    ) : null,
                    'titleRules' => Sign_Docs_Title_Template::client_config(),
                    'fonts' => array(
                        'regular' => SIGN_DOCS_PLUGIN_URL . 'assets/vendor/GolosText-Regular.ttf',
                        'medium' => SIGN_DOCS_PLUGIN_URL . 'assets/vendor/GolosText-Medium.ttf',
                ),
            )
        );

    }

    public static function menu(): void
    {
        add_submenu_page(
            'edit.php?post_type=' . Sign_Docs_Post_Type::POST_TYPE,
            'Добавить документ',
            'Добавить документ',
            Sign_Docs_Settings::UPLOAD_CAPABILITY,
            'sign-docs-upload',
            array(self::class, 'render_upload_page')
        );

        remove_submenu_page(
            'edit.php?post_type=' . Sign_Docs_Post_Type::POST_TYPE,
            'post-new.php?post_type=' . Sign_Docs_Post_Type::POST_TYPE
        );

        add_submenu_page(
            'edit.php?post_type=' . Sign_Docs_Post_Type::POST_TYPE,
            'Настройки Sign Docs',
            'Настройки',
            'manage_options',
            'sign-docs-settings',
            array(Sign_Docs_Settings::class, 'render_page')
        );

        add_submenu_page(
            'edit.php?post_type=' . Sign_Docs_Post_Type::POST_TYPE,
            'Матрица названий',
            'Матрица названий',
            'manage_options',
            'sign-docs-title-rules',
            array(Sign_Docs_Title_Template::class, 'render_page')
        );
    }

    public static function remove_taxonomy_menus(): void
    {
        foreach (array('sign_doc_category', 'sign_doc_type', 'sign_doc_department', 'sign_doc_institution') as $taxonomy) {
            remove_submenu_page(
                'edit.php?post_type=' . Sign_Docs_Post_Type::POST_TYPE,
                'edit-tags.php?taxonomy=' . $taxonomy . '&post_type=' . Sign_Docs_Post_Type::POST_TYPE
            );
            remove_submenu_page(
                'edit.php?post_type=' . Sign_Docs_Post_Type::POST_TYPE,
                'edit-tags.php?taxonomy=' . $taxonomy
            );
        }
    }

    public static function render_upload_page(): void
    {
        if (! Sign_Docs_Settings::current_user_can_upload_documents()) {
            wp_die(esc_html__('You are not allowed to upload files.', 'sign-docs'));
        }

        $created = isset($_GET['created']) ? absint($_GET['created']) : 0;
        $error = isset($_GET['sign_docs_error']) ? sanitize_key((string) $_GET['sign_docs_error']) : '';
        $settings = Sign_Docs_Settings::get();
        $category_terms = self::terms_for_select('sign_doc_category');
        $type_terms = self::document_type_terms_for_select();
        $institution_terms = self::terms_for_select('sign_doc_institution');
        $return_to = isset($_GET['return_to']) ? esc_url_raw((string) wp_unslash($_GET['return_to'])) : '';
        $replaces_post_id = Sign_Docs_Document_Service::valid_replaces_post_id(isset($_GET['replaces']) ? absint($_GET['replaces']) : 0);
        ?>
        <div class="wrap">
            <h1>Добавить документ</h1>

            <?php if ($created > 0) : ?>
                <div class="notice notice-success">
                    <p>
                        <?php echo esc_html(isset($_GET['unsigned']) ? 'Документ сохранен без подписи.' : 'Документ подписан и зарегистрирован.'); ?>
                        <a href="<?php echo esc_url(Sign_Docs_Verification_Page::url($created)); ?>" target="_blank" rel="noopener">
                            Открыть страницу проверки
                        </a>
                    </p>
                </div>
            <?php endif; ?>

            <?php if ('' !== $error) : ?>
                <div class="notice notice-error">
                    <p><?php echo esc_html(self::error_message($error)); ?></p>
                </div>
            <?php endif; ?>

            <div class="notice notice-info">
                <p>
                    Реквизиты подписанта, организация и параметры штампа берутся из страницы
                    <a href="<?php echo esc_url(admin_url('edit.php?post_type=' . Sign_Docs_Post_Type::POST_TYPE . '&page=sign-docs-settings')); ?>">настроек Sign Docs</a>.
                </p>
            </div>

            <?php if ($replaces_post_id > 0) : ?>
                <div class="notice notice-warning">
                    <p>
                        Новый документ заменит:
                        <a href="<?php echo esc_url(get_edit_post_link($replaces_post_id, '')); ?>">
                            <?php echo esc_html(get_the_title($replaces_post_id)); ?>
                        </a>
                        После успешной подписи предыдущая запись получит статус «Заменен».
                    </p>
                </div>
            <?php endif; ?>

            <div id="sign-docs-upload-status" class="notice notice-info" hidden>
                <p></p>
            </div>

            <style>
                .sign-docs-upload-actions {
                    align-items: center;
                    display: flex;
                    flex-wrap: wrap;
                    gap: 8px;
                    justify-content: flex-start;
                    margin: 16px 0;
                }
                .sign-docs-upload-actions .button {
                    margin: 0;
                }
                .sign-docs-upload-layout {
                    align-items: flex-start;
                    display: grid;
                    gap: 24px;
                    grid-template-columns: minmax(420px, 1fr) minmax(420px, 1fr);
                }
                .sign-docs-upload-fields,
                .sign-docs-upload-preview {
                    min-width: 0;
                }
                .sign-docs-upload-fields {
                    background: #fff;
                    border: 1px solid #dcdcde;
                    box-sizing: border-box;
                    padding: 4px 20px 12px;
                }
                .sign-docs-upload-fields .form-table th {
                    width: 170px;
                }
                .sign-docs-upload-fields input.regular-text,
                .sign-docs-upload-fields input.large-text,
                .sign-docs-upload-fields textarea.large-text,
                .sign-docs-upload-fields select {
                    box-sizing: border-box;
                    max-width: none;
                    width: 100%;
                }
                .sign-docs-upload-fields #sign-docs-document-date,
                .sign-docs-upload-fields #sign-docs-document-number {
                    max-width: none !important;
                    width: 100%;
                }
                .sign-docs-date-number {
                    display: flex;
                    gap: 12px;
                }
                .sign-docs-date-number input {
                    min-width: 0;
                }
                .sign-docs-file-dropzone {
                    align-items: center;
                    background: #f6f7f7;
                    border: 2px dashed #c3c4c7;
                    border-radius: 4px;
                    box-sizing: border-box;
                    cursor: pointer;
                    display: flex;
                    justify-content: center;
                    min-height: 116px;
                    padding: 18px;
                    text-align: center;
                    transition: border-color .15s ease, background-color .15s ease;
                    width: 100%;
                }
                .sign-docs-file-dropzone:hover,
                .sign-docs-file-dropzone:focus-within,
                .sign-docs-file-dropzone.is-dragover {
                    background: #fff;
                    border-color: #2271b1;
                }
                .sign-docs-file-dropzone input {
                    height: 1px;
                    opacity: 0;
                    overflow: hidden;
                    position: absolute;
                    width: 1px;
                }
                .sign-docs-file-dropzone strong {
                    display: block;
                    margin-bottom: 4px;
                }
                .sign-docs-file-dropzone span {
                    color: #646970;
                    display: block;
                }
                .sign-docs-case-actions {
                    display: flex;
                    flex-wrap: wrap;
                    gap: 12px;
                    margin: 6px 0 0;
                }
                .sign-docs-year-actions {
                    display: flex;
                    flex-direction: column;
                    gap: 8px;
                    margin: 8px 0 0;
                }
                .sign-docs-year-actions__group {
                    align-items: center;
                    display: flex;
                    flex-wrap: wrap;
                    gap: 6px;
                }
                .sign-docs-year-actions__group strong {
                    margin-right: 4px;
                }
                .sign-docs-case-actions button {
                    background: transparent;
                    border: 0;
                    color: #2271b1;
                    cursor: pointer;
                    padding: 0;
                    text-decoration: underline;
                }
                .sign-docs-case-actions button:hover,
                .sign-docs-case-actions button:focus {
                    color: #135e96;
                }
                #sign-docs-pdf-preview.sign-docs-upload-preview {
                    margin-top: 0 !important;
                    min-width: 0 !important;
                }
                #sign-docs-preview-frame-wrap {
                    box-sizing: border-box;
                }
                @media (max-width: 1120px) {
                    .sign-docs-upload-layout {
                        grid-template-columns: 1fr;
                    }
                }
            </style>

            <form id="sign-docs-upload-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                <?php wp_nonce_field('sign_docs_upload', 'sign_docs_nonce'); ?>
                <input type="hidden" name="action" value="sign_docs_upload">
                <input type="hidden" name="signer_name" value="<?php echo esc_attr($settings['signer_name']); ?>">
                <input type="hidden" name="signer_position" value="<?php echo esc_attr($settings['signer_position']); ?>">
                <input type="hidden" name="signer_organization" value="<?php echo esc_attr($settings['signer_organization']); ?>">
                <input type="hidden" name="stamp_corner" value="<?php echo esc_attr($settings['stamp_corner']); ?>">
                <input type="hidden" name="stamp_color" value="<?php echo esc_attr($settings['stamp_color']); ?>">
                <input type="hidden" name="stamp_opacity" value="<?php echo esc_attr($settings['stamp_opacity']); ?>">
                <input type="hidden" name="stamp_font_size" value="<?php echo esc_attr($settings['stamp_font_size']); ?>">
                <input type="hidden" name="stamp_width_mm" value="<?php echo esc_attr($settings['stamp_width_mm']); ?>">
                <input type="hidden" name="stamp_border_enabled" value="<?php echo esc_attr($settings['stamp_border_enabled']); ?>">
                <input type="hidden" name="qr_logo_enabled" value="<?php echo esc_attr($settings['qr_logo_enabled']); ?>">
                <input type="hidden" name="stamp_placement_mode" value="corner">
                <input type="hidden" name="stamp_manual_x" value="">
                <input type="hidden" name="stamp_manual_y" value="">
                <input type="hidden" name="return_to" value="<?php echo esc_attr($return_to); ?>">
                <input type="hidden" name="default_institution" value="<?php echo esc_attr($settings['signer_organization']); ?>">
                <input type="hidden" name="replaces_post_id" value="<?php echo esc_attr((string) $replaces_post_id); ?>">

                <div class="sign-docs-upload-actions sign-docs-upload-actions--top">
                    <button type="submit" class="button button-primary sign-docs-save-signed" name="sign_docs_save_mode" value="signed">Сохранить и подписать документ</button>
                    <button type="submit" class="button button-secondary sign-docs-save-unsigned" name="sign_docs_save_mode" value="unsigned">Сохранить без подписи</button>
                </div>

                <div class="sign-docs-upload-layout">
                    <div class="sign-docs-upload-fields">
                        <table class="form-table" role="presentation">
                            <tbody>
                                <tr>
                                    <th scope="row">
                                        <label for="sign-docs-pdf">Исходный PDF</label>
                                    </th>
                                    <td>
                                        <label class="sign-docs-file-dropzone" for="sign-docs-pdf">
                                            <span>
                                                <strong id="sign-docs-file-dropzone-title">Перетащите PDF сюда</strong>
                                                <span id="sign-docs-file-dropzone-text">или щелкните, чтобы выбрать файл</span>
                                            </span>
                                            <input id="sign-docs-pdf" name="sign_docs_pdf" type="file" accept="application/pdf,.pdf" required>
                                        </label>
                                        <p class="description">Исходный файл сохраняется без изменений, SHA-256 считается на сервере.</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="sign-docs-category">Категория</label>
                                    </th>
                                    <td>
                                        <select id="sign-docs-category" name="document_category">
                                            <?php foreach ($category_terms as $slug => $name) : ?>
                                                <option value="<?php echo esc_attr($slug); ?>"><?php echo esc_html($name); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="sign-docs-document-type">Вид документа</label>
                                    </th>
                                    <td>
                                        <select id="sign-docs-document-type" name="document_type_label">
                                            <?php foreach ($type_terms as $term) : ?>
                                                <option
                                                    value="<?php echo esc_attr($term['name']); ?>"
                                                    data-term-id="<?php echo esc_attr((string) $term['term_id']); ?>"
                                                    data-type-slug="<?php echo esc_attr((string) $term['slug']); ?>"
                                                    data-category="<?php echo esc_attr($term['category']); ?>"
                                                >
                                                    <?php echo esc_html($term['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <input type="hidden" name="document_type_term_id" value="">
                                    </td>
                                </tr>
                                <tr id="sign-docs-institution-row">
                                    <th scope="row">
                                        <label for="sign-docs-institution">Институция</label>
                                    </th>
                                    <td>
                                        <select id="sign-docs-institution-select" class="regular-text" style="max-width: 25em;">
                                            <option value="">Выбрать из справочника</option>
                                            <?php foreach ($institution_terms as $name) : ?>
                                                <option value="<?php echo esc_attr($name); ?>"><?php echo esc_html($name); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <p style="margin: 12px 0 4px;">
                                            <label for="sign-docs-institution">Или введите новое краткое наименование органа</label>
                                        </p>
                                        <input id="sign-docs-institution" name="document_institution" type="text" class="regular-text" list="sign-docs-institutions" autocomplete="off" placeholder="Вводите в родительном падеже">
                                        <datalist id="sign-docs-institutions">
                                            <?php foreach ($institution_terms as $name) : ?>
                                                <option value="<?php echo esc_attr($name); ?>"></option>
                                            <?php endforeach; ?>
                                        </datalist>
                                        <p class="description">Можно выбрать существующую институцию из справочника или вписать новую в поле ниже.</p>
                                    </td>
                                </tr>
                                <tr id="sign-docs-include-institution-row">
                                    <th scope="row">Учреждение в названии</th>
                                    <td>
                                        <label for="sign-docs-include-institution">
                                            <input id="sign-docs-include-institution" name="include_institution_in_title" type="checkbox" value="1">
                                            Добавить наименование учреждения в конструктор названия
                                        </label>
                                        <p class="description">Для локальных актов учреждение берется из настроек Sign Docs и обычно не нужно в названии.</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="sign-docs-document-date">Дата и номер</label>
                                    </th>
                                    <td>
                                        <div class="sign-docs-date-number">
                                            <input id="sign-docs-document-date" name="document_date" type="text" class="regular-text" placeholder="20.05.2026">
                                            <input id="sign-docs-document-number" name="document_number" type="text" class="regular-text" placeholder="183-р">
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="sign-docs-document-subject">О чем документ</label>
                                    </th>
                                    <td>
                                        <textarea id="sign-docs-document-subject" name="document_subject" class="large-text" rows="2" placeholder="Например: О проведении аттестации в 9-х классах"></textarea>
                                        <p style="margin: 8px 0 6px;">
                                            <label for="sign-docs-include-subject-quotes">
                                                <input id="sign-docs-include-subject-quotes" name="include_subject_quotes_in_title" type="checkbox" value="1" checked>
                                                Добавлять кавычки в название
                                            </label>
                                        </p>
                                        <p class="sign-docs-case-actions">
                                            <button type="button" data-sign-docs-case="sentence">Как предложение</button>
                                            <button type="button" data-sign-docs-case="lower">нижний регистр</button>
                                            <button type="button" data-sign-docs-case="upper">ВЕРХНИЙ РЕГИСТР</button>
                                        </p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="sign-docs-title">Название</label>
                                    </th>
                                    <td>
                                        <input id="sign-docs-title" name="post_title" type="text" class="large-text">
                                        <p class="description">Краткое название записи в WordPress.</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="sign-docs-full-title">Полное название</label>
                                    </th>
                                    <td>
                                        <textarea id="sign-docs-full-title" name="full_title" class="large-text" rows="3"></textarea>
                                        <p class="description">Используется на странице проверки, в блоке документа и в публичной карточке. Если оставить пустым, будет использовано краткое название.</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="sign-docs-document-comment">Комментарий</label>
                                    </th>
                                    <td>
                                        <textarea id="sign-docs-document-comment" name="document_comment" class="large-text" rows="3"></textarea>
                                        <p class="description">Внутреннее описание для администратора. На странице проверки и в блоке документа не отображается.</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="sign-docs-academic-year">Год / период</label>
                                    </th>
                                    <td>
                                        <input id="sign-docs-academic-year" name="academic_year" type="text" class="regular-text" placeholder="на 2025/26 учебный год">
                                        <p class="sign-docs-year-actions" aria-label="Автозаполнение года"></p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div id="sign-docs-pdf-preview" class="sign-docs-upload-preview" style="display:none;">
                        <h2 style="margin-bottom: 8px;">Просмотр</h2>
                        <p class="description" style="margin: 0 0 10px;">Показывается локальная копия выбранного PDF. Файл будет загружен только после отправки формы.</p>
                        <p style="margin: 0 0 10px;">
                            <button type="button" class="button" id="sign-docs-stamp-pick">Выбрать место штампа</button>
                            <button type="button" class="button" id="sign-docs-stamp-reset" hidden>Сбросить место</button>
                            <span id="sign-docs-stamp-placement-status" class="description" style="margin-left: 8px;">Используется угол из настроек.</span>
                        </p>
                        <div id="sign-docs-preview-frame-wrap" style="position: relative; width: 100%; height: 620px; border: 1px solid #c3c4c7; background: #fff; overflow: hidden;">
                            <iframe
                                title="Просмотр выбранного PDF"
                                style="position: relative; z-index: 1; width: 100%; height: 100%; border: 0; background: #fff;"
                            ></iframe>
                            <div id="sign-docs-stamp-pick-layer" style="display:none; position:absolute; z-index: 2; inset:0; cursor:crosshair; background: rgba(255,255,255,0.01);">
                                <div id="sign-docs-stamp-preview-rect" style="display:none; position:absolute; z-index: 3; border: 2px solid #2271b1; background: rgba(34,113,177,0.08); box-sizing:border-box; pointer-events:none;"></div>
                            </div>
                            <div id="sign-docs-stamp-selected-rect" style="display:none; position:absolute; z-index: 4; border: 2px solid #2271b1; background: rgba(34,113,177,0.08); box-sizing:border-box; pointer-events:none;"></div>
                        </div>
                    </div>
                </div>

                <div class="sign-docs-upload-actions sign-docs-upload-actions--bottom">
                    <button id="sign-docs-save-signed" type="submit" class="button button-primary sign-docs-save-signed" name="sign_docs_save_mode" value="signed">Сохранить и подписать документ</button>
                    <button id="sign-docs-save-unsigned" type="submit" class="button button-secondary sign-docs-save-unsigned" name="sign_docs_save_mode" value="unsigned">Сохранить без подписи</button>
                </div>
            </form>
        </div>
        <?php
    }

    public static function redirect_add_new(): void
    {
        if (! is_admin()) {
            return;
        }

        global $pagenow;

        if ('post-new.php' !== $pagenow) {
            return;
        }

        $post_type = isset($_GET['post_type']) ? sanitize_key((string) wp_unslash($_GET['post_type'])) : 'post';

        if (Sign_Docs_Post_Type::POST_TYPE !== $post_type) {
            return;
        }

        wp_safe_redirect(admin_url('edit.php?post_type=' . Sign_Docs_Post_Type::POST_TYPE . '&page=sign-docs-upload'));
        exit;
    }

    public static function handle_upload(): void
    {
        if (! Sign_Docs_Settings::current_user_can_upload_documents()) {
            wp_die(esc_html__('You are not allowed to upload files.', 'sign-docs'));
        }

        check_admin_referer('sign_docs_upload', 'sign_docs_nonce');

        if (! isset($_FILES['sign_docs_pdf']) || ! is_array($_FILES['sign_docs_pdf'])) {
            self::redirect_upload_error('missing_file');
        }

        $file = $_FILES['sign_docs_pdf'];
        $error = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;

        if (UPLOAD_ERR_OK !== $error) {
            self::redirect_upload_error('upload_failed');
        }

        $tmp_name = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';
        $source_name = isset($file['name']) ? (string) $file['name'] : '';
        $settings = Sign_Docs_Settings::get();
        $save_mode = isset($_POST['sign_docs_save_mode']) ? sanitize_key((string) wp_unslash($_POST['sign_docs_save_mode'])) : 'signed';
        $document_category = isset($_POST['document_category']) ? sanitize_key((string) wp_unslash($_POST['document_category'])) : '';
        $document_institution = isset($_POST['document_institution']) ? (string) wp_unslash($_POST['document_institution']) : '';
        if ('local-act' === $document_category && '' === trim($document_institution)) {
            $document_institution = $settings['signer_organization'];
        }
        $save_unsigned = 'unsigned' === $save_mode || 'external-regulation' === $document_category;
        $replaces_post_id = Sign_Docs_Document_Service::valid_replaces_post_id(isset($_POST['replaces_post_id']) ? absint($_POST['replaces_post_id']) : 0);

        $post_id = Sign_Docs_Document_Service::create_from_local_pdf(
            $tmp_name,
            array(
                'post_title' => isset($_POST['post_title']) ? (string) wp_unslash($_POST['post_title']) : '',
                'full_title' => isset($_POST['full_title']) ? (string) wp_unslash($_POST['full_title']) : '',
                'document_category' => $document_category,
                'document_type_label' => isset($_POST['document_type_label']) ? (string) wp_unslash($_POST['document_type_label']) : '',
                'document_type_term_id' => isset($_POST['document_type_term_id']) ? absint($_POST['document_type_term_id']) : 0,
                'document_institution' => $document_institution,
                'document_comment' => isset($_POST['document_comment']) ? (string) wp_unslash($_POST['document_comment']) : '',
                'document_date' => isset($_POST['document_date']) ? (string) wp_unslash($_POST['document_date']) : '',
                'document_number' => isset($_POST['document_number']) ? (string) wp_unslash($_POST['document_number']) : '',
                'document_subject' => isset($_POST['document_subject']) ? (string) wp_unslash($_POST['document_subject']) : '',
                'academic_year' => isset($_POST['academic_year']) ? (string) wp_unslash($_POST['academic_year']) : '',
                'include_subject_quotes_in_title' => isset($_POST['include_subject_quotes_in_title']) ? '1' : '0',
                'signer_name' => $save_unsigned ? '' : $settings['signer_name'],
                'signer_position' => $save_unsigned ? '' : $settings['signer_position'],
                'signer_organization' => $save_unsigned ? '' : $settings['signer_organization'],
                'stamp_corner' => $settings['stamp_corner'],
                'stamp_color' => $settings['stamp_color'],
                'stamp_opacity' => $settings['stamp_opacity'],
                'stamp_font_size' => $settings['stamp_font_size'],
                'stamp_width_mm' => $settings['stamp_width_mm'],
                'stamp_border_enabled' => $settings['stamp_border_enabled'],
                'qr_logo_enabled' => $settings['qr_logo_enabled'],
                'source_filename' => $source_name,
                'document_status' => $save_unsigned ? 'unsigned' : 'active',
                'document_version' => $replaces_post_id > 0 ? 0 : 1,
                'defer_stamped' => $save_unsigned,
                'replaces_post_id' => $replaces_post_id,
            )
        );

        if (is_wp_error($post_id)) {
            self::redirect_upload_error($post_id->get_error_code());
        }

        $return_to = isset($_POST['return_to']) ? esc_url_raw((string) wp_unslash($_POST['return_to'])) : '';
        if ('' !== $return_to) {
            wp_safe_redirect(self::upload_return_url($return_to, (int) $post_id));
            exit;
        }

        wp_safe_redirect(
            add_query_arg(
                array_filter(
                    array(
                        'created' => (int) $post_id,
                        'unsigned' => $save_unsigned ? 1 : null,
                    )
                ),
                admin_url('edit.php?post_type=' . Sign_Docs_Post_Type::POST_TYPE . '&page=sign-docs-upload')
            )
        );
        exit;
    }

    private static function upload_return_url(string $return_to, int $post_id): string
    {
        $url = str_replace('__SIGN_DOCS_CREATED_ID__', (string) $post_id, $return_to);
        $url = add_query_arg('sign_docs_document_id', $post_id, $url);

        return wp_validate_redirect($url, admin_url('post.php'));
    }

    /**
     * @param array<string,string> $columns
     * @return array<string,string>
     */
    public static function columns(array $columns): array
    {
        unset($columns['taxonomy-sign_doc_type']);

        $columns['sign_docs_status'] = __('Status', 'sign-docs');

        return $columns;
    }

    public static function column_content(string $column, int $post_id): void
    {
        if ('sign_docs_status' === $column) {
            echo esc_html(self::status_label(Sign_Docs_Meta::get($post_id, 'document_status')));
            return;
        }

        if ('sign_docs_signed_at' === $column) {
            echo esc_html(Sign_Docs_Meta::get($post_id, 'signed_at'));
            return;
        }

        if ('sign_docs_hash' === $column) {
            $hash = Sign_Docs_Meta::get($post_id, 'sha256_hash');
            echo esc_html($hash ? substr($hash, 0, 16) . '...' : '');
        }
    }

    public static function taxonomy_filters(string $post_type): void
    {
        if (Sign_Docs_Post_Type::POST_TYPE !== $post_type) {
            return;
        }

        foreach (array('sign_doc_category', 'sign_doc_type', 'sign_doc_institution', 'sign_doc_department') as $taxonomy) {
            self::render_taxonomy_filter($taxonomy);
        }
    }

    private static function render_taxonomy_filter(string $taxonomy): void
    {
        $taxonomy_object = get_taxonomy($taxonomy);
        if (! $taxonomy_object) {
            return;
        }

        $terms = get_terms(
            array(
                'taxonomy' => $taxonomy,
                'hide_empty' => false,
                'fields' => 'ids',
                'number' => 1,
            )
        );

        if (is_wp_error($terms) || empty($terms)) {
            return;
        }

        $selected = isset($_GET[$taxonomy]) ? sanitize_text_field((string) wp_unslash($_GET[$taxonomy])) : '';

        wp_dropdown_categories(
            array(
                'show_option_all' => $taxonomy_object->labels->all_items,
                'taxonomy' => $taxonomy,
                'name' => $taxonomy,
                'orderby' => 'name',
                'selected' => $selected,
                'hierarchical' => true,
                'depth' => 3,
                'show_count' => true,
                'hide_empty' => false,
                'value_field' => 'slug',
            )
        );
    }

    public static function meta_boxes(WP_Post $post): void
    {
        add_meta_box(
            'sign-docs-document-data',
            'Данные документа',
            array(self::class, 'render_document_data_box'),
            Sign_Docs_Post_Type::POST_TYPE,
            'normal',
            'high'
        );

        add_meta_box(
            'sign-docs-file-data',
            'Файлы и проверка',
            array(self::class, 'render_file_data_box'),
            Sign_Docs_Post_Type::POST_TYPE,
            'normal',
            'high'
        );

        add_meta_box(
            'sign-docs-stamp-data',
            'Параметры штампа',
            array(self::class, 'render_stamp_data_box'),
            Sign_Docs_Post_Type::POST_TYPE,
            'side',
            'default'
        );
    }

    public static function render_document_data_box(WP_Post $post): void
    {
        $post_id = (int) $post->ID;

        self::render_meta_table(
            array(
                'Название' => Sign_Docs_Meta::get($post_id, 'full_title') ?: get_the_title($post_id),
                'Комментарий' => Sign_Docs_Meta::get($post_id, 'document_comment') ?: $post->post_content,
                'Категория' => self::term_names($post_id, 'sign_doc_category'),
                'Вид документа' => Sign_Docs_Meta::get($post_id, 'document_type_label'),
                'Институция' => Sign_Docs_Meta::get($post_id, 'document_institution'),
                'Дата документа' => Sign_Docs_Meta::get($post_id, 'document_date'),
                'Номер документа' => Sign_Docs_Meta::get($post_id, 'document_number'),
                'О чем документ' => Sign_Docs_Meta::get($post_id, 'document_subject'),
                'Год / период' => Sign_Docs_Meta::get($post_id, 'academic_year'),
                'Статус документа' => self::status_label(Sign_Docs_Meta::get($post_id, 'document_status')),
                'Версия документа' => Sign_Docs_Meta::get($post_id, 'document_version'),
                'Заменяет документ' => self::document_link_value(absint(Sign_Docs_Meta::get($post_id, 'replaces_post_id'))),
                'Заменен документом' => self::document_link_value(absint(Sign_Docs_Meta::get($post_id, 'replaced_by_post_id'))),
                'Комментарий к замене' => Sign_Docs_Meta::get($post_id, 'replacement_note'),
                'Дата и время подписи' => Sign_Docs_Meta::get($post_id, 'signed_at'),
                'Подписант' => Sign_Docs_Meta::get($post_id, 'signer_name'),
                'Должность' => Sign_Docs_Meta::get($post_id, 'signer_position'),
                'Организация' => Sign_Docs_Meta::get($post_id, 'signer_organization'),
                'Пользователь' => self::user_label(Sign_Docs_Meta::get($post_id, 'signer_user_id')),
            )
        );
    }

    public static function render_file_data_box(WP_Post $post): void
    {
        $post_id = (int) $post->ID;
        $original_url = Sign_Docs_Meta::get($post_id, 'original_file_url');
        $stamped_url = Sign_Docs_Meta::get($post_id, 'stamped_file_url');
        $verification_url = Sign_Docs_Meta::get($post_id, 'verification_url') ?: Sign_Docs_Verification_Page::url($post_id);

        self::render_meta_table(
            array(
                'Страница проверки' => self::link_value($verification_url, 'Открыть страницу проверки'),
                'Публичная PDF-копия' => self::link_value($stamped_url, 'Открыть PDF с отметкой'),
                'Исходная контрольная копия' => self::link_value($original_url, 'Открыть исходный PDF'),
                'SHA-256 исходного PDF' => Sign_Docs_Meta::get($post_id, 'sha256_hash'),
                'SHA-256 публичной PDF-копии' => Sign_Docs_Meta::get($post_id, 'stamped_file_hash'),
                'QR-code data' => Sign_Docs_Meta::get($post_id, 'qr_code_data'),
                'Имя исходного файла' => Sign_Docs_Meta::get($post_id, 'source_filename'),
                'Размер файла' => self::file_size_label(Sign_Docs_Meta::get($post_id, 'file_size')),
                'MIME type' => Sign_Docs_Meta::get($post_id, 'mime_type'),
                'Публичная копия сохранена' => Sign_Docs_Meta::get($post_id, 'completed_at'),
                'Кто сохранил публичную копию' => self::user_label(Sign_Docs_Meta::get($post_id, 'completed_by_user_id')),
            )
        );
    }

    public static function render_stamp_data_box(WP_Post $post): void
    {
        $post_id = (int) $post->ID;

        self::render_meta_table(
            array(
                'Угол' => Sign_Docs_Meta::get($post_id, 'stamp_corner'),
                'Цвет' => Sign_Docs_Meta::get($post_id, 'stamp_color'),
                'Прозрачность' => Sign_Docs_Meta::get($post_id, 'stamp_opacity'),
                'Размер шрифта' => Sign_Docs_Meta::get($post_id, 'stamp_font_size') . ' pt',
                'Длина штампа' => Sign_Docs_Meta::get($post_id, 'stamp_width_mm') . ' мм',
                'Рамка' => self::yes_no(Sign_Docs_Meta::get($post_id, 'stamp_border_enabled')),
                'Расположение' => self::stamp_placement_label($post_id),
                'Логотип в QR' => self::yes_no(Sign_Docs_Meta::get($post_id, 'qr_logo_enabled')),
            ),
            true
        );
    }

    /**
     * @param array<string,string> $actions
     * @return array<string,string>
     */
    public static function row_actions(array $actions, WP_Post $post): array
    {
        if (Sign_Docs_Post_Type::POST_TYPE !== $post->post_type) {
            return $actions;
        }

        unset($actions['trash'], $actions['delete']);

        $document_status = Sign_Docs_Meta::get((int) $post->ID, 'document_status');

        if (self::can_replace_document($document_status) && current_user_can('edit_post', (int) $post->ID)) {
            $actions['replace'] = sprintf(
                '<a href="%s">%s</a>',
                esc_url(self::replace_url((int) $post->ID)),
                esc_html__('Заменить', 'sign-docs')
            );
        }

        if ('archived' !== $document_status && current_user_can('edit_post', (int) $post->ID)) {
            $actions['archive'] = sprintf(
                '<a href="%s">%s</a>',
                esc_url(self::archive_url((int) $post->ID)),
                esc_html__('Архивировать', 'sign-docs')
            );
        }

        return $actions;
    }

    /**
     * @param array<string,string> $actions
     * @return array<string,string>
     */
    public static function bulk_actions(array $actions): array
    {
        unset($actions['trash'], $actions['delete']);

        return $actions;
    }

    public static function submitbox_archive_action(): void
    {
        global $post;

        if (! $post instanceof WP_Post || Sign_Docs_Post_Type::POST_TYPE !== $post->post_type) {
            return;
        }

        $document_status = Sign_Docs_Meta::get((int) $post->ID, 'document_status');

        if (! current_user_can('edit_post', (int) $post->ID)) {
            return;
        }

        ?>
        <?php if (self::can_replace_document($document_status)) : ?>
            <div class="misc-pub-section">
                <a href="<?php echo esc_url(self::replace_url((int) $post->ID)); ?>">
                    <?php echo esc_html__('Заменить новым PDF', 'sign-docs'); ?>
                </a>
            </div>
        <?php endif; ?>
        <?php if ('archived' === $document_status) : ?>
            <?php return; ?>
        <?php endif; ?>
        <div class="misc-pub-section">
            <a class="submitdelete" href="<?php echo esc_url(self::archive_url((int) $post->ID)); ?>">
                <?php echo esc_html__('Архивировать документ', 'sign-docs'); ?>
            </a>
        </div>
        <?php
    }

    public static function hide_trash_action(): void
    {
        $screen = get_current_screen();

        if (! $screen || Sign_Docs_Post_Type::POST_TYPE !== $screen->post_type) {
            return;
        }

        echo '<style>#delete-action{display:none;}</style>';
    }

    public static function handle_archive(): void
    {
        $post_id = isset($_GET['post_id']) ? absint($_GET['post_id']) : 0;

        if ($post_id <= 0 || Sign_Docs_Post_Type::POST_TYPE !== get_post_type($post_id) || ! current_user_can('edit_post', $post_id)) {
            wp_die(esc_html__('You are not allowed to archive this document.', 'sign-docs'));
        }

        check_admin_referer('sign_docs_archive_' . (string) $post_id);
        self::archive_document($post_id);

        wp_safe_redirect(
            add_query_arg(
                array('archived' => $post_id),
                admin_url('edit.php?post_type=' . Sign_Docs_Post_Type::POST_TYPE)
            )
        );
        exit;
    }

    /**
     * @param mixed $trash
     * @return mixed
     */
    public static function archive_instead_of_trash($trash, WP_Post $post)
    {
        if (Sign_Docs_Post_Type::POST_TYPE !== $post->post_type) {
            return $trash;
        }

        if (Sign_Docs_Document_Service::is_rollback_delete_in_progress()) {
            return $trash;
        }

        self::archive_document((int) $post->ID);

        return false;
    }

    /**
     * @param mixed $delete
     * @return mixed
     */
    public static function archive_instead_of_delete($delete, WP_Post $post, bool $force_delete)
    {
        if (Sign_Docs_Post_Type::POST_TYPE !== $post->post_type) {
            return $delete;
        }

        if (Sign_Docs_Document_Service::is_rollback_delete_in_progress()) {
            return $delete;
        }

        self::archive_document((int) $post->ID);

        return false;
    }

    /**
     * @return array<string,string>|array<int,string>
     */
    public static function document_type_terms_for_select(): array
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
                'term_id' => (int) $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
                'category' => $parent_categories[$parent_slug] ?? 'other-document',
            );
        }

        usort(
            $items,
            static function (array $a, array $b): int {
                $category_order = array('local-act' => 0, 'external-regulation' => 1, 'other-document' => 2);
                $type_order = array(
                    'local-order' => 0,
                    'local-regulation' => 1,
                    'local-rule' => 2,
                    'local-program' => 3,
                    'external-order' => 10,
                    'external-directive' => 11,
                    'external-resolution' => 12,
                    'external-federal-law' => 13,
                    'other-document-type' => 20,
                );
                $category_compare = ($category_order[$a['category']] ?? 99) <=> ($category_order[$b['category']] ?? 99);

                if (0 !== $category_compare) {
                    return $category_compare;
                }

                $type_compare = ($type_order[$a['slug']] ?? 99) <=> ($type_order[$b['slug']] ?? 99);

                return 0 !== $type_compare ? $type_compare : strnatcasecmp((string) $a['name'], (string) $b['name']);
            }
        );

        return $items;
    }

    /**
     * @return array<string,string>|array<int,string>
     */
    public static function terms_for_select(string $taxonomy): array
    {
        $terms = get_terms(
            array(
                'taxonomy' => $taxonomy,
                'hide_empty' => false,
                'orderby' => 'name',
            )
        );

        if (is_wp_error($terms) || ! is_array($terms)) {
            $terms = array();
        }

        $items = array();
        foreach ($terms as $term) {
            if (! $term instanceof WP_Term) {
                continue;
            }

            if ('sign_doc_category' === $taxonomy) {
                $items[$term->slug] = $term->name;
                continue;
            }

            $items[] = $term->name;
        }

        if ('sign_doc_category' === $taxonomy) {
            $defaults = array(
                'local-act' => 'Локальный акт',
                'external-regulation' => 'Внешний нормативный документ',
                'other-document' => 'Прочий документ',
            );
            $ordered = array();

            foreach ($defaults as $slug => $label) {
                $ordered[$slug] = isset($items[$slug]) ? $items[$slug] : $label;
            }

            return $ordered + $items;
        }

        if ('sign_doc_type' === $taxonomy) {
            $ordered = array('Приказ', 'Положение', 'Распоряжение', 'Постановление', 'Федеральный закон');
            $extra = array_values(array_diff($items, $ordered));

            return array_merge($ordered, $extra);
        }

        return $items;
    }

    private static function term_names(int $post_id, string $taxonomy): string
    {
        $terms = get_the_terms($post_id, $taxonomy);
        if (is_wp_error($terms) || empty($terms)) {
            return '';
        }

        return implode(
            ', ',
            array_map(
                static function (WP_Term $term): string {
                    return $term->name;
                },
                $terms
            )
        );
    }

    /**
     * @param array<string,string> $rows
     */
    private static function render_meta_table(array $rows, bool $compact = false): void
    {
        $class = $compact ? 'widefat striped' : 'widefat striped';
        ?>
        <table class="<?php echo esc_attr($class); ?>">
            <tbody>
                <?php foreach ($rows as $label => $value) : ?>
                    <tr>
                        <th scope="row" style="width: 220px;"><?php echo esc_html($label); ?></th>
                        <td><?php echo '' !== $value ? wp_kses_post($value) : '&mdash;'; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private static function link_value(string $url, string $label): string
    {
        if ('' === $url) {
            return '';
        }

        return sprintf(
            '<a href="%s" target="_blank" rel="noopener">%s</a><br><code>%s</code>',
            esc_url($url),
            esc_html($label),
            esc_html($url)
        );
    }

    private static function document_link_value(int $post_id): string
    {
        if ($post_id <= 0 || Sign_Docs_Post_Type::POST_TYPE !== get_post_type($post_id)) {
            return '';
        }

        return sprintf(
            '<a href="%s">%s</a>',
            esc_url(get_edit_post_link($post_id, '')),
            esc_html(get_the_title($post_id))
        );
    }

    private static function file_size_label(string $bytes): string
    {
        $size = absint($bytes);

        return $size > 0 ? size_format($size, 2) : '';
    }

    private static function user_label(string $user_id): string
    {
        $user = get_user_by('id', absint($user_id));
        if (! $user instanceof WP_User) {
            return '';
        }

        return $user->display_name . ' (#' . (string) $user->ID . ')';
    }

    private static function yes_no(string $value): string
    {
        return '0' === $value ? 'Нет' : 'Да';
    }

    private static function stamp_placement_label(int $post_id): string
    {
        if ('manual' !== Sign_Docs_Meta::get($post_id, 'stamp_placement_mode')) {
            return 'Угол из настроек';
        }

        $x = Sign_Docs_Meta::get($post_id, 'stamp_manual_x');
        $y = Sign_Docs_Meta::get($post_id, 'stamp_manual_y');

        return 'Вручную: ' . $x . ', ' . $y;
    }

    private static function status_label(string $status): string
    {
        $labels = array(
            'active' => 'Действующий',
            'unsigned' => 'Без подписи',
            'archive' => 'Архив',
            'archived' => 'Архив',
            'replaced' => 'Заменен',
            'deleted' => 'Архив',
            'draft' => 'Черновик',
            'needs_public_copy' => 'Ожидает публичную копию',
        );

        return $labels[$status] ?? ($status ?: 'Действующий');
    }

    private static function archive_url(int $post_id): string
    {
        return wp_nonce_url(
            admin_url('admin-post.php?action=sign_docs_archive&post_id=' . (string) $post_id),
            'sign_docs_archive_' . (string) $post_id
        );
    }

    private static function replace_url(int $post_id): string
    {
        return add_query_arg(
            array('replaces' => $post_id),
            admin_url('edit.php?post_type=' . Sign_Docs_Post_Type::POST_TYPE . '&page=sign-docs-upload')
        );
    }

    private static function can_replace_document(string $status): bool
    {
        return ! in_array($status, array('archived', 'archive', 'deleted', 'replaced', 'needs_public_copy'), true);
    }

    private static function archive_document(int $post_id): void
    {
        update_post_meta($post_id, 'document_status', 'archived');
    }

    private static function redirect_upload_error(string $error): void
    {
        wp_safe_redirect(
            add_query_arg(
                array('sign_docs_error' => sanitize_key($error)),
                admin_url('edit.php?post_type=' . Sign_Docs_Post_Type::POST_TYPE . '&page=sign-docs-upload')
            )
        );
        exit;
    }

    private static function error_message(string $error): string
    {
        $messages = array(
            'missing_file' => __('Choose a PDF file.', 'sign-docs'),
            'upload_failed' => __('The file upload failed.', 'sign-docs'),
            'sign_docs_source_unreadable' => __('The uploaded PDF is not readable.', 'sign-docs'),
            'sign_docs_invalid_mime' => __('Only PDF files can be signed.', 'sign-docs'),
            'sign_docs_original_copy_failed' => __('Failed to save the original PDF.', 'sign-docs'),
            'sign_docs_stamped_copy_failed' => __('Failed to save the public PDF copy.', 'sign-docs'),
            'sign_docs_browser_signing_required' => __('Подписание PDF требует браузерной обработки. Включите JavaScript и проверьте bundled PDF-библиотеки в assets/vendor.', 'sign-docs'),
            'sign_docs_hash_failed' => __('Failed to calculate SHA-256 for the original PDF.', 'sign-docs'),
        );

        return $messages[$error] ?? __('The document could not be signed.', 'sign-docs');
    }

    private static function site_icon_url(): string
    {
        return Sign_Docs_Site_Icon::url();
    }
}
