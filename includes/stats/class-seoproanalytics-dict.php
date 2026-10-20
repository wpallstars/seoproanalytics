<?php
/**
 * The dictionary table: each distinct text of a kind (path, host, event
 * name…) stored once and referenced by a small number, so fact rows and
 * their indexes stay small.
 *
 * Lookups are in bulk: one INSERT IGNORE and one SELECT per kind per batch,
 * by an 8-byte hash of the text (the unique key), with a cache for the
 * request.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 * Provenance: wpas-c8662019b99fb07b. Copies, even of part, keep these lines.
 *
 * @package SEOProAnalytics
 * @since 0.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProAnalytics_Dict {

    /** Longest text kept (the column's size). */
    const MAX_LENGTH = 2048;

    /** Rows per INSERT or SELECT. */
    const CHUNK = 500;

    /** @var array<string,array<int,array<string,int>>> Table (one per data set) => kind => text => id. */
    private static $cache = array();

    /**
     * The 8-byte key of a text, as 16 hex digits. Binary values always go
     * to MySQL as UNHEX(%s): $wpdb may strip bytes that are not valid UTF-8.
     *
     * @param string $value Text.
     * @return string Hex.
     */
    public static function hash($value) {
        return substr(hash('sha256', $value), 0, 16);
    }

    /**
     * Normalise a text the way it is stored.
     *
     * @param string $value Text.
     * @return string
     */
    public static function clean($value) {
        $value = (string) $value;
        if (strlen($value) > self::MAX_LENGTH) {
            $value = function_exists('mb_strcut') ? mb_strcut($value, 0, self::MAX_LENGTH, 'UTF-8') : substr($value, 0, self::MAX_LENGTH);
        }
        return $value;
    }

    /**
     * IDs for texts of one kind, adding the new ones. '' is always 0.
     *
     * @param int      $kind   SEOProAnalytics_Schema::DICT_* constant.
     * @param string[] $values Texts.
     * @return array<string,int> Text (cleaned) => id.
     */
    public static function ids($kind, array $values) {
        $kind  = (int) $kind;
        $table = SEOProAnalytics_Schema::table('dict');
        $known = isset(self::$cache[$table][$kind]) ? self::$cache[$table][$kind] : array();
        list($out, $need) = self::known($values, $known);

        foreach (array_chunk($need, self::CHUNK, true) as $chunk) {
            $out = self::add_chunk($out, $table, $kind, $chunk);
        }

        foreach ($out as $value => $id) {
            if ($id > 0) {
                $known[(string) $value] = $id;
            }
        }
        // Keep the cache bounded for long batches.
        self::$cache[$table][$kind] = count($known) > 20000 ? array() : $known;
        return $out;
    }

    /**
     * The texts' IDs the cache already has, and the texts still to look up
     * by hash. Each text (cleaned) once; '' is always 0.
     *
     * @param string[]          $values Texts.
     * @param array<string,int> $known  Cached text => id of this kind.
     * @return array{0:array<string,int>,1:array<string,string>} Text => id, and hash => text still needed.
     */
    private static function known(array $values, array $known) {
        $out  = array('' => 0);
        $need = array();
        foreach ($values as $value) {
            $value = self::clean($value);
            if ($value === '' || isset($out[$value])) {
                continue;
            }
            if (isset($known[$value])) {
                $out[$value] = $known[$value];
            } else {
                $need[self::hash($value)] = $value;
            }
        }
        return array($out, $need);
    }

    /**
     * Add one chunk of new texts (INSERT IGNORE keeps those already
     * stored) and read their IDs back by the unique key.
     *
     * @param array<string,int>    $out   Text => id so far.
     * @param string               $table Dictionary table.
     * @param int                  $kind  SEOProAnalytics_Schema::DICT_* constant.
     * @param array<string,string> $chunk Hash => text.
     * @return array<string,int>
     */
    private static function add_chunk(array $out, $table, $kind, array $chunk) {
        global $wpdb;
        $args = array();
        foreach ($chunk as $hash => $value) {
            array_push($args, $kind, (string) $hash, $value);
        }
        $groups = implode(', ', array_fill(0, count($chunk), '(%d, UNHEX(%s), %s)'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table; $groups holds only fixed placeholder groups, one per row.
        $wpdb->query($wpdb->prepare("INSERT IGNORE INTO %i (kind, hash, value) VALUES $groups", array_merge(array($table), $args)));

        $holders = implode(', ', array_fill(0, count($chunk), 'UNHEX(%s)'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table, by its unique key; $holders holds only fixed placeholders, one per value.
        $found = $wpdb->get_results($wpdb->prepare("SELECT id, LOWER(HEX(hash)) AS h FROM %i WHERE kind = %d AND hash IN ($holders)", array_merge(array($table, $kind), array_map('strval', array_keys($chunk)))));
        foreach ((array) $found as $row) {
            if (isset($chunk[$row->h])) {
                $out[$chunk[$row->h]] = (int) $row->id;
            }
        }
        return $out;
    }

    /**
     * IDs of texts of one kind that are already stored (for filters; adds
     * nothing). Uses the unique (kind, hash) key.
     *
     * @param int      $kind   SEOProAnalytics_Schema::DICT_* constant.
     * @param string[] $values Texts.
     * @return int[]
     */
    public static function find($kind, array $values) {
        global $wpdb;
        $hashes = array();
        foreach ($values as $value) {
            $value = self::clean($value);
            if ($value !== '') {
                $hashes[self::hash($value)] = true;
            }
        }
        $out = array();
        foreach (array_chunk(array_map('strval', array_keys($hashes)), self::CHUNK) as $chunk) {
            $holders = implode(', ', array_fill(0, count($chunk), 'UNHEX(%s)'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table, by its unique key; $holders holds only fixed placeholders, one per value.
            $found = $wpdb->get_col($wpdb->prepare("SELECT id FROM %i WHERE kind = %d AND hash IN ($holders)", array_merge(array(SEOProAnalytics_Schema::table('dict'), (int) $kind), $chunk)));
            $out   = array_merge($out, array_map('intval', (array) $found));
        }
        return $out;
    }

    /**
     * IDs of stored texts of one kind that match a LIKE pattern (for
     * "contains" and "matches" filters). At most $limit.
     *
     * @param int    $kind    SEOProAnalytics_Schema::DICT_* constant.
     * @param string $pattern LIKE pattern, already escaped with esc_like().
     * @param int    $limit   Most IDs returned.
     * @return int[]
     */
    public static function like($kind, $pattern, $limit = 5000) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table; the kind prefix of the unique key narrows the scan.
        $found = $wpdb->get_col($wpdb->prepare('SELECT id FROM %i WHERE kind = %d AND value LIKE %s LIMIT %d', SEOProAnalytics_Schema::table('dict'), (int) $kind, $pattern, (int) $limit));
        return array_map('intval', (array) $found);
    }

    /**
     * Texts for IDs (for reports).
     *
     * @param int[] $ids IDs.
     * @return array<int,string> Id => text.
     */
    public static function values(array $ids) {
        global $wpdb;
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $out = array();
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $holders = implode(', ', array_fill(0, count($chunk), '%d'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table, by primary key; placeholders built above.
            $rows = $wpdb->get_results($wpdb->prepare("SELECT id, value FROM %i WHERE id IN ($holders)", array_merge(array(SEOProAnalytics_Schema::table('dict')), $chunk)));
            foreach ((array) $rows as $row) {
                $out[(int) $row->id] = (string) $row->value;
            }
        }
        return $out;
    }
}
