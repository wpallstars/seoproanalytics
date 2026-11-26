<?php
/**
 * Google Business Profile: the locations the owner linked, their daily
 * Search and Maps figures, the searches that showed them, their reviews
 * and profile edits, kept and reported. The free plugin never asks
 * Google: the SEO Pro Analytics Connect add-on's read stores them through
 * save_location(), store_metrics(), store_keywords() and store_reviews().
 *
 * Profile edits, new reviews and a falling rating are changes on the
 * timeline; unanswered reviews, a falling rating, a profile missing its
 * basics and a website link that is untagged or goes elsewhere are Plan
 * items (findings()). Reviewers' names are never stored.
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

// The add-on's read calls in from its own job: the texts and reports it needs.
require_once __DIR__ . '/class-seoproanalytics-dict.php';
require_once __DIR__ . '/class-seoproanalytics-query.php';

final class SEOProAnalytics_GBP {
    /** The add-on's connection (SEOProAnalytics_Connections::ADDON). */
    const SOURCE = 'gbp';

    /** Kind codes of a profile edited, new reviews and a falling rating (SEOProAnalytics_Changes::KINDS). */
    const PROFILE_CHANGE = 54;
    const REVIEWS_CHANGE = 55;
    const RATING_CHANGE  = 56;

    /**
     * A location's profile as kept and compared between reads: title,
     * categories (the primary first), hours (one "DAY HH:MM-HH:MM" line per
     * opening), description, website (the link as set), phone, address
     * (one line) and postcode (its own field, for checking against the
     * site's details).
     */
    const FIELDS = array('title', 'categories', 'hours', 'description', 'website', 'phone', 'address', 'postcode');

    /** Fields a complete profile has (finding gbp_incomplete). */
    const BASICS = array('categories', 'hours', 'description', 'phone', 'website');

    /** Daily metrics: stored code => Google's name. */
    const METRICS = array(
        1 => 'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH',
        2 => 'BUSINESS_IMPRESSIONS_MOBILE_SEARCH',
        3 => 'BUSINESS_IMPRESSIONS_DESKTOP_MAPS',
        4 => 'BUSINESS_IMPRESSIONS_MOBILE_MAPS',
        5 => 'WEBSITE_CLICKS',
        6 => 'CALL_CLICKS',
        7 => 'BUSINESS_DIRECTION_REQUESTS',
    );

    /** What each metric adds to in reports: code => figure. */
    const FIGURES = array(
        1 => 'search_desktop',
        2 => 'search_mobile',
        3 => 'maps_desktop',
        4 => 'maps_mobile',
        5 => 'website',
        6 => 'calls',
        7 => 'directions',
    );

    /** Plan findings (SEOProAnalytics_Queue, kind audit). */
    const FINDINGS = array('gbp_reviews', 'gbp_rating', 'gbp_incomplete', 'gbp_link', 'gbp_details');

    /** Most locations kept. */
    const MAX_LOCATIONS = 50;

    /** Most search keywords kept per location and month (the most shown). */
    const MAX_KEYWORDS = 1000;

    /** Longest review or reply text kept, in bytes. */
    const MAX_TEXT = 4096;

    /** A falling rating: the last RATING_DAYS' reviews, at least RATING_MIN of them, average RATING_DROP stars or more below the overall rating. */
    const RATING_DAYS = 30;
    const RATING_MIN  = 3;
    const RATING_DROP = 0.5;

    /** Reviews without a reply are listed while younger than this. */
    const UNANSWERED_DAYS = 90;

    /** Rows written in one statement. */
    const CHUNK = 500;

    /** The kept data's version, moved by every write (the report cache's key; live and demo, SEOProAnalytics_Schema::option()). */
    const VERSION_OPTION = 'seoproanalytics_gbp_version';

    /** Rows one prune statement deletes. */
    const PRUNE_BATCH = 5000;

    // ------------------------------------------------------------------
    // Writes (the add-on's read).

    /**
     * Save a location's read: its IDs, linked page, profile, rating and
     * review count. A profile that differs from the one kept is a change
     * on the timeline (not on the first read).
     *
     * @param array<string,mixed> $input location and account (Google's IDs, digits), page (a local path), profile (FIELDS), rating, reviews.
     * @return int|WP_Error The location's row id.
     */
    public static function save_location(array $input) {
        global $wpdb;
        $location = self::google_id($input['location'] ?? '');
        if ($location === '') {
            return new WP_Error('seoproanalytics_gbp_location', __('A Business Profile location needs its Google ID.', 'seoproanalytics'));
        }
        $table = SEOProAnalytics_Schema::table('gbp_locations');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table, by its unique key.
        $before = $wpdb->get_row($wpdb->prepare('SELECT id, title, profile FROM %i WHERE location = %s', $table, $location), ARRAY_A);
        if (!$before && count(self::rows()) >= self::MAX_LOCATIONS) {
            /* translators: %d: most locations */
            return new WP_Error('seoproanalytics_gbp_limit', sprintf(__('At most %d Business Profile locations can be linked.', 'seoproanalytics'), self::MAX_LOCATIONS));
        }
        $profile = self::profile(isset($input['profile']) && is_array($input['profile']) ? $input['profile'] : array());
        $path    = self::local_path(isset($input['page']) ? (string) $input['page'] : '');
        $ids     = $path !== '' ? SEOProAnalytics_Dict::ids(SEOProAnalytics_Schema::DICT_PATH, array($path)) : array();
        $rating  = isset($input['rating']) && is_numeric($input['rating']) ? round(max(0.0, min(5.0, (float) $input['rating'])), 2) : 0.0;
        $row     = array(
            'location' => $location,
            'account'  => self::google_id($input['account'] ?? ''),
            'path_id'  => (int) ($ids[SEOProAnalytics_Dict::clean($path)] ?? 0),
            'title'    => self::cut($profile['title'], 190),
            'profile'  => (string) wp_json_encode($profile),
            'rating'   => $rating,
            'reviews'  => max(0, (int) ($input['reviews'] ?? 0)),
            'checked'  => time(),
        );
        // phpcs:disable WordPress.DB.DirectDatabaseQuery -- own table, by primary key.
        if ($before) {
            $id = (int) $before['id'];
            $ok = $wpdb->update($table, $row, array('id' => $id));
        } else {
            $ok = $wpdb->insert($table, $row + array('since' => time(), 'falling' => 0));
            $id = (int) $wpdb->insert_id;
        }
        // phpcs:enable
        if ($ok === false || $id < 1) {
            return new WP_Error('seoproanalytics_gbp_save', __('The Business Profile location could not be saved.', 'seoproanalytics'));
        }
        if ($before) {
            $old = json_decode((string) $before['profile'], true);
            self::profile_changed($id, $path, $profile, is_array($old) ? self::profile($old) : array());
        }
        self::touch();
        return $id;
    }

    /**
     * Keep a location's daily figures (a day read again replaces its value).
     *
     * @param int                            $id   Location row id.
     * @param array<int,array<string,mixed>> $rows Each: day (Y-m-d), metric (code or Google's name), value.
     * @return int|false Rows kept, false on a database error.
     */
    public static function store_metrics($id, array $rows) {
        global $wpdb;
        $codes  = array_flip(self::METRICS);
        $values = array();
        foreach ($rows as $row) {
            $metric = isset($row['metric']) ? $row['metric'] : 0;
            $metric = is_int($metric) ? $metric : (int) ($codes[(string) $metric] ?? 0);
            $day    = isset($row['day']) ? (string) $row['day'] : '';
            if (isset(self::METRICS[$metric]) && self::is_day($day)) {
                $values[$day . ':' . $metric] = array((int) $id, $day, $metric, max(0, min(4294967295, (int) ($row['value'] ?? 0))));
            }
        }
        $count = 0;
        foreach (array_chunk(array_values($values), self::CHUNK) as $chunk) {
            $holders = implode(', ', array_fill(0, count($chunk), '(%d, %s, %d, %d)'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- own table, by primary key; $holders holds only fixed placeholders, one group per row.
            $done = $wpdb->query($wpdb->prepare("REPLACE INTO %i (location_id, day, metric, value) VALUES $holders", array_merge(array(SEOProAnalytics_Schema::table('gbp_metrics')), array_merge(...$chunk))));
            if ($done === false) {
                self::touch();
                return false;
            }
            $count += count($chunk);
        }
        if ($count > 0) {
            self::touch();
        }
        return $count;
    }

    /**
     * Keep the searches that showed a location in a month, in place of
     * that month's kept ones, in one transaction: on a database error the
     * month's earlier keywords stay. An empty list empties the month.
     *
     * @param int                            $id    Location row id.
     * @param string                         $month Any day of the month (Y-m-d).
     * @param array<int,array<string,mixed>> $rows  Each: keyword, impressions, below (Google gave only "fewer than" impressions).
     * @return int|false Rows kept, false on a database error.
     */
    public static function store_keywords($id, $month, array $rows) {
        global $wpdb;
        if (!self::is_day((string) $month)) {
            return 0;
        }
        $month = substr((string) $month, 0, 8) . '01';
        $kept  = array();
        foreach ($rows as $row) {
            $keyword = SEOProAnalytics_Dict::clean(trim(sanitize_text_field((string) ($row['keyword'] ?? ''))));
            if ($keyword !== '' && !isset($kept[$keyword])) {
                $kept[$keyword] = array(max(0, min(4294967295, (int) ($row['impressions'] ?? 0))), empty($row['below']) ? 0 : 1);
            }
        }
        uasort($kept, static function ($a, $b) { return $b[0] <=> $a[0]; });
        $kept  = array_slice($kept, 0, self::MAX_KEYWORDS, true);
        $ids   = $kept ? SEOProAnalytics_Dict::ids(SEOProAnalytics_Schema::DICT_QUERY, array_map('strval', array_keys($kept))) : array();
        $table  = SEOProAnalytics_Schema::table('gbp_keywords');
        $values = array();
        foreach ($kept as $keyword => $figures) {
            if (!empty($ids[(string) $keyword])) {
                $values[(int) $ids[(string) $keyword]] = array((int) $id, $month, (int) $ids[(string) $keyword], $figures[0], $figures[1]);
            }
        }
        // phpcs:disable WordPress.DB.DirectDatabaseQuery -- own table, by its primary key's prefix; the month's rows replaced in one transaction.
        if ($wpdb->query('START TRANSACTION') === false) {
            return false;
        }
        if ($wpdb->query($wpdb->prepare('DELETE FROM %i WHERE location_id = %d AND month = %s', $table, (int) $id, $month)) === false) {
            $wpdb->query('ROLLBACK');
            return false;
        }
        $count = 0;
        foreach (array_chunk(array_values($values), self::CHUNK) as $chunk) {
            $holders = implode(', ', array_fill(0, count($chunk), '(%d, %s, %d, %d, %d)'));
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $holders holds only fixed placeholders, one group per row.
            $done = $wpdb->query($wpdb->prepare("REPLACE INTO %i (location_id, month, keyword_id, impressions, below) VALUES $holders", array_merge(array($table), array_merge(...$chunk))));
            if ($done === false) {
                $wpdb->query('ROLLBACK');
                return false;
            }
            $count += count($chunk);
        }
        if ($wpdb->query('COMMIT') === false) {
            $wpdb->query('ROLLBACK');
            return false;
        }
        // phpcs:enable
        self::touch();
        return $count;
    }

    /**
     * Keep a location's reviews (a review read again replaces its row).
     * Reviews written since the location's first read are a change on the
     * timeline; then the falling-rating check runs.
     *
     * @param int                            $id      Location row id.
     * @param array<int,array<string,mixed>> $reviews Each: review (Google's ID), rating (1-5), created and updated (Unix), replied (Unix, 0 none), comment, reply.
     * @return int|false Rows kept, false on a database error.
     */
    public static function store_reviews($id, array $reviews) {
        global $wpdb;
        $table = SEOProAnalytics_Schema::table('gbp_reviews');
        $loc   = self::row((int) $id);
        if (!$loc) {
            return 0;
        }
        $values = array();
        foreach ($reviews as $review) {
            $key    = self::cut((string) preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($review['review'] ?? '')), 190);
            $rating = (int) ($review['rating'] ?? 0);
            if ($key === '' || $rating < 1 || $rating > 5) {
                continue;
            }
            $values[$key] = array(
                (int) $id,
                $key,
                $rating,
                max(0, (int) ($review['created'] ?? 0)),
                max(0, (int) ($review['updated'] ?? 0)),
                max(0, (int) ($review['replied'] ?? 0)),
                self::text($review['comment'] ?? ''),
                self::text($review['reply'] ?? ''),
            );
        }
        $known = self::known_reviews((int) $id, array_keys($values));
        $new   = array();
        foreach ($values as $key => $value) {
            if (!isset($known[$key]) && $value[3] >= (int) $loc['since']) {
                $new[] = $value;
            }
        }
        $count = 0;
        foreach (array_chunk(array_values($values), self::CHUNK) as $chunk) {
            $holders = implode(', ', array_fill(0, count($chunk), '(%d, %s, %d, %d, %d, %d, %s, %s)'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- own table, by primary key; $holders holds only fixed placeholders, one group per row.
            $done = $wpdb->query($wpdb->prepare("REPLACE INTO %i (location_id, review, rating, created, updated, replied, comment, reply) VALUES $holders", array_merge(array($table), array_merge(...$chunk))));
            if ($done === false) {
                self::touch();
                return false;
            }
            $count += count($chunk);
        }
        if ($new && SEOProAnalytics_Schema::set() === 'live') {
            self::reviews_changed($loc, $new);
        }
        self::check_rating($loc);
        self::touch();
        return $count;
    }

    /**
     * Forget one location and everything kept for it (unlinked). The
     * location's row goes last, so a failed delete can be run again.
     *
     * @param string $location Google's location ID.
     * @return int|WP_Error Rows deleted.
     */
    public static function remove_location($location) {
        global $wpdb;
        $location = self::google_id($location);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table, by its unique key.
        $id = $location !== '' ? (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE location = %s', SEOProAnalytics_Schema::table('gbp_locations'), $location)) : 0;
        if ($id < 1) {
            return 0;
        }
        $deleted = 0;
        foreach (array('gbp_metrics', 'gbp_keywords', 'gbp_reviews', 'gbp_locations') as $name) {
            $where = $name === 'gbp_locations' ? 'id' : 'location_id';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own tables, by primary key or its prefix.
            $rows = $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE %i = %d', SEOProAnalytics_Schema::table($name), $where, $id));
            if ($rows === false) {
                self::touch();
                return self::delete_failed();
            }
            $deleted += (int) $rows;
        }
        self::touch();
        return $deleted;
    }

    /**
     * Delete everything kept from Google Business Profile (a disconnect
     * with its data). The locations go last, so a failed delete stops
     * there and can be run again. Changes already on the timeline stay, as
     * other sources' do.
     *
     * @return int|WP_Error Rows deleted.
     */
    public static function delete_data() {
        global $wpdb;
        $deleted = 0;
        foreach (array('gbp_metrics', 'gbp_keywords', 'gbp_reviews', 'gbp_locations') as $name) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- explicitly confirmed connection data deletion, own tables.
            $rows = $wpdb->query($wpdb->prepare('DELETE FROM %i', SEOProAnalytics_Schema::table($name)));
            if ($rows === false) {
                self::touch();
                return self::delete_failed();
            }
            $deleted += (int) $rows;
        }
        self::touch();
        return $deleted;
    }

    /**
     * Delete Business Profile data older than the search data's retention
     * (Settings → Data): daily figures by day, monthly keywords by month,
     * in batches within the run's time. Locations and reviews mirror the
     * profile as Google shows it now, so they stay while the location is
     * linked.
     *
     * @param int   $months Months kept (0: forever).
     * @param float $start  microtime(true) when the run began.
     * @param int   $budget Seconds the run may take.
     * @return array{deleted:int,done:bool}
     */
    public static function prune($months, $start, $budget) {
        global $wpdb;
        $deleted = 0;
        if ($months <= 0) {
            return array('deleted' => 0, 'done' => true);
        }
        foreach (self::prune_before($months) as $name => $target) {
            do {
                if (!SEOProAnalytics_Feature::more_time($start, $budget)) {
                    self::touch();
                    return array('deleted' => $deleted, 'done' => false);
                }
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table, by its time key, in batches.
                $rows = $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE %i < %s LIMIT %d', SEOProAnalytics_Schema::table($name), $target[0], $target[1], self::PRUNE_BATCH));
                $deleted += (int) $rows;
            } while ($rows === self::PRUNE_BATCH);
        }
        if ($deleted > 0) {
            self::touch();
        }
        return array('deleted' => $deleted, 'done' => true);
    }

    /**
     * Rows a prune would delete now, by table (WP-CLI).
     *
     * @param int $months Months kept (0: forever).
     * @return array<string,int>
     */
    public static function prune_counts($months) {
        global $wpdb;
        $out = array();
        if ($months <= 0) {
            return $out;
        }
        foreach (self::prune_before($months) as $name => $target) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table, by its time key; WP-CLI only.
            $out[$name] = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE %i < %s', SEOProAnalytics_Schema::table($name), $target[0], $target[1]));
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Reads.

    /**
     * The kept locations, by title.
     *
     * @return array<int,array<string,mixed>> Each: id, location, account, path_id, title, profile (array), rating, reviews, checked, since, falling.
     */
    public static function rows() {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table, at most MAX_LOCATIONS rows.
        $rows = (array) $wpdb->get_results($wpdb->prepare('SELECT id, location, account, path_id, title, profile, rating, reviews, checked, since, falling FROM %i ORDER BY title LIMIT %d', SEOProAnalytics_Schema::table('gbp_locations'), self::MAX_LOCATIONS), ARRAY_A);
        return array_map(array(__CLASS__, 'shape_row'), $rows);
    }

    /**
     * The report: each location's figures in the range, its rating and
     * reviews, the searches that showed it in its latest month, and its
     * findings; with the range's daily figures for all locations.
     *
     * @param array<string,mixed> $req From SEOProAnalytics_Query::request().
     * @return array<string,mixed>
     */
    public static function report(array $req) {
        require_once __DIR__ . '/class-seoproanalytics-connections.php';
        // Its data stays readable; connected only while its source is registered.
        $conn = SEOProAnalytics_Connections::source_class(self::SOURCE) ? SEOProAnalytics_Connections::get(self::SOURCE) : null;
        $key  = array('range' => $req['range'], 'from' => $req['from'] ?? '', 'to' => $req['to'] ?? '', 'connected' => $conn !== null, 'stamp' => self::stamp());
        return SEOProAnalytics_Query::cached('gbp', $key, static function () use ($req, $conn) { return self::build($req, $conn !== null); });
    }

    /**
     * Each location's Plan findings, with what scores them: its website
     * clicks over the newest 28 days kept, and its linked page.
     *
     * @return array<int,array<string,mixed>> Each: id, location, title, path_id, path, website_clicks, findings (finding => figures).
     */
    public static function findings() {
        $rows = self::rows();
        if (!$rows) {
            return array();
        }
        $clicks  = self::recent_clicks();
        $reviews = self::review_figures(time() - self::UNANSWERED_DAYS * DAY_IN_SECONDS);
        $paths   = SEOProAnalytics_Query::texts(array_column($rows, 'path_id'));
        $out     = array();
        foreach ($rows as $row) {
            $path  = isset($paths[$row['path_id']]) ? $paths[$row['path_id']] : '/';
            $found = self::location_findings($row, $reviews[$row['id']] ?? array(), $path);
            if ($found) {
                $out[] = array(
                    'id'             => $row['id'],
                    'location'       => $row['location'],
                    'title'          => $row['title'],
                    'path_id'        => $row['path_id'],
                    'path'           => $path,
                    'website_clicks' => (int) ($clicks[$row['id']] ?? 0),
                    'findings'       => $found,
                );
            }
        }
        return $out;
    }

    /**
     * Whether a link has the campaign tags reports count it by.
     *
     * @param string $url Link.
     * @return bool
     */
    public static function tagged($url) {
        $query = (string) wp_parse_url((string) $url, PHP_URL_QUERY);
        parse_str($query, $args);
        return !empty($args['utm_source']) && !empty($args['utm_medium']);
    }

    // ------------------------------------------------------------------
    // Internals.

    /**
     * Build the report.
     *
     * @param array<string,mixed> $req       Request.
     * @param bool                $connected Whether the source is registered and connected.
     * @return array<string,mixed>
     */
    private static function build(array $req, $connected) {
        global $wpdb;
        $range   = SEOProAnalytics_Query::range($req);
        $from    = $range['start']->format('Y-m-d');
        $to      = $range['end']->modify('-1 day')->format('Y-m-d');
        $rows    = self::rows();
        $paths   = SEOProAnalytics_Query::texts(array_column($rows, 'path_id'));
        $metrics = SEOProAnalytics_Schema::table('gbp_metrics');
        // phpcs:disable WordPress.DB.DirectDatabaseQuery -- own table, the range's days by KEY day; grouped to locations × metrics and days × metrics.
        $totals = (array) $wpdb->get_results($wpdb->prepare('SELECT location_id, metric, SUM(value) AS n FROM %i WHERE day >= %s AND day <= %s GROUP BY location_id, metric', $metrics, $from, $to), ARRAY_A);
        $days   = (array) $wpdb->get_results($wpdb->prepare('SELECT day, metric, SUM(value) AS n FROM %i WHERE day >= %s AND day <= %s GROUP BY day, metric ORDER BY day', $metrics, $from, $to), ARRAY_A);
        $latest = (string) $wpdb->get_var($wpdb->prepare('SELECT MAX(day) FROM %i', $metrics));
        // phpcs:enable
        $by_location = array();
        foreach ($totals as $total) {
            $by_location[(int) $total['location_id']][(int) $total['metric']] = (int) $total['n'];
        }
        $series = array();
        foreach ($days as $day) {
            $series[(string) $day['day']][(int) $day['metric']] = (int) $day['n'];
        }
        $in_range = self::review_figures($range['from'], $range['to']);
        $open     = self::review_figures(time() - self::UNANSWERED_DAYS * DAY_IN_SECONDS);
        $sum      = self::figures(array());
        $out      = array();
        foreach ($rows as $row) {
            $id      = $row['id'];
            $path    = isset($paths[$row['path_id']]) ? $paths[$row['path_id']] : null;
            $figures = self::figures($by_location[$id] ?? array());
            foreach ($figures as $name => $value) {
                $sum[$name] += $value;
            }
            $website = (string) $row['profile']['website'];
            $out[]   = array(
                'id'           => $id,
                'location'     => $row['location'],
                'title'        => $row['title'],
                'page'         => $path,
                'url'          => $path !== null ? home_url($path) : null,
                'rating'       => $row['reviews'] > 0 ? $row['rating'] : null,
                'reviews'      => $row['reviews'],
                'checked'      => $row['checked'] ? gmdate('c', $row['checked']) : null,
                'profile'      => $row['profile'],
                'campaign'     => self::campaign($website),
                'tagged'       => $website !== '' && self::tagged($website),
                'totals'       => $figures,
                'new_reviews'  => array(
                    'count'  => (int) ($in_range[$id]['count'] ?? 0),
                    'rating' => isset($in_range[$id]['average']) ? round((float) $in_range[$id]['average'], 2) : null,
                ),
                'recent'       => array(
                    'count'  => (int) ($open[$id]['recent_count'] ?? 0),
                    'rating' => isset($open[$id]['recent_average']) ? round((float) $open[$id]['recent_average'], 2) : null,
                ),
                'unanswered'   => (int) ($open[$id]['unanswered'] ?? 0),
                'falling'      => self::is_falling($row['rating'], $open[$id] ?? array()),
                'keywords'     => self::keywords($id),
                'latest'       => self::latest_reviews($id),
                'findings'     => array_keys(self::location_findings($row, $open[$id] ?? array(), $path ?? '/')),
            );
        }
        $points = array();
        foreach ($series as $day => $values) {
            $points[] = array('day' => (string) $day) + self::figures($values);
        }
        return array(
            'connected' => $connected,
            'source'    => self::SOURCE,
            'range'     => SEOProAnalytics_Query::range_out($range),
            'through'   => $latest !== '' ? $latest : null,
            'totals'    => $sum,
            'series'    => $points,
            'locations' => $out,
        );
    }

    /**
     * Report figures from metric sums: each metric's, and Search and Maps
     * impressions over both devices.
     *
     * @param array<int,int> $values Metric code => sum.
     * @return array<string,int>
     */
    private static function figures(array $values) {
        $out = array();
        foreach (self::FIGURES as $code => $name) {
            $out[$name] = (int) ($values[$code] ?? 0);
        }
        return array(
            'search' => $out['search_desktop'] + $out['search_mobile'],
            'maps'   => $out['maps_desktop'] + $out['maps_mobile'],
        ) + $out;
    }

    /**
     * A location's findings now.
     *
     * @param array<string,mixed> $row     From rows().
     * @param array<string,mixed> $reviews From review_figures() (since UNANSWERED_DAYS).
     * @param string              $path    Its linked page.
     * @return array<string,array<string,mixed>> Finding => figures.
     */
    private static function location_findings(array $row, array $reviews, $path) {
        $out        = array();
        $unanswered = (int) ($reviews['unanswered'] ?? 0);
        if ($unanswered > 0) {
            $out['gbp_reviews'] = array('unanswered' => $unanswered, 'oldest' => gmdate('c', (int) $reviews['oldest']));
        }
        // From the reviews kept now, not the stored mark (which only finds when a fall starts, for the timeline).
        if (self::is_falling($row['rating'], $reviews)) {
            $out['gbp_rating'] = array(
                'rating'        => $row['rating'],
                'recent_rating' => isset($reviews['recent_average']) ? round((float) $reviews['recent_average'], 2) : null,
                'recent_count'  => (int) ($reviews['recent_count'] ?? 0),
            );
        }
        $absent = array();
        foreach (self::BASICS as $field) {
            if ($row['profile'][$field] === '' || $row['profile'][$field] === array()) {
                $absent[] = $field;
            }
        }
        if ($absent) {
            $out['gbp_incomplete'] = array('absent' => $absent);
        }
        $website = (string) $row['profile']['website'];
        if ($website !== '') {
            $problem = !self::own_host($website) ? 'other_site' : (!self::tagged($website) ? 'untagged' : '');
            if ($problem !== '') {
                $out['gbp_link'] = array('problem' => $problem, 'website' => $website, 'link' => self::suggested_link($row, $path));
            }
        }
        $differs = self::details_differ($row['profile']);
        if ($differs) {
            $out['gbp_details'] = array('fields' => array_keys($differs), 'values' => $differs);
        }
        return $out;
    }

    /**
     * The profile's name, phone number and postcode that differ from the
     * site's own business details (SEOProAnalytics_Site_Details). A detail
     * the SEO plugins do not hold, or the profile lacks, is not compared.
     *
     * @param array<string,mixed> $profile A location's profile.
     * @return array<string,array{profile:string,site:string}> Field (name, phone, postcode) => both values.
     */
    private static function details_differ(array $profile) {
        require_once __DIR__ . '/class-seoproanalytics-site-details.php';
        $site = SEOProAnalytics_Site_Details::get();
        $out  = array();
        foreach (array('name' => 'title', 'phone' => 'phone', 'postcode' => 'postcode') as $field => $key) {
            $mine = (string) ($profile[$key] ?? '');
            if (isset($site[$field]) && $mine !== '' && !SEOProAnalytics_Site_Details::same($field, $site[$field], $mine)) {
                $out[$field] = array('profile' => $mine, 'site' => $site[$field]);
            }
        }
        return $out;
    }

    /**
     * The tagged link to set as a location's website: a listed Google
     * profile's for its page (Settings → Data → Business profiles), else
     * one tagged as such a profile's would be.
     *
     * @param array<string,mixed> $row  From rows().
     * @param string              $path Its linked page.
     * @return string
     */
    private static function suggested_link(array $row, $path) {
        require_once __DIR__ . '/class-seoproanalytics-business-profiles.php';
        foreach (SEOProAnalytics_Business_Profiles::listed() as $profile) {
            if ($profile['platform'] === 'google' && $profile['page'] === $path) {
                return $profile['link'];
            }
        }
        $campaign = sanitize_title($row['title']);
        return SEOProAnalytics_Business_Profiles::link(array('page' => $path, 'source' => 'google', 'campaign' => $campaign !== '' ? substr($campaign, 0, SEOProAnalytics_Business_Profiles::MAX_CAMPAIGN) : 'google-business-profile'));
    }

    /**
     * Per location: reviews written in [from, to), their average, those
     * without a reply and the oldest of them, and the last RATING_DAYS'
     * count and average.
     *
     * @param int $from Unix time.
     * @param int $to   Unix time (0: now).
     * @return array<int,array<string,int|float>>
     */
    private static function review_figures($from, $to = 0) {
        global $wpdb;
        $table  = SEOProAnalytics_Schema::table('gbp_reviews');
        $to     = $to > 0 ? (int) $to : time() + 1;
        $recent = time() - self::RATING_DAYS * DAY_IN_SECONDS;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table; at most MAX_LOCATIONS groups.
        $rows = (array) $wpdb->get_results($wpdb->prepare('SELECT location_id, COUNT(*) AS n, AVG(rating) AS average, SUM(replied = 0) AS unanswered, MIN(IF(replied = 0, created, NULL)) AS oldest, SUM(created >= %d) AS recent_count, AVG(IF(created >= %d, rating, NULL)) AS recent_average FROM %i WHERE created >= %d AND created < %d GROUP BY location_id', $recent, $recent, $table, (int) $from, $to), ARRAY_A);
        $out  = array();
        foreach ($rows as $row) {
            $out[(int) $row['location_id']] = array(
                'count'          => (int) $row['n'],
                'average'        => (float) $row['average'],
                'unanswered'     => (int) $row['unanswered'],
                'oldest'         => (int) $row['oldest'],
                'recent_count'   => (int) $row['recent_count'],
            ) + ($row['recent_average'] !== null ? array('recent_average' => (float) $row['recent_average']) : array());
        }
        return $out;
    }

    /**
     * The searches that showed a location in its newest month kept, most
     * shown first.
     *
     * @param int $id Location row id.
     * @return array{month:string|null,rows:array<int,array{keyword:string,impressions:int,below:bool}>}
     */
    private static function keywords($id) {
        global $wpdb;
        $table = SEOProAnalytics_Schema::table('gbp_keywords');
        // phpcs:disable WordPress.DB.DirectDatabaseQuery -- own table, by its primary key's prefix; at most MAX_KEYWORDS rows sorted.
        $month = (string) $wpdb->get_var($wpdb->prepare('SELECT MAX(month) FROM %i WHERE location_id = %d', $table, (int) $id));
        $rows  = $month !== '' ? (array) $wpdb->get_results($wpdb->prepare('SELECT keyword_id, impressions, below FROM %i WHERE location_id = %d AND month = %s ORDER BY below, impressions DESC LIMIT 20', $table, (int) $id, $month), ARRAY_A) : array();
        // phpcs:enable
        $texts = SEOProAnalytics_Query::texts(array_column($rows, 'keyword_id'));
        $out   = array();
        foreach ($rows as $row) {
            $out[] = array('keyword' => (string) ($texts[(int) $row['keyword_id']] ?? ''), 'impressions' => (int) $row['impressions'], 'below' => (bool) $row['below']);
        }
        return array('month' => $month !== '' ? substr($month, 0, 7) : null, 'rows' => $out);
    }

    /**
     * A location's newest reviews, with the start of their text.
     *
     * @param int $id Location row id.
     * @return array<int,array{rating:int,created:string,replied:bool,comment:string}>
     */
    private static function latest_reviews($id) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table, KEY location_created, five rows.
        $rows = (array) $wpdb->get_results($wpdb->prepare('SELECT rating, created, replied, comment FROM %i WHERE location_id = %d ORDER BY created DESC LIMIT 5', SEOProAnalytics_Schema::table('gbp_reviews'), (int) $id), ARRAY_A);
        $out  = array();
        foreach ($rows as $row) {
            $out[] = array(
                'rating'  => (int) $row['rating'],
                'created' => gmdate('c', (int) $row['created']),
                'replied' => (int) $row['replied'] > 0,
                'comment' => wp_html_excerpt((string) $row['comment'], 300, '…'),
            );
        }
        return $out;
    }

    /**
     * Website clicks per location over the newest 28 days kept.
     *
     * @return array<int,int> Location row id => clicks.
     */
    private static function recent_clicks() {
        global $wpdb;
        $table = SEOProAnalytics_Schema::table('gbp_metrics');
        // phpcs:disable WordPress.DB.DirectDatabaseQuery -- own table; MAX() on KEY day, then 28 days of one metric.
        $latest = (string) $wpdb->get_var($wpdb->prepare('SELECT MAX(day) FROM %i', $table));
        if ($latest === '') {
            return array();
        }
        $from = gmdate('Y-m-d', (int) strtotime($latest . ' -27 days'));
        $rows = (array) $wpdb->get_results($wpdb->prepare('SELECT location_id, SUM(value) AS n FROM %i WHERE day >= %s AND day <= %s AND metric = %d GROUP BY location_id', $table, $from, $latest, 5), ARRAY_A);
        // phpcs:enable
        $out = array();
        foreach ($rows as $row) {
            $out[(int) $row['location_id']] = (int) $row['n'];
        }
        return $out;
    }

    /**
     * Record a profile edit: the fields that differ from the profile kept.
     *
     * @param int                 $id     Location row id.
     * @param string              $path   Its linked page.
     * @param array<string,mixed> $now    Profile read now.
     * @param array<string,mixed> $before Profile kept.
     */
    private static function profile_changed($id, $path, array $now, array $before) {
        if (!$before || SEOProAnalytics_Schema::set() !== 'live') {
            return;
        }
        $fields = array();
        foreach (self::FIELDS as $field) {
            if ($now[$field] !== $before[$field]) {
                $fields[] = $field;
            }
        }
        if (!$fields) {
            return;
        }
        $one = count($fields) === 1 && !is_array($now[$fields[0]]);
        require_once __DIR__ . '/class-seoproanalytics-changes.php';
        SEOProAnalytics_Changes::record(self::PROFILE_CHANGE, array(
            'path'        => $path,
            'object_type' => 'gbp',
            'object_id'   => (int) $id,
            'old'         => $one ? (string) $before[$fields[0]] : '',
            'new'         => $one ? (string) $now[$fields[0]] : implode(', ', $fields),
            'meta'        => array('name' => $now['title'] !== '' ? $now['title'] : $before['title'], 'fields' => $fields),
            'user_id'     => 0,
        ));
    }

    /**
     * Record new reviews: how many, and their stars.
     *
     * @param array<string,mixed>       $loc Location row.
     * @param array<int,array<int,mixed>> $new The new reviews' rows (rating third, created fourth).
     */
    private static function reviews_changed(array $loc, array $new) {
        $ratings = array();
        $newest  = 0;
        foreach ($new as $row) {
            $ratings[] = (int) $row[2];
            $newest    = max($newest, (int) $row[3]);
        }
        if (!$ratings) {
            return;
        }
        require_once __DIR__ . '/class-seoproanalytics-changes.php';
        SEOProAnalytics_Changes::record(self::REVIEWS_CHANGE, array(
            'ts'          => $newest,
            'path'        => self::path_of((int) $loc['path_id']),
            'object_type' => 'gbp',
            'object_id'   => (int) $loc['id'],
            'new'         => (string) count($new),
            'meta'        => array('name' => (string) $loc['title'], 'count' => count($new), 'average' => round(array_sum($ratings) / count($ratings), 2), 'lowest' => min($ratings)),
            'user_id'     => 0,
        ));
    }

    /**
     * Mark a location whose recent reviews average well below its overall
     * rating, and record the fall when it starts.
     *
     * @param array<string,mixed> $loc Location row.
     */
    private static function check_rating(array $loc) {
        global $wpdb;
        $figures = self::review_figures(time() - self::RATING_DAYS * DAY_IN_SECONDS)[(int) $loc['id']] ?? array();
        $count   = (int) ($figures['recent_count'] ?? 0);
        $recent  = isset($figures['recent_average']) ? (float) $figures['recent_average'] : null;
        $rating  = (float) $loc['rating'];
        $falling = self::is_falling($rating, $figures) ? 1 : 0;
        if ($falling === (int) $loc['falling']) {
            return;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table, by primary key.
        $wpdb->update(SEOProAnalytics_Schema::table('gbp_locations'), array('falling' => $falling), array('id' => (int) $loc['id']));
        if ($falling && SEOProAnalytics_Schema::set() === 'live') {
            require_once __DIR__ . '/class-seoproanalytics-changes.php';
            SEOProAnalytics_Changes::record(self::RATING_CHANGE, array(
                'path'        => self::path_of((int) $loc['path_id']),
                'object_type' => 'gbp',
                'object_id'   => (int) $loc['id'],
                'old'         => number_format($rating, 1, '.', ''),
                'new'         => number_format((float) $recent, 1, '.', ''),
                'meta'        => array('name' => (string) $loc['title'], 'count' => $count, 'days' => self::RATING_DAYS),
                'user_id'     => 0,
            ));
        }
    }

    /**
     * Whether a location's rating is falling: at least RATING_MIN reviews
     * in the last RATING_DAYS, averaging RATING_DROP stars or more below
     * its overall rating.
     *
     * @param float               $rating  Its overall rating (0: none).
     * @param array<string,mixed> $figures From review_figures(): recent_count and recent_average.
     * @return bool
     */
    private static function is_falling($rating, array $figures) {
        $rating = (float) $rating;
        return (int) ($figures['recent_count'] ?? 0) >= self::RATING_MIN && isset($figures['recent_average']) && $rating > 0 && (float) $figures['recent_average'] <= $rating - self::RATING_DROP;
    }

    /**
     * One location's row.
     *
     * @param int $id Row id.
     * @return array<string,mixed>|null
     */
    private static function row($id) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table, by primary key.
        $row = $wpdb->get_row($wpdb->prepare('SELECT id, location, account, path_id, title, profile, rating, reviews, checked, since, falling FROM %i WHERE id = %d', SEOProAnalytics_Schema::table('gbp_locations'), (int) $id), ARRAY_A);
        return is_array($row) ? self::shape_row($row) : null;
    }

    /**
     * A location row with its types.
     *
     * @param array<string,mixed> $row Table row.
     * @return array<string,mixed>
     */
    private static function shape_row(array $row) {
        $profile = json_decode((string) $row['profile'], true);
        return array(
            'id'       => (int) $row['id'],
            'location' => (string) $row['location'],
            'account'  => (string) $row['account'],
            'path_id'  => (int) $row['path_id'],
            'title'    => (string) $row['title'],
            'profile'  => self::profile(is_array($profile) ? $profile : array()),
            'rating'   => round((float) $row['rating'], 2),
            'reviews'  => (int) $row['reviews'],
            'checked'  => (int) $row['checked'],
            'since'    => (int) $row['since'],
            'falling'  => (bool) $row['falling'],
        );
    }

    /**
     * Which of some reviews are kept already.
     *
     * @param int      $id   Location row id.
     * @param string[] $keys Google's review IDs.
     * @return array<string,bool>
     */
    private static function known_reviews($id, array $keys) {
        global $wpdb;
        $out = array();
        foreach (array_chunk($keys, self::CHUNK) as $chunk) {
            $holders = implode(', ', array_fill(0, count($chunk), '%s'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- own table, by primary key; placeholders built above.
            $found = (array) $wpdb->get_col($wpdb->prepare("SELECT review FROM %i WHERE location_id = %d AND review IN ($holders)", array_merge(array(SEOProAnalytics_Schema::table('gbp_reviews'), (int) $id), $chunk)));
            foreach ($found as $key) {
                $out[(string) $key] = true;
            }
        }
        return $out;
    }

    /**
     * The report cache's version: the newest read of any location, and
     * the kept data's version (touch()), so a report never outlives a
     * write.
     *
     * @return string
     */
    private static function stamp() {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table, at most MAX_LOCATIONS rows.
        $checked = (int) $wpdb->get_var($wpdb->prepare('SELECT MAX(checked) FROM %i', SEOProAnalytics_Schema::table('gbp_locations')));
        return $checked . ':' . (int) get_option(SEOProAnalytics_Schema::option(self::VERSION_OPTION), 0);
    }

    /**
     * Move the kept data's version after a write, a failed one too (part
     * of it may have been written). Only the add-on's read, a disconnect
     * and the daily prune write, never a visitor's request.
     */
    private static function touch() {
        $name = SEOProAnalytics_Schema::option(self::VERSION_OPTION);
        update_option($name, (int) get_option($name, 0) + 1, false);
    }

    /**
     * The cut-off per table for a retention: daily figures before the
     * day, keyword months before the cut-off's month.
     *
     * @param int $months Months kept.
     * @return array<string,array{0:string,1:string}> Table => [time column, first value kept].
     */
    private static function prune_before($months) {
        $before = (new DateTimeImmutable('today', wp_timezone()))->modify('-' . (int) $months . ' months');
        return array(
            'gbp_metrics'  => array('day', $before->format('Y-m-d')),
            'gbp_keywords' => array('month', $before->format('Y-m-01')),
        );
    }

    /**
     * A delete that did not complete.
     *
     * @return WP_Error
     */
    private static function delete_failed() {
        return new WP_Error('seoproanalytics_gbp_delete', __('Some Google Business Profile data could not be deleted. Please try again.', 'seoproanalytics'), array('status' => 500));
    }

    /**
     * A profile with every field, cleaned.
     *
     * @param array<string,mixed> $input Fields.
     * @return array<string,mixed>
     */
    private static function profile(array $input) {
        $list = static function ($value, $max) {
            $out = array();
            foreach (is_array($value) ? $value : array() as $item) {
                $item = self::cut(sanitize_text_field((string) $item), 190);
                if ($item !== '' && !in_array($item, $out, true)) {
                    $out[] = $item;
                }
            }
            return array_slice($out, 0, $max);
        };
        $hours = $list($input['hours'] ?? array(), 50);
        sort($hours);
        $website = isset($input['website']) ? esc_url_raw(trim((string) $input['website']), array('http', 'https')) : '';
        return array(
            'title'       => self::cut(sanitize_text_field((string) ($input['title'] ?? '')), 190),
            'categories'  => $list($input['categories'] ?? array(), 10),
            'hours'       => $hours,
            'description' => self::cut(sanitize_textarea_field((string) ($input['description'] ?? '')), 1000),
            'website'     => self::cut($website, 2000),
            'phone'       => self::cut(sanitize_text_field((string) ($input['phone'] ?? '')), 40),
            'address'     => self::cut(sanitize_text_field((string) ($input['address'] ?? '')), 300),
            'postcode'    => self::cut(sanitize_text_field((string) ($input['postcode'] ?? '')), 20),
        );
    }

    /**
     * utm_campaign of a link, or ''.
     *
     * @param string $url Link.
     * @return string
     */
    private static function campaign($url) {
        parse_str((string) wp_parse_url((string) $url, PHP_URL_QUERY), $args);
        return isset($args['utm_campaign']) && is_string($args['utm_campaign']) ? sanitize_text_field($args['utm_campaign']) : '';
    }

    /**
     * Whether a link goes to this site (its host, with or without www).
     *
     * @param string $url Link.
     * @return bool
     */
    private static function own_host($url) {
        $strip = static function ($host) {
            $host = strtolower((string) $host);
            return strpos($host, 'www.') === 0 ? substr($host, 4) : $host;
        };
        return $strip(wp_parse_url((string) $url, PHP_URL_HOST)) === $strip(wp_parse_url(home_url('/'), PHP_URL_HOST));
    }

    /**
     * A local page path ('/' for none or an address elsewhere), as
     * Business profiles read a profile's page (a site in a folder too).
     *
     * @param string $page Path or address on this site.
     * @return string
     */
    private static function local_path($page) {
        require_once __DIR__ . '/class-seoproanalytics-business-profiles.php';
        return SEOProAnalytics_Business_Profiles::page($page);
    }

    /**
     * The path of a dictionary id ('' for none).
     *
     * @param int $path_id Path id.
     * @return string
     */
    private static function path_of($path_id) {
        return $path_id > 0 ? (string) (SEOProAnalytics_Query::texts(array($path_id))[$path_id] ?? '') : '';
    }

    /**
     * Google's numeric ID from an ID or a resource name (locations/123).
     *
     * @param mixed $value ID.
     * @return string Digits, or ''.
     */
    private static function google_id($value) {
        $parts = explode('/', is_scalar($value) ? (string) $value : '');
        $value = (string) end($parts);
        return preg_match('/^\d{1,32}$/', $value) ? $value : '';
    }

    /**
     * A review's or reply's text, kept to MAX_TEXT bytes.
     *
     * @param mixed $value Text.
     * @return string
     */
    private static function text($value) {
        return self::cut(sanitize_textarea_field(is_scalar($value) ? (string) $value : ''), self::MAX_TEXT);
    }

    /**
     * A text cut to at most some bytes, never inside a character.
     *
     * @param string $value Text.
     * @param int    $bytes Most bytes.
     * @return string
     */
    private static function cut($value, $bytes) {
        $value = (string) $value;
        if (strlen($value) <= $bytes) {
            return $value;
        }
        return function_exists('mb_strcut') ? mb_strcut($value, 0, $bytes, 'UTF-8') : substr($value, 0, $bytes);
    }

    /**
     * Whether a text is a YYYY-MM-DD date.
     *
     * @param string $value Text.
     * @return bool
     */
    private static function is_day($value) {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value;
    }
}
