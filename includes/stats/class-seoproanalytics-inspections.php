<?php
/**
 * Search Console site data, kept and reported: the sitemaps submitted
 * for the property and Google's URL Inspection of the site's pages (the
 * version in Google's index, not a live test). The free plugin never
 * asks Google: the SEO Pro Analytics Connect add-on's read writes them
 * through write_sitemaps(), store() and save(), and the demo data does.
 *
 * Sitemaps, kept in the OPTION: each sitemap's path, type, last
 * submitted and downloaded, pending, errors, warnings and contents.
 * Errors or warnings, a sitemap Google has not downloaded for STALE_DAYS
 * days, or the site's own sitemap index not submitted at all are
 * problems: the Indexation report's Sitemaps card shows them and the
 * decision queue lists each (SEOProAnalytics_Queue).
 *
 * URL Inspection: one inspections row per page, Google's values as codes
 * and dictionary ids; findings in its flags (FLAGS): Google chose
 * another canonical, blocked by robots.txt, crawled but not indexed,
 * rich result errors. A page whose verdict changes is a change
 * (index_status) on the timeline.
 *
 * Reads: inspections by its primary key (pages listed), its checked,
 * verdict_checked and flags keys. Design: docs/seo-loop.md → Indexation.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 * Provenance: wpas-c8662019b99fb07b. Copies, even of part, keep these lines.
 *
 * @package SEOProAnalytics
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Facade shared by the add-on's read, CLI, reports and demo data for one
 * inspection store. Keeping these entry points together preserves its
 * quota and storage contract.
 *
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity") The facade coordinates the complete inspection lifecycle; procedural steps are private helpers.
 * @SuppressWarnings("PHPMD.ExcessiveClassLength") Normalization and report field maps belong to the same stored inspection contract.
 * @SuppressWarnings("PHPMD.TooManyMethods") Named private steps keep lifecycle procedures independently readable.
 * @SuppressWarnings("PHPMD.TooManyPublicMethods") Existing cron, CLI, report and demo entry points are compatibility contracts.
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") The facade integrates existing dictionary, search, indexation and timeline services.
 */
final class SEOProAnalytics_Inspections { // NOSONAR: a compatibility facade for cron, CLI, reports and demo data; private helpers decompose its procedures.

    /** Remove the optional www host prefix when comparing site addresses. */
    private const WWW_PREFIX = '/^www\./i';

    /** Search Console's source key (SEOProAnalytics_Connections::sources()). */
    const SOURCE = 'search-console';

    /** Google's verdicts: code => value (0: unspecified). Codes never change meaning. */
    const VERDICTS = array(1 => 'PASS', 2 => 'PARTIAL', 3 => 'FAIL', 4 => 'NEUTRAL');

    /** Whether the page blocks indexing (indexingState). */
    const INDEXING = array(1 => 'INDEXING_ALLOWED', 2 => 'BLOCKED_BY_META_TAG', 3 => 'BLOCKED_BY_HTTP_HEADER', 4 => 'BLOCKED_BY_ROBOTS_TXT');

    /** Whether robots.txt blocks Google (robotsTxtState). */
    const ROBOTS = array(1 => 'ALLOWED', 2 => 'DISALLOWED');

    /** Whether Google could fetch the page (pageFetchState). */
    const FETCH = array(
        1  => 'SUCCESSFUL',
        2  => 'SOFT_404',
        3  => 'BLOCKED_ROBOTS_TXT',
        4  => 'NOT_FOUND',
        5  => 'ACCESS_DENIED',
        6  => 'SERVER_ERROR',
        7  => 'REDIRECT_ERROR',
        8  => 'ACCESS_FORBIDDEN',
        9  => 'BLOCKED_4XX',
        10 => 'INTERNAL_CRAWL_ERROR',
        11 => 'INVALID_URL',
    );

    /** Google's primary crawler (crawledAs). */
    const CRAWLED_AS = array(1 => 'DESKTOP', 2 => 'MOBILE');

    /** Findings, most serious first: bit in the flags column. */
    const FLAGS = array(
        'robots_blocked'   => 1,
        'not_indexed'      => 2,
        'google_canonical' => 4,
        'rich_errors'      => 8,
    );

    /** Sitemap problems (queue findings are sitemap_ and the problem). */
    const SITEMAP_PROBLEMS = array('errors', 'missing', 'stale', 'warnings');

    /** Share of a typical shown page's clicks a sitemap problem puts at stake (the decision queue's potential clicks). */
    const SITEMAP_SHARE = array('errors' => 1.0, 'missing' => 1.0, 'stale' => 0.5, 'warnings' => 0.2);

    /** Progress, per data set (autoload off): sitemaps, sitemaps_read, sitemaps_error, day, used, last, error, version. */
    const OPTION = 'seoproanalytics_inspections';

    /** The add-on's read, one at a time (autoload off): the time it started. */
    const LOCK_OPTION = 'seoproanalytics_inspections_lock';

    /** Seconds per run of the add-on's read. */
    const BUDGET = 20;

    /** Days before a page is inspected again. */
    const RECHECK_DAYS = 14;

    /** Days without Google downloading a sitemap before it is a problem. */
    const STALE_DAYS = 7;

    /** Rich result types, issues, sitemaps and referring addresses kept per page. */
    const MAX_LIST = 5;

    /** Rows read at most per report, and per IN list. */
    const MAX_ROWS = 5000;
    const CHUNK    = 500;

    /** Rows listed: default and most. */
    const LIMIT     = 50;
    const MAX_LIMIT = 500;

    /** The sitemap index of each SEO plugin, under the home address (WordPress's own: get_sitemap_url()). */
    const PLUGIN_SITEMAPS = array(
        'yoast'     => '/sitemap_index.xml',
        'rank-math' => '/sitemap_index.xml',
        'seopress'  => '/sitemaps.xml',
        'aioseo'    => '/sitemap.xml',
    );

    // ------------------------------------------------------------------
    // Storing.

    /**
     * Keep sitemaps as read (the add-on's read, and the demo's).
     *
     * @param array<int,array<string,mixed>> $rows From sitemap_row().
     * @param int                            $read When they were read.
     */
    public static function write_sitemaps(array $rows, $read = 0) {
        self::save(array(
            'sitemaps'       => array_values($rows),
            'sitemaps_read'  => $read ? (int) $read : time(),
            'sitemaps_error' => null,
            'version'        => time(),
        ));
    }

