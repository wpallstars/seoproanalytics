<?php
/**
 * Reviewed page changes with full-value backups and conflict-aware undo.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 * Provenance: wpas-c8662019b99fb07b. Copies, even of part, keep these lines.
 *
 * @package SEOProAnalytics
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProAnalytics_Change_Sets {

    const STATUSES = array(1 => 'applied', 2 => 'partly applied', 3 => 'undone', 4 => 'partly undone');

    /**
     * Check a plan against current WordPress values, without writing anything.
     *
     * @param array<string,mixed> $plan Name and pages with [now, new] pairs.
     * @return array<string,mixed>|WP_Error
     */
    public static function preview(array $plan) {
        require_once __DIR__ . '/class-seoproanalytics-changes.php';
        require_once __DIR__ . '/class-seoproanalytics-coverage.php';
        require_once __DIR__ . '/class-seoproanalytics-experiments.php';
        if (!SEOProAnalytics_API::can_manage()) {
            return self::error(__('Managing experiments requires an administrator. In WP-CLI, use --user.', 'seoproanalytics'), 403);
        }
        if (!isset($plan['name'], $plan['pages']) || !is_string($plan['name']) || trim($plan['name']) === '' || !is_array($plan['pages']) || !$plan['pages'] || count($plan['pages']) > SEOProAnalytics_Experiments::MAX_PAGES) {
            return self::error(__('Give a name and between 1 and 50 pages.', 'seoproanalytics'));
        }
        $seen = array();
        $pages = array();
        $keys = self::seo_keys();
        foreach ($plan['pages'] as $page) {
            if (!is_array($page) || !isset($page['post']) || !is_numeric($page['post']) || (int) $page['post'] <= 0 || (string) (int) $page['post'] !== (string) $page['post'] || isset($seen[(int) $page['post']])) {
                return self::error(__('Each page needs a unique positive post id.', 'seoproanalytics'));
            }
            $id = (int) $page['post'];
            $seen[$id] = true;
            $post = get_post($id);
            $reason = !$post || !SEOProAnalytics_Changes::is_public($post) ? __('Not a viewable post.', 'seoproanalytics') : '';
            if (!current_user_can('edit_post', $id)) {
                $reason = __('You cannot edit this page.', 'seoproanalytics');
            }
            $items = array();
            // SEO fields first: post_updated then sees the complete new metadata.
            foreach (array('seo_title', 'seo_description', 'title') as $field) {
                if (!array_key_exists($field, $page)) {
                    continue;
                }
                $pair = $page[$field];
                if (!is_array($pair) || array_keys($pair) !== array(0, 1) || !is_string($pair[0]) || !is_string($pair[1])) {
                    return self::error(__('Each field must be a pair of strings: [now, new].', 'seoproanalytics'));
                }
                if ($field !== 'title' && !isset($keys[$field])) {
                    $reason = __('SEO fields need active Rank Math, Yoast SEO or SEOPress.', 'seoproanalytics');
                }
                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- backup data, not a meta query.
                $item = array('post' => $id, 'field' => $field, 'meta_key' => $keys[$field] ?? '', 'old' => '', 'new' => $pair[1], 'result' => 'pending');
                if ($post && current_user_can('edit_post', $id) && ($field === 'title' || isset($keys[$field]))) {
                    $item['old'] = self::read_value($item);
                    $item['old_exists'] = $field === 'title' || metadata_exists('post', $id, $item['meta_key']);
                    $item['new'] = self::stored_value($item, $pair[1]);
                    if ($item['old'] !== self::stored_value($item, $pair[0])) {
                        $reason = sprintf(/* translators: %s: field name */ __('Current value differs: %s.', 'seoproanalytics'), $field);
                    }
                }
                $items[] = $item;
            }
            if (!$items || array_diff(array_keys($page), array('post', 'title', 'seo_title', 'seo_description'))) {
                return self::error(__('Use only post, title, seo_title and seo_description, with at least one field.', 'seoproanalytics'));
            }
            $pages[] = array('post' => $id, 'url' => $post ? (string) get_permalink($post) : '', 'reason' => $reason, 'items' => $items);
        }
        return array('name' => mb_substr(sanitize_text_field($plan['name']), 0, 190), 'pages' => $pages);
    }

    /** @return array<string,string> Active plugin keys, read from the change log's registry. */
    private static function seo_keys() {
        $plugin = SEOProAnalytics_Coverage::seo_plugin();
        $prefix = SEOProAnalytics_Coverage::META_PREFIX[$plugin] ?? '';
        $keys = array();
        if ($prefix === '' || $plugin === 'aioseo') {
            return $keys;
        }
        foreach (SEOProAnalytics_Changes::SEO_META as $key => $spec) {
            if (strpos($key, $prefix) === 0 && in_array($spec[0], array(10, 11), true)) {
                $keys[$spec[0] === 10 ? 'seo_title' : 'seo_description'] = $key;
            }
        }
        return $keys;
    }

    /**
     * WordPress's stored form for this acting user (including title KSES).
     * @param array<string,mixed> $item Field.
     * @param string $value Proposed text.
     * @return string
     */
    private static function stored_value(array $item, $value) {
        if ($item['field'] === 'title') {
            return wp_unslash((string) sanitize_post_field('post_title', wp_slash($value), $item['post'], 'db'));
        }
        return (string) sanitize_meta($item['meta_key'], $value, 'post', (string) get_post_type($item['post']));
    }

    /** @param array<string,mixed> $item Field. @return string */
    private static function read_value(array $item) {
        // An ordinary editor request does not share our advisory lock or caches.
        wp_cache_delete($item['post'], 'posts');
        wp_cache_delete($item['post'], 'post_meta');
        return $item['field'] === 'title' ? (string) get_post_field('post_title', $item['post'], 'raw') : (string) get_post_meta($item['post'], $item['meta_key'], true);
    }

    /**
     * Apply a checked plan, saving its backup before the first write.
     * @param array<string,mixed> $plan Plan.
     * @param array<string,mixed> $options dry_run, experiment and experiment options.
     * @return array<string,mixed>|WP_Error
     */
    public static function apply(array $plan, array $options = array()) {
        if (!empty($options['dry_run'])) {
            return self::preview($plan);
        }
        return self::live_locked(static function () use ($plan, $options) {
            $preview = self::preview($plan);
            if (is_wp_error($preview)) {
                return $preview;
            }
            if (!SEOProAnalytics_Schema::maybe_upgrade()) {
                return self::error(__('The change set table could not be prepared.', 'seoproanalytics'), 500);
            }
            return self::apply_pages($preview, $options);
        });
    }

    /**
     * Serialize change-set writers; always restore the caller's data selection.
     * @param callable $work Operation.
     * @return mixed
     */
    private static function live_locked(callable $work) {
        global $wpdb;
        $before = SEOProAnalytics_Schema::use_set('live');
        $lock = 'seoproanalytics_change_sets_' . get_current_blog_id();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- connection-owned advisory lock, not cached.
        $locked = (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock)) === 1;
        try {
            return $locked ? $work() : self::error(__('Another change set is being written. Try again.', 'seoproanalytics'), 409);
        } finally {
            if ($locked) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- release this connection's lock.
                $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
            }
            SEOProAnalytics_Schema::use_set($before);
        }
    }

    /**
     * @param array<string,mixed> $preview Checked pages.
     * @param array<string,mixed> $options Experiment options.
     * @return array<string,mixed>|WP_Error
     */
    private static function apply_pages(array $preview, array $options) {
        global $wpdb;
        $items = array();
        foreach ($preview['pages'] as $page) {
            foreach ($page['items'] as $item) {
                $item['url'] = $page['url'];
                $item['result'] = $page['reason'] === '' ? 'pending' : 'skipped';
                $item['reason'] = $page['reason'];
                $items[] = $item;
            }
        }
        $created = time();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- durable backup in our own table.
        $saved = $wpdb->insert(SEOProAnalytics_Schema::table('change_sets'), array('created' => $created, 'user_id' => get_current_user_id(), 'name' => $preview['name'], 'status' => 2, 'items' => wp_json_encode($items)), array('%d', '%d', '%s', '%d', '%s'));
        if (!$saved) {
            return self::error(__('The backup could not be saved; no page was changed.', 'seoproanalytics'), 500);
        }
        $id = (int) $wpdb->insert_id;
        $tag = self::tagger($id);
        add_filter('seoproanalytics_record_change', $tag);
        $paths = array();
        $checked = array();
        $skipped = array();
        try {
            foreach ($items as $index => $item) {
                if ($item['result'] === 'skipped') {
                    continue;
                }
                if (!empty($skipped[$item['post']])) {
                    $items[$index]['result'] = 'skipped';
                    $items[$index]['reason'] = __('The page changed after preview or is no longer editable.', 'seoproanalytics');
                    continue;
                }
                $first = !isset($checked[$item['post']]);
                $checked[$item['post']] = true;
                $items[$index]['result'] = 'writing';
                if (!self::save($id, $items, 2)) {
                    return self::error(__('The backup could not be updated. The partly applied set can be undone.', 'seoproanalytics'), 500);
                }
                $written = self::guarded_write($item, false, $first ? $items : array());
                if (is_wp_error($written) && $written->get_error_code() === 'seoproanalytics_change_set_stale_page') {
                    $skipped[$item['post']] = true;
                    $items[$index]['result'] = 'skipped';
                    $items[$index]['reason'] = $written->get_error_message();
                    continue;
                }
                $ok = !is_wp_error($written) && self::page_verified($items, $item['post']) && (string) get_permalink($item['post']) === $item['url'];
                $items[$index]['result'] = $ok ? 'applied' : 'failed';
                $items[$index]['reason'] = $ok ? '' : __('Read-back differed or the page address moved. The run stopped.', 'seoproanalytics');
                if (!self::save($id, $items, 2) || !$ok) {
                    break;
                }
                $paths[$item['post']] = SEOProAnalytics_Changes::path($item['url']);
            }
        } finally {
            remove_filter('seoproanalytics_record_change', $tag);
        }
        $complete = count(array_filter($items, static function ($item) { return $item['result'] !== 'applied'; })) === 0;
        if (!self::save($id, $items, $complete ? 1 : 2)) {
            return self::error(__('The final change set state could not be saved.', 'seoproanalytics'), 500);
        }
        $answer = self::get($id);
        if (!empty($options['experiment']) && $paths && !is_wp_error($answer)) {
            $experiment = SEOProAnalytics_Experiments::add(array_merge($options, array('name' => $options['experiment'], 'start' => (string) $created, 'change' => 0, 'pages' => array_values($paths), 'change_set' => $id)));
            if (is_wp_error($experiment)) {
                $answer['experiment_error'] = $experiment->get_error_message();
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- by primary key.
                $linked = $wpdb->update(SEOProAnalytics_Schema::table('change_sets'), array('experiment_id' => $experiment['id']), array('id' => $id), array('%d'), array('%d'));
                $answer['experiment_id'] = $linked === false ? 0 : $experiment['id'];
                if ($linked === false) {
                    $answer['experiment_error'] = __('The experiment was created but the set link could not be saved.', 'seoproanalytics');
                }
            }
        }
        return $answer;
    }

    /**
     * Recheck every field of a page immediately before its first write.
     * @param array $items Backups.
     * @param int $post Post id.
     * @return bool
     */
    private static function page_unchanged(array $items, $post) {
        $object = get_post($post);
        if (!$object || !SEOProAnalytics_Changes::is_public($object) || !current_user_can('edit_post', $post)) {
            return false;
        }
        foreach ($items as $item) {
            if ($item['post'] === $post && (self::read_value($item) !== $item['old'] || (string) get_permalink($post) !== $item['url'])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Catch hooks that rewrite an earlier field when a later one is saved.
     * @param array $items Backups, including the current write intent.
     * @param int $post Post id.
     * @return bool
     */
    private static function page_verified(array $items, $post) {
        foreach ($items as $item) {
            if ($item['post'] === $post && in_array($item['result'], array('applied', 'writing'), true) && self::read_value($item) !== $item['new']) {
                return false;
            }
        }
        return true;
    }

    /**
     * Restore only values still equal to what this set wrote.
     * @param int $id Set id.
     * @return array<string,mixed>|WP_Error
     */
    public static function undo($id) {
        require_once __DIR__ . '/class-seoproanalytics-changes.php';
        return self::live_locked(static function () use ($id) {
            if (!SEOProAnalytics_API::can_manage()) {
                return self::error(__('Managing experiments requires an administrator. In WP-CLI, use --user.', 'seoproanalytics'), 403);
            }
            $set = self::get($id);
            if (is_wp_error($set)) {
                return $set;
            }
            $items = $set['items'];
            $partial = false;
            $tag = self::tagger($id);
            add_filter('seoproanalytics_record_change', $tag);
            try {
                foreach ($items as $index => $item) {
                    if (in_array($item['result'], array('pending', 'skipped', 'undone'), true)) {
                        continue;
                    }
                    $post = get_post($item['post']);
                    $allowed = $post && SEOProAnalytics_Changes::is_public($post) && current_user_can('edit_post', $item['post']);
                    $current = $allowed ? self::read_value($item) : null;
                    if ($allowed && $current === $item['old'] && $current !== $item['new']) {
                        // Already at the backup value, including an apply write that never landed.
                        $items[$index]['result'] = 'undone';
                        $items[$index]['reason'] = '';
                        continue;
                    }
                    if (!$allowed || $current !== $item['new']) {
                        $items[$index]['result'] = 'undo conflict';
                        $items[$index]['reason'] = __('Changed since apply, missing, or not editable; left untouched.', 'seoproanalytics');
                        $partial = true;
                        continue;
                    }
                    $url = (string) get_permalink($item['post']);
                    $written = self::guarded_write($item, true);
                    $ok = !is_wp_error($written) && self::read_value($item) === $item['old'] && (string) get_permalink($item['post']) === $url;
                    $items[$index]['result'] = $ok ? 'undone' : 'undo failed';
                    $items[$index]['reason'] = $ok ? '' : __('Restored value differed on read-back.', 'seoproanalytics');
                    // A title-save hook may have changed a field restored earlier.
                    foreach ($items as $prior => $restored) {
                        if ($restored['post'] === $item['post'] && $restored['result'] === 'undone' && self::read_value($restored) !== $restored['old']) {
                            $items[$prior]['result'] = 'undo failed';
                            $items[$prior]['reason'] = __('A later save changed this restored value. The run stopped.', 'seoproanalytics');
                            $ok = false;
                        }
                    }
                    $partial = $partial || !$ok;
                    if (!self::save($id, $items, 4)) {
                        return self::error(__('Undo state could not be saved.', 'seoproanalytics'), 500);
                    }
                    if (!$ok) {
                        break;
                    }
                }
            } finally {
                remove_filter('seoproanalytics_record_change', $tag);
            }
            if (!self::save($id, $items, $partial ? 4 : 3, time())) {
                return self::error(__('Undo state could not be saved.', 'seoproanalytics'), 500);
            }
            return self::get($id);
        });
    }

    /**
     * Lock the actual WordPress row through compare and mutation. The backup
     * and write intent are already committed, outside this short transaction.
     * Normal WordPress hooks still run; no truncated change-log value is read.
     *
     * @param array<string,mixed> $item Field backup.
     * @param bool $undo Whether restoring.
     * @param array $page_items On a page's first apply write, lock and check every planned field.
     * @return mixed
     */
    private static function guarded_write(array $item, $undo, array $page_items = array()) {
        global $wpdb;
        // phpcs:disable WordPress.DB.DirectDatabaseQuery -- indexed row locks and an explicit short write transaction, never cached.
        if ($wpdb->query('START TRANSACTION') === false) {
            return self::error(__('The field could not be locked for writing.', 'seoproanalytics'), 500);
        }
        try {
            $post = $wpdb->get_var($wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE ID = %d FOR UPDATE", $item['post']));
            $locked = $post && $wpdb->last_error === '';
            foreach ($page_items ?: array($item) as $field) {
                if ($field['post'] === $item['post'] && $field['field'] !== 'title') {
                    $wpdb->get_col($wpdb->prepare("SELECT meta_id FROM $wpdb->postmeta WHERE post_id = %d AND meta_key = %s FOR UPDATE", $item['post'], $field['meta_key']));
                    $locked = $locked && $wpdb->last_error === '';
                }
            }
            $current = self::read_value($item);
            if ($locked && $page_items && !self::page_unchanged($page_items, $item['post'])) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('seoproanalytics_change_set_stale_page', __('The page changed after preview or is no longer editable.', 'seoproanalytics'), array('status' => 409));
            }
            $object = get_post($item['post']);
            if (!$locked || !$object || !SEOProAnalytics_Changes::is_public($object) || !current_user_can('edit_post', $item['post']) || $current !== ($undo ? $item['new'] : $item['old'])) {
                $wpdb->query('ROLLBACK');
                return self::error(__('This value changed or is no longer editable; it was left untouched.', 'seoproanalytics'), 409);
            }
            $written = self::write_value($item, $undo);
            if ($wpdb->query('COMMIT') === false) {
                $wpdb->query('ROLLBACK');
                return self::error(__('The field write could not be committed. Inspect the partly applied set.', 'seoproanalytics'), 500);
            }
            return $written;
        } catch (Throwable $error) {
            $wpdb->query('ROLLBACK');
            throw $error;
        } finally {
            wp_cache_delete($item['post'], 'posts');
            wp_cache_delete($item['post'], 'post_meta');
        }
        // phpcs:enable WordPress.DB.DirectDatabaseQuery
    }

    /**
     * @param array<string,mixed> $item Field backup.
     * @param bool $undo Restore old rather than write new.
     * @return mixed
     */
    private static function write_value(array $item, $undo) {
        $value = $undo ? $item['old'] : $item['new'];
        if ($item['field'] === 'title') {
            return wp_update_post(wp_slash(array('ID' => $item['post'], 'post_title' => $value)), true);
        }
        if ($undo && empty($item['old_exists'])) {
            return delete_post_meta($item['post'], $item['meta_key']);
        }
        return update_post_meta($item['post'], $item['meta_key'], wp_slash($value));
    }

    /** @param int $id Set id. @return callable */
    private static function tagger($id) {
        return static function ($row) use ($id) {
            if (is_array($row)) {
                $row['meta']['change_set'] = $id;
            }
            return $row;
        };
    }

    /**
     * @param int $id Set id.
     * @param array $items Full backups.
     * @param int $status Status code.
     * @param int $undone Undo time.
     * @return bool
     */
    private static function save($id, array $items, $status, $undone = 0) {
        global $wpdb;
        $json = wp_json_encode($items);
        if ($json === false) {
            return false;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- by primary key, never cached.
        return $wpdb->update(SEOProAnalytics_Schema::table('change_sets'), array('items' => $json, 'status' => $status, 'undone' => $undone), array('id' => $id), array('%s', '%d', '%d'), array('%d')) !== false;
    }

    /** @param int $id Set id. @return array<string,mixed>|WP_Error */
    public static function get($id) {
        global $wpdb;
        $before = SEOProAnalytics_Schema::use_set('live');
        try {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- by primary key; current state, not cached.
            $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', SEOProAnalytics_Schema::table('change_sets'), $id), ARRAY_A);
            return $row ? self::decode($row) : self::error(__('There is no such change set.', 'seoproanalytics'), 404);
        } finally {
            SEOProAnalytics_Schema::use_set($before);
        }
    }

    /** @param int $before_id Older than this id (0: newest). @return array<string,mixed> */
    public static function list_sets($before_id = 0) {
        global $wpdb;
        $before = SEOProAnalytics_Schema::use_set('live');
        try {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- bounded primary-key range, newest first.
            $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE id < %d ORDER BY id DESC LIMIT 50', SEOProAnalytics_Schema::table('change_sets'), $before_id > 0 ? $before_id : PHP_INT_MAX), ARRAY_A);
            return array('change_sets' => array_map(array(__CLASS__, 'decode'), $rows), 'next' => count($rows) === 50 ? (int) end($rows)['id'] : null);
        } finally {
            SEOProAnalytics_Schema::use_set($before);
        }
    }

    /** @param array<string,mixed> $row Stored row. @return array<string,mixed> */
    private static function decode(array $row) {
        foreach (array('id', 'created', 'user_id', 'experiment_id', 'undone') as $key) {
            $row[$key] = (int) $row[$key];
        }
        $row['status'] = self::STATUSES[(int) $row['status']];
        $row['items'] = json_decode($row['items'], true) ?: array();
        return $row;
    }

    /** @param string $message Explanation. @param int $status HTTP status. @return WP_Error */
    private static function error($message, $status = 400) {
        return new WP_Error('seoproanalytics_change_set', $message, array('status' => $status));
    }
}
