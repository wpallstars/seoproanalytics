<?php
/**
 * Search → Backlinks → Reported: the referring pages link exports named
 * (SEOProAnalytics_Backlinks_Import), checked or not. Search Console names
 * the linking page but not the page it links to, so each is a referring
 * page's own row (path_id 0) until the check opens it and keeps its links.
 *
 * Reads at most MAX_ROWS referring pages by the path_checked key, never
 * checked first; the live links per page (count and targets: the page of
 * this site each links to, its text and rel) come from the report's own
 * read, with no query of their own.
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

final class SEOProAnalytics_Backlinks_Reported {

    /** A reported page's check: not opened yet, links to the site, none seen, could not be opened, or gone (404, 410). */
    const STATES = array('unchecked', 'links', 'none', 'error', 'gone');

    /**
     * The reported pages, newest report first, with their counts.
     *
     * @param array<int,array<string,string>> $live   The report's live link rows (of the source, when given).
     * @param array<int,array<string,mixed>>  $out    The same links as the report gives them, in the same order.
     * @param string                          $source One of SEOProAnalytics_Backlinks::FOUND, or '' for all.
     * @param array<int,array<string,string>> $rows   From page_rows().
     * @return array{rows:array<int,array<string,mixed>>,domains:int,checked:int}
     */
    public static function read(array $live, array $out, $source, array $rows) {
        $pages   = self::pages($rows, $source);
        $texts   = self::texts($pages);
        $targets = self::targets($live, $out);
        $rows    = array();
        $hosts   = array();
        $done    = 0;
        foreach ($pages as $page) {
            $row     = self::row($page, $texts, $targets);
            $rows[]  = $row;
            $done   += $row['state'] === 'unchecked' ? 0 : 1;
            $hosts[$row['host']] = true;
        }
        usort($rows, static function ($a, $b) {
            return array((string) $b['reported'], $a['host'], $a['source']) <=> array((string) $a['reported'], $b['host'], $b['source']);
        });
        return array('rows' => $rows, 'domains' => count($hosts), 'checked' => $done);
    }

    /**
     * The live links by referring page: the page of this site each links
     * to, its text and rel, first found first.
     *
     * @param array<int,array<string,string>> $live Live link rows.
     * @param array<int,array<string,mixed>>  $out  The same links as the report gives them.
     * @return array<int,array<int,array<string,mixed>>> Referring page's address id => its links.
     */
    private static function targets(array $live, array $out) {
        $by = array();
        foreach ($live as $i => $row) {
            if (!isset($out[$i])) {
                continue;
            }
            $by[(int) $row['source_url_id']][] = array(
                'page'       => (string) $out[$i]['page'],
                'anchor'     => (string) $out[$i]['anchor'],
                'rel'        => $out[$i]['rel'],
                'first_seen' => $out[$i]['first_seen'],
            );
        }
        foreach ($by as &$links) {
            usort($links, static function ($a, $b) {
                return array((string) $a['first_seen'], $a['page']) <=> array((string) $b['first_seen'], $b['page']);
            });
        }
        unset($links);
        return $by;
    }

    /**
     * Referring pages' own rows (path_id 0), at most MAX_ROWS by the
     * path_checked key: the report reads them once for the Reported list
     * and for the classes the owner gave each page. Export-only reads filter
     * before the limit so visit-only pages cannot crowd out reported pages.
     *
     * @param bool $exports Only pages named by exports (false for page classes).
     * @return array<int,array<string,string>>
     */
    public static function page_rows($exports = false) {
        global $wpdb;
        $bits = $exports ? SEOProAnalytics_Backlinks::EXPORTS : 0;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, the path_id = 0 range of its path_checked key in its order.
        return (array) $wpdb->get_results($wpdb->prepare('SELECT id, source_host_id, source_url_id, found, status, checked, misses, first_seen, last_seen, providers, classes, unclasses FROM %i FORCE INDEX (`path_checked`) WHERE path_id = 0 AND (%d = 0 OR (found & %d) <> 0) ORDER BY checked LIMIT %d', SEOProAnalytics_Schema::table('links'), $bits, $bits, SEOProAnalytics_Backlinks::MAX_ROWS), ARRAY_A);
    }

    /**
     * The classes the owner gave referring pages and took off them, by the
     * page's address id.
     *
     * @param array<int,array<string,string>> $rows From page_rows().
     * @return array<int,array{0:int,1:int}> Address id => [classes bits, unclasses bits].
     */
    public static function page_classes(array $rows) {
        $out = array();
        foreach ($rows as $row) {
            $un = isset($row['unclasses']) ? (int) $row['unclasses'] : 0;
            if ((int) $row['classes'] || $un) {
                $out[(int) $row['source_url_id']] = array((int) $row['classes'], $un);
            }
        }
        return $out;
    }

    /**
     * Referring pages' own rows that an export named (and of the source).
     *
     * @param array<int,array<string,string>> $rows   From page_rows().
     * @param string                          $source One of SEOProAnalytics_Backlinks::FOUND, or ''.
     * @return array<int,array<string,string>>
     */
    private static function pages(array $rows, $source) {
        $bit = $source !== '' ? SEOProAnalytics_Backlinks::FOUND[$source] : 0;
        return array_values(array_filter($rows, static function ($row) use ($bit) {
            return ((int) $row['found'] & SEOProAnalytics_Backlinks::EXPORTS) && (!$bit || ((int) $row['found'] & $bit));
        }));
    }

    /**
     * The hosts' and addresses' texts.
     *
     * @param array<int,array<string,string>> $pages Rows.
     * @return array<int,string>
     */
    private static function texts(array $pages) {
        $ids = array();
        foreach ($pages as $page) {
            array_push($ids, (int) $page['source_host_id'], (int) $page['source_url_id']);
        }
        return SEOProAnalytics_Dict::values($ids);
    }

    /**
     * One reported page as the report gives it.
     *
     * @param array<string,string>                      $page  Its row.
     * @param array<int,string>                         $texts Id => text.
     * @param array<int,array<int,array<string,mixed>>> $links Live links by referring page (targets()).
     * @return array<string,mixed>
     */
    private static function row(array $page, array $texts, array $links) {
        $url       = isset($texts[(int) $page['source_url_id']]) ? (string) $texts[(int) $page['source_url_id']] : '';
        $host      = isset($texts[(int) $page['source_host_id']]) ? (string) $texts[(int) $page['source_host_id']] : (string) wp_parse_url($url, PHP_URL_HOST);
        $providers = json_decode((string) $page['providers'], true);
        $providers = is_array($providers) ? $providers : array();
        $reported  = (int) $page['last_seen'];
        $out       = array();
        foreach ($providers as $name => $facts) {
            $seen         = isset($facts['last_seen']) ? (int) $facts['last_seen'] : 0;
            $reported     = max($reported, $seen);
            $out[$name]   = array(
                'authority' => isset($facts['authority']) ? $facts['authority'] : null,
                'last_seen' => self::date($seen),
            );
        }
        $found = array();
        foreach (SEOProAnalytics_Backlinks::FOUND as $name => $bit) {
            if ((int) $page['found'] & $bit) {
                $found[] = $name;
            }
        }
        $targets = isset($links[(int) $page['source_url_id']]) ? $links[(int) $page['source_url_id']] : array();
        // The page's own classes (every link on it has them) and those of the lists.
        $classes = SEOProAnalytics_Backlinks_Triage::effective((int) $page['classes'], 0, SEOProAnalytics_Backlink_Lists::match_url($url), isset($page['unclasses']) ? (int) $page['unclasses'] : 0);
        return array(
            'id'        => (int) $page['id'],
            'source'    => $url,
            'host'      => $host,
            'found'     => $found,
            'state'     => self::state($page),
            'links'     => count($targets),
            'targets'   => $targets,
            'reported'  => self::date($reported),
            'checked'   => self::date((int) $page['checked']),
            'providers' => (object) $out,
            'classes'   => $classes['classes'],
            'inherited' => $classes['inherited'],
        );
    }

    /**
     * A reported page's check, in one word (STATES).
     *
     * @param array<string,string> $page Its row (checked, status, misses).
     * @return string
     */
    private static function state(array $page) {
        if (!(int) $page['checked']) {
            return 'unchecked';
        }
        if ((int) $page['status'] === SEOProAnalytics_Backlinks::PAGE_LINKS) {
            return 'links';
        }
        if ((int) $page['status'] === SEOProAnalytics_Backlinks::PAGE_GONE) {
            return 'gone';
        }
        return (int) $page['misses'] > 0 ? 'error' : 'none';
    }

    /**
     * A time for the answer (ISO 8601 in the site's time zone), null for 0.
     *
     * @param int $ts Unix seconds.
     * @return string|null
     */
    private static function date($ts) {
        return $ts > 0 ? (string) wp_date('c', $ts) : null;
    }
}