    /**
     * One sitemap as Google gives it, as kept.
     *
     * @param array<string,mixed> $one WmxSitemap.
     * @return array<string,mixed>
     */
    public static function sitemap_row(array $one) {
        return array(
            'path'       => isset($one['path']) ? esc_url_raw((string) $one['path']) : '',
            'type'       => isset($one['type']) ? sanitize_key((string) $one['type']) : '',
            'index'      => !empty($one['isSitemapsIndex']),
            'pending'    => !empty($one['isPending']),
            'submitted'  => self::sitemap_time($one, 'lastSubmitted'),
            'downloaded' => self::sitemap_time($one, 'lastDownloaded'),
            'errors'     => self::integer_field($one, 'errors'),
            'warnings'   => self::integer_field($one, 'warnings'),
            'contents'   => self::sitemap_contents($one),
        );
    }

    /**
     * Keep submitted counts only; Google's indexed count is deprecated.
     *
     * @param array<string,mixed> $one Sitemap response.
     * @return array<int,array{type:string,submitted:int}>
     */
    private static function sitemap_contents(array $one) {
        $contents = array();
        foreach (self::array_field($one, 'contents') as $content) {
            if (is_array($content)) {
                // indexed is deprecated: not kept.
                $contents[] = array(
                    'type'      => isset($content['type']) ? sanitize_key((string) $content['type']) : '',
                    'submitted' => isset($content['submitted']) ? (int) $content['submitted'] : 0,
                );
            }
        }
        return $contents;
    }

    /**
     * An optional whole-number field from a remote response.
     *
     * @param array<string,mixed> $one Response.
     * @param string              $key Field.
     * @return int
     */
    private static function integer_field(array $one, $key) {
        return isset($one[$key]) ? (int) $one[$key] : 0;
    }

    /**
     * An optional list field from a remote response.
     *
     * @param array<string,mixed> $one Response.
     * @param string              $key Field.
     * @return array<mixed>
     */
    private static function array_field(array $one, $key) {
        return isset($one[$key]) && is_array($one[$key]) ? $one[$key] : array();
    }

    /**
     * Convert an optional sitemap timestamp, including Google's empty values.
     *
     * @param array<string,mixed> $one Sitemap response.
     * @param string $key Timestamp field.
     * @return int
     */
    private static function sitemap_time(array $one, $key) {
        $ts = isset($one[$key]) ? strtotime((string) $one[$key]) : false;
        return $ts ? (int) $ts : 0;
    }

    /**
     * Keep one page's inspection (Google's inspectionResult), and record
     * a change when its verdict changed (live data).
     *
     * @param int                 $path_id Path id (0: found from the path).
     * @param string              $path    Path.
     * @param array<string,mixed> $result  inspectionResult; empty when Google would not inspect it.
     * @param int                 $checked When it was inspected.
     * @param string              $error   Google's message when it would not.
     * @return bool
     */
    public static function store($path_id, $path, array $result, $checked, $error = '') {
        global $wpdb;
        require_once __DIR__ . '/class-seoproanalytics-dict.php';
        require_once __DIR__ . '/class-seoproanalytics-query.php';
        $path_id = (int) $path_id;
        if (!$path_id) {
            $path_id = self::dict_id(SEOProAnalytics_Dict::ids(SEOProAnalytics_Schema::DICT_PATH, array((string) $path)), (string) $path);
        }
        if (!$path_id) {
            return false;
        }
        $row     = self::parse($result, self::url((string) $path));
        $urls    = SEOProAnalytics_Dict::ids(SEOProAnalytics_Schema::DICT_URL, array($row['google_canonical'], $row['user_canonical']));
        $cover   = SEOProAnalytics_Dict::ids(SEOProAnalytics_Schema::DICT_COVERAGE, array($row['coverage']));
        $details = $row['details'];
        if ($error !== '') {
            $details['error'] = sanitize_text_field($error);
        }
        $table  = SEOProAnalytics_Schema::table('inspections');
        // phpcs:disable WordPress.DB.DirectDatabaseQuery -- our own table, by its primary key.
        $before = $wpdb->get_row($wpdb->prepare('SELECT verdict, coverage_id FROM %i WHERE path_id = %d', $table, $path_id), ARRAY_A);
        $done   = $wpdb->query($wpdb->prepare(
            'INSERT INTO %i (path_id, checked, verdict, coverage_id, indexing, robots, page_fetch, crawled_as, crawled, google_canonical_id, user_canonical_id, rich, flags, details) VALUES (%d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %s)'
            . ' ON DUPLICATE KEY UPDATE checked = VALUES(checked), verdict = VALUES(verdict), coverage_id = VALUES(coverage_id), indexing = VALUES(indexing), robots = VALUES(robots), page_fetch = VALUES(page_fetch), crawled_as = VALUES(crawled_as), crawled = VALUES(crawled), google_canonical_id = VALUES(google_canonical_id), user_canonical_id = VALUES(user_canonical_id), rich = VALUES(rich), flags = VALUES(flags), details = VALUES(details)',
            $table,
            $path_id,
            (int) $checked,
            $row['verdict'],
            self::dict_id($cover, $row['coverage']),
            $row['indexing'],
            $row['robots'],
            $row['page_fetch'],
            $row['crawled_as'],
            $row['crawled'],
            self::dict_id($urls, $row['google_canonical']),
            self::dict_id($urls, $row['user_canonical']),
            $row['rich'],
            $row['flags'],
            (string) wp_json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        ));
        // phpcs:enable
        if (is_array($before)) {
            self::record_verdict_change($before, $row, (string) $path, (int) $checked);
        }
        return $done !== false;
    }

    /**
     * A dictionary id from SEOProAnalytics_Dict::ids()'s answer, or 0.
     *
     * @param array<string,int> $ids  Clean text => id.
     * @param string            $text Text.
     * @return int
     */
    private static function dict_id(array $ids, $text) {
        $clean = SEOProAnalytics_Dict::clean((string) $text);
        return isset($ids[$clean]) ? (int) $ids[$clean] : 0;
    }

