<?php
/**
 * Real visitors' page speed: the report of what the tracker measured
 * (Settings → Tracking → Measure page speed for visitors), on the same
 * ranges, comparisons, filters, data sets and cache as
 * SEOProAnalytics_Query.
 *
 * Each measured page load is one row of the speed table: time to first
 * byte, first and largest contentful paint, interaction to next paint (ms)
 * and cumulative layout shift (thousandths). The report gives each
 * metric's 75th percentile (as Google judges a page) and its share of
 * good, needs-improvement and poor loads by Google's thresholds, for the
 * site or one page, by device, and the pages with the slowest LCP.
 *
 * Unlike SEOProAnalytics_Vitals (Chrome UX Report: Chrome users of
 * popular pages, 28-day windows), this covers every browser that measures
 * them, every page with visits, and any range. Reads at most MAX_ROWS
 * page loads of a period (the newest), by index `ts` or `path_ts`.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 * Provenance: wpas-c8662019b99fb07b. Copies, even of part, keep these lines.
 *
 * @package SEOProAnalytics
 * @since 1.22.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProAnalytics_Speed {

    /** Metrics: column => [good up to, poor from] (Google's thresholds; CLS in thousandths). */
    const METRICS = array(
        'ttfb' => array(800, 1800),
        'fcp'  => array(1800, 3000),
        'lcp'  => array(2500, 4000),
        'inp'  => array(200, 500),
        'cls'  => array(100, 250),
    );

    /** Devices, as SEOProAnalytics_UA codes them. */
    const DEVICES = array(1 => 'desktop', 2 => 'mobile', 3 => 'tablet');

    /** Most page loads read for one period. */
    const MAX_ROWS = 50000;

    /** Fewest page loads for a page to be listed. */
    const MIN_LOADS = 3;

    /**
     * The page speed report.
     *
     * @param array<string,mixed> $req  From SEOProAnalytics_Query::request().
     * @param string              $page Only this page (path; * for any text); '' for the site.
     * @return array<string,mixed>
     */
    public static function report(array $req, $page = '') {
        $page = trim((string) $page);
        return SEOProAnalytics_Query::cached('speed', $req + array('page' => $page), static function () use ($req, $page) {
            $range  = SEOProAnalytics_Query::range($req);
            $loads  = self::loads(SEOProAnalytics_Clicks::scope($range, $req['filters'], $page, 'speed'));
            $answer = array(
                'range'   => SEOProAnalytics_Query::range_out($range),
                'page'    => $page,
                'enabled' => SEOProAnalytics_Statistics::speed_sample() > 0,
                'sample'  => SEOProAnalytics_Statistics::speed_sample(),
                'totals'  => self::summary($loads),
                'devices' => self::by_device($loads),
                'pages'   => $page === '' || strpos($page, '*') !== false ? self::pages($loads, (int) $req['limit'], (int) $req['offset']) : array(),
            );
            $other = SEOProAnalytics_Query::compare_range($range, $req['compare']);
            if ($other) {
                $answer['compare'] = array(
                    'range'  => SEOProAnalytics_Query::range_out($other),
                    'totals' => self::summary(self::loads(SEOProAnalytics_Clicks::scope($other, $req['filters'], $page, 'speed'))),
                );
            }
            return $answer;
        });
    }

    /**
     * The 75th percentile of each metric for one page over the last 30
     * days, for the toolbar (SEOProAnalytics_Toolbar).
     *
     * @param string $path Page path.
     * @return array<string,int|null> Metric => p75, and loads.
     */
    public static function page_p75($path) {
        $req = SEOProAnalytics_Query::request(array('range' => '30d', 'compare' => 'none'));
        if (is_wp_error($req)) {
            return array();
        }
        $answer = self::report($req, $path);
        $out    = array('loads' => (int) $answer['totals']['loads']);
        foreach (array_keys(self::METRICS) as $metric) {
            $out[$metric] = $answer['totals'][$metric]['p75'];
        }
        return $out;
    }

    /**
     * A period's page loads, newest first.
     *
     * @param array{sql:string,args:array<int,mixed>}|null $scope From SEOProAnalytics_Clicks::scope().
     * @return array<int,array<string,int|null>>
     */
    private static function loads($scope) {
        global $wpdb;
        if ($scope === null) {
            return array();
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own tables: speed by index `ts` or `path_ts`, visits by primary key; the scope holds only placeholders.
        $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT c.path_id, c.device, c.ttfb, c.fcp, c.lcp, c.inp, c.cls {$scope['sql']} ORDER BY c.ts DESC LIMIT %d", array_merge($scope['args'], array(self::MAX_ROWS))), ARRAY_A);
        foreach ($rows as &$row) {
            foreach ($row as $key => $value) {
                $row[$key] = $value === null ? null : (int) $value;
            }
        }
        unset($row);
        return $rows;
    }

    /**
     * Each metric's 75th percentile and good, needs-improvement and poor
     * shares, and the page loads they come from.
     *
     * @param array<int,array<string,int|null>> $loads From loads().
     * @return array<string,mixed>
     */
    private static function summary(array $loads) {
        $out = array('loads' => count($loads));
        foreach (self::METRICS as $metric => $limits) {
            $values = array();
            foreach ($loads as $load) {
                if ($load[$metric] !== null) {
                    $values[] = (int) $load[$metric];
                }
            }
            $out[$metric] = self::metric($values, $limits);
        }
        return $out;
    }

    /**
     * One metric's figures.
     *
     * @param int[]           $values Measured values.
     * @param array{0:int,1:int} $limits Good up to, poor from.
     * @return array{p75:int|null,n:int,good:float,ni:float,poor:float,rating:string}
     */
    private static function metric(array $values, array $limits) {
        $count = count($values);
        if (!$count) {
            return array('p75' => null, 'n' => 0, 'good' => 0, 'ni' => 0, 'poor' => 0, 'rating' => '');
        }
        sort($values);
        $p75  = $values[(int) min($count - 1, ceil($count * 0.75) - 1)];
        $good = 0;
        $poor = 0;
        foreach ($values as $value) {
            if ($value <= $limits[0]) {
                $good++;
            } elseif ($value > $limits[1]) {
                $poor++;
            }
        }
        return array(
            'p75'    => $p75,
            'n'      => $count,
            'good'   => round($good / $count, 4),
            'ni'     => round(($count - $good - $poor) / $count, 4),
            'poor'   => round($poor / $count, 4),
            'rating' => self::rating($p75, $limits),
        );
    }

    /**
     * good, ni (needs improvement) or poor.
     *
     * @param int                $value  Value.
     * @param array{0:int,1:int} $limits Good up to, poor from.
     * @return string
     */
    private static function rating($value, array $limits) {
        if ($value <= $limits[0]) {
            return 'good';
        }
        return $value > $limits[1] ? 'poor' : 'ni';
    }

    /**
     * The summary for each device with page loads.
     *
     * @param array<int,array<string,int|null>> $loads From loads().
     * @return array<string,array<string,mixed>>
     */
    private static function by_device(array $loads) {
        $groups = array();
        foreach ($loads as $load) {
            $groups[(int) $load['device']][] = $load;
        }
        $out = array();
        foreach (self::DEVICES as $code => $name) {
            if (!empty($groups[$code])) {
                $out[$name] = self::summary($groups[$code]);
            }
        }
        return $out;
    }

    /**
     * Pages by most page loads with at least MIN_LOADS, with each metric's
     * 75th percentile and rating.
     *
     * @param array<int,array<string,int|null>> $loads  From loads().
     * @param int                               $limit  Rows.
     * @param int                               $offset Rows skipped.
     * @return array<int,array<string,mixed>>
     */
    private static function pages(array $loads, $limit, $offset) {
        $groups = array();
        foreach ($loads as $load) {
            $groups[(int) $load['path_id']][] = $load;
        }
        $groups = array_filter($groups, static function ($group) {
            return count($group) >= self::MIN_LOADS;
        });
        uasort($groups, static function ($a, $b) {
            return count($b) - count($a);
        });
        $groups = array_slice($groups, max(0, $offset), max(1, $limit), true);
        $text   = SEOProAnalytics_Query::texts(array_keys($groups));
        $out    = array();
        foreach ($groups as $path_id => $group) {
            $row = array('path' => isset($text[$path_id]) ? $text[$path_id] : '', 'loads' => count($group));
            foreach (self::summary($group) as $metric => $figures) {
                if (is_array($figures)) {
                    $row[$metric]             = $figures['p75'];
                    $row[$metric . '_rating'] = $figures['rating'];
                }
            }
            $out[] = $row;
        }
        return $out;
    }
}
