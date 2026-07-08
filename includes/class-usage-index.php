<?php
/**
 * Index of where sign-docs/document block is used.
 *
 * @package SignDocs
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class Sign_Docs_Usage_Index
{
    private const FILTER_KEY = 'sign_docs_usage';
    private const BACKFILL_OPTION = 'sign_docs_usage_index_backfilled';

    public static function ensure_table(): void
    {
        global $wpdb;

        $table = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            document_id bigint(20) unsigned NOT NULL,
            host_post_id bigint(20) unsigned NOT NULL,
            host_post_type varchar(50) NOT NULL DEFAULT '',
            host_post_status varchar(20) NOT NULL DEFAULT '',
            updated_at datetime NOT NULL,
            PRIMARY KEY  (document_id, host_post_id),
            KEY host_post_id (host_post_id),
            KEY host_post_status (host_post_status)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function sync_on_save(int $post_id, WP_Post $post): void
    {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        if (Sign_Docs_Post_Type::POST_TYPE === $post->post_type || 'attachment' === $post->post_type) {
            return;
        }

        $document_ids = self::extract_document_ids((string) $post->post_content);
        self::replace_host_links($post_id, $post->post_type, $post->post_status, $document_ids);
    }

    public static function delete_host_links(int $post_id): void
    {
        global $wpdb;
        $wpdb->delete(self::table_name(), array('host_post_id' => $post_id), array('%d'));
    }

    public static function sync_status(int $post_id, WP_Post $post): void
    {
        if (Sign_Docs_Post_Type::POST_TYPE === $post->post_type || 'attachment' === $post->post_type) {
            return;
        }

        global $wpdb;
        $wpdb->update(
            self::table_name(),
            array(
                'host_post_status' => sanitize_key($post->post_status),
                'updated_at' => current_time('mysql'),
            ),
            array('host_post_id' => $post_id),
            array('%s', '%s'),
            array('%d')
        );
    }

    public static function sync_status_transition(string $new_status, string $old_status, WP_Post $post): void
    {
        if ($new_status === $old_status) {
            return;
        }

        self::sync_status((int) $post->ID, $post);
    }

    /**
     * @param array<string,string> $columns
     * @return array<string,string>
     */
    public static function columns(array $columns): array
    {
        $columns['sign_docs_usage'] = __('Used in', 'sign-docs');
        return $columns;
    }

    public static function column_content(string $column, int $post_id): void
    {
        if ('sign_docs_usage' !== $column) {
            return;
        }

        $usage = self::usage_rows($post_id, 3);
        $count = self::usage_count($post_id);

        if ($count <= 0) {
            echo esc_html__('Not used', 'sign-docs');
            return;
        }

        $links = array();
        foreach ($usage as $row) {
            $links[] = sprintf(
                '<a href="%s">%s</a>',
                esc_url(get_edit_post_link((int) $row['host_post_id']) ?: ''),
                esc_html((string) $row['title'])
            );
        }

        echo wp_kses_post(implode(', ', $links));

        if ($count > count($usage)) {
            echo esc_html(' +' . ($count - count($usage)));
        }
    }

    public static function render_filter(string $post_type): void
    {
        if (Sign_Docs_Post_Type::POST_TYPE !== $post_type) {
            return;
        }

        $selected = isset($_GET[self::FILTER_KEY]) ? sanitize_key((string) wp_unslash($_GET[self::FILTER_KEY])) : '';
        ?>
        <select name="<?php echo esc_attr(self::FILTER_KEY); ?>">
            <option value=""><?php echo esc_html__('Usage: all', 'sign-docs'); ?></option>
            <option value="used" <?php selected($selected, 'used'); ?>><?php echo esc_html__('Usage: used', 'sign-docs'); ?></option>
            <option value="unused" <?php selected($selected, 'unused'); ?>><?php echo esc_html__('Usage: not used', 'sign-docs'); ?></option>
            <option value="broken" <?php selected($selected, 'broken'); ?>><?php echo esc_html__('Usage: broken links', 'sign-docs'); ?></option>
        </select>
        <?php
    }

    public static function apply_filter(WP_Query $query): void
    {
        if (! is_admin() || ! $query->is_main_query()) {
            return;
        }

        if (Sign_Docs_Post_Type::POST_TYPE !== $query->get('post_type')) {
            return;
        }

        $filter = isset($_GET[self::FILTER_KEY]) ? sanitize_key((string) wp_unslash($_GET[self::FILTER_KEY])) : '';
        if (! in_array($filter, array('used', 'unused', 'broken'), true)) {
            return;
        }

        $ids = self::document_ids_for_filter($filter);
        if (empty($ids)) {
            $query->set('post__in', array(0));
            return;
        }

        $query->set('post__in', array_values(array_unique(array_map('intval', $ids))));
    }

    public static function add_meta_box(WP_Post $post): void
    {
        add_meta_box(
            'sign-docs-usage-data',
            __('Используется в записях', 'sign-docs'),
            array(self::class, 'render_meta_box'),
            Sign_Docs_Post_Type::POST_TYPE,
            'side',
            'default'
        );
    }

    public static function maybe_backfill(): void
    {
        if ('1' === get_option(self::BACKFILL_OPTION, '0')) {
            return;
        }

        global $wpdb;
        $like = '%' . $wpdb->esc_like('sign-docs/document') . '%';
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ID
                 FROM {$wpdb->posts}
                 WHERE post_content LIKE %s
                   AND post_type <> %s
                   AND post_type <> 'attachment'
                   AND post_status <> 'auto-draft'",
                $like,
                Sign_Docs_Post_Type::POST_TYPE
            )
        );

        if (is_array($ids)) {
            foreach ($ids as $id) {
                $post = get_post((int) $id);
                if ($post instanceof WP_Post) {
                    self::sync_on_save((int) $post->ID, $post);
                }
            }
        }

        update_option(self::BACKFILL_OPTION, '1', false);
    }

    public static function render_meta_box(WP_Post $post): void
    {
        $rows = self::usage_rows((int) $post->ID, 50);
        if (empty($rows)) {
            echo '<p>' . esc_html__('Блок документа пока не размещен ни в одной записи или странице.', 'sign-docs') . '</p>';
            return;
        }

        echo '<ul style="margin:0; padding-left: 16px;">';
        foreach ($rows as $row) {
            $title = (string) $row['title'];
            $type = (string) $row['host_post_type'];
            $status = (string) $row['host_post_status'];
            $label = sprintf('%s (%s, %s)', $title, $type, $status);
            $edit_url = get_edit_post_link((int) $row['host_post_id']);
            if ($edit_url) {
                echo '<li><a href="' . esc_url($edit_url) . '">' . esc_html($label) . '</a></li>';
            } else {
                echo '<li>' . esc_html($label) . '</li>';
            }
        }
        echo '</ul>';
    }

    /**
     * @return array<int,array{host_post_id:int,host_post_type:string,host_post_status:string,title:string}>
     */
    private static function usage_rows(int $document_id, int $limit): array
    {
        global $wpdb;

        $limit = max(1, $limit);
        $table = self::table_name();
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT u.host_post_id, u.host_post_type, u.host_post_status, p.post_title
                 FROM {$table} u
                 LEFT JOIN {$wpdb->posts} p ON p.ID = u.host_post_id
                 WHERE u.document_id = %d
                 ORDER BY u.updated_at DESC
                 LIMIT %d",
                $document_id,
                $limit
            ),
            ARRAY_A
        );

        if (! is_array($rows)) {
            return array();
        }

        return array_map(
            static function (array $row): array {
                $title = (string) ($row['post_title'] ?? '');
                if ('' === trim($title)) {
                    $title = __('(без названия)', 'sign-docs');
                }

                return array(
                    'host_post_id' => (int) ($row['host_post_id'] ?? 0),
                    'host_post_type' => (string) ($row['host_post_type'] ?? ''),
                    'host_post_status' => (string) ($row['host_post_status'] ?? ''),
                    'title' => $title,
                );
            },
            $rows
        );
    }

    private static function usage_count(int $document_id): int
    {
        global $wpdb;
        $table = self::table_name();
        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE document_id = %d",
                $document_id
            )
        );

        return (int) $count;
    }

    /**
     * @param array<int,int> $document_ids
     */
    private static function replace_host_links(int $host_post_id, string $post_type, string $post_status, array $document_ids): void
    {
        global $wpdb;
        $table = self::table_name();

        $wpdb->delete($table, array('host_post_id' => $host_post_id), array('%d'));
        if (empty($document_ids)) {
            return;
        }

        foreach (array_values(array_unique(array_map('intval', $document_ids))) as $document_id) {
            if ($document_id <= 0) {
                continue;
            }

            $wpdb->replace(
                $table,
                array(
                    'document_id' => $document_id,
                    'host_post_id' => $host_post_id,
                    'host_post_type' => sanitize_key($post_type),
                    'host_post_status' => sanitize_key($post_status),
                    'updated_at' => current_time('mysql'),
                ),
                array('%d', '%d', '%s', '%s', '%s')
            );
        }
    }

    /**
     * @return array<int,int>
     */
    private static function extract_document_ids(string $content): array
    {
        if ('' === trim($content) || ! has_blocks($content)) {
            return array();
        }

        $ids = array();
        $visited_refs = array();
        self::collect_from_blocks(parse_blocks($content), $ids, $visited_refs);

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * @param array<int,array<string,mixed>> $blocks
     * @param array<int,int> $ids
     * @param array<int,bool> $visited_refs
     */
    private static function collect_from_blocks(array $blocks, array &$ids, array &$visited_refs): void
    {
        foreach ($blocks as $block) {
            $name = isset($block['blockName']) ? (string) $block['blockName'] : '';
            $attrs = isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : array();

            if ('sign-docs/document' === $name && ! empty($attrs['postId'])) {
                $ids[] = absint($attrs['postId']);
            }

            if ('core/block' === $name && ! empty($attrs['ref'])) {
                $ref = absint($attrs['ref']);
                if ($ref > 0 && ! isset($visited_refs[$ref])) {
                    $visited_refs[$ref] = true;
                    $reusable = get_post($ref);
                    if ($reusable instanceof WP_Post) {
                        self::collect_from_blocks(parse_blocks((string) $reusable->post_content), $ids, $visited_refs);
                    }
                }
            }

            if (! empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                self::collect_from_blocks($block['innerBlocks'], $ids, $visited_refs);
            }
        }
    }

    /**
     * @return array<int,int>
     */
    private static function document_ids_for_filter(string $filter): array
    {
        global $wpdb;
        $table = self::table_name();

        if ('used' === $filter) {
            $ids = $wpdb->get_col(
                "SELECT DISTINCT u.document_id
                 FROM {$table} u
                 LEFT JOIN {$wpdb->posts} p ON p.ID = u.host_post_id
                 WHERE p.ID IS NOT NULL
                   AND p.post_status NOT IN ('trash', 'auto-draft')"
            );
            return array_map('intval', is_array($ids) ? $ids : array());
        }

        if ('broken' === $filter) {
            $ids = $wpdb->get_col(
                "SELECT DISTINCT u.document_id
                 FROM {$table} u
                 LEFT JOIN {$wpdb->posts} p ON p.ID = u.host_post_id
                 WHERE p.ID IS NULL
                    OR p.post_status IN ('trash', 'auto-draft')"
            );
            return array_map('intval', is_array($ids) ? $ids : array());
        }

        $all_doc_ids = get_posts(
            array(
                'post_type' => Sign_Docs_Post_Type::POST_TYPE,
                'post_status' => 'any',
                'fields' => 'ids',
                'posts_per_page' => -1,
                'no_found_rows' => true,
            )
        );

        if (! is_array($all_doc_ids) || empty($all_doc_ids)) {
            return array();
        }

        $used_ids = self::document_ids_for_filter('used');
        $used_map = array_fill_keys(array_map('intval', $used_ids), true);

        $result = array();
        foreach ($all_doc_ids as $doc_id) {
            $doc_id = (int) $doc_id;
            if (! isset($used_map[$doc_id])) {
                $result[] = $doc_id;
            }
        }

        return $result;
    }

    private static function table_name(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'sign_docs_usage';
    }
}
