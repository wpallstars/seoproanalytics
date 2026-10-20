<?php
/**
 * Tracked search positions: the site's search targets checked by a paid
 * provider (the SEO Pro Analytics Connect add-on), one row per engine,
 * target, device and day in the serp table.
 *
 * Engines are search engines' results and the sources AI answer services
 * give an AI to answer from (AI engines: kept apart from search places),
 * each a key in ENGINES. A position
 * is the site's best place among the results (0: not within the depth
 * read), with the page shown and the result features on the page; for an
 * AI engine, its place among the answer's sources. A move of MOVE places
 * or more, or into or out of the top TOP, is a rank_move change on the
 * timeline; for an AI engine, entering or leaving the sources is an
 * ai_source change. Reads use the primary key (one target's history), the
 * day key (the newest checks) or the engine (the primary key's first
 * column, to delete one engine's rows). Nothing here makes a request.
 * Layout: docs/architecture.md → Integrations.
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

final class SEOProAnalytics_Serp {

    /** Devices checked: name => code (as SEOProAnalytics_Schema::GSC_DEVICES). Engines without devices use desktop's. */
    const DEVICES = array(
        'desktop' => 1,
        'mobile'  => 2,
    );

    /** Engines of the rows: name => code (SEOProAnalytics_Schema::ENGINE_*). */
    const ENGINES = array(
        'google'    => SEOProAnalytics_Schema::ENGINE_GOOGLE,
        'brave'     => SEOProAnalytics_Schema::ENGINE_BRAVE,
        'deepseek'  => SEOProAnalytics_Schema::ENGINE_DEEPSEEK,
        'brave_ai'  => SEOProAnalytics_Schema::ENGINE_BRAVE_AI,
        'linkup_ai' => SEOProAnalytics_Schema::ENGINE_LINKUP_AI,
    );

    /** Engines whose rows are an AI answer's sources, not search places: name => the search engine named. */
    const AI_ENGINES = array(
        'brave_ai'  => 'brave',
        'linkup_ai' => 'linkup',
    );

    /** Engine names shown in change labels (brand names, not translated). */
    const NAMES = array(
        'google'    => 'Google',
        'brave'     => 'Brave',
        'deepseek'  => 'DeepSeek',
        'brave_ai'  => 'Brave',
        'linkup_ai' => 'Linkup',
    );

    /** Result features: the provider's item type => bit. cited: the site is among an AI overview's sources. */
    const FEATURES = array(
        'ai_overview'      => 1,
        'featured_snippet' => 2,
        'people_also_ask'  => 4,
        'local_pack'       => 8,
        'video'            => 16,
        'images'           => 32,
        'top_stories'      => 64,
        'shopping'         => 128,
        'knowledge_graph'  => 256,
        'cited'            => 512,
    );

    /** Places that make a move, and the top that moving into or out of is one. */
    const MOVE = 3;
    const TOP  = 10;

    /** Days back the newest checks are read from. */
    const RECENT = 21;

    /**
     * An engine's code, or 0 for a name that is not one.
     *
     * @param string $engine Engine name.
     * @return int
     */
    public static function engine_code($engine) {
        return isset(self::ENGINES[$engine]) ? (int) self::ENGINES[$engine] : 0;
    }

    /**
     * Keep one check and record a change from the check before it.
     *
     * @param int    $query_id Target's query (DICT_QUERY).
     * @param string $query    Its text.
     * @param string $device   desktop or mobile.
     * @param array{position:int,path:string,features:int} $found The site's place, page and the features.
     * @param int    $depth    Results read (positions past it are not known).
     * @param string $engine   Engine name (ENGINES).
     * @return bool Whether it was kept.
     */
    public static function write($query_id, $query, $device, array $found, $depth, $engine = 'google') {
        global $wpdb;
        $code    = self::DEVICES[$device];
        $engine  = isset(self::ENGINES[$engine]) ? (string) $engine : 'google';
        $number  = self::engine_code($engine);
        $day     = (new DateTimeImmutable('today', wp_timezone()))->format('Y-m-d');
        $path_id = 0;
        if ($found['path'] !== '') {
            $ids     = SEOProAnalytics_Dict::ids(SEOProAnalytics_Schema::DICT_PATH, array($found['path']));
            $path_id = (int) ($ids[SEOProAnalytics_Dict::clean($found['path'])] ?? 0);
        }
        $table = SEOProAnalytics_Schema::table('serp');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table by its primary key: the check before today's.
        $before = $wpdb->get_row($wpdb->prepare('SELECT position, path_id FROM %i WHERE engine = %d AND query_id = %d AND device = %d AND day < %s ORDER BY day DESC LIMIT 1', $table, $number, $query_id, $code, $day), ARRAY_A);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table by its primary key.
        $ok = $wpdb->query($wpdb->prepare('INSERT INTO %i (engine, query_id, device, day, position, path_id, features) VALUES (%d, %d, %d, %s, %d, %d, %d) ON DUPLICATE KEY UPDATE position = VALUES(position), path_id = VALUES(path_id), features = VALUES(features)', $table, $number, $query_id, $code, $day, max(0, min(255, (int) $found['position'])), $path_id, (int) $found['features']));
        if ($ok === false) {
            return false;
        }
        if (is_array($before)) {
            $path = $found['path'] !== '' ? $found['path'] : self::path((int) $before['path_id']);
            $move = array('query' => $query, 'device' => $device, 'engine' => $engine, 'depth' => (int) $depth, 'path' => $path);
            if (isset(self::AI_ENGINES[$engine])) {
                self::record_source($move, (int) $before['position'], (int) $found['position']);
            } else {
                self::record_move($move, (int) $before['position'], (int) $found['position']);
            }
        }
        return true;
    }

    /**
     * Whether a position change is a move: MOVE places or more, or into or
     * out of the top TOP (a place past the depth counts as depth + 1).
     *
     * @param int $old   Position before (0: not within the depth).
     * @param int $new   Position now (0: not within the depth).
     * @param int $depth Results read.
     * @return bool
     */
    public static function moved($old, $new, $depth) {
        if ($old === $new) {
            return false;
        }
        $from = $old ? $old : $depth + 1;
        $to   = $new ? $new : $depth + 1;
        return abs($from - $to) >= self::MOVE || ($from <= self::TOP) !== ($to <= self::TOP);
    }

    /**
     * The newest check of each target and device within RECENT days, by
     * the day key, for one engine.
     *
     * @param int[]  $query_ids Targets' queries.
     * @param string $engine    Engine name (ENGINES).
     * @return array<int,array<string,array{position:int|null,path_id:int,features:string[],day:string}>> Query id => device => check.
     */
    public static function latest(array $query_ids, $engine = 'google') {
        $all = self::newest($query_ids);
        return isset($all[$engine]) ? $all[$engine] : array();
    }

    /**
     * The newest check of each target, engine and device within RECENT
     * days, in one read by the day key.
     *
     * @param int[] $query_ids Targets' queries.
     * @return array<string,array<int,array<string,array{position:int|null,path_id:int,features:string[],day:string}>>> Engine name => query id => device => check.
     */
    public static function newest(array $query_ids) {
        global $wpdb;
        if (!$query_ids || !SEOProAnalytics_Schema::is_current()) {
            return array();
        }
        $since   = (new DateTimeImmutable('today', wp_timezone()))->modify('-' . self::RECENT . ' days')->format('Y-m-d');
        $wanted  = array_flip(array_map('intval', $query_ids));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table by its day key: at most RECENT days of targets × devices × engines.
        $rows    = (array) $wpdb->get_results($wpdb->prepare('SELECT engine, query_id, device, day, position, path_id, features FROM %i FORCE INDEX (`day`) WHERE day >= %s ORDER BY day', SEOProAnalytics_Schema::table('serp'), $since), ARRAY_A);
        $names   = array_flip(self::DEVICES);
        $engines = array_flip(self::ENGINES);
        $out     = array();
        foreach ($rows as $row) {
            $query_id = (int) $row['query_id'];
            if (!isset($wanted[$query_id], $names[(int) $row['device']], $engines[(int) $row['engine']])) {
                continue;
            }
            // Ordered by day: the newest wins.
            $out[$engines[(int) $row['engine']]][$query_id][$names[(int) $row['device']]] = array(
                'position' => (int) $row['position'] ? (int) $row['position'] : null,
                'path_id'  => (int) $row['path_id'],
                'features' => self::feature_names((int) $row['features']),
                'day'      => (string) $row['day'],
            );
        }
        return $out;
    }

    /**
     * The newest day checked, or ''.
     *
     * @return string
     */
    public static function last_day() {
        global $wpdb;
        if (!SEOProAnalytics_Schema::is_current()) {
            return '';
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table: MAX() of its day key.
        return (string) $wpdb->get_var($wpdb->prepare('SELECT MAX(day) FROM %i', SEOProAnalytics_Schema::table('serp')));
    }

    /**
     * Feature bits as names.
     *
     * @param int $bits Bits.
     * @return string[]
     */
    public static function feature_names($bits) {
        $out = array();
        foreach (self::FEATURES as $name => $bit) {
            if ($bits & $bit) {
                $out[] = $name;
            }
        }
        return $out;
    }

    /**
     * Delete the checks (disconnecting with its data): one engine's, or
     * every engine's.
     *
     * @param string|null $engine Engine name (ENGINES), or null for all.
     * @return int Rows deleted.
     */
    public static function delete_all($engine = null) {
        global $wpdb;
        if ($engine === null) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- explicitly confirmed deletion of the provider's data, own table.
            return (int) $wpdb->query($wpdb->prepare('DELETE FROM %i', SEOProAnalytics_Schema::table('serp')));
        }
        $code = self::engine_code((string) $engine);
        if (!$code) {
            return 0;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- explicitly confirmed deletion of one engine's rows, own table, by its primary key's first column.
        return (int) $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE engine = %d', SEOProAnalytics_Schema::table('serp'), $code));
    }

    /**
     * Record a rank_move change (live data only).
     *
     * @param array{query:string,device:string,engine:string,depth:int,path:string} $move The check: target, device, engine, results read and the site's page ('' when none).
     * @param int $old Position before (0: past the depth).
     * @param int $new Position now (0: past the depth).
     */
    private static function record_move(array $move, $old, $new) {
        if (!self::moved($old, $new, $move['depth']) || SEOProAnalytics_Schema::set() !== 'live') {
            return;
        }
        self::record(SEOProAnalytics_Changes::RANK_MOVE, $move, $old, $new);
    }

    /**
     * Record an ai_source change when the site entered or left an AI
     * answer's sources (live data only). A move among the sources is not
     * one: their order is not a ranking people see.
     *
     * @param array{query:string,device:string,engine:string,depth:int,path:string} $move The check.
     * @param int $old Place among the sources before (0: not among them).
     * @param int $new Place now (0: not among them).
     */
    private static function record_source(array $move, $old, $new) {
        if ((bool) $old === (bool) $new || SEOProAnalytics_Schema::set() !== 'live') {
            return;
        }
        self::record(SEOProAnalytics_Changes::AI_SOURCE, $move, $old, $new);
    }

    /**
     * Write a serp change.
     *
     * @param int                                                                  $kind SEOProAnalytics_Changes kind code.
     * @param array{query:string,device:string,engine:string,depth:int,path:string} $move The check.
     * @param int                                                                  $old  Before (0: none).
     * @param int                                                                  $new  Now (0: none).
     */
    private static function record($kind, array $move, $old, $new) {
        require_once __DIR__ . '/class-seoproanalytics-changes.php';
        SEOProAnalytics_Changes::record($kind, array(
            'ts'          => time(),
            'path'        => $move['path'],
            'object_type' => 'serp',
            'old'         => $old ? (string) $old : '',
            'new'         => $new ? (string) $new : '',
            'meta'        => array('name' => $move['query'], 'query' => $move['query'], 'device' => $move['device'], 'engine' => $move['engine'], 'depth' => (int) $move['depth']),
            // WP-CLI (2) or the import cron (4).
            'source'      => defined('WP_CLI') && WP_CLI ? 2 : 4,
            'user_id'     => 0,
        ));
    }

    /**
     * A path id's text, or ''.
     *
     * @param int $path_id Path id.
     * @return string
     */
    private static function path($path_id) {
        if (!$path_id) {
            return '';
        }
        $text = SEOProAnalytics_Query::texts(array($path_id));
        return isset($text[$path_id]) ? (string) $text[$path_id] : '';
    }
}
