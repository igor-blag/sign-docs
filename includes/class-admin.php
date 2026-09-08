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
    private const STATUS_FILTER_KEY = 'sign_docs_status_filter';
    private const ARCHIVED_STATUSES = array('archived', 'archive', 'deleted');

    private static bool $permanent_delete_in_progress = false;

    public static function is_permanent_delete_in_progress(): bool
    {
        return self::$permanent_delete_in_progress;
    }

    public static function enqueue_assets(string $hook_suffix): void
    {
        if ('sign-docs_page_sign-docs-upload' !== $hook_suffix) {
            return;
        }

        $layout_path = SIGN_DOCS_PLUGIN_DIR . 'assets/js/stamp-layout.js';
        wp_enqueue_script(
            'sign-docs-stamp-layout',
            SIGN_DOCS_PLUGIN_URL . 'assets/js/stamp-layout.js',
            array('wp-i18n'),
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

        $admin_asset_path = SIGN_DOCS_PLUGIN_DIR . 'assets/js/admin-upload.js';
        $admin_version = file_exists($admin_asset_path) ? SIGN_DOCS_VERSION . '-' . (string) filemtime($admin_asset_path) : SIGN_DOCS_VERSION;

        $dependencies = array('wp-i18n', 'sign-docs-stamp-layout', 'sign-docs-stamp-ui');
        $config = array(
            'prepareUrl' => rest_url('sign-docs/v1/prepare'),
            'completeUrl' => rest_url('sign-docs/v1/complete'),
            'suggestMetadataUrl' => rest_url('sign-docs/v1/suggest-metadata'),
            'nonce' => wp_create_nonce('wp_rest'),
            'hasVendor' => $has_vendor,
            'aiAutofillEnabled' => '1' === $settings['ai_autofill_enabled'],
            'hasPdfJs' => $has_pdfjs,
            'siteIconUrl' => self::site_icon_url(),
            'pdfJs' => $has_pdfjs ? array(
                'module' => SIGN_DOCS_PLUGIN_URL . 'assets/vendor/pdf.min.mjs',
                'worker' => SIGN_DOCS_PLUGIN_URL . 'assets/vendor/pdf.worker.min.mjs',
            ) : null,
            'titleRules' => Sign_Docs_Title_Template::client_config(),
        );

        if ($has_vendor) {
            wp_enqueue_script('sign-docs-pdf-lib', SIGN_DOCS_PLUGIN_URL . 'assets/vendor/pdf-lib.min.js', array(), (string) filemtime($pdf_lib_path), true);
            wp_enqueue_script('sign-docs-qrcode', SIGN_DOCS_PLUGIN_URL . 'assets/vendor/qrcode.min.js', array(), (string) filemtime($qr_path), true);
            wp_enqueue_script('sign-docs-fontkit', SIGN_DOCS_PLUGIN_URL . 'assets/vendor/fontkit.umd.min.js', array(), (string) filemtime($fontkit_path), true);
            $dependencies[] = 'sign-docs-pdf-lib';
            $dependencies[] = 'sign-docs-qrcode';
            $dependencies[] = 'sign-docs-fontkit';
            $config['fonts'] = array(
                'regular' => SIGN_DOCS_PLUGIN_URL . 'assets/vendor/GolosText-Regular.ttf',
                'medium' => SIGN_DOCS_PLUGIN_URL . 'assets/vendor/GolosText-Medium.ttf',
            );
        }

        wp_enqueue_script(
            'sign-docs-admin-upload',
            SIGN_DOCS_PLUGIN_URL . 'assets/js/admin-upload.js',
            $dependencies,
            $admin_version,
            true
        );

        wp_localize_script('sign-docs-admin-upload', 'SignDocsUpload', $config);
        wp_set_script_translations('sign-docs-stamp-layout', 'sign-docs', SIGN_DOCS_PLUGIN_DIR . 'languages');
        wp_set_script_translations('sign-docs-admin-upload', 'sign-docs', SIGN_DOCS_PLUGIN_DIR . 'languages');
    }

    public static function menu(): void
    {
        add_submenu_page(
            'edit.php?post_type=' . Sign_Docs_Post_Type::POST_TYPE,
            __('Add document', 'sign-docs'),
            __('Add document', 'sign-docs'),
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
            __('Sign Docs Settings', 'sign-docs'),
            __('Settings', 'sign-docs'),
            'manage_options',
            'sign-docs-settings',
            array(Sign_Docs_Settings::class, 'render_page')
        );

        add_submenu_page(
            'edit.php?post_type=' . Sign_Docs_Post_Type::POST_TYPE,
            __('Title matrix', 'sign-docs'),
            __('Title matrix', 'sign-docs'),
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
            <h1><?php echo esc_html__('Add document', 'sign-docs'); ?></h1>

            <?php if ($created > 0) : ?>
                <div class="notice notice-success">
                    <p>
                        <?php echo esc_html(isset($_GET['unsigned']) ? __('The document was saved without a signature.', 'sign-docs') : __('The document is signed and registered.', 'sign-docs')); ?>
                        <a href="<?php echo esc_url(Sign_Docs_Verification_Page::url($created)); ?>" target="_blank" rel="noopener">
                            <?php echo esc_html__('Open verification page', 'sign-docs'); ?>
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
                    <?php echo esc_html__('Signer details, organization and stamp parameters are taken from the', 'sign-docs'); ?>
                    <a href="<?php echo esc_url(admin_url('edit.php?post_type=' . Sign_Docs_Post_Type::POST_TYPE . '&page=sign-docs-settings')); ?>"><?php echo esc_html__('Sign Docs settings', 'sign-docs'); ?></a>.
                </p>
            </div>

            <?php if ($replaces_post_id > 0) : ?>
                <div class="notice notice-warning">
                    <p>
                        <?php echo esc_html__('The new document will replace:', 'sign-docs'); ?>
                        <a href="<?php echo esc_url(get_edit_post_link($replaces_post_id, '')); ?>">
                            <?php echo esc_html(get_the_title($replaces_post_id)); ?>
                        </a>
                        <?php echo esc_html__('After a successful signature the previous record receives the "Replaced" status.', 'sign-docs'); ?>
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
                <input type="hidden" name="stamp_border_enabled" value="<?php echo esc_attr($settings['stamp_border_enabled']); ?>">
                <input type="hidden" name="stamp_padding" value="<?php echo esc_attr($settings['stamp_padding']); ?>">
                <input type="hidden" name="stamp_qr_gap" value="<?php echo esc_attr($settings['stamp_qr_gap']); ?>">
                <input type="hidden" name="stamp_qr_padding" value="<?php echo esc_attr($settings['stamp_qr_padding']); ?>">
                <input type="hidden" name="stamp_qr_size" value="<?php echo esc_attr($settings['stamp_qr_size']); ?>">
                <input type="hidden" name="stamp_qr_ec_level" value="<?php echo esc_attr($settings['stamp_qr_ec_level']); ?>">
                <input type="hidden" name="stamp_page" value="<?php echo esc_attr($settings['stamp_page']); ?>">
                <input type="hidden" name="stamp_footer_enabled" value="<?php echo esc_attr($settings['stamp_footer_enabled']); ?>">
                <input type="hidden" name="stamp_footer_border_enabled" value="<?php echo esc_attr($settings['stamp_footer_border_enabled']); ?>">
                <input type="hidden" name="stamp_footer_font_size" value="<?php echo esc_attr($settings['stamp_footer_font_size']); ?>">
                <input type="hidden" name="stamp_footer_opacity" value="<?php echo esc_attr($settings['stamp_footer_opacity']); ?>">
                <input type="hidden" name="stamp_footer_position" value="<?php echo esc_attr($settings['stamp_footer_position']); ?>">
                <input type="hidden" name="stamp_line_spacing" value="<?php echo esc_attr($settings['stamp_line_spacing']); ?>">
                <input type="hidden" name="stamp_rows" value="<?php echo esc_attr($settings['stamp_rows']); ?>">
                <input type="hidden" name="stamp_qr_enabled" value="<?php echo esc_attr($settings['stamp_qr_enabled']); ?>">
                <input type="hidden" name="stamp_qr_position" value="<?php echo esc_attr($settings['stamp_qr_position']); ?>">
                <input type="hidden" name="qr_logo_enabled" value="<?php echo esc_attr($settings['qr_logo_enabled']); ?>">
                <input type="hidden" name="stamp_placement_mode" value="corner">
                <input type="hidden" name="stamp_manual_x" value="">
                <input type="hidden" name="stamp_manual_y" value="">
                <input type="hidden" name="return_to" value="<?php echo esc_attr($return_to); ?>">
                <input type="hidden" name="default_institution" value="<?php echo esc_attr($settings['signer_organization']); ?>">
                <input type="hidden" name="replaces_post_id" value="<?php echo esc_attr((string) $replaces_post_id); ?>">

                <div class="sign-docs-upload-actions sign-docs-upload-actions--top">
                    <button type="submit" class="button button-primary sign-docs-save-signed" name="sign_docs_save_mode" value="signed"><?php echo esc_html__('Save and sign document', 'sign-docs'); ?></button>
                    <button type="submit" class="button button-secondary sign-docs-save-unsigned" name="sign_docs_save_mode" value="unsigned"><?php echo esc_html__('Save without signature', 'sign-docs'); ?></button>
                </div>

                <div class="sign-docs-upload-layout">
                    <div class="sign-docs-upload-fields">
                        <table class="form-table" role="presentation">
                            <tbody>
                                <tr>
                                    <th scope="row">
                                        <label for="sign-docs-pdf"><?php echo esc_html__('Source PDF', 'sign-docs'); ?></label>
                                    </th>
                                    <td>
                                        <label class="sign-docs-file-dropzone" for="sign-docs-pdf">
                                            <span>
                                                <strong id="sign-docs-file-dropzone-title"><?php echo esc_html__('Drop PDF here', 'sign-docs'); ?></strong>
                                                <span id="sign-docs-file-dropzone-text"><?php echo esc_html__('or click to choose a file', 'sign-docs'); ?></span>
                                            </span>
                                            <input id="sign-docs-pdf" name="sign_docs_pdf" type="file" accept="application/pdf,.pdf" required>
                                        </label>
                                        <p class="description"><?php echo esc_html__('The source file is stored unchanged; the SHA-256 is calculated on the server.', 'sign-docs'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="sign-docs-category"><?php echo esc_html__('Category', 'sign-docs'); ?></label>
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
                                        <label for="sign-docs-document-type"><?php echo esc_html__('Document type', 'sign-docs'); ?></label>
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
                                        <label for="sign-docs-institution"><?php echo esc_html__('Institution', 'sign-docs'); ?></label>
                                    </th>
                                    <td>
                                        <select id="sign-docs-institution-select" class="regular-text" style="max-width: 25em;">
                                            <option value=""><?php echo esc_html__('Choose from directory', 'sign-docs'); ?></option>
                                            <?php foreach ($institution_terms as $name) : ?>
                                                <option value="<?php echo esc_attr($name); ?>"><?php echo esc_html($name); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <p style="margin: 12px 0 4px;">
                                            <label for="sign-docs-institution"><?php echo esc_html__('Or enter a new short authority name', 'sign-docs'); ?></label>
                                        </p>
                                        <input id="sign-docs-institution" name="document_institution" type="text" class="regular-text" list="sign-docs-institutions" autocomplete="off" placeholder="<?php echo esc_attr__('Enter the name in the genitive case', 'sign-docs'); ?>">
                                        <datalist id="sign-docs-institutions">
                                            <?php foreach ($institution_terms as $name) : ?>
                                                <option value="<?php echo esc_attr($name); ?>"></option>
                                            <?php endforeach; ?>
                                        </datalist>
                                        <p class="description"><?php echo esc_html__('Choose an existing institution from the directory or type a new one in the field below.', 'sign-docs'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="sign-docs-document-date"><?php echo esc_html__('Date and number', 'sign-docs'); ?></label>
                                    </th>
                                    <td>
                                        <div class="sign-docs-date-number">
                                            <input id="sign-docs-document-date" name="document_date" type="text" class="regular-text" placeholder="20.05.2026">
                                            <input id="sign-docs-document-number" name="document_number" type="text" class="regular-text" placeholder="<?php echo esc_attr__('183-R', 'sign-docs'); ?>">
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="sign-docs-title"><?php echo esc_html__('Title', 'sign-docs'); ?></label>
                                    </th>
                                    <td>
                                        <textarea id="sign-docs-title" name="post_title" class="large-text" rows="3"></textarea>
                                        <p class="description">
                                            <?php echo esc_html__('The title of the WordPress record.', 'sign-docs'); ?>
                                            <button type="button" id="sign-docs-add-institution-to-title" class="button button-small" style="margin-left:8px;vertical-align:middle;">
                                                <?php echo esc_html__('Add institution name', 'sign-docs'); ?>
                                            </button>
                                        </p>
                                        <p class="sign-docs-case-actions" style="margin:4px 0 0;">
                                            <button type="button" id="sign-docs-sentence-case"><?php echo esc_html__('Sentence case', 'sign-docs'); ?></button>
                                        </p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php echo esc_html__('Year / period', 'sign-docs'); ?></th>
                                    <td>
                                        <p class="sign-docs-year-actions" aria-label="<?php echo esc_attr__('Year autofill', 'sign-docs'); ?>"></p>
                                        <p class="description"><?php echo esc_html__('Click a period button to add it to the title.', 'sign-docs'); ?></p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div id="sign-docs-pdf-preview" class="sign-docs-upload-preview" style="display:none;">
                        <h2 style="margin-bottom: 8px;"><?php echo esc_html__('Preview', 'sign-docs'); ?></h2>
                        <p class="description" style="margin: 0 0 10px;"><?php echo esc_html__('A local copy of the selected PDF is shown. The file is uploaded only after the form is submitted.', 'sign-docs'); ?></p>
                        <p style="margin: 0 0 10px;">
                            <button type="button" class="button" id="sign-docs-stamp-pick"><?php echo esc_html__('Pick stamp position', 'sign-docs'); ?></button>
                            <button type="button" class="button" id="sign-docs-stamp-reset" hidden><?php echo esc_html__('Reset position', 'sign-docs'); ?></button>
                            <span id="sign-docs-stamp-placement-status" class="description" style="margin-left: 8px;"><?php echo esc_html__('The corner from settings is used.', 'sign-docs'); ?></span>
                        </p>
                        <div id="sign-docs-preview-frame-wrap" style="position: relative; width: 100%; height: 620px; border: 1px solid #c3c4c7; background: #fff; overflow: hidden;">
                            <iframe
                                title="<?php echo esc_attr__('Preview of the selected PDF', 'sign-docs'); ?>"
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
                    <button id="sign-docs-save-signed" type="submit" class="button button-primary sign-docs-save-signed" name="sign_docs_save_mode" value="signed"><?php echo esc_html__('Save and sign document', 'sign-docs'); ?></button>
                    <button id="sign-docs-save-unsigned" type="submit" class="button button-secondary sign-docs-save-unsigned" name="sign_docs_save_mode" value="unsigned"><?php echo esc_html__('Save without signature', 'sign-docs'); ?></button>
                </div>

                <div style="margin-top:12px;">
                    <label for="sign-docs-document-comment" style="font-weight:600;display:block;margin-bottom:4px;"><?php echo esc_html__('Comment', 'sign-docs'); ?></label>
                    <textarea id="sign-docs-document-comment" name="document_comment" class="large-text" rows="1" placeholder="<?php echo esc_attr__('Internal note for the administrator', 'sign-docs'); ?>"></textarea>
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
                'stamp_border_enabled' => $settings['stamp_border_enabled'],
                'stamp_padding' => $settings['stamp_padding'],
                'stamp_qr_gap' => $settings['stamp_qr_gap'],
                'stamp_qr_padding' => $settings['stamp_qr_padding'],
                'stamp_line_spacing' => $settings['stamp_line_spacing'],
                'stamp_rows' => $settings['stamp_rows'],
                'stamp_qr_enabled' => $settings['stamp_qr_enabled'],
                'stamp_qr_position' => $settings['stamp_qr_position'],
                'stamp_qr_size' => $settings['stamp_qr_size'],
                'stamp_qr_ec_level' => $settings['stamp_qr_ec_level'],
                'stamp_page' => $settings['stamp_page'],
                'stamp_footer_enabled' => $settings['stamp_footer_enabled'],
                'stamp_footer_border_enabled' => $settings['stamp_footer_border_enabled'],
                'stamp_footer_font_size' => $settings['stamp_footer_font_size'],
                'stamp_footer_opacity' => $settings['stamp_footer_opacity'],
                'stamp_footer_position' => $settings['stamp_footer_position'],
                'qr_logo_enabled' => $settings['qr_logo_enabled'],
                'source_filename' => $source_name,
                'document_status' => 'unsigned',
                'document_version' => $replaces_post_id > 0 ? 0 : 1,
                'defer_stamped' => true,
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
                array(
                    'created' => (int) $post_id,
                    'unsigned' => 1,
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

    public static function apply_status_filter(WP_Query $query): void
    {
        if (! is_admin() || ! $query->is_main_query()) {
            return;
        }

        if (Sign_Docs_Post_Type::POST_TYPE !== $query->get('post_type')) {
            return;
        }

        $filter = isset($_GET[self::STATUS_FILTER_KEY]) ? sanitize_key((string) wp_unslash($_GET[self::STATUS_FILTER_KEY])) : '';

        if ('all' === $filter) {
            return;
        }

        if ('archived' === $filter) {
            $query->set(
                'meta_query',
                array(
                    array(
                        'key' => 'document_status',
                        'value' => self::ARCHIVED_STATUSES,
                        'compare' => 'IN',
                    ),
                )
            );
            return;
        }

        $query->set(
            'meta_query',
            array(
                'relation' => 'AND',
                array(
                    'relation' => 'OR',
                    array(
                        'key' => 'document_status',
                        'compare' => 'NOT EXISTS',
                    ),
                    array(
                        'key' => 'document_status',
                        'value' => self::ARCHIVED_STATUSES,
                        'compare' => 'NOT IN',
                    ),
                ),
            )
        );
    }

    /**
     * @param array<string,string> $views
     * @return array<string,string>
     */
    public static function views_array(array $views): array
    {
        $screen = get_current_screen();

        if (! $screen || Sign_Docs_Post_Type::POST_TYPE !== $screen->post_type) {
            return $views;
        }

        $counts = self::status_view_counts();
        $current = isset($_GET[self::STATUS_FILTER_KEY]) ? sanitize_key((string) wp_unslash($_GET[self::STATUS_FILTER_KEY])) : '';
        $base = admin_url('edit.php?post_type=' . Sign_Docs_Post_Type::POST_TYPE);

        $tabs = array(
            'active' => array(
                'url' => $base,
                'label' => __('Current', 'sign-docs'),
                'count' => $counts['active'],
            ),
            'archived' => array(
                'url' => add_query_arg(self::STATUS_FILTER_KEY, 'archived', $base),
                'label' => __('Archived', 'sign-docs'),
                'count' => $counts['archived'],
            ),
            'all' => array(
                'url' => add_query_arg(self::STATUS_FILTER_KEY, 'all', $base),
                'label' => __('All', 'sign-docs'),
                'count' => $counts['all'],
            ),
        );

        $is_current = 'all' === $current ? 'all' : ('archived' === $current ? 'archived' : 'active');
        $views = array();

        foreach ($tabs as $key => $tab) {
            $class = $is_current === $key ? 'current' : '';
            $views[$key] = sprintf(
                '<a href="%1$s" class="%2$s">%3$s <span class="count">(%4$s)</span></a>',
                esc_url($tab['url']),
                esc_attr($class),
                esc_html($tab['label']),
                esc_html((string) $tab['count'])
            );
        }

        return $views;
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
            __('Document data', 'sign-docs'),
            array(self::class, 'render_document_data_box'),
            Sign_Docs_Post_Type::POST_TYPE,
            'normal',
            'high'
        );

        add_meta_box(
            'sign-docs-file-data',
            __('Files and verification', 'sign-docs'),
            array(self::class, 'render_file_data_box'),
            Sign_Docs_Post_Type::POST_TYPE,
            'normal',
            'high'
        );

        add_meta_box(
            'sign-docs-stamp-data',
            __('Stamp parameters', 'sign-docs'),
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
                __('Title', 'sign-docs') => get_the_title($post_id),
                __('Comment', 'sign-docs') => Sign_Docs_Meta::get($post_id, 'document_comment') ?: $post->post_content,
                __('Category', 'sign-docs') => self::term_names($post_id, 'sign_doc_category'),
                __('Document type', 'sign-docs') => Sign_Docs_Meta::get($post_id, 'document_type_label'),
                __('Institution', 'sign-docs') => Sign_Docs_Meta::get($post_id, 'document_institution'),
                __('Document date', 'sign-docs') => Sign_Docs_Meta::get($post_id, 'document_date'),
                __('Document number', 'sign-docs') => Sign_Docs_Meta::get($post_id, 'document_number'),
                __('Subject', 'sign-docs') => Sign_Docs_Meta::get($post_id, 'document_subject'),
                __('Year / period', 'sign-docs') => Sign_Docs_Meta::get($post_id, 'academic_year'),
                __('Status', 'sign-docs') => self::status_label(Sign_Docs_Meta::get($post_id, 'document_status')),
                __('Version', 'sign-docs') => Sign_Docs_Meta::get($post_id, 'document_version'),
                __('Replaces', 'sign-docs') => self::document_link_value(absint(Sign_Docs_Meta::get($post_id, 'replaces_post_id'))),
                __('Replaced by', 'sign-docs') => self::document_link_value(absint(Sign_Docs_Meta::get($post_id, 'replaced_by_post_id'))),
                __('Replacement comment', 'sign-docs') => Sign_Docs_Meta::get($post_id, 'replacement_note'),
                __('Signing date and time', 'sign-docs') => Sign_Docs_Meta::get($post_id, 'signed_at'),
                __('Signer', 'sign-docs') => Sign_Docs_Meta::get($post_id, 'signer_name'),
                __('Position', 'sign-docs') => Sign_Docs_Meta::get($post_id, 'signer_position'),
                __('Organization', 'sign-docs') => Sign_Docs_Meta::get($post_id, 'signer_organization'),
                __('User', 'sign-docs') => self::user_label(Sign_Docs_Meta::get($post_id, 'signer_user_id')),
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
                __('Verification page', 'sign-docs') => self::link_value($verification_url, __('Open verification page', 'sign-docs')),
                __('Public PDF copy', 'sign-docs') => self::link_value($stamped_url, __('Open the stamped PDF', 'sign-docs')),
                __('Original control copy', 'sign-docs') => self::link_value($original_url, __('Open the original PDF', 'sign-docs')),
                __('SHA-256 of the original PDF', 'sign-docs') => Sign_Docs_Meta::get($post_id, 'sha256_hash'),
                __('SHA-256 of the public PDF copy', 'sign-docs') => Sign_Docs_Meta::get($post_id, 'stamped_file_hash'),
                __('QR-code data', 'sign-docs') => Sign_Docs_Meta::get($post_id, 'qr_code_data'),
                __('Original file name', 'sign-docs') => Sign_Docs_Meta::get($post_id, 'source_filename'),
                __('File size', 'sign-docs') => self::file_size_label(Sign_Docs_Meta::get($post_id, 'file_size')),
                __('MIME type', 'sign-docs') => Sign_Docs_Meta::get($post_id, 'mime_type'),
                __('Public copy saved', 'sign-docs') => Sign_Docs_Meta::get($post_id, 'completed_at'),
                __('Saved by', 'sign-docs') => self::user_label(Sign_Docs_Meta::get($post_id, 'completed_by_user_id')),
            )
        );
    }

    public static function render_stamp_data_box(WP_Post $post): void
    {
        $post_id = (int) $post->ID;

        self::render_meta_table(
            array(
                __('Corner', 'sign-docs') => Sign_Docs_Meta::get($post_id, 'stamp_corner'),
                __('Color', 'sign-docs') => Sign_Docs_Meta::get($post_id, 'stamp_color'),
                __('Opacity', 'sign-docs') => Sign_Docs_Meta::get($post_id, 'stamp_opacity'),
                __('Font size', 'sign-docs') => Sign_Docs_Meta::get($post_id, 'stamp_font_size') . ' pt',
                __('Padding from the border', 'sign-docs') => Sign_Docs_Meta::get($post_id, 'stamp_padding') . ' pt',
                __('QR gap', 'sign-docs') => Sign_Docs_Meta::get($post_id, 'stamp_qr_gap') . ' pt',
                __('QR padding', 'sign-docs') => Sign_Docs_Meta::get($post_id, 'stamp_qr_padding') . ' pt',
                __('Line spacing', 'sign-docs') => Sign_Docs_Meta::get($post_id, 'stamp_line_spacing') . ' ×',
                __('Border', 'sign-docs') => self::yes_no(Sign_Docs_Meta::get($post_id, 'stamp_border_enabled')),
                __('Rows', 'sign-docs') => self::stamp_rows_label(Sign_Docs_Meta::get($post_id, 'stamp_rows')),
                __('QR code', 'sign-docs') => self::yes_no(Sign_Docs_Meta::get($post_id, 'stamp_qr_enabled')),
                __('QR position', 'sign-docs') => self::qr_position_label(Sign_Docs_Meta::get($post_id, 'stamp_qr_position')),
                __('Placement', 'sign-docs') => self::stamp_placement_label($post_id),
                __('Logo in QR', 'sign-docs') => self::yes_no(Sign_Docs_Meta::get($post_id, 'qr_logo_enabled')),
            ),
            true
        );
    }

    private static function stamp_rows_label(string $rows): string
    {
        $keys = Sign_Docs_Settings::sanitize_stamp_rows($rows);
        $labels = Sign_Docs_Settings::row_labels();

        return implode(
            ', ',
            array_map(
                static function (string $key) use ($labels): string {
                    return $labels[$key] ?? $key;
                },
                explode(',', $keys)
            )
        );
    }

    private static function qr_position_label(string $position): string
    {
        return 'below' === $position ? __('Below the text', 'sign-docs') : __('Right of the text', 'sign-docs');
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
                esc_html__('Replace', 'sign-docs')
            );
        }

        if ('archived' !== $document_status && current_user_can('edit_post', (int) $post->ID)) {
            $actions['archive'] = sprintf(
                '<a href="%s">%s</a>',
                esc_url(self::archive_url((int) $post->ID)),
                esc_html__('Archive', 'sign-docs')
            );
        }

        if (self::is_archived_status($document_status) && current_user_can('delete_post', (int) $post->ID)) {
            $actions['delete_forever'] = sprintf(
                '<a href="%1$s" class="submitdelete" onclick="return confirm(\'%2$s\');">%3$s</a>',
                esc_url(self::delete_url((int) $post->ID)),
                esc_js(__('Delete the document forever? This action cannot be undone.', 'sign-docs')),
                esc_html__('Delete forever', 'sign-docs')
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
        $actions['archive'] = __('Archive', 'sign-docs');

        $status_filter = isset($_GET[self::STATUS_FILTER_KEY]) ? sanitize_key((string) wp_unslash($_GET[self::STATUS_FILTER_KEY])) : '';
        if ('archived' === $status_filter) {
            $actions['delete_forever'] = __('Delete forever', 'sign-docs');
        }

        return $actions;
    }

    /**
     * @param string $redirect
     * @param string $doaction
     * @param list<int> $post_ids
     */
    public static function handle_bulk_archive(string $redirect, string $doaction, array $post_ids): string
    {
        if ('archive' !== $doaction) {
            return $redirect;
        }

        $archived = 0;

        foreach ($post_ids as $post_id) {
            $post_id = absint($post_id);

            if ($post_id <= 0 || Sign_Docs_Post_Type::POST_TYPE !== get_post_type($post_id) || ! current_user_can('edit_post', $post_id)) {
                continue;
            }

            self::archive_document($post_id);
            $archived++;
        }

        return add_query_arg('bulk_archived', $archived, $redirect);
    }

    public static function bulk_archive_notice(): void
    {
        $screen = get_current_screen();

        if (! $screen || Sign_Docs_Post_Type::POST_TYPE !== $screen->post_type) {
            return;
        }

        $count = isset($_GET['bulk_archived']) ? absint($_GET['bulk_archived']) : 0;

        if ($count > 0) {
            echo '<div class="notice notice-success"><p>' . esc_html(sprintf(/* translators: %d = number of documents */ _n('Archived %d document.', 'Archived %d documents.', $count, 'sign-docs'), $count)) . '</p></div>';
        }
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
                    <?php echo esc_html__('Replace with a new PDF', 'sign-docs'); ?>
                </a>
            </div>
        <?php endif; ?>
        <?php if ('archived' === $document_status) : ?>
            <?php return; ?>
        <?php endif; ?>
        <div class="misc-pub-section">
            <a class="submitdelete" href="<?php echo esc_url(self::archive_url((int) $post->ID)); ?>">
                <?php echo esc_html__('Archive document', 'sign-docs'); ?>
            </a>
        </div>
        <?php if (self::is_archived_status($document_status) && current_user_can('delete_post', (int) $post->ID)) : ?>
            <div class="misc-pub-section">
                <a class="submitdelete delete-forever" href="<?php echo esc_url(self::delete_url((int) $post->ID)); ?>" onclick="return confirm('<?php echo esc_js(__('Delete the document forever? This action cannot be undone.', 'sign-docs')); ?>');">
                    <?php echo esc_html__('Delete forever', 'sign-docs'); ?>
                </a>
            </div>
        <?php endif; ?>
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

    public static function handle_delete(): void
    {
        $post_id = isset($_GET['post_id']) ? absint($_GET['post_id']) : 0;

        if ($post_id <= 0 || Sign_Docs_Post_Type::POST_TYPE !== get_post_type($post_id) || ! current_user_can('delete_post', $post_id)) {
            wp_die(esc_html__('You are not allowed to delete this document.', 'sign-docs'));
        }

        if (! self::is_archived_status(Sign_Docs_Meta::get($post_id, 'document_status'))) {
            wp_die(esc_html__('Only archived documents can be deleted.', 'sign-docs'));
        }

        check_admin_referer('sign_docs_delete_' . (string) $post_id);
        self::permanently_delete($post_id);

        wp_safe_redirect(
            add_query_arg(
                array('deleted' => $post_id),
                admin_url('edit.php?post_type=' . Sign_Docs_Post_Type::POST_TYPE)
            )
        );
        exit;
    }

    /**
     * @return bool
     */
    public static function permanently_delete(int $post_id): bool
    {
        if ($post_id <= 0) {
            return false;
        }

        self::$permanent_delete_in_progress = true;

        try {
            $deleted = wp_delete_post($post_id, true);
        } finally {
            self::$permanent_delete_in_progress = false;
        }

        if (! $deleted) {
            return false;
        }

        Sign_Docs_Storage::delete_document_files($post_id);

        return true;
    }

    /**
     * @param string $redirect
     * @param string $doaction
     * @param list<int> $post_ids
     */
    public static function handle_bulk_delete(string $redirect, string $doaction, array $post_ids): string
    {
        if ('delete_forever' !== $doaction) {
            return $redirect;
        }

        $deleted = 0;

        foreach ($post_ids as $post_id) {
            $post_id = absint($post_id);

            if ($post_id <= 0 || Sign_Docs_Post_Type::POST_TYPE !== get_post_type($post_id) || ! current_user_can('delete_post', $post_id)) {
                continue;
            }

            if (! self::is_archived_status(Sign_Docs_Meta::get($post_id, 'document_status'))) {
                continue;
            }

            if (self::permanently_delete($post_id)) {
                $deleted++;
            }
        }

        return add_query_arg('bulk_deleted', $deleted, $redirect);
    }

    public static function deleted_notice(): void
    {
        $screen = get_current_screen();

        if (! $screen || Sign_Docs_Post_Type::POST_TYPE !== $screen->post_type) {
            return;
        }

        $single = isset($_GET['deleted']) ? absint($_GET['deleted']) : 0;
        $bulk = isset($_GET['bulk_deleted']) ? absint($_GET['bulk_deleted']) : 0;
        $count = $bulk > 0 ? $bulk : $single;

        if ($count > 0) {
            echo '<div class="notice notice-success"><p>' . esc_html(sprintf(/* translators: %d = number of documents */ _n('Deleted %d document.', 'Deleted %d documents.', $count, 'sign-docs'), $count)) . '</p></div>';
        }
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

        if (self::$permanent_delete_in_progress || Sign_Docs_Document_Service::is_rollback_delete_in_progress()) {
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

        if (self::$permanent_delete_in_progress || Sign_Docs_Document_Service::is_rollback_delete_in_progress()) {
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
                'local-act' => __('Local act', 'sign-docs'),
                'external-regulation' => __('External regulatory document', 'sign-docs'),
                'other-document' => __('Other document', 'sign-docs'),
            );
            $ordered = array();

            foreach ($defaults as $slug => $label) {
                $ordered[$slug] = isset($items[$slug]) ? $items[$slug] : $label;
            }

            return $ordered + $items;
        }

        if ('sign_doc_type' === $taxonomy) {
            $ordered = array(__('Order', 'sign-docs'), __('Regulation', 'sign-docs'), __('Directive', 'sign-docs'), __('Resolution', 'sign-docs'), __('Federal law', 'sign-docs'));
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
        return '0' === $value ? __('No', 'sign-docs') : __('Yes', 'sign-docs');
    }

    private static function stamp_placement_label(int $post_id): string
    {
        if ('manual' !== Sign_Docs_Meta::get($post_id, 'stamp_placement_mode')) {
            return __('Corner from settings', 'sign-docs');
        }

        $x = Sign_Docs_Meta::get($post_id, 'stamp_manual_x');
        $y = Sign_Docs_Meta::get($post_id, 'stamp_manual_y');

        return sprintf(/* translators: %1$s and %2$s = stamp coordinates */ __('Manually: %1$s, %2$s', 'sign-docs'), $x, $y);
    }

    private static function status_label(string $status): string
    {
        $labels = array(
            'active' => __('Active', 'sign-docs'),
            'unsigned' => __('Unsigned', 'sign-docs'),
            'archive' => __('Archived', 'sign-docs'),
            'archived' => __('Archived', 'sign-docs'),
            'replaced' => __('Replaced', 'sign-docs'),
            'deleted' => __('Archived', 'sign-docs'),
            'draft' => __('Draft', 'sign-docs'),
            'needs_public_copy' => __('Needs public copy', 'sign-docs'),
        );

        return $labels[$status] ?? ($status ?: __('Active', 'sign-docs'));
    }

    private static function archive_url(int $post_id): string
    {
        return wp_nonce_url(
            admin_url('admin-post.php?action=sign_docs_archive&post_id=' . (string) $post_id),
            'sign_docs_archive_' . (string) $post_id
        );
    }

    private static function delete_url(int $post_id): string
    {
        return wp_nonce_url(
            admin_url('admin-post.php?action=sign_docs_delete&post_id=' . (string) $post_id),
            'sign_docs_delete_' . (string) $post_id
        );
    }

    private static function replace_url(int $post_id): string
    {
        return add_query_arg(
            array('replaces' => $post_id),
            admin_url('edit.php?post_type=' . Sign_Docs_Post_Type::POST_TYPE . '&page=sign-docs-upload')
        );
    }

    private static function is_archived_status(string $status): bool
    {
        return in_array($status, self::ARCHIVED_STATUSES, true);
    }

    /**
     * @return array{all:int,active:int,archived:int}
     */
    private static function status_view_counts(): array
    {
        global $wpdb;

        $placeholders = implode(',', array_fill(0, count(self::ARCHIVED_STATUSES), '%s'));
        $all = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('trash', 'auto-draft')",
                Sign_Docs_Post_Type::POST_TYPE
            )
        );

        $archived = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'document_status'
                 WHERE p.post_type = %s
                   AND p.post_status NOT IN ('trash', 'auto-draft')
                   AND pm.meta_value IN ($placeholders)",
                array_merge(array(Sign_Docs_Post_Type::POST_TYPE), self::ARCHIVED_STATUSES)
            )
        );

        return array(
            'all' => $all,
            'active' => max(0, $all - $archived),
            'archived' => $archived,
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
            'sign_docs_browser_signing_required' => __('Signing the PDF requires browser processing. Enable JavaScript and check the bundled PDF libraries in assets/vendor.', 'sign-docs'),
            'sign_docs_hash_failed' => __('Failed to calculate SHA-256 for the original PDF.', 'sign-docs'),
        );

        return $messages[$error] ?? __('The document could not be signed.', 'sign-docs');
    }

    private static function site_icon_url(): string
    {
        return Sign_Docs_Site_Icon::url();
    }
}