    /**
     * Record a change when a page's verdict changed (live data only).
     *
     * @param array<string,mixed> $before  The stored verdict and coverage_id.
     * @param array<string,mixed> $row     From parse().
     * @param string              $path    Path.
     * @param int                 $checked When it was inspected.
     * @return void
     */
    private static function record_verdict_change(array $before, array $row, $path, $checked) {
        $was = (int) $before['verdict'];
        if (!$row['verdict'] || !$was || $was === $row['verdict'] || SEOProAnalytics_Schema::set() !== 'live') {
            return;
        }
        require_once __DIR__ . '/class-seoproanalytics-changes.php';
        $coverage_id = (int) $before['coverage_id'];
        $old         = SEOProAnalytics_Query::texts(array($coverage_id));
        SEOProAnalytics_Changes::record(SEOProAnalytics_Changes::INDEX_STATUS, array(
            'ts'          => $checked,
            'path'        => $path,
            'object_type' => 'inspection',
            'old'         => isset($old[$coverage_id]) ? (string) $old[$coverage_id] : '',
            'new'         => $row['coverage'],
            'meta'        => array('name' => $path, 'verdict' => self::verdict_name((int) $row['verdict']), 'verdict_before' => self::verdict_name($was)),
            // WP-CLI (2) or the import cron (4).
            'source'      => defined('WP_CLI') && WP_CLI ? 2 : 4,
            'user_id'     => 0,
        ));
    }

    /**
     * A verdict code's name, or ''.
     *
     * @param int $code Code from VERDICTS.
     * @return string
     */
    private static function verdict_name($code) {
        return isset(self::VERDICTS[$code]) ? self::VERDICTS[$code] : '';
    }

    /**
     * An inspectionResult as kept: codes, texts, findings and details.
     *
     * @param array<string,mixed> $result inspectionResult.
     * @param string              $url    The address inspected.
     * @return array{verdict:int,coverage:string,indexing:int,robots:int,page_fetch:int,crawled_as:int,crawled:int,google_canonical:string,user_canonical:string,rich:int,flags:int,details:array<string,mixed>}
     */
    public static function parse(array $result, $url) {
        $index    = self::array_field($result, 'indexStatusResult');
        $rich     = self::array_field($result, 'richResultsResult');
        $types    = self::rich_types($rich);
        $google   = esc_url_raw(self::text_of($index, 'googleCanonical'));
        $user     = esc_url_raw(self::text_of($index, 'userCanonical'));
        $coverage = sanitize_text_field(self::text_of($index, 'coverageState'));
        $codes    = array(
            'robots'     => self::code_of(self::ROBOTS, self::text_of($index, 'robotsTxtState')),
            'indexing'   => self::code_of(self::INDEXING, self::text_of($index, 'indexingState')),
            'page_fetch' => self::code_of(self::FETCH, self::text_of($index, 'pageFetchState')),
        );
        $crawled  = strtotime(self::text_of($index, 'lastCrawlTime'));
        return array(
            'verdict'          => self::code_of(self::VERDICTS, self::text_of($index, 'verdict')),
            'coverage'         => $coverage,
            'indexing'         => $codes['indexing'],
            'robots'           => $codes['robots'],
            'page_fetch'       => $codes['page_fetch'],
            'crawled_as'       => self::code_of(self::CRAWLED_AS, self::text_of($index, 'crawledAs')),
            'crawled'          => $crawled ? (int) $crawled : 0,
            'google_canonical' => $google,
            'user_canonical'   => $user,
            'rich'             => self::code_of(self::VERDICTS, self::text_of($rich, 'verdict')),
            // The page's own canonical: declared, else the page itself.
            'flags'            => self::parse_flags($codes, $coverage, $google, $user !== '' ? $user : (string) $url, $types),
            'details'          => array(
                'rich'      => $types,
                'sitemaps'  => self::url_list($index, 'sitemap'),
                'referring' => self::url_list($index, 'referringUrls'),
                'link'      => esc_url_raw(self::text_of($result, 'inspectionResultLink')),
            ),
        );
    }

    /**
     * A scalar field as text, or ''.
     *
     * @param array<mixed> $from Response.
     * @param string       $key  Field.
     * @return string
     */
    private static function text_of(array $from, $key) {
        return isset($from[$key]) && is_scalar($from[$key]) ? (string) $from[$key] : '';
    }

    /**
     * A value's code in a list of Google's values, or 0.
     *
     * @param array<int,string> $codes Code => value.
     * @param string            $value Google's value.
     * @return int
     */
    private static function code_of(array $codes, $value) {
        $found = array_search((string) $value, $codes, true);
        return $found === false ? 0 : (int) $found;
    }

    /**
     * Up to MAX_LIST addresses from a list field.
     *
     * @param array<mixed> $from Response.
     * @param string       $key  Field.
     * @return string[]
     */
    private static function url_list(array $from, $key) {
        $values = isset($from[$key]) && is_array($from[$key]) ? array_filter($from[$key], 'is_scalar') : array();
        return array_slice(array_values(array_filter(array_map('esc_url_raw', array_map('strval', $values)))), 0, self::MAX_LIST);
    }

    /**
     * Findings (FLAGS) of one inspection.
     *
     * @param array{robots:int,indexing:int,page_fetch:int} $codes     Robots, indexing and fetch codes.
     * @param string                                         $coverage  Google's coverage state.
     * @param string                                         $google    Google's canonical.
     * @param string                                         $own       The page's own canonical.
     * @param array<int,array<string,mixed>>                 $types     From rich_types().
     * @return int
     */
    private static function parse_flags(array $codes, $coverage, $google, $own, array $types) {
        $flags = 0;
        if ($codes['robots'] === 2 || $codes['indexing'] === 4 || $codes['page_fetch'] === 3) {
            $flags |= self::FLAGS['robots_blocked'];
        }
        // Google's state in English (the request asks for en-US): "Crawled - currently not indexed".
        if (preg_match('/^crawled\b.*\bnot indexed/i', $coverage)) {
            $flags |= self::FLAGS['not_indexed'];
        }
        // Google chose another canonical than the page's own.
        if ($google !== '' && self::same_url($google, $own) === false) {
            $flags |= self::FLAGS['google_canonical'];
        }
        if (array_filter(array_column($types, 'errors'))) {
            $flags |= self::FLAGS['rich_errors'];
        }
        return $flags;
    }

    /**
     * Rich result types found, with their item, error and warning counts
     * and up to MAX_LIST distinct issues each.
     *
     * @param array<mixed> $rich richResultsResult.
     * @return array<int,array{type:string,items:int,errors:int,warnings:int,issues:array<int,array{message:string,severity:string}>}>
     */
    private static function rich_types(array $rich) {
        $types = array();
        foreach (self::array_field($rich, 'detectedItems') as $detected) {
            if (is_array($detected)) {
                $types[] = self::rich_type($detected);
            }
        }
        return $types;
    }

