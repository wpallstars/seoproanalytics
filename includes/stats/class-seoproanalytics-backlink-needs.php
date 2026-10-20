<?php
/**
 * Backlinks with search data: which pages need links, and how pages did
 * after a site first linked to them (Search → Backlinks → Needs links and
 * Link impact).
 *
 * - needs: pages with queries in striking distance (positions 4–20, as
 *   SEOProAnalytics_Opportunities::striking_pairs()), each with the clicks it
 *   could gain in the top three (potential), its best queries, its
 *   referring domains and its useful ones (SEOProAnalytics_Backlinks::useful():
 *   followed, not of a NOT_USEFUL class such as Spam or Paid Link). The
 *   benchmark is the median useful referring domains of the site's own
 *   pages in the top three for the period; gap = that median less the
 *   page's own, and need = potential × gap. Pages with no gap are left
 *   out: links are not what the pages above them have more of.
 * - impact: pages that gained useful referring domains in the period
 *   (newest first link first, MAX_IMPACT at most), with the page's search
 *   clicks, impressions and position in the WINDOW days before the first
 *   new link was found and the WINDOW days from then, against the whole
 *   site's clicks in the same days, and the backlink_new change of that
 *   first link (to start an experiment from). A change associated with
 *   the links, not caused by them: other changes, seasons and updates
 *   move pages too, and a link can be older than the day it was found.
 *
 * Reads: the backlinks report's cached live links, Opportunities'
 * striking pairs and top pages (cached), and for impact gsc_pages by
 * path_day, gsc_totals by the primary key (engine, day) and the
 * backlink_new changes by kind_ts; impact is cached. Nothing reads the
 * visit tables or leaves the site.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 * Provenance: wpas-c8662019b99fb07b. Copies, even of part, keep these lines.
 *
 * @package SEOProAnalytics
 * @since 1.10.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProAnalytics_Backlink_Needs {

    /** Queries named per page that needs links. */
    const QUERIES = 3;

    /** Link impact: days compared on each side of the day the first new link was found; the fewest days before; pages measured at most; new sites named per page; backlink_new changes read at most. */
    const WINDOW      = 28;
    const MIN_BEFORE  = 7;
    const MAX_IMPACT  = 200;
    const HOSTS       = 5;
    const MAX_CHANGES = 5000;

    /** Paths read per query of gsc_pages. */
    const CHUNK = 50;

    /** Classes whose links count for nothing here (hidden until asked for). */
    const SKIP = array('ignore', 'spam');

    /**
     * Needs links or Link impact: the answer's extra keys and the whole
     * list, best first.
     *
     * @param array<string,mixed>            $req     From SEOProAnalytics_Query::request().
     * @param string                         $kind    needs or impact.
     * @param string                         $engine  google, bing or all.
     * @param array<int,array<string,mixed>> $links   The live links (SEOProAnalytics_Backlinks link rows).
     * @param string                         $version The links' version (for impact's cache).
     * @return array{answer:array<string,mixed>,list:array<int,array<string,mixed>>}
     */
    public static function report(array $req, $kind, $engine, array $links, $version) {
        require_once __DIR__ . '/class-seoproanalytics-search.php';
        require_once __DIR__ . '/class-seoproanalytics-opportunities.php';
        $name  = SEOProAnalytics_Search::report_engine($engine);
        $links = array_values(array_filter($links, static function ($link) {
            return !array_intersect((array) $link['classes'], self::SKIP);
        }));
        $answer = array(
            'engine'    => $name,
            'engines'   => SEOProAnalytics_Search::engines(),
            'combined'  => SEOProAnalytics_Search::combinable(),
            'connected' => SEOProAnalytics_Schema::set() !== 'live' || SEOProAnalytics_Search::connected($name),
        );
        if ($kind === 'needs') {
            $pairs            = SEOProAnalytics_Opportunities::striking_pairs($req, $name);
            $linked           = self::linked($links);
            $top              = (array) $pairs['top'];
            $median           = SEOProAnalytics_Backlinks::median(array_map(static function ($id) use ($linked) {
                return isset($linked[$id]) ? count($linked[$id]['useful']) : 0;
            }, $top));
            $median           = $median === null ? 0.0 : round($median, 1);
            $answer['search'] = array('range' => $pairs['range'], 'days' => $pairs['days'], 'through' => $pairs['through'], 'rules' => $pairs['rules'] + array('queries' => self::QUERIES), 'top' => array('pages' => count($top), 'median' => $median));
            return array('answer' => $answer, 'list' => self::needs($pairs['list'], $linked, $median));
        }
        $range  = SEOProAnalytics_Query::range($req);
        $bounds = SEOProAnalytics_Search::span($name);
        $key    = array('from' => $range['from'], 'to' => $range['to'], 'engine' => $name, 'imports' => SEOProAnalytics_Search::version(), 'links' => $version, 'shape' => 2);
        $found  = SEOProAnalytics_Query::cached('backlink_impact', $key, static function () use ($name, $links, $range, $bounds) {
            return array('rows' => self::impact($name, $links, $range, $bounds));
        });
        $answer['search'] = array('range' => null, 'days' => 0, 'through' => $bounds['to'], 'first' => $bounds['from'], 'rules' => array('window' => self::WINDOW, 'min_before' => self::MIN_BEFORE, 'max_pages' => self::MAX_IMPACT));
        return array('answer' => $answer, 'list' => (array) $found['rows']);
    }

    /**
     * Rows as shown: pages' addresses, posts and editor links, and the
     * queries' texts. Run on the rows of one page of the list only.
     *
     * @param string                         $kind needs or impact.
     * @param array<int,array<string,mixed>> $rows Rows from report()'s list.
     * @return array<int,array<string,mixed>>
     */
    public static function shown($kind, array $rows) {
        require_once __DIR__ . '/class-seoproanalytics-clicks.php';
        $ids = array_column($rows, 'path_id');
        if ($kind === 'needs') {
            foreach ($rows as $row) {
                $ids = array_merge($ids, array_column($row['queries'], 'query_id'));
            }
        }
        $text = SEOProAnalytics_Dict::values($ids);
        foreach ($rows as &$row) {
            $path = isset($text[$row['path_id']]) ? (string) $text[$row['path_id']] : (isset($row['page']) ? (string) $row['page'] : '');
            $info = SEOProAnalytics_Clicks::page_info($path) ?: array('path' => $path, 'url' => '', 'post_id' => 0, 'edit_url' => null);
            $info = SEOProAnalytics_Clicks::with_edit_url($info);
            $row  = array_merge($row, array_intersect_key($info, array_flip(array('term_id', 'taxonomy'))), array('page' => $path, 'path' => $path, 'url' => $info['url'], 'post_id' => (int) $info['post_id'], 'edit_url' => $info['edit_url']));
            if ($kind === 'needs') {
                foreach ($row['queries'] as &$query) {
                    $query['query'] = isset($text[$query['query_id']]) ? (string) $text[$query['query_id']] : '';
                }
                unset($query);
            }
        }
        unset($row);
        return $rows;
    }

    /**
     * Pages that need links, highest need (potential × gap) first.
     *
     * @param array<int,array<string,mixed>> $pairs  Striking pairs, best first.
     * @param array<int,array<string,mixed>> $linked From linked().
     * @param float                          $median Median useful referring domains of the pages in the top three.
     * @return array<int,array<string,mixed>>
     */
    private static function needs(array $pairs, array $linked, $median) {
        $pages = array();
        foreach ($pairs as $pair) {
            $id = (int) $pair['path_id'];
            if (!isset($pages[$id])) {
                $pages[$id] = array('path_id' => $id, 'potential' => 0, 'impressions' => 0, 'clicks' => 0, 'pos' => 0.0, 'queries' => array());
            }
            $pages[$id]['potential']   += (int) $pair['potential'];
            $pages[$id]['impressions'] += (int) $pair['impressions'];
            $pages[$id]['clicks']      += (int) $pair['clicks'];
            $pages[$id]['pos']         += (float) $pair['position'] * (int) $pair['impressions'];
            if (count($pages[$id]['queries']) < self::QUERIES) {
                $pages[$id]['queries'][] = array('query_id' => (int) $pair['query_id'], 'impressions' => (int) $pair['impressions'], 'position' => (float) $pair['position'], 'potential' => (int) $pair['potential']);
            }
        }
        $out = array();
        foreach ($pages as $id => $page) {
            $have  = isset($linked[$id]) ? $linked[$id] : array('hosts' => array(), 'followed' => array(), 'useful' => array(), 'links' => 0, 'value' => 0);
            $gap   = round((float) $median - count($have['useful']), 1);
            $out[] = array(
                'path_id'     => $id,
                'potential'   => $page['potential'],
                'impressions' => $page['impressions'],
                'clicks'      => $page['clicks'],
                'position'    => $page['impressions'] ? round($page['pos'] / $page['impressions'], 1) : 0.0,
                'queries'     => $page['queries'],
                'domains'     => count($have['hosts']),
                'followed'    => count($have['followed']),
                'useful'      => count($have['useful']),
                'top_median'  => (float) $median,
                'gap'         => $gap,
                'links'       => $have['links'],
                'value'       => $have['value'],
                'need'        => $gap > 0 ? (int) round($page['potential'] * $gap) : 0,
            );
        }
        usort($out, static function ($a, $b) {
            return array($b['need'], $b['potential'], $a['path_id']) <=> array($a['need'], $a['potential'], $b['path_id']);
        });
        return array_values(array_filter($out, static function ($row) {
            return $row['need'] > 0;
        }));
    }

    /**
     * The live links to each page: its referring sites, those with a
     * followed link and those with a useful one, links, and the sum of
     * each site's best link value.
     *
     * @param array<int,array<string,mixed>> $links Live links.
     * @return array<int,array{hosts:array<string,int>,followed:array<string,bool>,useful:array<string,bool>,links:int,value:int}>
     */
    private static function linked(array $links) {
        $out = array();
        foreach ($links as $link) {
            $id   = (int) $link['path_id'];
            $host = (string) $link['host'];
            if (!isset($out[$id])) {
                $out[$id] = array('hosts' => array(), 'followed' => array(), 'useful' => array(), 'links' => 0, 'value' => 0);
            }
            ++$out[$id]['links'];
            $best = isset($out[$id]['hosts'][$host]) ? $out[$id]['hosts'][$host] : 0;
            // A site counts once: its best link to the page.
            $out[$id]['hosts'][$host] = max($best, (int) $link['value']);
            if (!$link['rel']) {
                $out[$id]['followed'][$host] = true;
            }
            if (SEOProAnalytics_Backlinks::useful($link)) {
                $out[$id]['useful'][$host] = true;
            }
        }
        foreach ($out as &$page) {
            $page['value'] = (int) array_sum($page['hosts']);
        }
        unset($page);
        return $out;
    }

    /**
     * Pages that gained useful referring domains in the period, with the
     * page's search figures before and after the first new one, best
     * change against the site first.
     *
     * @param string                         $name   Engine name.
     * @param array<int,array<string,mixed>> $links  Live links.
     * @param array<string,mixed>            $range  From SEOProAnalytics_Query::range().
     * @param array{from:string,to:string}   $bounds First and newest search day.
     * @return array<int,array<string,mixed>>
     */
    private static function impact($name, array $links, array $range, array $bounds) {
        $gains = self::new_domains($links, (int) $range['from'], (int) $range['to']);
        if (!$gains || $bounds['to'] === '') {
            return array();
        }
        $tz      = wp_timezone();
        $first   = $bounds['from'];
        $last    = $bounds['to'];
        $from    = $last;
        $earlier = PHP_INT_MAX;
        foreach ($gains as &$gain) {
            // The site's day the first new link was found, at its midnight.
            $day            = (new DateTimeImmutable('@' . (int) $gain['first']))->setTimezone($tz)->setTime(0, 0);
            $gain['start']  = $day->getTimestamp();
            $gain['day']    = $day->format('Y-m-d');
            $gain['before'] = array(max($first, $day->modify('-' . self::WINDOW . ' days')->format('Y-m-d')), $day->modify('-1 day')->format('Y-m-d'));
            $gain['after']  = array($gain['day'], min($last, $day->modify('+' . (self::WINDOW - 1) . ' days')->format('Y-m-d')));
            $from           = min($from, $gain['before'][0]);
            $earlier        = min($earlier, $gain['start']);
        }
        unset($gain);
        $codes   = SEOProAnalytics_Search::codes($name);
        $pages   = self::page_days($codes, array_values(array_unique(array_column($gains, 'path_id'))), $from, $last);
        $site    = self::site_days($codes, $from, $last);
        $changes = self::new_link_changes($earlier, (int) $range['to'] + self::WINDOW * DAY_IN_SECONDS);
        $out     = array();
        foreach ($gains as $gain) {
            $out[] = self::impact_row($gain, isset($pages[$gain['path_id']]) ? $pages[$gain['path_id']] : array(), $site, self::change_of($changes, $gain['hosts'][0]['host'], $gain['start']));
        }
        usort($out, static function ($a, $b) {
            $ra = $a['status'] === 'measured' ? 0 : 1;
            $rb = $b['status'] === 'measured' ? 0 : 1;
            return array($ra, $b['relative'] === null ? -INF : $b['relative'], $b['first_seen']) <=> array($rb, $a['relative'] === null ? -INF : $a['relative'], $a['first_seen']);
        });
        return $out;
    }

    /**
     * Pages that gained useful referring domains in the period: the
     * MAX_IMPACT whose first new link is newest, newest first. A site is new to a page
     * when its first useful link to it was found in the period.
     *
     * @param array<int,array<string,mixed>> $links Live links.
     * @param int                            $from  Period start, Unix seconds.
     * @param int                            $to    Period end, Unix seconds.
     * @return array<int,array<string,mixed>> Each: path_id, page, first, useful (sites now), links (from the new sites), value (their best) and hosts (the new sites, first found first).
     */
    private static function new_domains(array $links, $from, $to) {
        $sites = array();
        foreach ($links as $link) {
            if (!SEOProAnalytics_Backlinks::useful($link)) {
                continue;
            }
            $id   = (int) $link['path_id'];
            $host = (string) $link['host'];
            $ts   = $link['first_seen'] ? (int) strtotime((string) $link['first_seen']) : 0;
            if (!isset($sites[$id][$host])) {
                $sites[$id][$host] = array('host' => $host, 'source' => '', 'first' => PHP_INT_MAX, 'links' => 0, 'value' => 0, 'page' => (string) $link['page']);
            }
            $site = &$sites[$id][$host];
            ++$site['links'];
            $site['value'] = max($site['value'], (int) $link['value']);
            if ($ts && $ts < $site['first']) {
                $site['first']  = $ts;
                $site['source'] = (string) $link['source'];
            }
            unset($site);
        }
        $gains = array();
        foreach ($sites as $id => $hosts) {
            $new = array_values(array_filter($hosts, static function ($site) use ($from, $to) {
                return $site['first'] >= $from && $site['first'] < $to;
            }));
            if (!$new) {
                continue;
            }
            usort($new, static function ($a, $b) {
                return array($a['first'], $a['host']) <=> array($b['first'], $b['host']);
            });
            $gains[] = array(
                'path_id' => (int) $id,
                'page'    => $new[0]['page'],
                'first'   => $new[0]['first'],
                'useful'  => count($hosts),
                'links'   => (int) array_sum(array_column($new, 'links')),
                'value'   => (int) max(array_column($new, 'value')),
                'hosts'   => $new,
            );
        }
        usort($gains, static function ($a, $b) {
            return array($b['first'], $a['path_id']) <=> array($a['first'], $b['path_id']);
        });
        return array_slice($gains, 0, self::MAX_IMPACT);
    }

    /**
     * The backlink_new changes between two times by key kind_ts (oldest
     * first, MAX_CHANGES at most): referring host => [time, id].
     *
     * @param int $from Start, Unix seconds.
     * @param int $to   End, Unix seconds (left out).
     * @return array<string,array<int,array{0:int,1:int}>>
     */
    private static function new_link_changes($from, $to) {
        global $wpdb;
        require_once __DIR__ . '/class-seoproanalytics-changes.php';
        if ($from >= $to || !SEOProAnalytics_Schema::is_current()) {
            return array();
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, a range of its kind_ts key in its order.
        $rows = (array) $wpdb->get_results($wpdb->prepare('SELECT id, ts, new FROM %i FORCE INDEX (`kind_ts`) WHERE kind = %d AND ts >= %d AND ts < %d ORDER BY ts, id LIMIT %d', SEOProAnalytics_Schema::table('changes'), SEOProAnalytics_Changes::BACKLINK_NEW, (int) $from, (int) $to, self::MAX_CHANGES), ARRAY_A);
        $out  = array();
        foreach ($rows as $row) {
            $out[(string) $row['new']][] = array((int) $row['ts'], (int) $row['id']);
        }
        return $out;
    }

    /**
     * The change a referring site's new links were written as: its first
     * backlink_new change from the day its link was found; else its last
     * one before. Null when none was written (links found before changes
     * were kept, or never checked here).
     *
     * @param array<string,array<int,array{0:int,1:int}>> $changes From new_link_changes().
     * @param string                                      $host    Referring host.
     * @param int                                         $start   The day the link was found, its midnight (Unix).
     * @return int|null Change id.
     */
    private static function change_of(array $changes, $host, $start) {
        $found = null;
        foreach (isset($changes[$host]) ? $changes[$host] : array() as $change) {
            $found = $change[1];
            if ($change[0] >= $start) {
                break;
            }
        }
        return $found;
    }

    /**
     * One impact row: figures before and after, per day, and the changes.
     * status: measured (WINDOW days after), measuring (fewer so far), or
     * no_data (too few days before, or no impressions either side).
     *
     * @param array<string,mixed>                     $gain   From new_domains(), with day, before and after.
     * @param array<string,array{c:int,i:int,p:int}> $page   The page's figures by day.
     * @param array<string,array{c:int,i:int,p:int}> $site   The site's figures by day.
     * @param int|null                                $change_id The first new site's backlink_new change.
     * @return array<string,mixed>
     */
    private static function impact_row(array $gain, array $page, array $site, $change_id) {
        $before     = self::window($page, $gain['before']);
        $after      = self::window($page, $gain['after']);
        $site_then  = self::window($site, $gain['before']);
        $site_now   = self::window($site, $gain['after']);
        $change     = self::change($before, $after, 'clicks');
        $site_delta = self::change($site_then, $site_now, 'clicks');
        $status     = $after['days'] >= self::WINDOW ? 'measured' : 'measuring';
        if ($before['days'] < self::MIN_BEFORE || $after['days'] < 1 || (!$before['impressions'] && !$after['impressions'])) {
            $status = 'no_data';
        }
        $hosts = array_map(static function ($site) {
            return array('host' => $site['host'], 'source' => $site['source'], 'links' => $site['links'], 'value' => $site['value'], 'first_seen' => (string) wp_date('c', $site['first']));
        }, array_slice($gain['hosts'], 0, self::HOSTS));
        return array(
            'path_id'     => $gain['path_id'],
            'page'        => $gain['page'],
            'new_domains' => count($gain['hosts']),
            'hosts'       => $hosts,
            'useful'      => $gain['useful'],
            'links'       => $gain['links'],
            'value'       => $gain['value'],
            'first_seen'  => (string) wp_date('c', $gain['first']),
            'change_id'   => $change_id,
            'status'      => $status,
            'before'      => $before,
            'after'       => $after,
            'change'      => $change,
            'impressions_change' => self::change($before, $after, 'impressions'),
            'position_change'    => $before['impressions'] && $after['impressions'] ? round($after['position'] - $before['position'], 1) : null,
            'site_change' => $site_delta,
            'relative'    => $status !== 'no_data' && $change !== null && $site_delta !== null ? round($change - $site_delta, 1) : null,
        );
    }

    /**
     * Figures of the days of a window with data: days, clicks and
     * impressions per day, and position.
     *
     * @param array<string,array{c:int,i:int,p:int}> $by_day Figures by day.
     * @param array{0:string,1:string}               $span   First and last day.
     * @return array{days:int,clicks:float,impressions:float,position:float}
     */
    private static function window(array $by_day, array $span) {
        $days = 0;
        $c    = 0;
        $i    = 0;
        $p    = 0;
        if ($span[0] <= $span[1]) {
            $day = new DateTimeImmutable($span[0], wp_timezone());
            for ($n = 0; $n <= 2 * self::WINDOW && $day->format('Y-m-d') <= $span[1]; $n++) {
                $key = $day->format('Y-m-d');
                ++$days;
                if (isset($by_day[$key])) {
                    $c += $by_day[$key]['c'];
                    $i += $by_day[$key]['i'];
                    $p += $by_day[$key]['p'];
                }
                $day = $day->modify('+1 day');
            }
        }
        return array(
            'days'        => $days,
            'clicks'      => $days ? round($c / $days, 2) : 0.0,
            'impressions' => $days ? round($i / $days, 2) : 0.0,
            'position'    => $i ? round($p / $i / 100, 1) : 0.0,
        );
    }

    /**
     * Change per day in percent, null when there was none before.
     *
     * @param array<string,float|int> $before From window().
     * @param array<string,float|int> $after  From window().
     * @param string                  $field  clicks or impressions.
     * @return float|null
     */
    private static function change(array $before, array $after, $field) {
        if (!$before['days'] || !$after['days'] || (float) $before[$field] <= 0) {
            return null;
        }
        return round(((float) $after[$field] - (float) $before[$field]) / (float) $before[$field] * 100, 1);
    }

    /**
     * Pages' search figures by day, by path_day.
     *
     * @param int[]    $codes Engine codes.
     * @param int[]    $paths Path ids.
     * @param string   $from  First day.
     * @param string   $to    Last day.
     * @return array<int,array<string,array{c:int,i:int,p:int}>> Path id => day => figures.
     */
    private static function page_days(array $codes, array $paths, $from, $to) {
        global $wpdb;
        $out = array();
        $on  = SEOProAnalytics_Search::engine_where($codes);
        foreach (array_chunk(array_map('intval', $paths), self::CHUNK) as $chunk) {
            $holders = implode(', ', array_fill(0, count($chunk), '%d'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own table by its path_day key; $on and $holders hold only placeholders.
            $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT path_id AS pg, day AS d, SUM(clicks) AS c, SUM(impressions) AS i, SUM(pos_impr) AS p FROM %i FORCE INDEX (`path_day`) WHERE path_id IN ($holders) AND day >= %s AND day <= %s AND {$on['sql']} GROUP BY path_id, day ORDER BY NULL", array_merge(array(SEOProAnalytics_Schema::table('gsc_pages')), $chunk, array($from, $to), $on['args'])), ARRAY_A);
            foreach ($rows as $row) {
                $out[(int) $row['pg']][(string) $row['d']] = array('c' => (int) $row['c'], 'i' => (int) $row['i'], 'p' => (int) $row['p']);
            }
        }
        return $out;
    }

    /**
     * The whole site's search figures by day, by the primary key.
     *
     * @param int[]  $codes Engine codes.
     * @param string $from  First day.
     * @param string $to    Last day.
     * @return array<string,array{c:int,i:int,p:int}>
     */
    private static function site_days(array $codes, $from, $to) {
        global $wpdb;
        $on = SEOProAnalytics_Search::engine_where($codes);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own table by its primary key (engine, day); $on holds only placeholders.
        $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT day AS d, SUM(clicks) AS c, SUM(impressions) AS i, SUM(pos_impr) AS p FROM %i FORCE INDEX (`PRIMARY`) WHERE {$on['sql']} AND day >= %s AND day <= %s GROUP BY day ORDER BY NULL", array_merge(array(SEOProAnalytics_Schema::table('gsc_totals')), $on['args'], array($from, $to))), ARRAY_A);
        $out  = array();
        foreach ($rows as $row) {
            $out[(string) $row['d']] = array('c' => (int) $row['c'], 'i' => (int) $row['i'], 'p' => (int) $row['p']);
        }
        return $out;
    }
}
