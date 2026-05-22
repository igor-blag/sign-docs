<?php
/**
 * Taxonomy registration.
 *
 * @package SignDocs
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class Sign_Docs_Taxonomies
{
    public static function register(): void
    {
        self::register_taxonomy(
            'sign_doc_category',
            __('Document categories', 'sign-docs'),
            __('Document category', 'sign-docs'),
            'document-category'
        );

        self::register_taxonomy(
            'sign_doc_type',
            __('Document types', 'sign-docs'),
            __('Document type', 'sign-docs'),
            'document-type'
        );

        self::register_taxonomy(
            'sign_doc_department',
            __('Departments', 'sign-docs'),
            __('Department', 'sign-docs'),
            'document-department'
        );

        self::register_taxonomy(
            'sign_doc_institution',
            __('Institutions', 'sign-docs'),
            __('Institution', 'sign-docs'),
            'document-institution',
            false
        );

        self::ensure_default_terms();
    }

    private static function register_taxonomy(string $taxonomy, string $plural, string $single, string $slug, bool $hierarchical = true): void
    {
        register_taxonomy(
            $taxonomy,
            Sign_Docs_Post_Type::POST_TYPE,
            array(
                'labels' => array(
                    'name' => $plural,
                    'singular_name' => $single,
                    'search_items' => sprintf(__('Search %s', 'sign-docs'), $plural),
                    'all_items' => sprintf(__('All %s', 'sign-docs'), $plural),
                    'edit_item' => sprintf(__('Edit %s', 'sign-docs'), $single),
                    'update_item' => sprintf(__('Update %s', 'sign-docs'), $single),
                    'add_new_item' => sprintf(__('Add new %s', 'sign-docs'), $single),
                ),
                'public' => false,
                'show_ui' => true,
                'show_in_menu' => false,
                'show_admin_column' => true,
                'show_in_rest' => true,
                'hierarchical' => $hierarchical,
                'rewrite' => array('slug' => $slug),
            )
        );
    }

    private static function ensure_default_terms(): void
    {
        self::ensure_terms(
            'sign_doc_category',
            array(
                array('slug' => 'local-act', 'name' => 'Локальный акт'),
                array('slug' => 'external-regulation', 'name' => 'Внешний нормативный документ'),
                array('slug' => 'other-document', 'name' => 'Прочий документ'),
            )
        );

        self::ensure_terms(
            'sign_doc_type',
            array(
                array('slug' => 'local-acts', 'name' => 'Локальные акты'),
                array('slug' => 'local-order', 'name' => 'Приказ', 'parent' => 'local-acts'),
                array('slug' => 'local-regulation', 'name' => 'Положение', 'parent' => 'local-acts'),
                array('slug' => 'local-rule', 'name' => 'Правила', 'parent' => 'local-acts'),
                array('slug' => 'local-program', 'name' => 'Программа', 'parent' => 'local-acts'),
                array('slug' => 'external-regulations', 'name' => 'Внешние нормативные документы'),
                array('slug' => 'external-order', 'name' => 'Приказ', 'parent' => 'external-regulations'),
                array('slug' => 'external-directive', 'name' => 'Распоряжение', 'parent' => 'external-regulations'),
                array('slug' => 'external-resolution', 'name' => 'Постановление', 'parent' => 'external-regulations'),
                array('slug' => 'external-federal-law', 'name' => 'Федеральный закон', 'parent' => 'external-regulations'),
                array('slug' => 'other-documents', 'name' => 'Прочие документы'),
                array('slug' => 'other-document-type', 'name' => 'Иное', 'parent' => 'other-documents'),
            )
        );

        self::delete_unused_legacy_type_terms();
    }

    /**
     * @param array<int,array{slug:string,name:string,parent?:string}> $terms
     */
    private static function ensure_terms(string $taxonomy, array $terms): void
    {
        foreach ($terms as $term) {
            if (term_exists($term['slug'], $taxonomy)) {
                continue;
            }

            $args = array('slug' => $term['slug']);
            if (! empty($term['parent'])) {
                $parent = term_exists($term['parent'], $taxonomy);
                if (is_array($parent) && isset($parent['term_id'])) {
                    $args['parent'] = (int) $parent['term_id'];
                }
            }

            wp_insert_term($term['name'], $taxonomy, $args);
        }
    }

    private static function delete_unused_legacy_type_terms(): void
    {
        foreach (array('order', 'regulation', 'directive', 'resolution', 'federal-law') as $slug) {
            $term = get_term_by('slug', $slug, 'sign_doc_type');
            if (! $term instanceof WP_Term || (int) $term->count > 0) {
                continue;
            }

            wp_delete_term((int) $term->term_id, 'sign_doc_type');
        }
    }
}