    /**
     * One detected rich result type.
     *
     * @param array<mixed> $detected Detected items.
     * @return array{type:string,items:int,errors:int,warnings:int,issues:array<int,array{message:string,severity:string}>}
     */
    private static function rich_type(array $detected) {
        $type = array('type' => sanitize_text_field(self::text_of($detected, 'richResultType')), 'items' => 0, 'errors' => 0, 'warnings' => 0, 'issues' => array());
        foreach (self::array_field($detected, 'items') as $item) {
            if (!is_array($item)) {
                continue;
            }
            ++$type['items'];
            foreach (self::array_field($item, 'issues') as $issue) {
                self::add_issue($type, $issue);
            }
        }
        return $type;
    }

    /**
     * Count one issue and keep its message once.
     *
     * @param array{type:string,items:int,errors:int,warnings:int,issues:array<int,array{message:string,severity:string}>} $type  Rich result type.
     * @param mixed                                                                                                      $issue Issue.
     * @param-out array{type:string,items:int,errors:int,warnings:int,issues:array<int,array{message:string,severity:string}>} $type
     * @return void
     */
    private static function add_issue(array &$type, $issue) {
        $issue    = is_array($issue) ? $issue : array();
        $severity = self::text_of($issue, 'severity');
        if ($severity === 'ERROR') {
            ++$type['errors'];
        } elseif ($severity === 'WARNING') {
            ++$type['warnings'];
        }
        $message = $issue ? sanitize_text_field(self::text_of($issue, 'issueMessage')) : '';
        if ($message !== '' && count($type['issues']) < self::MAX_LIST && !in_array($message, array_column($type['issues'], 'message'), true)) {
            $type['issues'][] = array('message' => $message, 'severity' => $severity === 'ERROR' ? 'error' : 'warning');
        }
    }

    /**
     * Whether two addresses are the same page: scheme, host (with or
     * without www) and path with or without a trailing slash; the query
     * counts.
     *
     * @param string $a Address.
     * @param string $b Address.
     * @return bool
     */
    public static function same_url($a, $b) {
        $norm = static function ($url) {
            $parts = wp_parse_url((string) $url);
            if (!is_array($parts)) {
                return (string) $url;
            }
            $host = strtolower((string) preg_replace(self::WWW_PREFIX, '', isset($parts['host']) ? (string) $parts['host'] : ''));
            $path = isset($parts['path']) && $parts['path'] !== '' ? rtrim((string) $parts['path'], '/') : '';
            return strtolower(isset($parts['scheme']) ? (string) $parts['scheme'] : '') . '://' . $host . $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
        };
        return $norm($a) === $norm($b);
    }

    // ------------------------------------------------------------------
    // Reading.

    /**
     * The inspections report: pages inspected, newest first, with the
     * sitemaps and the run's progress.
     *
     * @param array<string,mixed> $req      From SEOProAnalytics_Query::request() (filters, limit, offset).
     * @param string              $verdict  Only this verdict (VERDICTS), '' for all.
     * @param string              $coverage Only this coverage state (Google's words), '' for all.
     * @param string              $finding  Only pages with this finding (FLAGS), '' for all.
     * @return array<string,mixed>|WP_Error
     */
    public static function report(array $req, $verdict = '', $coverage = '', $finding = '') {
        self::load();
        $verdict  = strtoupper(trim((string) $verdict));
        $coverage = trim((string) $coverage);
        $finding  = trim((string) $finding);
        $invalid  = self::report_invalid($verdict, $finding);
        if ($invalid) {
            return $invalid;
        }
        $limit   = max(1, min(self::MAX_LIMIT, isset($req['limit']) ? (int) $req['limit'] : self::LIMIT));
        $offset  = max(0, isset($req['offset']) ? (int) $req['offset'] : 0);
        $ignored = array();
        $pages   = SEOProAnalytics_Search::page_ids(isset($req['filters']) ? (array) $req['filters'] : array(), '', $ignored);
        $rows    = self::report_rows($pages, $verdict, $coverage, $finding);
        $counts  = self::verdict_counts();
        $inspected = self::inspected();
        $rows      = self::filter_rows($rows, $verdict, $coverage, $finding);
        usort($rows, static function ($a, $b) {
            return array((int) $b['checked'], (int) $a['path_id']) <=> array((int) $a['checked'], (int) $b['path_id']);
        });
        $total = count($rows);
        $shown = self::rows(array_slice($rows, $offset, $limit));
        foreach ($shown as &$row) {
            if ((int) $row['post_id']) {
                $row = SEOProAnalytics_Clicks::with_edit_url($row);
            }
        }
        unset($row);
        return array(
            'connected' => SEOProAnalytics_Schema::set() !== 'live' || SEOProAnalytics_Search::connected('google'),
            'ignored'   => array_values(array_unique($ignored)),
            'verdict'   => $verdict,
            'coverage'  => $coverage,
            'finding'   => $finding,
            'progress'  => self::progress(),
            'rules'     => self::rules(),
            'sitemaps'  => self::sitemaps(),
            'inspected' => $inspected,
            'counts'    => $counts,
            'rows'      => $shown,
            'total'     => $total,
            'more'      => $offset + $limit < $total,
        );
    }

    /**
     * The report's error for a verdict or finding it does not know, or null.
     *
     * @param string $verdict Verdict, '' for all.
     * @param string $finding Finding, '' for all.
     * @return WP_Error|null
     */
    private static function report_invalid($verdict, $finding) {
        if ($verdict !== '' && !in_array($verdict, self::VERDICTS, true)) {
            /* translators: %s: list of verdicts */
            return new WP_Error('seoproanalytics_inspections_verdict', sprintf(__('The verdict is one of: %s.', 'seoproanalytics'), implode(', ', self::VERDICTS)), array('status' => 400));
        }
        if ($finding !== '' && !isset(self::FLAGS[$finding])) {
            /* translators: %s: list of findings */
            return new WP_Error('seoproanalytics_inspections_finding', sprintf(__('The finding is one of: %s.', 'seoproanalytics'), implode(', ', array_keys(self::FLAGS))), array('status' => 400));
        }
        return null;
    }

