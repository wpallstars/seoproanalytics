<?php
/**
 * Conversion reports: goals, funnels and custom properties, on the same
 * ranges, comparisons, filters, data sets and cache as SEOProAnalytics_Query,
 * so the numbers agree with the Overview.
 *
 * - Goals: visits that reached each goal (a page viewed, an event sent),
 *   their visitors, how often, the conversion rate (÷ visits) and, for
 *   event goals, revenue per currency (never added across currencies).
 * - Funnels: visits that reached each step in order within the visit
 *   (steps can mix pages and events; `seq` orders a visit's hits). One
 *   query with a derived table per step, which every supported MySQL and
 *   MariaDB runs (no window functions or CTEs).
 * - Properties: the keys sent with events and pages, or the values of one
 *   key, with counts, visits and event revenue per currency.
 *
 * They read the fact tables (by indexes `name_ts`, `path_ts`, `ts` and
 * `session_seq`), so they reach back as far as retention keeps events
 * (goals and funnels with page steps: visits).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 * Provenance: wpas-c8662019b99fb07b. Copies, even of part, keep these lines.
 *
 * @package SEOProAnalytics
 * @since 0.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProAnalytics_Conversions {

    /**
     * Every goal with its conversions, compared with the other period.
     *
     * @param array<string,mixed> $req From SEOProAnalytics_Query::request().
     * @return array<string,mixed>
     */
    public static function goals(array $req) {
        $goals = SEOProAnalytics_Goals::goals();
        return SEOProAnalytics_Query::cached('goals', $req + array('defs' => $goals), static function () use ($req, $goals) {
            $range  = SEOProAnalytics_Query::range($req);
            $answer = self::goal_period($goals, $range, $req['filters']);
            $other  = SEOProAnalytics_Query::compare_range($range, $req['compare']);
            if ($other) {
                $then              = self::goal_period($goals, $other, $req['filters']);
                $answer['compare'] = $then;
                foreach ($answer['goals'] as $i => $row) {
                    $was                            = $then['goals'][$i];
                    $answer['goals'][$i]['change'] = SEOProAnalytics_Query::change(
                        array('visits' => $row['visits'], 'completions' => $row['completions'], 'conversion_rate' => $row['conversion_rate']),
                        array('visits' => $was['visits'], 'completions' => $was['completions'], 'conversion_rate' => $was['conversion_rate'])
                    );
                }
            }
            return $answer;
        });
    }

    /**
     * Every funnel with the visits at each step, compared with the other
     * period.
     *
     * @param array<string,mixed> $req From SEOProAnalytics_Query::request().
     * @return array<string,mixed>
     */
    public static function funnels(array $req) {
        $funnels = SEOProAnalytics_Goals::funnels();
        return SEOProAnalytics_Query::cached('funnels', $req + array('defs' => $funnels), static function () use ($req, $funnels) {
            $range  = SEOProAnalytics_Query::range($req);
            $answer = self::funnel_period($funnels, $range, $req['filters']);
            $other  = SEOProAnalytics_Query::compare_range($range, $req['compare']);
            if ($other) {
                $then              = self::funnel_period($funnels, $other, $req['filters']);
                $answer['compare'] = $then;
                foreach ($answer['funnels'] as $i => $row) {
                    $was                              = $then['funnels'][$i];
                    $answer['funnels'][$i]['change'] = SEOProAnalytics_Query::change(
                        array('entered' => $row['entered'], 'completed' => $row['completed'], 'conversion_rate' => $row['conversion_rate']),
                        array('entered' => $was['entered'], 'completed' => $was['completed'], 'conversion_rate' => $was['conversion_rate'])
                    );
                }
            }
            return $answer;
        });
    }

    /**
     * Property keys sent with events and pages, or the values of one key.
     *
     * @param array<string,mixed> $req   From SEOProAnalytics_Query::request().
     * @param string              $key   Key whose values to list; '' lists the keys.
     * @param string              $event Only properties of this event; '' for all, with pages.
     * @return array<string,mixed>
     */
    public static function properties(array $req, $key = '', $event = '') {
        $key   = (string) $key;
        $event = (string) $event;
        return SEOProAnalytics_Query::cached('properties', $req + array('key' => $key, 'event' => $event), static function () use ($req, $key, $event) {
            $range    = SEOProAnalytics_Query::range($req);
            $compiled = SEOProAnalytics_Query::compile($req['filters'], $range);
            $visits   = (int) SEOProAnalytics_Query::totals($range, $compiled)['visits'];
            return array(
                'range'  => SEOProAnalytics_Query::range_out($range),
                'key'    => $key,
                'event'  => $event,
                'visits' => $visits,
                'rows'   => self::property_rows($range, $compiled, $key, $event, (int) $req['limit'], (int) $req['offset'], $visits),
            );
        });
    }

    /**
     * Goal rows for one period.
     *
     * @param array<int,array<string,mixed>>                                $goals   Goals.
     * @param array<string,mixed>                                           $range   From SEOProAnalytics_Query::range().
     * @param array<int,array{dimension:string,op:string,values:string[]}> $filters Filters.
     * @return array{range:array<string,string>,visits:int,goals:array<int,array<string,mixed>>}
     */
    private static function goal_period(array $goals, array $range, array $filters) {
        $compiled = SEOProAnalytics_Query::compile($filters, $range);
        $visits   = (int) SEOProAnalytics_Query::totals($range, $compiled)['visits'];
        $rows     = array();
        foreach ($goals as $goal) {
            $rows[] = $goal + self::goal_counts($goal, $range, $compiled, $visits);
        }
        return array(
            'range'  => SEOProAnalytics_Query::range_out($range),
            'visits' => $visits,
            'goals'  => $rows,
        );
    }

    /**
     * One goal's numbers in a period.
     *
     * @param array<string,mixed> $goal     Goal.
     * @param array<string,mixed> $range    From SEOProAnalytics_Query::range().
     * @param array<string,mixed> $compiled From SEOProAnalytics_Query::compile().
     * @param int                 $visits   Visits in the period.
     * @return array{visitors:int,visits:int,completions:int,conversion_rate:float,revenue:array<int,array{currency:string,amount:float,count:int}>}
     */
    private static function goal_counts(array $goal, array $range, array $compiled, $visits) {
        global $wpdb;
        $out = array(
            'visitors'        => 0,
            'visits'          => 0,
            'completions'     => 0,
            'conversion_rate' => 0,
            'revenue'         => array(),
        );
        list($table, $column, $ids, $property, $pargs) = self::target($goal['kind'], $goal['match'], $goal);
        if (!$ids) {
            return $out;
        }
        $holders = implode(', ', array_fill(0, count($ids), '%d'));
        $where   = $compiled['where'];
        $args    = array_merge(
            array(SEOProAnalytics_Schema::table('sessions'), $table),
            SEOProAnalytics_Query::fact_window($range),
            array($column),
            $ids,
            array($range['from'], $range['to']),
            $compiled['args'],
            $pargs
        );
        $from = "FROM %i s INNER JOIN %i f ON f.session_id = s.id WHERE f.ts >= %d AND f.ts < %d AND f.%i IN ($holders) AND s.started >= %d AND s.started < %d$where$property";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own tables by index `name_ts` or `path_ts`, and the primary key; $from holds the placeholders.
        $row = $wpdb->get_row($wpdb->prepare("SELECT COUNT(DISTINCT s.day, s.visitor) AS visitors, COUNT(DISTINCT f.session_id) AS visits, COUNT(*) AS completions $from", $args), ARRAY_A);
        $row = (array) $row;

        $out['visitors']        = isset($row['visitors']) ? (int) $row['visitors'] : 0;
        $out['visits']          = isset($row['visits']) ? (int) $row['visits'] : 0;
        $out['completions']     = isset($row['completions']) ? (int) $row['completions'] : 0;
        $out['conversion_rate'] = $visits ? round($out['visits'] / $visits, 4) : 0;

        if ($goal['kind'] === 'event' && $out['completions']) {
            $sign = 'f.revenue';
            if ($goal['match'] === SEOProAnalytics_Purchases::EVENT) {
                $refunds = SEOProAnalytics_Dict::find(SEOProAnalytics_Schema::DICT_EVENT, array(SEOProAnalytics_Purchases::REFUND));
                if ($refunds) {
                    $refund_id = (int) $refunds[0];
                    $revenue_ids = array_merge($ids, array($refund_id));
                    $holders = implode(', ', array_fill(0, count($revenue_ids), '%d'));
                    $args = array_merge(array(SEOProAnalytics_Schema::table('sessions'), $table), SEOProAnalytics_Query::fact_window($range), array($column), $revenue_ids, array($range['from'], $range['to']), $compiled['args'], $pargs);
                    $from = "FROM %i s INNER JOIN %i f ON f.session_id = s.id WHERE f.ts >= %d AND f.ts < %d AND f.%i IN ($holders) AND s.started >= %d AND s.started < %d$where$property";
                    // The ID is an integer from our dictionary, not request SQL.
                    $sign = "CASE WHEN f.name_id = $refund_id THEN -f.revenue ELSE f.revenue END";
                }
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- as above.
            $money          = $wpdb->get_results($wpdb->prepare("SELECT f.currency AS c, SUM($sign) AS r, COUNT(*) AS n $from AND f.revenue <> 0 GROUP BY f.currency ORDER BY r DESC", $args), ARRAY_A);
            $out['revenue'] = self::money((array) $money);
        }
        return $out;
    }

    /**
     * One goal's conversions among the visits of a channel, by each
     * visit's entry page (SEOProAnalytics_Content): the goal's hits by
     * `name_ts` or `path_ts`, joined to their visits by the primary key,
     * so the cost is the goal's hits in the period.
     *
     * @param array<string,mixed> $goal    Goal.
     * @param array<string,mixed> $range   from and to (Unix), as from SEOProAnalytics_Query::range().
     * @param int                 $channel Channel code (SEOProAnalytics_Query::CHANNELS).
     * @return array<int,int> Entry path id => visits that reached the goal.
     */
    public static function by_entry(array $goal, array $range, $channel) {
        global $wpdb;
        list($table, $column, $ids, $property, $pargs) = self::target($goal['kind'], $goal['match'], $goal);
        if (!$ids) {
            return array();
        }
        $holders = implode(', ', array_fill(0, count($ids), '%d'));
        $args    = array_merge(
            array(SEOProAnalytics_Schema::table('sessions'), $table),
            SEOProAnalytics_Query::fact_window($range),
            array($column),
            $ids,
            array($range['from'], $range['to'], (int) $channel),
            $pargs
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own tables by index `name_ts` or `path_ts`, and the primary key; $holders holds only placeholders.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT s.entry_id AS p, COUNT(DISTINCT f.session_id) AS n FROM %i s INNER JOIN %i f ON f.session_id = s.id WHERE f.ts >= %d AND f.ts < %d AND f.%i IN ($holders) AND s.started >= %d AND s.started < %d AND s.channel = %d$property GROUP BY s.entry_id", $args), ARRAY_A);
        $out  = array();
        foreach ((array) $rows as $row) {
            $out[(int) $row['p']] = (int) $row['n'];
        }
        return $out;
    }

    /**
     * Funnel rows for one period.
     *
     * @param array<int,array<string,mixed>>                                $funnels Funnels.
     * @param array<string,mixed>                                           $range   From SEOProAnalytics_Query::range().
     * @param array<int,array{dimension:string,op:string,values:string[]}> $filters Filters.
     * @return array{range:array<string,string>,visits:int,funnels:array<int,array<string,mixed>>}
     */
    private static function funnel_period(array $funnels, array $range, array $filters) {
        $compiled = SEOProAnalytics_Query::compile($filters, $range);
        $visits   = (int) SEOProAnalytics_Query::totals($range, $compiled)['visits'];
        $rows     = array();
        foreach ($funnels as $funnel) {
            $rows[] = self::funnel_row($funnel, self::reached($funnel['steps'], $range, $compiled), $visits);
        }
        return array(
            'range'   => SEOProAnalytics_Query::range_out($range),
            'visits'  => $visits,
            'funnels' => $rows,
        );
    }

    /**
     * One funnel's row: the visits that entered and completed it, its
     * rates, and its steps.
     *
     * @param array<string,mixed> $funnel  Funnel.
     * @param int[]               $reached Visits that reached each step, in order (reached()).
     * @param int                 $visits  Visits in the period.
     * @return array<string,mixed>
     */
    private static function funnel_row(array $funnel, array $reached, $visits) {
        $first = $reached ? $reached[0] : 0;
        $steps = self::funnel_steps($funnel['steps'], $reached, $first);
        $last  = $steps ? $steps[count($steps) - 1]['visits'] : 0;
        return array(
            'id'              => $funnel['id'],
            'name'            => $funnel['name'],
            'entered'         => $first,
            'completed'       => $last,
            'completion_rate' => $first ? round($last / $first, 4) : 0,
            'conversion_rate' => $visits ? round($last / $visits, 4) : 0,
            'steps'           => $steps,
        );
    }

    /**
     * A funnel's steps: the visits that reached each, as a share of those
     * that started the funnel and of the step before, and those lost
     * since the step before.
     *
     * @param array<int,array<string,string>> $steps   Steps.
     * @param int[]                           $reached Visits that reached each step, in order.
     * @param int                             $first   Visits that started the funnel.
     * @return array<int,array<string,mixed>>
     */
    private static function funnel_steps(array $steps, array $reached, $first) {
        $out = array();
        foreach ($steps as $i => $step) {
            $here   = isset($reached[$i]) ? $reached[$i] : 0;
            $before = $here;
            if ($i) {
                $before = isset($reached[$i - 1]) ? $reached[$i - 1] : 0;
            }
            $out[] = $step + array(
                'visits'    => $here,
                // Of the visits that started the funnel, and of the step before.
                'rate'      => $first ? round($here / $first, 4) : 0,
                'step_rate' => $before ? round($here / $before, 4) : 0,
                'dropped'   => max(0, $before - $here),
            );
        }
        return $out;
    }

    /**
     * Visits that reached each step of a funnel in order.
     *
     * Level 1 is each visit's first hit of step 1. Level k joins the
     * visit's first hit of step k after the hit that reached step k-1
     * (`seq` greater); a visit that missed a step keeps a NULL position,
     * so it reaches no later step. Depth counts the steps reached.
     *
     * @param array<int,array<string,string>> $steps    Steps.
     * @param array<string,mixed>             $range    From SEOProAnalytics_Query::range().
     * @param array<string,mixed>             $compiled From SEOProAnalytics_Query::compile().
     * @return int[] Visits per step, in order.
     */
    private static function reached(array $steps, array $range, array $compiled) {
        global $wpdb;
        $count   = count($steps);
        $targets = array();
        foreach ($steps as $step) {
            $targets[] = self::target($step['kind'], $step['match'], $step);
        }
        if (!$targets[0][2]) {
            return array_fill(0, $count, 0);
        }

        list($table, $column, $ids, $property, $pargs) = $targets[0];
        $holders                    = implode(', ', array_fill(0, count($ids), '%d'));
        $sql                        = "SELECT f.session_id AS sid, MIN(f.seq) AS seq, 1 AS depth FROM %i s INNER JOIN %i f ON f.session_id = s.id WHERE f.ts >= %d AND f.ts < %d AND f.%i IN ($holders) AND s.started >= %d AND s.started < %d{$compiled['where']}$property GROUP BY f.session_id";
        $args                       = array_merge(
            array(SEOProAnalytics_Schema::table('sessions'), $table),
            SEOProAnalytics_Query::fact_window($range),
            array($column),
            $ids,
            array($range['from'], $range['to']),
            $compiled['args'],
            $pargs
        );

        for ($k = 1; $k < $count; $k++) {
            list($table, $column, $ids, $property, $pargs) = $targets[$k];
            // A step nothing matches stops every visit there.
            $match = $ids ? 'f.%i IN (' . implode(', ', array_fill(0, count($ids), '%d')) . ')' : '1 = 0';
            $sql   = "SELECT l.sid, MIN(f.seq) AS seq, MAX(l.depth) + (MIN(f.seq) IS NOT NULL) AS depth FROM ($sql) l LEFT JOIN %i f ON f.session_id = l.sid AND f.seq > l.seq AND $match$property GROUP BY l.sid";
            $args  = array_merge($args, array($table), $ids ? array_merge(array($column), $ids) : array(), $pargs);
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own tables: step 1 by index `name_ts` or `path_ts`, later steps by `session_seq`; $sql holds only placeholders and fixed SQL.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT x.depth AS d, COUNT(*) AS n FROM ($sql) x GROUP BY x.depth", $args), ARRAY_A);

        $at = array_fill(1, $count, 0);
        foreach ((array) $rows as $row) {
            $depth = min($count, (int) $row['d']);
            if ($depth > 0) {
                $at[$depth] += (int) $row['n'];
            }
        }
        // Reaching step k means a depth of k or more.
        $out = array();
        $sum = 0;
        for ($k = $count; $k >= 1; $k--) {
            $sum             += $at[$k];
            $out[$k - 1] = $sum;
        }
        ksort($out);
        return array_values($out);
    }

    /**
     * Property rows: keys (no key given) or one key's values.
     *
     * @param array<string,mixed> $range    From SEOProAnalytics_Query::range().
     * @param array<string,mixed> $compiled From SEOProAnalytics_Query::compile().
     * @param string              $key      Key, or ''.
     * @param string              $event    Event name, or ''.
     * @param int                 $limit    Rows.
     * @param int                 $offset   Rows skipped.
     * @param int                 $visits   Visits in the range, for shares.
     * @return array<int,array<string,mixed>>
     */
    private static function property_rows(array $range, array $compiled, $key, $event, $limit, $offset, $visits) {
        global $wpdb;
        $column = $key === '' ? 'key_id' : 'value_id';
        $by_key = self::dict_filter(SEOProAnalytics_Schema::DICT_PROP_KEY, $key, 'p.key_id');
        if ($by_key === null) {
            return array();
        }
        list($keys, $filter) = $by_key;
        $by_event = self::dict_filter(SEOProAnalytics_Schema::DICT_EVENT, $event, 'e.name_id');
        if ($by_event === null) {
            return array();
        }
        list($names, $only) = $by_event;
        $props  = SEOProAnalytics_Schema::table('props');
        $window = SEOProAnalytics_Query::fact_window($range);
        // Read only the period: the optimizer may otherwise pick the primary
        // key's owner prefix, which is every property ever kept.
        $index = $key === '' ? 'owner_ts' : 'key_ts';

        $parts = array("SELECT p.%i AS k, e.session_id AS sid FROM %i p FORCE INDEX (%i) INNER JOIN %i e ON e.id = p.owner_id WHERE p.owner = %d AND p.ts >= %d AND p.ts < %d$filter$only");
        $args  = array_merge(array($column, $props, $index, SEOProAnalytics_Schema::table('events'), SEOProAnalytics_Schema::OWNER_EVENT), $window, $keys, $names);
        if ($event === '') {
            $parts[] = "SELECT p.%i AS k, v.session_id AS sid FROM %i p FORCE INDEX (%i) INNER JOIN %i v ON v.id = p.owner_id WHERE p.owner = %d AND p.ts >= %d AND p.ts < %d$filter";
            $args    = array_merge($args, array($column, $props, $index, SEOProAnalytics_Schema::table('pageviews'), SEOProAnalytics_Schema::OWNER_PAGEVIEW), $window, $keys);
        }
        $union = implode(' UNION ALL ', $parts);
        $args  = array_merge($args, array(SEOProAnalytics_Schema::table('sessions'), $range['from'], $range['to']), $compiled['args'], array($limit, $offset));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own tables: props by index `owner_ts` or `key_ts` (index only), owners and visits by primary key; $union and the where clause hold only placeholders.
        $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT u.k AS v, COUNT(*) AS n, COUNT(DISTINCT u.sid) AS visits FROM ($union) u INNER JOIN %i s ON s.id = u.sid WHERE s.started >= %d AND s.started < %d{$compiled['where']} GROUP BY u.k ORDER BY n DESC, u.k LIMIT %d OFFSET %d", $args), ARRAY_A);
        if (!$rows) {
            return array();
        }
        $money = $key === '' ? array() : self::property_money($rows, $event, $keys, $names, $range, $compiled);
        return self::property_out($rows, $money, $visits);
    }

    /**
     * The dictionary ids of a property key or event name and the SQL that
     * keeps rows to them: none and '' when none is asked for, null when
     * the one asked for was never stored.
     *
     * @param int    $kind   SEOProAnalytics_Schema::DICT_* constant.
     * @param string $text   Key or event name, or ''.
     * @param string $column Column the ids are in.
     * @return array{0:int[],1:string}|null
     */
    private static function dict_filter($kind, $text, $column) {
        if ($text === '') {
            return array(array(), '');
        }
        $ids = SEOProAnalytics_Dict::find($kind, array($text));
        if (!$ids) {
            return null;
        }
        return array($ids, self::in_ids($column, $ids));
    }

    /**
     * SQL that keeps a column to some ids, one placeholder each.
     *
     * @param string $column Column.
     * @param int[]  $ids    Ids (at least one).
     * @return string
     */
    private static function in_ids($column, array $ids) {
        return ' AND ' . $column . ' IN (' . implode(', ', array_fill(0, count($ids), '%d')) . ')';
    }

    /**
     * Revenue of the events carrying each of one key's values, per
     * currency. Refunds subtract when every event or Purchase is read, and
     * reading Purchase reads its refunds too.
     *
     * @param array<int,array<string,mixed>> $rows     Value rows (v).
     * @param string                         $event    Event name, or ''.
     * @param int[]                          $keys     The key's dictionary ids.
     * @param int[]                          $names    The event's dictionary ids, or none for every event.
     * @param array<string,mixed>            $range    From SEOProAnalytics_Query::range().
     * @param array<string,mixed>            $compiled From SEOProAnalytics_Query::compile().
     * @return array<int,array<int,array<string,mixed>>> Value id => rows by currency.
     */
    private static function property_money(array $rows, $event, array $keys, array $names, array $range, array $compiled) {
        global $wpdb;
        $refunds = SEOProAnalytics_Dict::find(SEOProAnalytics_Schema::DICT_EVENT, array(SEOProAnalytics_Purchases::REFUND));
        $sign    = 'e.revenue';
        if ($refunds && ($event === '' || $event === SEOProAnalytics_Purchases::EVENT)) {
            $refund_id = (int) $refunds[0];
            $sign      = "CASE WHEN e.name_id = $refund_id THEN -e.revenue ELSE e.revenue END";
            if ($event === SEOProAnalytics_Purchases::EVENT) {
                $names[] = $refund_id;
            }
        }
        $filter  = self::in_ids('p.key_id', $keys);
        $only    = $names ? self::in_ids('e.name_id', $names) : '';
        $values  = array_map('intval', array_column($rows, 'v'));
        $holders = implode(', ', array_fill(0, count($values), '%d'));
        $margs   = array_merge(array(SEOProAnalytics_Schema::table('props'), SEOProAnalytics_Schema::table('events'), SEOProAnalytics_Schema::table('sessions'), SEOProAnalytics_Schema::OWNER_EVENT), SEOProAnalytics_Query::fact_window($range), $keys, $names, $values, array($range['from'], $range['to']), $compiled['args']);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- props by index `key_ts` (index only), owners and visits by primary key; $holders, $filter, $only and the where clause hold only placeholders.
        $found = (array) $wpdb->get_results($wpdb->prepare("SELECT p.value_id AS v, e.currency AS c, SUM($sign) AS r, COUNT(*) AS n FROM %i p FORCE INDEX (key_ts) INNER JOIN %i e ON e.id = p.owner_id INNER JOIN %i s ON s.id = e.session_id WHERE p.owner = %d AND p.ts >= %d AND p.ts < %d$filter$only AND p.value_id IN ($holders) AND e.revenue <> 0 AND s.started >= %d AND s.started < %d{$compiled['where']} GROUP BY p.value_id, e.currency ORDER BY r DESC", $margs), ARRAY_A);
        $money = array();
        foreach ($found as $row) {
            $money[(int) $row['v']][] = $row;
        }
        return $money;
    }

    /**
     * Property rows as reported: each value's text and label, its count,
     * visits and their share, and its revenue.
     *
     * @param array<int,array<string,mixed>>            $rows   Rows (v, n, visits).
     * @param array<int,array<int,array<string,mixed>>> $money  Value id => rows by currency.
     * @param int                                       $visits Visits in the range.
     * @return array<int,array<string,mixed>>
     */
    private static function property_out(array $rows, array $money, $visits) {
        $text = SEOProAnalytics_Query::texts(array_column($rows, 'v'));
        $out  = array();
        foreach ($rows as $row) {
            $id    = (int) $row['v'];
            $value = isset($text[$id]) ? $text[$id] : '';
            $out[] = array(
                'value'   => $value,
                'label'   => $value === '' ? __('(none)', 'seoproanalytics') : $value,
                'count'   => (int) $row['n'],
                'visits'  => (int) $row['visits'],
                'share'   => $visits ? round((int) $row['visits'] / $visits, 4) : 0,
                'revenue' => isset($money[$id]) ? self::money($money[$id]) : array(),
            );
        }
        return $out;
    }

    /**
     * The fact table, column and dictionary ids a page or event matches
     * (`*` is any text). Also SEOProAnalytics_AB_Report's goals.
     *
     * @param string              $kind      page or event.
     * @param string              $match     Path or event name.
     * @param array<string,mixed> $condition Optional prop/value pair.
     * @return array{0:string,1:string,2:int[],3:string,4:array<int,mixed>}
     */
    public static function target($kind, $match, array $condition = array()) {
        $page = $kind === 'page';
        $ids  = SEOProAnalytics_Query::dict_ids(
            $page ? SEOProAnalytics_Schema::DICT_PATH : SEOProAnalytics_Schema::DICT_EVENT,
            array(
                'dimension' => $page ? 'page' : 'event',
                'op'        => strpos($match, '*') !== false ? 'matches' : 'is',
                'values'    => array($match),
            )
        );
        list($property, $args) = self::property_condition($condition);
        return array(
            SEOProAnalytics_Schema::table($page ? 'pageviews' : 'events'),
            $page ? 'path_id' : 'name_id',
            array_map('intval', $ids),
            $property,
            $args,
        );
    }

    /**
     * Match properties on this event, never a different event in its visit.
     * The props primary key starts with (owner, owner_id, key_id).
     *
     * @param array<string,mixed> $condition Optional prop/value pair.
     * @return array{0:string,1:array<int,mixed>} Prepared SQL fragment and values.
     */
    private static function property_condition(array $condition) {
        global $wpdb;
        if (!isset($condition['prop']) || $condition['prop'] === '') {
            return array('', array());
        }
        $keys = SEOProAnalytics_Dict::find(SEOProAnalytics_Schema::DICT_PROP_KEY, array($condition['prop']));
        $value = (string) $condition['value'];
        if (!$keys) {
            return array(' AND 1 = 0', array());
        }
        $filter = self::in_ids('p.key_id', $keys);
        if (trim($value, '*') === '') {
            // Any text includes the empty value, stored as dictionary id 0.
            return array(
                " AND EXISTS (SELECT 1 FROM %i p WHERE p.owner = %d AND p.owner_id = f.id$filter)",
                array_merge(array(SEOProAnalytics_Schema::table('props'), SEOProAnalytics_Schema::OWNER_EVENT), $keys)
            );
        }
        if (strpos($value, '*') !== false) {
            // Match only this event's value via the dictionary primary key.
            // Enumerating global value ids would silently truncate at MAX_IDS.
            $pattern = implode('%', array_map(static function ($part) use ($wpdb) {
                return $wpdb->esc_like($part);
            }, explode('*', $value)));
            return array(
                " AND EXISTS (SELECT 1 FROM %i p INNER JOIN %i d ON d.id = p.value_id WHERE p.owner = %d AND p.owner_id = f.id$filter AND d.kind = %d AND d.value LIKE %s)",
                array_merge(array(SEOProAnalytics_Schema::table('props'), SEOProAnalytics_Schema::table('dict'), SEOProAnalytics_Schema::OWNER_EVENT), $keys, array(SEOProAnalytics_Schema::DICT_PROP_VAL, $pattern))
            );
        }
        $values = SEOProAnalytics_Dict::find(SEOProAnalytics_Schema::DICT_PROP_VAL, array($value));
        if (!$values) {
            return array(' AND 1 = 0', array());
        }
        $filter .= self::in_ids('p.value_id', $values);
        return array(
            " AND EXISTS (SELECT 1 FROM %i p WHERE p.owner = %d AND p.owner_id = f.id$filter)",
            array_merge(array(SEOProAnalytics_Schema::table('props'), SEOProAnalytics_Schema::OWNER_EVENT), $keys, $values)
        );
    }

    /**
     * Revenue rows (c currency, r minor units, n events) as amounts in the
     * currency's main unit. Currencies are never added together.
     *
     * @param array<int,array<string,mixed>> $rows Rows.
     * @return array<int,array{currency:string,amount:float,count:int}>
     */
    public static function money(array $rows) {
        $out = array();
        foreach ($rows as $row) {
            $out[] = array(
                'currency' => (string) $row['c'],
                'amount'   => round((int) $row['r'] / 100, 2),
                'count'    => (int) $row['n'],
            );
        }
        return $out;
    }
}
