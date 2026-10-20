<?php
/**
 * Linking sites' and pages' metrics (Search → Backlinks): a provider's
 * rank (0–100, DR in the dashboard) and referring domains (LD), one row
 * per dictionary id of a linking host (DICT_HOST) or page address
 * (DICT_URL) in the link_metrics table.
 *
 * A backlink data provider (the SEO Pro Analytics Connect add-on) writes
 * them for the linking sites and pages of the live links whose figures
 * are missing or older than MAX_AGE, whatever source found the link. The
 * report reads the rows by the primary key. Live data only; never on a
 * visitor page. Layout: docs/architecture.md → Integrations.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 * Provenance: wpas-c8662019b99fb07b. Copies, even of part, keep these lines.
 *
 * @package SEOProAnalytics
 * @since 1.8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProAnalytics_Link_Metrics {

    /** Figures older than this are read again. */
    const MAX_AGE = 30 * DAY_IN_SECONDS;

    /** Rows per query. */
    const CHUNK = 500;

    /** Columns: the figure => when it was read. */
    const FIELDS = array(
        'dr' => 'ranked',
        'ld' => 'counted',
    );

    /**
     * The figures of dictionary ids: missing ones are null.
     *
     * @param int[] $ids Dictionary ids (hosts, page addresses).
     * @return array<int,array{dr:int|null,ld:int|null}> Id => figures, for ids with a row.
     */
    public static function get(array $ids) {
        global $wpdb;
        $out = array();
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $holders = implode(', ', array_fill(0, count($chunk), '%d'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table by its primary key; $holders holds only placeholders.
            $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT target_id, dr, ld, ranked, counted FROM %i WHERE target_id IN ($holders)", array_merge(array(SEOProAnalytics_Schema::table('link_metrics')), $chunk)), ARRAY_A);
            foreach ($rows as $row) {
                $out[(int) $row['target_id']] = array(
                    'dr' => (int) $row['ranked'] ? (int) $row['dr'] : null,
                    'ld' => (int) $row['counted'] ? (int) $row['ld'] : null,
                );
            }
        }
        return $out;
    }

    /**
     * Keep one figure for dictionary ids, read now.
     *
     * @param string          $field  dr or ld.
     * @param array<int,int>  $values Id => figure.
     * @return bool Whether every write worked.
     */
    public static function put($field, array $values) {
        global $wpdb;
        if (!isset(self::FIELDS[$field])) {
            return false;
        }
        $when = self::FIELDS[$field];
        $now  = time();
        $ok   = true;
        foreach (array_chunk($values, self::CHUNK, true) as $chunk) {
            $rows = array();
            $args = array(SEOProAnalytics_Schema::table('link_metrics'));
            foreach ($chunk as $id => $value) {
                if ((int) $id < 1) {
                    continue;
                }
                $rows[] = '(%d, %d, %d)';
                $value  = max(0, (int) $value);
                array_push($args, (int) $id, $field === 'dr' ? min(100, $value) : min(4294967295, $value), $now);
            }
            if (!$rows) {
                continue;
            }
            $values = implode(', ', $rows);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table; $field and $when are fixed column names from FIELDS, $values holds only placeholders.
            $ok = $wpdb->query($wpdb->prepare("INSERT INTO %i (target_id, $field, $when) VALUES $values ON DUPLICATE KEY UPDATE $field = VALUES($field), $when = VALUES($when)", $args)) !== false && $ok;
        }
        return $ok;
    }

    /**
     * The linking sites and pages of the live links (the report's bounded
     * read, by status_first) whose figure is missing or older than
     * MAX_AGE: sites first, then pages.
     *
     * @param string $field dr or ld.
     * @param int    $most  Most targets.
     * @return array<int,string> Dictionary id => target (a host, or a page's address).
     */
    public static function stale($field, $most) {
        global $wpdb;
        if (!isset(self::FIELDS[$field]) || $most < 1) {
            return array();
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, by its status_first key in its order, bounded like the report.
        $rows  = (array) $wpdb->get_results($wpdb->prepare('SELECT source_host_id, source_url_id FROM %i FORCE INDEX (`status_first`) WHERE status = %d AND path_id > 0 ORDER BY first_seen DESC LIMIT %d', SEOProAnalytics_Schema::table('links'), SEOProAnalytics_Backlinks::LINK_LIVE, SEOProAnalytics_Backlinks::MAX_ROWS), ARRAY_A);
        $ids   = array_values(array_unique(array_merge(array_map('intval', array_column($rows, 'source_host_id')), array_map('intval', array_column($rows, 'source_url_id')))));
        $known = self::read_times($ids, self::FIELDS[$field]);
        $old   = time() - self::MAX_AGE;
        $want  = array();
        foreach ($ids as $id) {
            if ($id > 0 && (!isset($known[$id]) || $known[$id] < $old)) {
                $want[] = $id;
            }
        }
        if (!$want) {
            return array();
        }
        $texts = SEOProAnalytics_Dict::values($want);
        $out   = array();
        foreach ($want as $id) {
            if (isset($texts[$id]) && self::askable((string) $texts[$id])) {
                $out[$id] = (string) $texts[$id];
                if (count($out) >= $most) {
                    break;
                }
            }
        }
        return $out;
    }

    /**
     * Whether a target can be asked about: a domain name, or an http(s)
     * address of printable ASCII (one malformed target would fail the
     * whole bulk request).
     *
     * @param string $target Host or page address.
     * @return bool
     */
    private static function askable($target) {
        if (strpos($target, '://') === false) {
            return strpos($target, '.') !== false && (bool) preg_match('/^[a-z0-9.-]{1,253}$/', $target) && !filter_var($target, FILTER_VALIDATE_IP);
        }
        return strlen($target) <= 2000 && (bool) preg_match('#^https?://[a-z0-9.-]+(?::\d+)?(?:/[\x21-\x7e]*)?$#i', $target);
    }

    /**
     * When each id's figure was read.
     *
     * @param int[]  $ids  Dictionary ids.
     * @param string $when ranked or counted.
     * @return array<int,int> Id => Unix seconds (0: never).
     */
    private static function read_times(array $ids, $when) {
        global $wpdb;
        $out = array();
        foreach (array_chunk(array_values(array_filter($ids)), self::CHUNK) as $chunk) {
            $holders = implode(', ', array_fill(0, count($chunk), '%d'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table by its primary key; $when is a fixed column name from FIELDS, $holders holds only placeholders.
            $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT target_id, $when AS at FROM %i WHERE target_id IN ($holders)", array_merge(array(SEOProAnalytics_Schema::table('link_metrics')), $chunk)), ARRAY_A);
            foreach ($rows as $row) {
                $out[(int) $row['target_id']] = (int) $row['at'];
            }
        }
        return $out;
    }

    /**
     * Dictionary ids of hosts and page addresses (adding new ones).
     *
     * @param array<string,int> $hosts Host => figure.
     * @param array<string,int> $urls  Page address => figure.
     * @return array<int,int> Id => figure.
     */
    public static function by_id(array $hosts, array $urls) {
        $out = array();
        foreach (array(array(SEOProAnalytics_Schema::DICT_HOST, $hosts), array(SEOProAnalytics_Schema::DICT_URL, $urls)) as $pair) {
            list($kind, $values) = $pair;
            if (!$values) {
                continue;
            }
            $ids = SEOProAnalytics_Dict::ids($kind, array_map('strval', array_keys($values)));
            foreach ($values as $text => $value) {
                $id = isset($ids[SEOProAnalytics_Dict::clean((string) $text)]) ? (int) $ids[SEOProAnalytics_Dict::clean((string) $text)] : 0;
                if ($id > 0) {
                    $out[$id] = (int) $value;
                }
            }
        }
        return $out;
    }

    /**
     * Forget every figure (its provider disconnected with delete).
     *
     * @return int Rows deleted.
     */
    public static function delete_all() {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- explicitly confirmed deletion of our own table.
        return (int) $wpdb->query($wpdb->prepare('DELETE FROM %i', SEOProAnalytics_Schema::table('link_metrics')));
    }
}