    /**
     * Pages with a verdict of each kind, by the verdict_checked key.
     *
     * @return array<string,int> Verdict => pages.
     */
    private static function verdict_counts() {
        global $wpdb;
        $table  = SEOProAnalytics_Schema::table('inspections');
        $counts = array();
        foreach (self::VERDICTS as $code => $name) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, by its verdict_checked key.
            $counts[$name] = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i FORCE INDEX (`verdict_checked`) WHERE verdict = %d', $table, $code));
        }
        return $counts;
    }

    /**
     * Rows matching the verdict, coverage state and finding asked for.
     *
     * @param array<int,array<string,mixed>> $rows     Table rows.
     * @param string                         $verdict  Verdict, '' for all.
     * @param string                         $coverage Coverage state, '' for all.
     * @param string                         $finding  Finding, '' for all.
     * @return array<int,array<string,mixed>>
     */
    private static function filter_rows(array $rows, $verdict, $coverage, $finding) {
        $cover_ids = $coverage !== '' ? SEOProAnalytics_Dict::find(SEOProAnalytics_Schema::DICT_COVERAGE, array($coverage)) : array();
        return array_values(array_filter($rows, static function ($row) use ($verdict, $coverage, $cover_ids, $finding) {
            return ($verdict === '' || (int) $row['verdict'] === (int) array_search($verdict, self::VERDICTS, true))
                && ($coverage === '' || in_array((int) $row['coverage_id'], $cover_ids, true))
                && ($finding === '' || ((int) $row['flags'] & self::FLAGS[$finding]));
        }));
    }

    /**
     * The report's candidate rows, by the key that fits what is asked:
     * filtered pages by the primary key, else the verdict, coverage,
     * flags or checked key.
     *
     * @param int[]|null $pages    Filtered path ids, or null.
     * @param string     $verdict  Verdict, '' for all.
     * @param string     $coverage Coverage state, '' for all.
     * @param string     $finding  Finding, '' for all.
     * @return array<int,array<string,mixed>>
     */
    private static function report_rows($pages, $verdict, $coverage, $finding) {
        global $wpdb;
        $table = SEOProAnalytics_Schema::table('inspections');
        $cols  = 'path_id, checked, verdict, coverage_id, indexing, robots, page_fetch, crawled_as, crawled, google_canonical_id, user_canonical_id, rich, flags, details';
        $rows  = array();
        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table, by its primary, verdict_checked, coverage_checked, flags and checked keys; $cols is a fixed column list and $holders only placeholders.
        if ($pages !== null) {
            foreach (array_chunk(array_map('intval', $pages), self::CHUNK) as $chunk) {
                $holders = implode(', ', array_fill(0, count($chunk), '%d'));
                $rows    = array_merge($rows, (array) $wpdb->get_results($wpdb->prepare("SELECT $cols FROM %i WHERE path_id IN ($holders)", array_merge(array($table), $chunk)), ARRAY_A));
            }
        } elseif ($verdict !== '') {
            $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT $cols FROM %i FORCE INDEX (`verdict_checked`) WHERE verdict = %d ORDER BY checked DESC LIMIT %d", $table, (int) array_search($verdict, self::VERDICTS, true), self::MAX_ROWS), ARRAY_A);
        } elseif ($coverage !== '') {
            $ids  = SEOProAnalytics_Dict::find(SEOProAnalytics_Schema::DICT_COVERAGE, array($coverage));
            $rows = $ids ? (array) $wpdb->get_results($wpdb->prepare("SELECT $cols FROM %i FORCE INDEX (`coverage_checked`) WHERE coverage_id = %d ORDER BY checked DESC LIMIT %d", $table, (int) $ids[0], self::MAX_ROWS), ARRAY_A) : array();
        } elseif ($finding !== '') {
            $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT $cols FROM %i FORCE INDEX (`flags`) WHERE flags > 0 LIMIT %d", $table, self::MAX_ROWS), ARRAY_A);
        } else {
            $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT $cols FROM %i FORCE INDEX (`checked`) WHERE checked > 0 ORDER BY checked DESC LIMIT %d", $table, self::MAX_ROWS), ARRAY_A);
        }
        // phpcs:enable
        return $rows;
    }

    /**
     * Rows as the answer gives them.
     *
     * @param array<int,array<string,mixed>> $rows Table rows.
     * @return array<int,array<string,mixed>>
     */
    private static function rows(array $rows) {
        $ids = array();
        foreach ($rows as $row) {
            array_push($ids, (int) $row['path_id'], (int) $row['coverage_id'], (int) $row['google_canonical_id'], (int) $row['user_canonical_id']);
        }
        $text = SEOProAnalytics_Query::texts($ids);
        $live = SEOProAnalytics_Schema::set() === 'live';
        $out  = array();
        foreach ($rows as $row) {
            $path = isset($text[(int) $row['path_id']]) ? (string) $text[(int) $row['path_id']] : '';
            $info = $live && $path !== '' ? SEOProAnalytics_Clicks::page_info($path) : null;
            $out[] = array(
                'path_id'  => (int) $row['path_id'],
                'path'     => $path,
                'url'      => self::url($path),
                'post_id'  => is_array($info) && isset($info['post_id']) ? (int) $info['post_id'] : 0,
                'edit_url' => null,
            ) + self::google($row, $text);
        }
        return $out;
    }

    /**
     * Google's view of a page from its row, as reports give it.
     *
     * @param array<string,mixed> $row  Table row.
     * @param array<int,string>   $text Dictionary texts by id.
     * @return array<string,mixed>
     */
    private static function google(array $row, array $text) {
        $details = json_decode((string) $row['details'], true);
        $details = is_array($details) ? $details : array();
        return array(
            'checked'          => gmdate('c', (int) $row['checked']),
            'verdict'          => self::value_of(self::VERDICTS, $row['verdict']),
            'coverage'         => self::text_by_id($text, $row['coverage_id']),
            'indexing'         => self::value_of(self::INDEXING, $row['indexing']),
            'robots'           => self::value_of(self::ROBOTS, $row['robots']),
            'page_fetch'       => self::value_of(self::FETCH, $row['page_fetch']),
            'crawled_as'       => self::value_of(self::CRAWLED_AS, $row['crawled_as']),
            'last_crawl'       => (int) $row['crawled'] ? gmdate('c', (int) $row['crawled']) : null,
            'google_canonical' => self::text_by_id($text, $row['google_canonical_id']),
            'user_canonical'   => self::text_by_id($text, $row['user_canonical_id']),
            'rich_verdict'     => self::value_of(self::VERDICTS, $row['rich']),
            'rich'             => array_values(self::array_field($details, 'rich')),
            'sitemaps'         => array_values(self::array_field($details, 'sitemaps')),
            'referring'        => array_values(self::array_field($details, 'referring')),
            'link'             => isset($details['link']) && $details['link'] !== '' ? (string) $details['link'] : null,
            'error'            => isset($details['error']) ? (string) $details['error'] : null,
            'findings'         => self::findings((int) $row['flags']),
        );
    }

    /**
     * A stored code's value, or null.
     *
     * @param array<int,string> $codes Code => value.
     * @param mixed             $code  Stored code.
     * @return string|null
     */
    private static function value_of(array $codes, $code) {
        return isset($codes[(int) $code]) ? $codes[(int) $code] : null;
    }

    /**
     * A dictionary text by its id, or null for none.
     *
     * @param array<int,string> $text Dictionary texts by id.
     * @param mixed             $id   Dictionary id.
     * @return string|null
     */
    private static function text_by_id(array $text, $id) {
        return (int) $id && isset($text[(int) $id]) ? (string) $text[(int) $id] : null;
    }

    /**
     * The names of the findings (FLAGS) set in a flags value.
     *
     * @param int $flags Flags.
     * @return string[]
     */
    private static function findings($flags) {
        $findings = array();
        foreach (self::FLAGS as $name => $bit) {
            if ($flags & $bit) {
                $findings[] = $name;
            }
        }
        return $findings;
    }

    /**
     * Google's view of pages, for other reports (Indexation, Audit), by
     * the primary key.
     *
     * @param int[] $ids Path ids.
     * @return array<int,array<string,mixed>> Path id => google().
     */
    public static function of_pages(array $ids) {
        global $wpdb;
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids || !SEOProAnalytics_Schema::is_current()) {
            return array();
        }
        $rows = array();
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $holders = implode(', ', array_fill(0, count($chunk), '%d'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table by its primary key; $holders holds only placeholders.
            $rows = array_merge($rows, (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM %i WHERE path_id IN ($holders)", array_merge(array(SEOProAnalytics_Schema::table('inspections')), $chunk)), ARRAY_A));
        }
        $text = array();
        foreach ($rows as $row) {
            array_push($text, (int) $row['coverage_id'], (int) $row['google_canonical_id'], (int) $row['user_canonical_id']);
        }
        $text = SEOProAnalytics_Query::texts($text);
        $out  = array();
        foreach ($rows as $row) {
            $out[(int) $row['path_id']] = self::google($row, $text);
        }
        return $out;
    }

    /**
     * Pages inspected, by the checked key.
     *
     * @return int
     */
    public static function inspected() {
        global $wpdb;
        if (!SEOProAnalytics_Schema::is_current()) {
            return 0;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, by its checked key.
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i FORCE INDEX (`checked`) WHERE checked > 0', SEOProAnalytics_Schema::table('inspections')));
    }

    /**
     * Pages with a finding, by the flags key (the content audit).
     *
     * @param int $limit Most rows.
     * @return array<int,int> Path id => flags.
     */
    public static function flagged($limit = self::MAX_ROWS) {
        global $wpdb;
        if (!SEOProAnalytics_Schema::is_current()) {
            return array();
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, by its flags key.
        $rows = (array) $wpdb->get_results($wpdb->prepare('SELECT path_id, flags FROM %i FORCE INDEX (`flags`) WHERE flags > 0 LIMIT %d', SEOProAnalytics_Schema::table('inspections'), (int) $limit), ARRAY_A);
        $out  = array();
        foreach ($rows as $row) {
            $out[(int) $row['path_id']] = (int) $row['flags'];
        }
        return $out;
    }

    /**
     * The sitemaps as Google read them, each with its problems, and
     * whether the site's own sitemap index is submitted.
     *
     * @return array<string,mixed>
     */
    public static function sitemaps() {
        $state = self::state();
        $now   = time();
        $own   = self::own_sitemap();
        $rows  = array();
        foreach ($state['sitemaps'] as $one) {
            $rows[] = self::sitemap_out($one, $now);
        }
        $read = (bool) $state['sitemaps_read'] && $state['sitemaps_error'] === null;
        return array(
            'read'       => self::iso_time($state['sitemaps_read']),
            'error'      => $state['sitemaps_error'],
            'own'        => $own !== '' ? $own : null,
            'submitted'  => !$read || $own === '' || self::index_submitted($state['sitemaps'], $own),
            'stale_days' => self::STALE_DAYS,
            'rows'       => $rows,
        );
    }

    /**
     * Whether the site's own index, or another index on the site, is
     * submitted.
     *
     * @param array<int,array<string,mixed>> $sitemaps Stored sitemaps.
     * @param string                         $own      The site's own sitemap index.
     * @return bool
     */
    private static function index_submitted(array $sitemaps, $own) {
        foreach ($sitemaps as $one) {
            $path = (string) $one['path'];
            if (self::same_url($path, $own) || (!empty($one['index']) && self::on_site($path))) {
                return true;
            }
        }
        return false;
    }

    /**
     * One stored sitemap as the report gives it.
     *
     * @param array<string,mixed> $one Stored sitemap.
     * @param int                 $now Now.
     * @return array<string,mixed>
     */
    private static function sitemap_out(array $one, $now) {
        return array(
            'path'       => (string) $one['path'],
            'type'       => (string) $one['type'],
            'index'      => !empty($one['index']),
            'pending'    => !empty($one['pending']),
            'submitted'  => self::iso_time($one['submitted']),
            'downloaded' => self::iso_time($one['downloaded']),
            'errors'     => (int) $one['errors'],
            'warnings'   => (int) $one['warnings'],
            'contents'   => array_values(self::array_field($one, 'contents')),
            'problems'   => self::problems_of($one, $now),
        );
    }

    /**
     * A timestamp as ISO 8601 (UTC), or null for none.
     *
     * @param mixed $ts Timestamp.
     * @return string|null
     */
    private static function iso_time($ts) {
        return (int) $ts ? gmdate('c', (int) $ts) : null;
    }

    /**
     * One sitemap's problems, in SITEMAP_PROBLEMS order: errors, stale
     * (not downloaded, else submitted, in STALE_DAYS days), warnings.
     *
     * @param array<string,mixed> $one Stored sitemap.
     * @param int                 $now Now.
     * @return string[]
     */
    private static function problems_of(array $one, $now) {
        $problems = array();
        if ((int) $one['errors'] > 0) {
            $problems[] = 'errors';
        }
        $since = (int) $one['downloaded'] ? (int) $one['downloaded'] : (int) $one['submitted'];
        if ($since && $since < $now - self::STALE_DAYS * DAY_IN_SECONDS) {
            $problems[] = 'stale';
        }
        if ((int) $one['warnings'] > 0) {
            $problems[] = 'warnings';
        }
        return $problems;
    }

    /**
     * The sitemap problems, one per sitemap and problem (and one for the
     * site's own index not submitted), for the decision queue.
     *
     * @return array<int,array{problem:string,url:string,path:string,errors:int,warnings:int,downloaded:string|null,submitted:string|null}>
     */
    public static function sitemap_problems() {
        $all = self::sitemaps();
        $out = array();
        if ($all['read'] !== null && $all['error'] === null && !$all['submitted']) {
            $out[] = array('problem' => 'missing', 'url' => (string) $all['own'], 'path' => self::path_of((string) $all['own']), 'errors' => 0, 'warnings' => 0, 'downloaded' => null, 'submitted' => null);
        }
        foreach ($all['rows'] as $row) {
            foreach ($row['problems'] as $problem) {
                $out[] = array(
                    'problem'    => (string) $problem,
                    'url'        => (string) $row['path'],
                    'path'       => self::path_of((string) $row['path']),
                    'errors'     => (int) $row['errors'],
                    'warnings'   => (int) $row['warnings'],
                    'downloaded' => $row['downloaded'] !== null ? (string) $row['downloaded'] : null,
                    'submitted'  => $row['submitted'] !== null ? (string) $row['submitted'] : null,
                );
            }
        }
        return $out;
    }

    /**
     * Why a sitemap problem is listed, in a sentence.
     *
     * @param array<string,mixed> $problem From sitemap_problems().
     * @return string
     */
    public static function why(array $problem) {
        switch ($problem['problem']) {
            case 'missing':
                /* translators: %s: the site's sitemap address */
                return sprintf(__('The site’s sitemap %s is not submitted in Search Console, so Google may find new pages late.', 'seoproanalytics'), (string) $problem['url']);
            case 'errors':
                /* translators: 1: number of errors, 2: sitemap address */
                return sprintf(_n('Google found %1$s error in the sitemap %2$s; it may not read it.', 'Google found %1$s errors in the sitemap %2$s; it may not read it.', (int) $problem['errors'], 'seoproanalytics'), number_format_i18n((int) $problem['errors']), (string) $problem['url']);
            case 'stale':
                /* translators: 1: sitemap address, 2: number of days */
                return sprintf(__('Google has not downloaded the sitemap %1$s for over %2$s days.', 'seoproanalytics'), (string) $problem['url'], number_format_i18n(self::STALE_DAYS));
            default:
                /* translators: 1: number of warnings, 2: sitemap address */
                return sprintf(_n('Google found %1$s warning in the sitemap %2$s.', 'Google found %1$s warnings in the sitemap %2$s.', (int) $problem['warnings'], 'seoproanalytics'), number_format_i18n((int) $problem['warnings']), (string) $problem['url']);
        }
    }

    /**
     * What to do about a sitemap problem.
     *
     * @param string $problem One of SITEMAP_PROBLEMS.
     * @return string
     */
    public static function todo($problem) {
        switch ($problem) {
            case 'missing':
                return __('Submit it in Search Console → Sitemaps.', 'seoproanalytics');
            case 'errors':
                return __('Open the sitemap in Search Console → Sitemaps, fix what it names (often an address that fails or a sitemap too large), and submit it again.', 'seoproanalytics');
            case 'stale':
                return __('Check that the sitemap opens without an error or a redirect and is allowed in robots.txt, then submit it again.', 'seoproanalytics');
            default:
                return __('Open the sitemap in Search Console → Sitemaps and check the addresses it warns about.', 'seoproanalytics');
        }
    }

    /**
     * Why a page's inspection finding matters, in a few words (the content
     * audit's phrase).
     *
     * @param string              $finding One of FLAGS.
     * @param array<string,mixed> $google  google() of the page, or empty.
     * @return string
     */
    public static function phrase($finding, array $google) {
        switch ($finding) {
            case 'robots_blocked':
                return __('robots.txt stops Google crawling it', 'seoproanalytics');
            case 'not_indexed':
                return __('Google crawled it but did not index it', 'seoproanalytics');
            case 'google_canonical':
                /* translators: %s: the address Google chose as canonical */
                return isset($google['google_canonical']) && $google['google_canonical'] ? sprintf(__('Google chose %s as its canonical', 'seoproanalytics'), (string) $google['google_canonical']) : __('Google chose another page as its canonical', 'seoproanalytics');
            default:
                return self::rich_phrase($google);
        }
    }

    /**
     * The rich result errors phrase, naming the types with errors.
     *
     * @param array<string,mixed> $google google() of the page, or empty.
     * @return string
     */
    private static function rich_phrase(array $google) {
        $types = array();
        foreach (isset($google['rich']) ? (array) $google['rich'] : array() as $type) {
            if (is_array($type) && !empty($type['errors'])) {
                $types[] = (string) $type['type'];
            }
        }
        /* translators: %s: rich result types, e.g. "Breadcrumbs, FAQ" */
        return $types ? sprintf(__('rich result errors (%s)', 'seoproanalytics'), implode(', ', $types)) : __('rich result errors', 'seoproanalytics');
    }

    /**
     * What to do about a page's inspection finding.
     *
     * @param string $finding One of FLAGS.
     * @return string
     */
    public static function fix($finding) {
        switch ($finding) {
            case 'robots_blocked':
                return __('Allow the page in robots.txt, unless it is kept out of search on purpose.', 'seoproanalytics');
            case 'not_indexed':
                return __('Make the page clearly worth indexing (more of its own content, links from related pages), then ask Google to index it.', 'seoproanalytics');
            case 'google_canonical':
                return __('Make the page distinct from the one Google chose, or point the canonical address at that page and link to it.', 'seoproanalytics');
            default:
                return __('Fix the structured data issues Search Console names, then test the page again.', 'seoproanalytics');
        }
    }

    // ------------------------------------------------------------------
    // Progress and helpers.

    /**
     * The daily cap and today's use, the last run and its error.
     *
     * @return array<string,mixed>
     */
    public static function progress() {
        $state = self::state();
        return array(
            'daily'    => self::daily(),
            'used'     => self::used(),
            'day'      => self::today(),
            'last'     => $state['last'] ? gmdate('c', $state['last']) : null,
            'error'    => $state['error'],
            'error_at' => $state['error_at'] ? gmdate('c', $state['error_at']) : null,
        );
    }

    /**
     * The rules the run keeps.
     *
     * @return array<string,int>
     */
    public static function rules() {
        return array(
            'daily'        => self::daily(),
            'max_daily'    => 2000,
            'recheck_days' => self::RECHECK_DAYS,
            'budget'       => self::BUDGET,
            'stale_days'   => self::STALE_DAYS,
        );
    }

    /**
     * Inspections a day at most (the setting; the demo shows the default).
     *
     * @return int
     */
    public static function daily() {
        return class_exists('SEOProAnalytics_Statistics') ? SEOProAnalytics_Statistics::inspections() : 200;
    }

    /**
     * Inspections asked for today (Google's day).
     *
     * @return int
     */
    public static function used() {
        $state = self::state();
        return $state['day'] === self::today() ? $state['used'] : 0;
    }

    /**
     * Today in Google's time zone (Pacific), where its daily quota starts.
     *
     * @return string Y-m-d.
     */
    private static function today() {
        return (new DateTimeImmutable('now', new DateTimeZone('America/Los_Angeles')))->format('Y-m-d');
    }

    /**
     * Progress of the current data set.
     *
     * @return array{sitemaps:array<int,array<string,mixed>>,sitemaps_read:int,sitemaps_error:string|null,day:string,used:int,last:int,error:string|null,error_at:int,version:int}
     */
    public static function state() {
        $state = get_option(SEOProAnalytics_Schema::option(self::OPTION), array());
        $state = is_array($state) ? $state : array();
        return array(
            'sitemaps'       => self::array_field($state, 'sitemaps'),
            'sitemaps_read'  => self::integer_field($state, 'sitemaps_read'),
            'sitemaps_error' => self::nullable_text($state, 'sitemaps_error'),
            'day'            => isset($state['day']) ? (string) $state['day'] : '',
            'used'           => self::integer_field($state, 'used'),
            'last'           => self::integer_field($state, 'last'),
            'error'          => self::nullable_text($state, 'error'),
            'error_at'       => self::integer_field($state, 'error_at'),
            'version'        => self::integer_field($state, 'version'),
        );
    }

    /**
     * An optional text field, or null.
     *
     * @param array<mixed> $from Stored values.
     * @param string       $key  Field.
     * @return string|null
     */
    private static function nullable_text(array $from, $key) {
        return isset($from[$key]) ? (string) $from[$key] : null;
    }

    /**
     * Change some of the progress (null removes a value): the add-on's
     * read keeps day, used, last, error, error_at, sitemaps_read and
     * sitemaps_error here.
     *
     * @param array<string,mixed> $values Values.
     */
    public static function save(array $values) {
        $state = get_option(SEOProAnalytics_Schema::option(self::OPTION), array());
        $state = is_array($state) ? $state : array();
        foreach ($values as $key => $value) {
            if ($value === null) {
                unset($state[$key]);
            } else {
                $state[$key] = $value;
            }
        }
        update_option(SEOProAnalytics_Schema::option(self::OPTION), $state, false);
    }

    /**
     * Note that inspections were written (the demo), so cached reports are
     * made again.
     */
    public static function touch() {
        self::save(array('version' => time()));
    }

    /**
     * Delete the current data set's progress and sitemaps (demo removal,
     * uninstall); the live run's lock only with live data.
     */
    public static function reset() {
        delete_option(SEOProAnalytics_Schema::option(self::OPTION));
        if (SEOProAnalytics_Schema::set() === 'live') {
            delete_option(self::LOCK_OPTION);
        }
    }

    /**
     * The site's own sitemap index: its SEO plugin's, else WordPress's
     * when its sitemaps are on, else ''.
     *
     * @return string
     */
    private static function own_sitemap() {
        if (SEOProAnalytics_Schema::set() === 'demo') {
            return home_url('/wp-sitemap.xml');
        }
        require_once __DIR__ . '/class-seoproanalytics-coverage.php';
        $plugin = SEOProAnalytics_Coverage::seo_plugin();
        if (isset(self::PLUGIN_SITEMAPS[$plugin])) {
            return home_url(self::PLUGIN_SITEMAPS[$plugin]);
        }
        $url = function_exists('get_sitemap_url') ? get_sitemap_url('index') : false;
        return is_string($url) ? $url : '';
    }

    /**
     * Whether an address is on this site.
     *
     * @param string $url Address.
     * @return bool
     */
    private static function on_site($url) {
        $host = strtolower((string) preg_replace(self::WWW_PREFIX, '', (string) wp_parse_url((string) $url, PHP_URL_HOST)));
        $home = strtolower((string) preg_replace(self::WWW_PREFIX, '', (string) wp_parse_url(home_url('/'), PHP_URL_HOST)));
        return $host !== '' && $host === $home;
    }

    /**
     * An address's path (the queue's page), '' when it is not on the site.
     *
     * @param string $url Address.
     * @return string
     */
    private static function path_of($url) {
        if (!self::on_site($url)) {
            return '';
        }
        $path = (string) wp_parse_url((string) $url, PHP_URL_PATH);
        return $path !== '' ? $path : '/';
    }

    /**
     * A page's address on this site, or '' for a path that is not one.
     *
     * @param string $path Path.
     * @return string
     */
    public static function url($path) {
        $info = $path !== '' && $path[0] === '/' ? wp_parse_url(home_url('/')) : null;
        if (!is_array($info) || !isset($info['scheme'], $info['host'])) {
            return '';
        }
        return esc_url_raw($info['scheme'] . '://' . $info['host'] . (isset($info['port']) ? ':' . $info['port'] : '') . $path);
    }

    /**
     * Load the classes used.
     */
    private static function load() {
        require_once __DIR__ . '/class-seoproanalytics-dict.php';
        require_once __DIR__ . '/class-seoproanalytics-query.php';
        require_once __DIR__ . '/class-seoproanalytics-search.php';
        require_once __DIR__ . '/class-seoproanalytics-clicks.php';
        require_once __DIR__ . '/class-seoproanalytics-indexation.php';
    }
}
