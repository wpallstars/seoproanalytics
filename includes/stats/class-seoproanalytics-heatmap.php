<?php
/**
 * One page's click and scroll heatmap: where people clicked (the tracker's
 * x, y with each click: thousandths of the window's width, pixels from
 * the page's top) in cells of 1% of the width by 10 pixels, with the
 * dead clicks among them, and how far down the page its pageviews
 * scrolled. By device, since a page's layout differs by width.
 *
 * Shown over the page itself (SEOProAnalytics_Toolbar's overlay) and
 * linked from Clicks → Pages. Reads clicks by index `path_ts` and
 * pageviews by `path_ts`, visits by primary key; same ranges, filters,
 * data sets and cache as SEOProAnalytics_Query.
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

final class SEOProAnalytics_Heatmap {

    /** Devices, as SEOProAnalytics_UA codes them. */
    const DEVICES = array('desktop' => 1, 'mobile' => 2, 'tablet' => 3);

    /** Most cells returned (the busiest). */
    const MAX_CELLS = 4000;

    /** Cell height in pixels. */
    const CELL_PX = 10;

    /**
     * The heatmap of one page.
     *
     * @param array<string,mixed> $req    From SEOProAnalytics_Query::request().
     * @param string              $page   Page path (exact).
     * @param string              $device desktop, mobile, tablet or '' for all.
     * @return array<string,mixed>|WP_Error
     */
    public static function report(array $req, $page, $device = '') {
        $page = trim((string) $page);
        if ($page === '' || $page[0] !== '/' || strpos($page, '*') !== false) {
            return new WP_Error('seoproanalytics_heatmap_page', __('Use one local page path, such as /pricing/.', 'seoproanalytics'), array('status' => 400));
        }
        $device = isset(self::DEVICES[$device]) ? (string) $device : '';
        return SEOProAnalytics_Query::cached('heatmap', $req + array('page' => $page, 'device' => $device), static function () use ($req, $page, $device) {
            $range = SEOProAnalytics_Query::range($req);
            $cells = self::cells(SEOProAnalytics_Clicks::scope($range, $req['filters'], $page), $device);
            return array(
                'range'   => SEOProAnalytics_Query::range_out($range),
                'page'    => $page,
                'device'  => $device,
                'cell'    => array('x' => 10, 'y' => self::CELL_PX),
                'clicks'  => array_sum(array_column($cells, 2)),
                'cells'   => $cells,
                'scroll'  => self::scroll(SEOProAnalytics_Clicks::scope($range, $req['filters'], $page, 'pageviews'), $device),
                'devices' => self::devices(SEOProAnalytics_Clicks::scope($range, $req['filters'], $page)),
            );
        });
    }

    /**
     * The device condition, with its value.
     *
     * @param string $device desktop, mobile, tablet or ''.
     * @return array{0:string,1:array<int,int>}
     */
    private static function device_sql($device) {
        return $device === '' ? array('', array()) : array(' AND s.device = %d', array(self::DEVICES[$device]));
    }

    /**
     * Clicks by cell: [column (0–100, 1% of the width), row (10 px), clicks, dead clicks].
     *
     * @param array{sql:string,args:array<int,mixed>}|null $scope  From SEOProAnalytics_Clicks::scope().
     * @param string                                       $device Device or ''.
     * @return array<int,array{0:int,1:int,2:int,3:int}>
     */
    private static function cells($scope, $device) {
        global $wpdb;
        if ($scope === null) {
            return array();
        }
        list($on, $value) = self::device_sql($device);
        $args = array_merge(array(self::CELL_PX, SEOProAnalytics_Clicks::DEAD), $scope['args'], array(SEOProAnalytics_Schema::CLICK), $value, array(self::MAX_CELLS));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own tables: clicks by index `path_ts`, visits by primary key; the scope and $on hold only placeholders.
        $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT FLOOR((c.x - 1) / 10) AS gx, FLOOR((c.y - 1) / %d) AS gy, COUNT(*) AS n, SUM(c.flags & %d > 0) AS dead {$scope['sql']} AND c.kind = %d AND c.x > 0 AND c.y > 0$on GROUP BY gx, gy ORDER BY n DESC LIMIT %d", $args), ARRAY_A);
        $out = array();
        foreach ($rows as $row) {
            $out[] = array((int) $row['gx'], (int) $row['gy'], (int) $row['n'], (int) $row['dead']);
        }
        return $out;
    }

    /**
     * Share of pageviews that scrolled at least each tenth of the page
     * (index 0: 10% … 9: 100%), and the pageviews.
     *
     * @param array{sql:string,args:array<int,mixed>}|null $scope  From SEOProAnalytics_Clicks::scope() on pageviews.
     * @param string                                       $device Device or ''.
     * @return array{views:int,reached:float[]}
     */
    private static function scroll($scope, $device) {
        global $wpdb;
        $out = array('views' => 0, 'reached' => array_fill(0, 10, 0));
        if ($scope === null) {
            return $out;
        }
        list($on, $value) = self::device_sql($device);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own tables: pageviews by index `path_ts`, visits by primary key; the scope and $on hold only placeholders.
        $rows  = (array) $wpdb->get_results($wpdb->prepare("SELECT LEAST(10, FLOOR(c.scroll / 10)) AS b, COUNT(*) AS n {$scope['sql']}$on GROUP BY b", array_merge($scope['args'], $value)), ARRAY_A);
        $count = array_fill(0, 11, 0);
        foreach ($rows as $row) {
            $count[(int) $row['b']] = (int) $row['n'];
        }
        $views = array_sum($count);
        if (!$views) {
            return $out;
        }
        // Reaching 10% (bucket 1 and up) … 100% (bucket 10).
        for ($i = 0; $i < 10; $i++) {
            $out['reached'][$i] = round(array_sum(array_slice($count, $i + 1)) / $views, 4);
        }
        $out['views'] = $views;
        return $out;
    }

    /**
     * Clicks with a place by device, so the overlay can offer the others.
     *
     * @param array{sql:string,args:array<int,mixed>}|null $scope From SEOProAnalytics_Clicks::scope().
     * @return array<string,int>
     */
    private static function devices($scope) {
        global $wpdb;
        $out = array_fill_keys(array_keys(self::DEVICES), 0);
        if ($scope === null) {
            return $out;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- as in cells().
        $rows  = (array) $wpdb->get_results($wpdb->prepare("SELECT s.device AS d, COUNT(*) AS n {$scope['sql']} AND c.kind = %d AND c.x > 0 GROUP BY s.device", array_merge($scope['args'], array(SEOProAnalytics_Schema::CLICK))), ARRAY_A);
        $names = array_flip(self::DEVICES);
        foreach ($rows as $row) {
            if (isset($names[(int) $row['d']])) {
                $out[$names[(int) $row['d']]] = (int) $row['n'];
            }
        }
        return $out;
    }
}
