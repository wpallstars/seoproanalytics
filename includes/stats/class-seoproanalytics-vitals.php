<?php
/**
 * Page experience field data (not lab tests): kept and reported. The
 * free plugin never asks for it: the SEO Pro Analytics Connect add-on's
 * read stores it through store(), and the demo data does.
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

final class SEOProAnalytics_Vitals {
    /** The add-on's field-data connection (SEOProAnalytics_Connections::ADDON). */
    const SOURCE = 'crux';

    const FORMS = array('PHONE', 'DESKTOP');
    const THRESHOLDS = array('lcp' => array(2500, 4000), 'inp' => array(200, 500), 'cls' => array(0.1, 0.25), 'fcp' => array(1800, 3000), 'ttfb' => array(800, 1800));
    // Stored metric => the field-data record's name for it.
    const METRICS = array(
        'lcp' => 'largest_contentful_paint',
        'inp' => 'interaction_to_next_paint',
        'cls' => 'cumulative_layout_shift',
        'fcp' => 'first_contentful_paint',
        'ttfb' => 'experimental_time_to_first_byte',
    );

    /** @return string This site's origin, without a path. */
    public static function origin() {
        $parts = wp_parse_url(home_url('/'));
        return (string) ($parts['scheme'] ?? 'https') . '://' . (string) ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /**
     * Missing values never count as good.
     *
     * @param string $metric Metric.
     * @param float|null $p75 Value.
     * @return string
     */
    public static function status($metric, $p75) {
        if ($p75 === null || !isset(self::THRESHOLDS[$metric])) {
            return 'unavailable';
        }
        return $p75 <= self::THRESHOLDS[$metric][0] ? 'good' : ($p75 <= self::THRESHOLDS[$metric][1] ? 'needs_improvement' : 'poor');
    }

    /**
     * Pages from both rankings: search clicks and visits, without remote calls.
     *
     * @param int $limit Page cap.
     * @return array<int,array<string,mixed>>
     */
    public static function pages($limit = 100) {
        global $wpdb;
        $limit = max(0, min(1000, (int) $limit));
        if (!$limit) {
            return array();
        }
        $from = wp_date('Y-m-d', time() - 30 * DAY_IN_SECONDS);
        $to = wp_date('Y-m-d', time() - DAY_IN_SECONDS);
        // phpcs:disable WordPress.DB.DirectDatabaseQuery -- indexed 30-day summaries; rank in PHP like Content, avoiding SQL filesorts.
        $clicks = (array) $wpdb->get_results($wpdb->prepare('SELECT path_id, SUM(clicks) AS clicks FROM %i FORCE INDEX (PRIMARY) WHERE engine IN (1,2) AND day >= %s AND day <= %s GROUP BY path_id ORDER BY NULL', SEOProAnalytics_Schema::table('gsc_pages'), $from, $to), ARRAY_A);
        $traffic = (array) $wpdb->get_results($wpdb->prepare('SELECT val AS path_id, SUM(visits) AS visits FROM %i FORCE INDEX (PRIMARY) WHERE day >= %s AND day <= %s AND dim = 15 GROUP BY val ORDER BY NULL', SEOProAnalytics_Schema::table('daily'), $from, $to), ARRAY_A);
        // phpcs:enable
        usort($clicks, static function ($a, $b) { return (int) $b['clicks'] <=> (int) $a['clicks']; });
        usort($traffic, static function ($a, $b) { return (int) $b['visits'] <=> (int) $a['visits']; });
        $clicks = array_slice($clicks, 0, $limit);
        $traffic = array_slice($traffic, 0, $limit);
        $texts = SEOProAnalytics_Query::texts(array_map('intval', array_merge(array_column($clicks, 'path_id'), array_column($traffic, 'path_id'))));
        $search = array();
        foreach ($clicks as $row) {
            $row['value'] = (string) ($texts[(int) $row['path_id']] ?? '');
            $search[] = $row;
        }
        $visits = array();
        foreach ((array) $traffic as $row) {
            $row['value'] = (string) ($texts[(int) $row['path_id']] ?? '');
            $visits[] = $row;
        }
        $by_path = array();
        foreach ($search as $row) {
            $path = (string) $row['value'];
            $by_path[$path] = array('path_id' => (int) $row['path_id'], 'path' => $path, 'clicks' => (int) $row['clicks'], 'visits' => 0);
        }
        foreach ($visits as $row) {
            $path = (string) $row['value'];
            if (!isset($by_path[$path])) {
                $by_path[$path] = array('path_id' => (int) $row['path_id'], 'path' => $path, 'clicks' => 0, 'visits' => 0);
            }
            $by_path[$path]['visits'] = (int) $row['visits'];
        }
        $paths = array();
        // Interleave the rankings so visits-only pages are not crowded out.
        for ($i = 0; $i < $limit; ++$i) {
            foreach (array($search, $visits) as $list) {
                if (isset($list[$i])) {
                    $paths[(string) $list[$i]['value']] = true;
                }
            }
        }
        $out = array();
        foreach (array_slice(array_keys($paths), 0, $limit) as $path) {
            if ($by_path[$path]['path_id'] > 0 && self::local_url($path) !== '') {
                $out[] = $by_path[$path];
            }
        }
        return $out;
    }

    /**
     * Only a local path can be looked up.
     *
     * @param string $path Path.
     * @return string Empty for invalid input.
     */
    public static function local_url($path) {
        if ($path === '' || $path[0] !== '/' || strpos($path, '//') === 0 || preg_match('/[\\\\\x00-\x20?#]/', $path)) {
            return '';
        }
        return self::origin() . $path;
    }

    /**
     * Store current/history data; missing and NaN values stay absent.
     *
     * @param array<string,mixed> $record Record.
     * @param int $id Path ID, 0 origin.
     * @param string $form Form factor.
     * @param string $path Path.
     * @param bool $history History.
     * @return int|false Rows stored, false on database error.
     */
    public static function store(array $record, $id, $form, $path = '', $history = false) {
        global $wpdb;
        $table = SEOProAnalytics_Schema::table('vitals');
        $periods = $history ? ($record['collectionPeriods'] ?? array()) : array($record['collectionPeriod'] ?? array());
        $count = 0;
        foreach ($periods as $index => $period) {
            $last = $period['lastDate'] ?? array();
            if (!isset($last['year'], $last['month'], $last['day']) || !checkdate((int) $last['month'], (int) $last['day'], (int) $last['year'])) {
                continue;
            }
            $day = sprintf('%04d-%02d-%02d', $last['year'], $last['month'], $last['day']);
            foreach (self::METRICS as $metric => $name) {
                $data = $record['metrics'][$name] ?? array();
                $p75 = $history ? ($data['percentilesTimeseries']['p75s'][$index] ?? null) : ($data['percentiles']['p75'] ?? null);
                if (!is_numeric($p75) || !is_finite((float) $p75) || (float) $p75 < 0) {
                    continue;
                }
                $bins = $history ? ($data['histogramTimeseries'] ?? array()) : ($data['histogram'] ?? array());
                $shares = array();
                foreach (array(0, 1, 2) as $bin) {
                    $value = $history ? ($bins[$bin]['densities'][$index] ?? null) : ($bins[$bin]['density'] ?? null);
                    if (is_numeric($value) && (float) $value >= 0 && (float) $value <= 1) {
                        $shares[] = (float) $value;
                    }
                }
                if (count($shares) !== 3) {
                    continue;
                }
                // phpcs:disable WordPress.DB.DirectDatabaseQuery -- path_day lookup and primary-key replace, own table.
                $before = !$history ? $wpdb->get_var($wpdb->prepare('SELECT p75 FROM %i WHERE path_id = %d AND form_factor = %s AND metric = %s ORDER BY day DESC LIMIT 1', $table, $id, $form, $metric)) : null;
                $ok = $wpdb->replace($table, array('day' => $day, 'path_id' => $id, 'form_factor' => $form, 'metric' => $metric, 'p75' => (float) $p75, 'good' => $shares[0], 'ni' => $shares[1], 'poor' => $shares[2]));
                // phpcs:enable
                if ($ok === false) {
                    return false;
                }
                ++$count;
                $old = self::status($metric, $before === null ? null : (float) $before);
                $new = self::status($metric, (float) $p75);
                if (!$history && $old !== 'unavailable' && $old !== $new && in_array($metric, array('lcp', 'inp', 'cls'), true) && SEOProAnalytics_Schema::set() === 'live') {
                    $identity = array_search($metric, array_keys(self::THRESHOLDS), true) * 2 + ($form === 'PHONE' ? 1 : 2);
                    SEOProAnalytics_Changes::record(52, array('path' => $path, 'object_type' => 'vitals', 'object_id' => $identity, 'old' => $old, 'new' => $new, 'meta' => array('name' => $path === '' ? __('Origin page experience', 'seoproanalytics') : $path, 'metric' => $metric, 'form_factor' => $form, 'day' => $day), 'user_id' => 0));
                }
            }
        }
        return $count;
    }

    /**
     * Per-metric indexed history for one path.
     *
     * @param int $id Path ID.
     * @param bool $latest Only the newest sample per metric.
     * @return array<int,array<string,mixed>>
     */
    public static function series($id, $latest = false) {
        global $wpdb;
        $out = array();
        foreach (self::FORMS as $form) {
            foreach (array_keys(self::THRESHOLDS) as $metric) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- path_day equality prefix followed by ordered day range.
                $rows = $wpdb->get_results($wpdb->prepare('SELECT day, form_factor, metric, p75, good, ni, poor FROM %i WHERE path_id = %d AND form_factor = %s AND metric = %s AND day >= %s ORDER BY day DESC LIMIT %d', SEOProAnalytics_Schema::table('vitals'), $id, $form, $metric, gmdate('Y-m-d', time() - 400 * DAY_IN_SECONDS), $latest ? 1 : 400), ARRAY_A);
                foreach ((array) $rows as $row) {
                    foreach (array('p75', 'good', 'ni', 'poor') as $field) {
                        $row[$field] = (float) $row[$field];
                    }
                    $row['status'] = self::status($metric, $row['p75']);
                    $row['stale'] = (string) $row['day'] < gmdate('Y-m-d', time() - 14 * DAY_IN_SECONDS);
                    $out[] = $row;
                }
            }
        }
        return $out;
    }

    /**
     * Fresh page-level speed samples in batches, for imported crawl evidence.
     * The range is at most 15 dates × two forms × two metrics per path.
     * Latest selection happens in PHP, avoiding per-page queries and SQL sorts.
     *
     * @param int[] $ids Path ids.
     * @return array<int,array<int,array<string,mixed>>>
     */
    public static function speed_samples(array $ids) {
        global $wpdb;
        require_once __DIR__ . '/class-seoproanalytics-connections.php';
        $conn = SEOProAnalytics_Schema::set() === 'demo' ? null : SEOProAnalytics_Connections::get(self::SOURCE);
        $availability = (array) ($conn['state']['vitals_available'] ?? array());
        $out = array();
        foreach (array_chunk(array_unique(array_filter(array_map('intval', $ids))), 500) as $chunk) {
            $holders = implode(',', array_fill(0, count($chunk), '%d'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- bounded path_day ranges; fixed form/metric subsets; placeholders only.
            $rows = $wpdb->get_results($wpdb->prepare("SELECT path_id, day, form_factor, metric, p75, good, ni, poor FROM %i FORCE INDEX (path_day) WHERE path_id IN ($holders) AND form_factor IN ('PHONE','DESKTOP') AND metric IN ('ttfb','lcp') AND day >= %s AND day <= %s LIMIT 30000", array_merge(array(SEOProAnalytics_Schema::table('vitals')), $chunk, array(gmdate('Y-m-d', time() - 14 * DAY_IN_SECONDS), gmdate('Y-m-d')))), ARRAY_A);
            foreach ((array) $rows as $row) {
                $id = (int) $row['path_id'];
                $slot = $id . ':' . $row['form_factor'];
                $key = $row['form_factor'] . ':' . $row['metric'];
                if ((isset($availability[$slot]) && !in_array(self::METRICS[$row['metric']], (array) $availability[$slot], true)) || (isset($out[$id][$key]) && $out[$id][$key]['day'] >= $row['day'])) {
                    continue;
                }
                foreach (array('p75', 'good', 'ni', 'poor') as $field) {
                    $row[$field] = (float) $row[$field];
                }
                $row['status'] = self::status($row['metric'], $row['p75']);
                $row['stale'] = false;
                $out[$id][$key] = $row;
            }
        }
        return array_map('array_values', $out);
    }

    /**
     * Latest samples from a series.
     *
     * @param array<int,array<string,mixed>> $series Series.
     * @return array<int,array<string,mixed>>
     */
    public static function latest(array $series) {
        $out = array();
        foreach ($series as $row) {
            $key = $row['form_factor'] . ':' . $row['metric'];
            if (!isset($out[$key])) {
                $out[$key] = $row;
            }
        }
        return array_values($out);
    }

    /**
     * Local report: origin series and monitored pages, failures by clicks.
     *
     * @param string $page Optional local path.
     * @return array<string,mixed>
     */
    public static function report($page = '') {
        require_once __DIR__ . '/class-seoproanalytics-connections.php';
        // Its field data stays readable; connected only while its source is registered.
        $conn = SEOProAnalytics_Connections::source_class(self::SOURCE) ? SEOProAnalytics_Connections::get(self::SOURCE) : null;
        $key = array('page' => $page, 'connection' => $conn, 'day' => gmdate('Y-m-d'));
        return SEOProAnalytics_Query::cached('vitals', $key, static function () use ($page, $conn) { return self::build($page, $conn); });
    }

    /**
     * Build the shared, credential-free local report.
     *
     * @param string $page Local path.
     * @param array<string,mixed>|null $conn Connection without credentials.
     * @return array<string,mixed>
     */
    private static function build($page, $conn) {
        global $wpdb;
        $demo = SEOProAnalytics_Schema::set() === 'demo';
        $pages = self::pages($demo ? 100 : (int) ($conn['settings']['pages'] ?? 100));
        if ($page !== '' && self::local_url($page) !== '') {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- dictionary unique kind/hash key; reports never create entries.
            $id = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE kind = %d AND hash = UNHEX(%s)', SEOProAnalytics_Schema::table('dict'), SEOProAnalytics_Schema::DICT_PATH, SEOProAnalytics_Dict::hash(SEOProAnalytics_Dict::clean($page))));
            // phpcs:disable WordPress.DB.DirectDatabaseQuery -- per-path range keys, at most 30 summary days per engine.
            $visits = $id > 0 ? (int) $wpdb->get_var($wpdb->prepare('SELECT SUM(visits) FROM %i WHERE dim = 15 AND val = %d AND day >= %s AND day <= %s', SEOProAnalytics_Schema::table('daily'), $id, wp_date('Y-m-d', time() - 30 * DAY_IN_SECONDS), wp_date('Y-m-d', time() - DAY_IN_SECONDS))) : 0;
            $clicks = $id > 0 ? (int) $wpdb->get_var($wpdb->prepare('SELECT SUM(clicks) FROM %i WHERE path_id = %d AND day >= %s AND day <= %s', SEOProAnalytics_Schema::table('gsc_pages'), $id, wp_date('Y-m-d', time() - 30 * DAY_IN_SECONDS), wp_date('Y-m-d', time() - DAY_IN_SECONDS))) : 0;
            // phpcs:enable
            $pages = array(array('path_id' => $id, 'path' => $page, 'clicks' => $clicks, 'visits' => $visits));
        }
        $rows = array();
        foreach ($pages as $item) {
            $samples = $item['path_id'] > 0 ? self::series((int) $item['path_id'], true) : array();
            if (!$demo) {
                $availability = (array) ($conn['state']['vitals_available'] ?? array());
                $samples = array_values(array_filter($samples, static function ($sample) use ($availability, $item) {
                    $slot = $item['path_id'] . ':' . $sample['form_factor'];
                    return !isset($availability[$slot]) || in_array(self::METRICS[$sample['metric']], (array) $availability[$slot], true);
                }));
            }
            $failing = false;
            foreach ($samples as $sample) {
                $failing = $failing || (!$sample['stale'] && in_array($sample['metric'], array('lcp', 'inp', 'cls'), true) && $sample['status'] === 'poor');
            }
            $rows[] = $item + array('samples' => $samples, 'available' => (bool) $samples, 'failing' => $failing);
        }
        usort($rows, static function ($a, $b) { return array($b['failing'], $b['clicks'], $b['visits']) <=> array($a['failing'], $a['clicks'], $a['visits']); });
        $series = self::series(0);
        $origin = self::latest($series);
        if (!$demo) {
            $availability = (array) ($conn['state']['vitals_available'] ?? array());
            $origin = array_values(array_filter($origin, static function ($sample) use ($availability) {
                $slot = '0:' . $sample['form_factor'];
                return !isset($availability[$slot]) || in_array(self::METRICS[$sample['metric']], (array) $availability[$slot], true);
            }));
        }
        return array('connected' => $conn !== null || SEOProAnalytics_Schema::set() === 'demo', 'source' => self::SOURCE, 'window_days' => 28, 'traffic_days' => 30, 'origin' => $origin, 'series' => $series, 'rows' => $rows);
    }
}
