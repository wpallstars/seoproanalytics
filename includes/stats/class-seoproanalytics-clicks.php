<?php
/**
 * The clicks report: what people clicked and which forms they sent
 * (autocapture), on the same ranges, comparisons, filters, data sets and
 * cache as SEOProAnalytics_Query.
 *
 * Kinds of rows:
 * - elements:  clicked elements (tag#id.class and label), with dead clicks.
 * - dead:      only clicks the page did not react to within a second.
 * - links:     link destinations clicked, flagged outbound or affiliate.
 * - downloads: file links clicked.
 * - forms:     forms sent: name, destination and number of fields.
 *
 * Totals: clicks, dead clicks, outbound, affiliate and file links, form
 * submits, and the visits with any of them. A page (path, * for any text)
 * narrows it to clicks on that page; visit filters select the visits, and
 * a page filter also narrows the clicks to that page. Clicks are kept
 * for fewer months than visits (Settings → Data), so the report reaches
 * back only as far as they do. Reads the clicks table by index `ts` or
 * `path_ts`, and visits by primary key.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 * Provenance: wpas-c8662019b99fb07b. Copies, even of part, keep these lines.
 *
 * @package SEOProAnalytics
 * @since 0.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProAnalytics_Clicks {

    /** Kinds of report rows. */
    const KINDS = array('elements', 'dead', 'links', 'downloads', 'forms', 'pages');

    /** Click flags, as the tracker sends them. */
    const DEAD      = 1;
    const OUTBOUND  = 2;
    const AFFILIATE = 4;
    const DOWNLOAD  = 8;

    /**
     * The clicks report.
     *
     * @param array<string,mixed> $req  From SEOProAnalytics_Query::request().
     * @param string              $kind One of KINDS.
     * @param string              $page Only clicks on this page (path; * for any text); '' for all.
     * @return array<string,mixed>
     */
    public static function report(array $req, $kind = 'elements', $page = '') {
        $kind = in_array($kind, self::KINDS, true) ? (string) $kind : 'elements';
        $page = trim((string) $page);
        // Version the answer shape so pre-upgrade cache entries cannot omit page_info.
        $answer = SEOProAnalytics_Query::cached('clicks-pages', $req + array('kind' => $kind, 'page' => $page), static function () use ($req, $kind, $page) {
            $range  = SEOProAnalytics_Query::range($req);
            $now    = self::scope($range, $req['filters'], $page);
            $totals = self::totals($now);
            $answer = array(
                'range'  => SEOProAnalytics_Query::range_out($range),
                'kind'   => $kind,
                'page'   => $page,
                'page_info' => self::page_info($page),
                'totals' => $totals,
                'rows'   => self::rows($now, $kind, (int) $req['limit'], (int) $req['offset'], $totals),
            );
            $other = SEOProAnalytics_Query::compare_range($range, $req['compare']);
            if ($other) {
                $then              = self::totals(self::scope($other, $req['filters'], $page));
                $answer['compare'] = array(
                    'range'  => SEOProAnalytics_Query::range_out($other),
                    'totals' => $then,
                    'change' => SEOProAnalytics_Query::change($totals, $then),
                );
            }
            return $answer;
        });
        // Cached page identities are shared; edit permissions must be checked
        // on every request, including cache hits and after a role changes.
        if ($answer['page_info'] !== null) {
            $answer['page_info'] = self::with_edit_url($answer['page_info']);
        }
        if ($kind === 'pages') {
            foreach ($answer['rows'] as &$row) {
                $row = self::with_edit_url($row);
            }
            unset($row);
        }
        return $answer;
    }

    /**
     * Resolve an exact local path, never a pattern or an external address.
     * Only called for the selected page and the bounded returned page rows
     * (here and in SEOProAnalytics_Search).
     *
     * A post is found by url_to_postid(), or else by the path's last part
     * among published posts of every viewable post type, kept only when its
     * own permalink has this path: url_to_postid() misses custom post types
     * whose permalinks have no base. A path that is no post may be a term
     * archive of a viewable taxonomy, found and checked the same way.
     *
     * @param string $path Page path.
     * @return array{path:string,url:string,post_id:int,edit_url:null,term_id?:int,taxonomy?:string}|null
     */
    public static function page_info($path) {
        if ($path === '' || $path[0] !== '/' || strpos($path, '//') === 0 || strpos($path, '*') !== false || strpos($path, '\\') !== false) {
            return null;
        }
        $home = wp_parse_url(home_url('/'));
        if (!is_array($home) || !isset($home['scheme'], $home['host'])) {
            return null;
        }
        // Paths include the installation directory already on subdirectory sites.
        $url  = esc_url_raw($home['scheme'] . '://' . $home['host'] . (isset($home['port']) ? ':' . $home['port'] : '') . $path);
        $info = array('path' => $path, 'url' => $url, 'post_id' => url_to_postid($url), 'edit_url' => null);
        if (!$info['post_id']) {
            $info['post_id'] = self::post_at($path);
        }
        if (!$info['post_id']) {
            $term = self::term_at($path);
            if ($term) {
                $info['term_id']  = (int) $term->term_id;
                $info['taxonomy'] = (string) $term->taxonomy;
            }
        }
        return $info;
    }

    /**
     * A published post of a viewable post type whose permalink has this
     * path, found by its slug (indexed `post_name`).
     *
     * @param string $path Page path.
     * @return int Post ID, or 0.
     */
    private static function post_at($path) {
        $slug  = self::last_slug($path);
        $types = array_values(array_diff(array_filter(get_post_types(array('public' => true)), 'is_post_type_viewable'), array('attachment')));
        if ($slug === '' || !$types) {
            return 0;
        }
        $ids = get_posts(array(
            'name'                   => $slug,
            'post_type'              => $types,
            'post_status'            => 'publish',
            'fields'                 => 'ids',
            'posts_per_page'         => 10,
            'no_found_rows'          => true,
            'ignore_sticky_posts'    => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ));
        foreach ($ids as $id) {
            if (self::same_path(get_permalink((int) $id), $path)) {
                return (int) $id;
            }
        }
        return 0;
    }

    /**
     * A term of a viewable taxonomy whose archive has this path, found by
     * its slug (indexed `slug`).
     *
     * @param string $path Page path.
     * @return WP_Term|null
     */
    private static function term_at($path) {
        $slug       = self::last_slug($path);
        $taxonomies = array_values(array_filter(get_taxonomies(array('public' => true)), 'is_taxonomy_viewable'));
        if ($slug === '' || !$taxonomies) {
            return null;
        }
        $terms = get_terms(array(
            'taxonomy'               => $taxonomies,
            'slug'                   => $slug,
            'hide_empty'             => false,
            'number'                 => 10,
            'update_term_meta_cache' => false,
        ));
        if (!is_array($terms)) {
            return null;
        }
        foreach ($terms as $term) {
            $link = get_term_link($term);
            if (!is_wp_error($link) && self::same_path($link, $path)) {
                return $term;
            }
        }
        return null;
    }

    /**
     * The last part of a path as WordPress stores slugs, or ''.
     *
     * @param string $path Page path.
     * @return string
     */
    private static function last_slug($path) {
        $only  = wp_parse_url($path, PHP_URL_PATH);
        $parts = is_string($only) ? preg_split('#/#', $only, -1, PREG_SPLIT_NO_EMPTY) : false;
        if (!$parts) {
            return '';
        }
        // As get_page_by_path(): non-ASCII slugs are stored percent-encoded.
        $slug = sanitize_title_for_query(rawurlencode(urldecode(end($parts))));
        return strlen($slug) <= 200 ? $slug : '';
    }

    /**
     * Whether an address has this path, ignoring a trailing slash, case and
     * percent-encoding.
     *
     * @param mixed  $link Address, or false.
     * @param string $path Page path.
     * @return bool
     */
    private static function same_path($link, $path) {
        $have = is_string($link) ? wp_parse_url($link, PHP_URL_PATH) : null;
        $want = wp_parse_url($path, PHP_URL_PATH);
        if (!is_string($have) || !is_string($want)) {
            return false;
        }
        return strtolower(untrailingslashit(rawurldecode($have))) === strtolower(untrailingslashit(rawurldecode($want)));
    }

    /**
     * Add the current viewer's editor link, outside the shared report cache:
     * the post's with edit_post, or the term archive's with edit_term.
     * Shared read-only interfaces must omit this field entirely.
     *
     * @param array<string,mixed> $info Page identity or page row.
     * @return array<string,mixed>
     */
    public static function with_edit_url(array $info) {
        $post_id          = (int) $info['post_id'];
        $term_id          = isset($info['term_id']) ? (int) $info['term_id'] : 0;
        $info['edit_url'] = null;
        if ($post_id) {
            if (current_user_can('edit_post', $post_id)) {
                $info['edit_url'] = get_edit_post_link($post_id, 'raw') ?: null;
            }
        } elseif ($term_id && !empty($info['taxonomy']) && current_user_can('edit_term', $term_id)) {
            $info['edit_url'] = get_edit_term_link($term_id, (string) $info['taxonomy']) ?: null;
        }
        return $info;
    }

    /**
     * The shared FROM and WHERE of a period: clicks in the period's window
     * on visits that started in it and pass the filters, on the page asked
     * for (and the page filtered on).
     *
     * @param array<string,mixed>                                           $range   From SEOProAnalytics_Query::range().
     * @param array<int,array{dimension:string,op:string,values:string[]}> $filters Filters.
     * @param string                                                        $page    Page path, or ''.
     * @return array{sql:string,args:array<int,mixed>}|null Null when no page matches.
     */
    private static function scope(array $range, array $filters, $page) {
        $compiled = SEOProAnalytics_Query::compile($filters, $range);
        $pages    = $compiled['pages'];
        if ($page !== '') {
            $ids   = array_map('intval', SEOProAnalytics_Query::dict_ids(SEOProAnalytics_Schema::DICT_PATH, array(
                'dimension' => 'page',
                'op'        => strpos($page, '*') !== false ? 'matches' : 'is',
                'values'    => array($page),
            )));
            $pages = $pages === null ? $ids : array_values(array_intersect($pages, $ids));
        }
        if ($pages !== null && !$pages) {
            return null;
        }
        $on   = $pages === null ? '' : ' AND c.path_id IN (' . implode(', ', array_fill(0, count($pages), '%d')) . ')';
        $args = array_merge(
            array(SEOProAnalytics_Schema::table('clicks'), SEOProAnalytics_Schema::table('sessions')),
            SEOProAnalytics_Query::fact_window($range),
            $pages === null ? array() : array_map('intval', $pages),
            array($range['from'], $range['to']),
            $compiled['args']
        );
        return array(
            // Placeholders only; values are in args.
            'sql'  => "FROM %i c INNER JOIN %i s ON s.id = c.session_id WHERE c.ts >= %d AND c.ts < %d$on AND s.started >= %d AND s.started < %d{$compiled['where']}",
            'args' => $args,
        );
    }

    /**
     * Totals of a period.
     *
     * @param array{sql:string,args:array<int,mixed>}|null $scope From scope().
     * @return array{clicks:int,dead:int,dead_rate:float,links:int,outbound:int,affiliate:int,downloads:int,forms:int,visits:int}
     */
    private static function totals($scope) {
        global $wpdb;
        $out = array(
            'clicks'    => 0,
            'dead'      => 0,
            'dead_rate' => 0,
            'links'     => 0,
            'outbound'  => 0,
            'affiliate' => 0,
            'downloads' => 0,
            'forms'     => 0,
            'visits'    => 0,
        );
        if ($scope === null) {
            return $out;
        }
        $args = array_merge(
            array(SEOProAnalytics_Schema::CLICK, SEOProAnalytics_Schema::CLICK, self::DEAD, SEOProAnalytics_Schema::CLICK, SEOProAnalytics_Schema::CLICK, self::OUTBOUND, SEOProAnalytics_Schema::CLICK, self::AFFILIATE, SEOProAnalytics_Schema::CLICK, self::DOWNLOAD, SEOProAnalytics_Schema::FORM),
            $scope['args']
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own tables: clicks by index `ts` or `path_ts`, visits by primary key; the scope holds only placeholders.
        $row = (array) $wpdb->get_row($wpdb->prepare("SELECT SUM(c.kind = %d) AS clicks, SUM(c.kind = %d AND c.flags & %d > 0) AS dead, SUM(c.kind = %d AND c.target_id <> 0) AS links, SUM(c.kind = %d AND c.flags & %d > 0) AS outbound, SUM(c.kind = %d AND c.flags & %d > 0) AS affiliate, SUM(c.kind = %d AND c.flags & %d > 0) AS downloads, SUM(c.kind = %d) AS forms, COUNT(DISTINCT c.session_id) AS visits {$scope['sql']}", $args), ARRAY_A);
        foreach (array_keys($out) as $key) {
            if (isset($row[$key])) {
                $out[$key] = (int) $row[$key];
            }
        }
        $out['dead_rate'] = $out['clicks'] ? round($out['dead'] / $out['clicks'], 4) : 0;
        return $out;
    }

    /**
     * Rows of one kind.
     *
     * @param array{sql:string,args:array<int,mixed>}|null $scope  From scope().
     * @param string                                       $kind   One of KINDS.
     * @param int                                          $limit  Rows.
     * @param int                                          $offset Rows skipped.
     * @param array<string,int|float>                      $totals From totals(), for shares.
     * @return array<int,array<string,mixed>>
     */
    private static function rows($scope, $kind, $limit, $offset, array $totals) {
        global $wpdb;
        if ($scope === null) {
            return array();
        }
        if ($kind === 'pages') {
            return self::page_rows($scope, $limit, $offset, $totals);
        }
        $form              = $kind === 'forms';
        list($only, $own)  = self::kind_filter($kind);
        list($by, $select) = self::kind_group($kind);
        // In placeholder order: the select's flag, the scope, the kind and its own, limit and offset.
        $args = array_merge(
            array(self::DEAD),
            $scope['args'],
            array($form ? SEOProAnalytics_Schema::FORM : SEOProAnalytics_Schema::CLICK),
            $own,
            array($limit, $offset)
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- as in totals(); $select, $by and $only are fixed SQL with placeholders.
        $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT $select, COUNT(*) AS n, COUNT(DISTINCT c.session_id) AS visits, SUM(c.flags & %d > 0) AS dead, BIT_OR(c.flags) AS f, MAX(c.fields) AS fields {$scope['sql']} AND c.kind = %d$only GROUP BY $by ORDER BY n DESC, $by LIMIT %d OFFSET %d", $args), ARRAY_A);
        if (!$rows) {
            return array();
        }
        return self::kind_rows($rows, $form, $form ? $totals['forms'] : $totals['clicks']);
    }

    /**
     * The condition that keeps one kind's clicks, with its placeholders'
     * values: dead clicks by their flag, links by a destination, files by
     * both; none for elements and forms.
     *
     * @param string $kind One of KINDS but pages.
     * @return array{0:string,1:array<int,int>} SQL, its values.
     */
    private static function kind_filter($kind) {
        if ($kind === 'dead') {
            return array(' AND c.flags & %d > 0', array(self::DEAD));
        }
        if ($kind === 'links') {
            return array(' AND c.target_id <> 0', array());
        }
        if ($kind === 'downloads') {
            return array(' AND c.target_id <> 0 AND c.flags & %d > 0', array(self::DOWNLOAD));
        }
        return array('', array());
    }

    /**
     * How one kind's rows are grouped and what each selects: links and
     * files by destination (with a label they were clicked as), elements
     * by element and label, forms by all three.
     *
     * @param string $kind One of KINDS but pages.
     * @return array{0:string,1:string} GROUP BY columns, selected columns.
     */
    private static function kind_group($kind) {
        if (in_array($kind, array('links', 'downloads'), true)) {
            return array('c.target_id', 'MAX(c.selector_id) AS s, MAX(c.label_id) AS l, c.target_id AS t');
        }
        if ($kind === 'forms') {
            return array('c.selector_id, c.label_id, c.target_id', 'c.selector_id AS s, c.label_id AS l, c.target_id AS t');
        }
        return array('c.selector_id, c.label_id', 'c.selector_id AS s, c.label_id AS l, MAX(c.target_id) AS t');
    }

    /**
     * One kind's rows as reported: the element, label and destination
     * texts, the clicks and their share, dead clicks, and the flags.
     *
     * @param array<int,array<string,mixed>> $rows Grouped rows (s, l, t, n, visits, dead, f, fields).
     * @param bool                           $form Whether these are form sends.
     * @param int|float                      $base All clicks or form sends (from the totals), for shares.
     * @return array<int,array<string,mixed>>
     */
    private static function kind_rows(array $rows, $form, $base) {
        $ids  = array_merge(array_column($rows, 's'), array_column($rows, 'l'), array_column($rows, 't'));
        $text = SEOProAnalytics_Query::texts(array_filter(array_map('intval', $ids)));
        $get  = static function ($id) use ($text) {
            return isset($text[(int) $id]) ? $text[(int) $id] : '';
        };
        $out  = array();
        foreach ($rows as $row) {
            $count = (int) $row['n'];
            $dead  = $form ? 0 : (int) $row['dead'];
            $flags = (int) $row['f'];
            $out[] = array(
                'selector'  => $get($row['s']),
                'label'     => $get($row['l']),
                'target'    => $get($row['t']),
                'count'     => $count,
                'visits'    => (int) $row['visits'],
                'share'     => $base ? round($count / $base, 4) : 0,
                'dead'      => $dead,
                'dead_rate' => $count ? round($dead / $count, 4) : 0,
                'outbound'  => !$form && ($flags & self::OUTBOUND) > 0,
                'affiliate' => !$form && ($flags & self::AFFILIATE) > 0,
                'download'  => !$form && ($flags & self::DOWNLOAD) > 0,
                'fields'    => $form ? (int) $row['fields'] : 0,
            );
        }
        return $out;
    }

    /**
     * Page-level click and form totals, grouped only by the page's identity.
     *
     * @param array{sql:string,args:array<int,mixed>} $scope Scope.
     * @param int $limit Rows returned.
     * @param int $offset Rows skipped.
     * @param array<string,int|float> $totals Report totals.
     * @return array<int,array<string,mixed>>
     */
    private static function page_rows(array $scope, $limit, $offset, array $totals) {
        global $wpdb;
        $args = array_merge(array(SEOProAnalytics_Schema::CLICK, SEOProAnalytics_Schema::CLICK, self::DEAD, SEOProAnalytics_Schema::CLICK, SEOProAnalytics_Schema::FORM), $scope['args'], array($limit, $offset));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- bounded report reads clicks by ts or path_ts and visits by primary key; scope contains only placeholders.
        $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT c.path_id, SUM(c.kind = %d) AS n, SUM(c.kind = %d AND c.flags & %d > 0) AS dead, SUM(c.kind = %d AND c.target_id <> 0) AS links, SUM(c.kind = %d) AS forms, COUNT(DISTINCT c.session_id) AS visits {$scope['sql']} GROUP BY c.path_id HAVING n > 0 ORDER BY n DESC, c.path_id LIMIT %d OFFSET %d", $args), ARRAY_A);
        $text = SEOProAnalytics_Query::texts(array_map('intval', array_column($rows, 'path_id')));
        $out  = array();
        foreach ($rows as $row) {
            $path  = isset($text[(int) $row['path_id']]) ? $text[(int) $row['path_id']] : '';
            $count = (int) $row['n'];
            $dead  = (int) $row['dead'];
            $out[] = array_merge(array(
                'selector' => '', 'label' => '', 'target' => '',
                'count' => $count, 'dead' => $dead, 'dead_rate' => $count ? round($dead / $count, 4) : 0,
                'links' => (int) $row['links'], 'forms' => (int) $row['forms'], 'visits' => (int) $row['visits'],
                'share' => $totals['clicks'] ? round($count / $totals['clicks'], 4) : 0,
                'outbound' => false, 'affiliate' => false, 'download' => false, 'fields' => 0,
            ), self::page_info($path) ?: array('path' => $path, 'url' => '', 'post_id' => 0, 'edit_url' => null));
        }
        return $out;
    }
}
