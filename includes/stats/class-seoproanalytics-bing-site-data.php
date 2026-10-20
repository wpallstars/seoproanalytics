<?php
/**
 * Bing's view of the site, read with the Bing Webmaster Tools connection
 * (its API key and verified site; read-only methods only):
 *
 * - daily: crawl issues per address (GetCrawlIssues: the HTTP code and
 *   Bing's issue flags), crawl stats by day (GetCrawlStats), the sitemaps
 *   Bing has (GetFeeds) and the URL submission quota
 *   (GetUrlSubmissionQuota, for IndexNow);
 * - weekly: inbound links. GetLinkCounts lists the site's pages with
 *   links, GetUrlLinks each page's linking addresses with their anchor
 *   text. Each goes into the backlinks' links table with the Bing found
 *   bit (SEOProAnalytics_Backlinks::FOUND), first seen when Bing first gave
 *   it, the same upsert link exports use (SEOProAnalytics_Backlinks_Store),
 *   so a second read adds nothing new. The backlinks check then opens
 *   each referring page as it does an export's: a link is live while it
 *   is on its page and lost after two weekly checks without it (Bing's
 *   own list lags by weeks, so its absence alone loses nothing).
 *
 * Both paging methods start at page 0 and stop once the rows read reach
 * the answer's TotalPages (a row count, not a page count, in Bing's
 * answers) or a page comes back empty. A run stops at
 * REQUESTS requests or BUDGET seconds and carries on in the next hourly
 * run from its cursor. Requests come only from cron and WP-CLI, never a
 * visitor page; nothing is asked without the connection.
 *
 * What is read is kept in one option per data set (autoload off), with
 * the site it was read for: a disconnect deletes it, and a site changed
 * on the Connections tab is never shown another site's facts. Crawl
 * errors on published pages become the content audit's bing_crawl
 * finding (and so Plan items). Layout: docs/architecture.md →
 * Integrations → Bing Webmaster Tools.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 * Provenance: wpas-c8662019b99fb07b. Copies, even of part, keep these lines.
 *
 * @package SEOProAnalytics
 * @since 1.7.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProAnalytics_Bing_Site_Data {

    /** Bing's source key (SEOProAnalytics_Connections::sources()). */
    const SOURCE = 'bing';

    /** What was read, per data set (autoload off). */
    const OPTION = 'seoproanalytics_bing_site';

    /** The run's lock (live only), and how long a run may hold it. */
    const LOCK_OPTION = 'seoproanalytics_bing_site_lock';
    const LOCK_TIME   = 10 * MINUTE_IN_SECONDS;

    /** Seconds and requests a run may take. */
    const BUDGET   = 20;
    const REQUESTS = 60;

    /** Crawl facts are read daily, links weekly. */
    const DAILY  = DAY_IN_SECONDS;
    const WEEKLY = WEEK_IN_SECONDS;

    /** Pages of GetLinkCounts, and of GetUrlLinks per page of the site, read at most. */
    const TARGET_PAGES = 20;
    const LINK_PAGES   = 10;

    /** Links kept from one weekly read at most. */
    const MAX_LINKS = 20000;

    /** Crawl issues, days of crawl stats and sitemaps kept at most. */
    const MAX_ISSUES   = 1000;
    const MAX_STATS    = 60;
    const MAX_SITEMAPS = 100;

    /** Rows listed: default and most. */
    const LIMIT     = 50;
    const MAX_LIMIT = 500;

    /** Bing's crawl issue flags (its CrawlIssues values): name => bit. */
    const ISSUES = array(
        'redirect_301'      => 1 << 0,
        'redirect_302'      => 1 << 1,
        'client_error'      => 1 << 2,
        'server_error'      => 1 << 3,
        'robots_blocked'    => 1 << 4,
        'malware'           => 1 << 5,
        'important_blocked' => 1 << 6,
        'dns'               => 1 << 7,
        'timeout'           => 1 << 8,
    );

    /** The flags that are errors (the audit's bing_crawl finding): all but the two redirects. */
    const ERRORS = 0x1FF & ~0x3;

    /** Crawl stats kept: Bing's field => ours. */
    const STATS = array(
        'CrawledPages'       => 'crawled',
        'CrawlErrors'        => 'errors',
        'InIndex'            => 'in_index',
        'InLinks'            => 'inlinks',
        'Code2xx'            => 'code_2xx',
        'Code301'            => 'code_301',
        'Code302'            => 'code_302',
        'Code4xx'            => 'code_4xx',
        'Code5xx'            => 'code_5xx',
        'BlockedByRobotsTxt' => 'robots_blocked',
        'DnsFailures'        => 'dns',
        'ConnectionTimeout'  => 'timeout',
        'ContainsMalware'    => 'malware',
        'AllOtherCodes'      => 'other',
    );

    /** @var int Requests made by this run. */
    private static $requests = 0;

    // ------------------------------------------------------------------
    // Cron.

    /**
     * Hourly import job, while Bing is connected: the crawl facts once a
     * day, then the weekly links read from where it stopped. Live data only.
     *
     * @param bool $force Read now, whether due or not (WP-CLI).
     * @return array<string,mixed>|WP_Error status() after the run, or why it did not run.
     */
    public static function run($force = false) {
        if (SEOProAnalytics_Schema::set() !== 'live' || !SEOProAnalytics_Schema::is_current()) {
            return new WP_Error('seoproanalytics_bing_site_live', __('Bing is asked about live data only.', 'seoproanalytics'), array('status' => 400));
        }
        $ready = self::ready();
        if (is_wp_error($ready)) {
            return $ready;
        }
        if (!self::lock()) {
            return new WP_Error('seoproanalytics_bing_site_busy', __('Bing’s site data is being read. Try again in a few minutes.', 'seoproanalytics'), array('status' => 409));
        }
        try {
            list($key, $site) = $ready;
            $start          = microtime(true);
            self::$requests = 0;
            self::own($site);
            $state = self::state();
            if ($force || $state['daily_read'] < time() - self::DAILY + 10 * MINUTE_IN_SECONDS) {
                self::read_daily($key, $site);
            }
            $state = self::state();
            if ($force || $state['links']['cursor'] !== null || $state['links']['read'] < time() - self::WEEKLY + HOUR_IN_SECONDS) {
                self::read_links($key, $site, $start);
            }
        } finally {
            self::unlock();
        }
        return self::status();
    }

    /**
     * Read the crawl issues, crawl stats, sitemaps and quota, and keep
     * them. A failed method keeps what was read before it and records
     * the error; the others are still read.
     *
     * @param string $key  API key.
     * @param string $site Verified site.
     */
    private static function read_daily($key, $site) {
        $errors = array();
        $values = array('daily_read' => time());
        $issues = self::ask('GetCrawlIssues', $key, $site);
        if (is_wp_error($issues)) {
            $errors[] = $issues->get_error_message();
        } else {
            $values['issues'] = self::issue_rows($issues);
        }
        $stats = self::ask('GetCrawlStats', $key, $site);
        if (is_wp_error($stats)) {
            $errors[] = $stats->get_error_message();
        } else {
            $values['stats'] = self::stat_rows($stats);
        }
        $feeds = self::ask('GetFeeds', $key, $site);
        if (is_wp_error($feeds)) {
            $errors[] = $feeds->get_error_message();
        } else {
            $values['sitemaps'] = self::sitemap_rows($feeds);
        }
        $quota = self::ask('GetUrlSubmissionQuota', $key, $site);
        if (is_wp_error($quota)) {
            $errors[] = $quota->get_error_message();
        } else {
            $values['quota'] = array('daily' => (int) $quota['DailyQuota'], 'monthly' => (int) $quota['MonthlyQuota']);
        }
        $values['daily_error'] = $errors ? implode(' ', array_unique($errors)) : null;
        $values['version']     = time();
        self::save($values);
    }

    /**
     * Carry the weekly links read on within the budget: the site's pages
     * with links (GetLinkCounts, page by page), then each one's linking
     * addresses (GetUrlLinks, page by page), into the links table. The
     * cursor is saved after each request, so a run that stops (budget,
     * error, a site busy) carries on where it was.
     *
     * @param string $key   API key.
     * @param string $site  Verified site.
     * @param float  $start When the run began.
     */
    private static function read_links($key, $site, $start) {
        require_once __DIR__ . '/class-seoproanalytics-backlinks.php';
        require_once __DIR__ . '/class-seoproanalytics-backlinks-import.php';
        require_once __DIR__ . '/class-seoproanalytics-backlinks-store.php';
        require_once __DIR__ . '/class-seoproanalytics-dict.php';
        require_once __DIR__ . '/class-seoproanalytics-links.php';
        require_once __DIR__ . '/class-seoproanalytics-changes.php';
        $links  = self::state()['links'];
        $cursor = $links['cursor'] !== null ? $links['cursor'] : array('page' => 0, 'targets' => array(), 'target' => 0, 'detail' => 0);
        if ($links['cursor'] === null) {
            $links = array('read' => $links['read'], 'started' => time(), 'targets' => 0, 'links' => 0, 'error' => null, 'cursor' => $cursor);
        }
        while (self::$requests < self::REQUESTS && SEOProAnalytics_Feature::more_time($start, self::BUDGET)) {
            $step = self::links_step($key, $site, $cursor, $links);
            if (is_wp_error($step)) {
                $links['error']  = $step->get_error_message();
                $links['cursor'] = $cursor;
                break;
            }
            $links['error']  = null;
            $links['cursor'] = $step ? $cursor : null;
            self::save(array('links' => $links));
            if (!$step) {
                // Done: the week's read is in; the check opens the pages it named within minutes.
                $links['read'] = time();
                SEOProAnalytics_Backlinks::schedule_catch_up();
                self::touch_backlinks();
                break;
            }
        }
        self::save(array('links' => $links, 'version' => time()));
    }

    /**
     * One request of the links read, moving the cursor on.
     *
     * @param string              $key    API key.
     * @param string              $site   Verified site.
     * @param array<string,mixed> $cursor page (the next GetLinkCounts page), seen (its rows so far), more (whether it has another page), targets (the site's pages of the last page), target (index), detail (GetUrlLinks page), detail_seen (its rows so far); updated.
     * @param array<string,mixed> $links  Progress (targets, links); updated.
     * @return bool|WP_Error Whether there is more to read.
     */
    private static function links_step($key, $site, array &$cursor, array &$links) {
        if ($links['links'] >= self::MAX_LINKS) {
            return false;
        }
        if ($cursor['target'] >= count($cursor['targets'])) {
            // The next page of the site's pages with links (zero-based), or the end.
            if ($cursor['page'] >= self::TARGET_PAGES || (isset($cursor['more']) && !$cursor['more'])) {
                return false;
            }
            $answer = self::ask('GetLinkCounts', $key, $site, array('page' => (string) $cursor['page']));
            if (is_wp_error($answer)) {
                return $answer;
            }
            $targets = array();
            foreach ($answer['Links'] as $row) {
                if (is_array($row) && !empty($row['Url']) && !empty($row['Count'])) {
                    $targets[] = (string) $row['Url'];
                }
            }
            $seen   = (int) ($cursor['seen'] ?? 0) + count($answer['Links']);
            $more   = self::more_pages(count($answer['Links']), $seen, (int) $answer['TotalPages']);
            $cursor = array('page' => $cursor['page'] + 1, 'seen' => $seen, 'more' => $more, 'targets' => $targets, 'target' => 0, 'detail' => 0, 'detail_seen' => 0);
            $links['targets'] += count($targets);
            return $targets || ($more && $cursor['page'] < self::TARGET_PAGES);
        }
        $target = (string) $cursor['targets'][$cursor['target']];
        $answer = self::ask('GetUrlLinks', $key, $site, array('link' => $target, 'page' => (string) $cursor['detail']));
        if (is_wp_error($answer)) {
            return $answer;
        }
        $cursor['detail_seen'] = (int) ($cursor['detail_seen'] ?? 0) + count($answer['Details']);
        $now = time();
        foreach ($answer['Details'] as $row) {
            if ($links['links'] >= self::MAX_LINKS || !is_array($row) || empty($row['Url'])) {
                continue;
            }
            $link = SEOProAnalytics_Backlinks_Import::normalise(array(
                'sourceurl'  => (string) $row['Url'],
                'targeturl'  => $target,
                'anchortext' => isset($row['AnchorText']) ? (string) $row['AnchorText'] : '',
            ), self::SOURCE);
            if ($link === null) {
                continue;
            }
            // Bing gives no dates: first seen is when Bing first gave it (the upsert keeps the earliest).
            $link['first'] = $now;
            $link['last']  = $now;
            if (!SEOProAnalytics_Backlinks_Store::write($link, self::SOURCE)) {
                return new WP_Error('seoproanalytics_bing_links_write', __('A link from Bing could not be stored.', 'seoproanalytics'));
            }
            ++$links['links'];
        }
        // The next page of this page's links, else the next page of the site.
        if ($cursor['detail'] + 1 < self::LINK_PAGES && self::more_pages(count($answer['Details']), $cursor['detail_seen'], (int) $answer['TotalPages'])) {
            ++$cursor['detail'];
        } else {
            ++$cursor['target'];
            $cursor['detail']      = 0;
            $cursor['detail_seen'] = 0;
        }
        return true;
    }

    /**
     * Whether a paged Bing answer has another page. Bing's TotalPages is
     * the total number of rows, not of pages (seen in live answers: 26
     * rows on page 0 with TotalPages 26, then page 1 empty with
     * TotalPages 0), so there is more while the last page had rows and
     * fewer rows than that total have come in.
     *
     * @param int $rows  Rows on the page just read.
     * @param int $seen  Rows read so far, that page included.
     * @param int $total The answer's TotalPages.
     */
    private static function more_pages($rows, $seen, $total) {
        return $rows > 0 && $seen < $total;
    }

    /**
     * One read-only request, counted against the run's requests.
     *
     * @param string               $method Bing's method.
     * @param string               $key    API key.
     * @param string               $site   Verified site.
     * @param array<string,string> $args   Its parameters.
     * @return array<mixed>|WP_Error
     */
    private static function ask($method, $key, $site, array $args = array()) {
        ++self::$requests;
        return SEOProAnalytics_Source_Bing::site_data($method, $key, $site, $args);
    }

    /**
     * Crawl issues as kept: address, its path on the site and path id (0
     * when not a page of the site), HTTP code, flags and inbound links;
     * errors first, then most inbound links.
     *
     * @param array<int,mixed> $rows Bing's rows.
     * @return array<int,array{url:string,path:string,pid:int,http:int,flags:int,inlinks:int}>
     */
    public static function issue_rows(array $rows) {
        $out = array();
        foreach ($rows as $row) {
            if (!is_array($row) || empty($row['Url']) || !isset($row['Issues'])) {
                continue;
            }
            $url   = esc_url_raw((string) $row['Url']);
            $flags = (int) $row['Issues'] & array_sum(self::ISSUES);
            if ($url === '' || !$flags) {
                continue;
            }
            $out[] = array(
                'url'     => $url,
                'path'    => self::path_of($url),
                'pid'     => 0,
                'http'    => max(0, (int) ($row['HttpCode'] ?? 0)),
                'flags'   => $flags,
                'inlinks' => max(0, (int) ($row['InLinks'] ?? 0)),
            );
        }
        usort($out, static function ($a, $b) {
            return array((bool) ($b['flags'] & self::ERRORS), $b['inlinks'], $a['url']) <=> array((bool) ($a['flags'] & self::ERRORS), $a['inlinks'], $b['url']);
        });
        $out = array_slice($out, 0, self::MAX_ISSUES);
        // The path ids of addresses already known (the content audit's pages); none are added.
        require_once __DIR__ . '/class-seoproanalytics-dict.php';
        $paths = array_values(array_filter(array_unique(array_column($out, 'path'))));
        $ids   = $paths ? array_flip(SEOProAnalytics_Dict::values(SEOProAnalytics_Dict::find(SEOProAnalytics_Schema::DICT_PATH, $paths))) : array();
        foreach ($out as &$one) {
            $clean      = SEOProAnalytics_Dict::clean($one['path']);
            $one['pid'] = $one['path'] !== '' && isset($ids[$clean]) ? (int) $ids[$clean] : 0;
        }
        unset($one);
        return $out;
    }

    /**
     * Crawl stats as kept, oldest day first, the newest MAX_STATS days.
     *
     * @param array<int,mixed> $rows Bing's rows.
     * @return array<int,array<string,mixed>>
     */
    public static function stat_rows(array $rows) {
        $out = array();
        foreach ($rows as $row) {
            $day = is_array($row) && isset($row['Date']) ? self::day((string) $row['Date']) : '';
            if ($day === '') {
                continue;
            }
            $one = array('day' => $day);
            foreach (self::STATS as $from => $to) {
                $one[$to] = max(0, (int) ($row[$from] ?? 0));
            }
            $out[$day] = $one;
        }
        ksort($out);
        return array_values(array_slice($out, -self::MAX_STATS));
    }

    /**
     * Sitemaps (and feeds) as kept.
     *
     * @param array<int,mixed> $rows Bing's rows.
     * @return array<int,array<string,mixed>>
     */
    public static function sitemap_rows(array $rows) {
        $out = array();
        foreach (array_slice($rows, 0, self::MAX_SITEMAPS) as $row) {
            if (!is_array($row) || empty($row['Url'])) {
                continue;
            }
            $out[] = array(
                'url'       => esc_url_raw((string) $row['Url']),
                'type'      => sanitize_text_field((string) ($row['Type'] ?? '')),
                'status'    => sanitize_text_field((string) ($row['Status'] ?? '')),
                'submitted' => self::time((string) ($row['Submitted'] ?? '')),
                'crawled'   => self::time((string) ($row['LastCrawled'] ?? '')),
                'urls'      => max(0, (int) ($row['UrlCount'] ?? 0)),
            );
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Reads.

    /**
     * The crawl issues report: Bing's crawl issues, errors first, with the
     * counts of each issue, the crawl stats, Bing's sitemaps, the URL
     * submission quota and the links read's progress.
     *
     * @param array<string,mixed> $req   From SEOProAnalytics_Query::request() (limit, offset).
     * @param string              $issue Only addresses with this issue (ISSUES), 'errors' for every error, '' for all.
     * @return array<string,mixed>|WP_Error
     */
    public static function report(array $req, $issue = '') {
        $issue = trim((string) $issue);
        if ($issue !== '' && $issue !== 'errors' && !isset(self::ISSUES[$issue])) {
            /* translators: %s: list of issues */
            return new WP_Error('seoproanalytics_crawl_issue', sprintf(__('The issue is errors or one of: %s.', 'seoproanalytics'), implode(', ', array_keys(self::ISSUES))), array('status' => 400));
        }
        $limit  = max(1, min(self::MAX_LIMIT, isset($req['limit']) ? (int) $req['limit'] : self::LIMIT));
        $offset = max(0, isset($req['offset']) ? (int) $req['offset'] : 0);
        $state  = self::state();
        $counts = array_fill_keys(array_merge(array('errors'), array_keys(self::ISSUES)), 0);
        $rows   = array();
        foreach ($state['issues'] as $one) {
            $flags = (int) $one['flags'];
            $counts['errors'] += ($flags & self::ERRORS) ? 1 : 0;
            foreach (self::ISSUES as $name => $bit) {
                $counts[$name] += ($flags & $bit) ? 1 : 0;
            }
            if ($issue === '' || ($flags & ($issue === 'errors' ? self::ERRORS : self::ISSUES[$issue]))) {
                $rows[] = self::issue_out($one);
            }
        }
        $total = count($rows);
        return array(
            'engine'    => 'bing',
            'connected' => SEOProAnalytics_Schema::set() !== 'live' || self::connected(),
            'site'      => $state['site'] !== '' ? $state['site'] : null,
            'issue'     => $issue,
            'read'      => self::iso($state['daily_read']),
            'error'     => $state['daily_error'],
            'counts'    => $counts,
            'rows'      => array_slice($rows, $offset, $limit),
            'total'     => $total,
            'more'      => $offset + $limit < $total,
            'stats'     => $state['stats'],
            'sitemaps'  => array_map(array(__CLASS__, 'sitemap_out'), $state['sitemaps']),
            'quota'     => $state['quota'],
            'links'     => self::links_out($state['links']),
            'rules'     => array('requests' => self::REQUESTS, 'budget' => self::BUDGET, 'max_issues' => self::MAX_ISSUES, 'max_links' => self::MAX_LINKS),
        );
    }

    /**
     * What the Connections tab shows of Bing's site data: when it was read,
     * the quota, the links read's progress and the last error.
     *
     * @return array<string,mixed>
     */
    public static function status() {
        $state = self::state();
        return array(
            'read'   => self::iso($state['daily_read']),
            'error'  => $state['daily_error'],
            'issues' => count($state['issues']),
            'quota'  => $state['quota'],
            'links'  => self::links_out($state['links']),
        );
    }

    /**
     * Published pages with a Bing crawl error: path id => flags (the
     * content audit's bing_crawl finding).
     *
     * @return array<int,int>
     */
    public static function flagged() {
        $out = array();
        foreach (self::state()['issues'] as $one) {
            $flags = (int) $one['flags'] & self::ERRORS;
            if ($flags && (int) $one['pid'] > 0) {
                $out[(int) $one['pid']] = (isset($out[(int) $one['pid']]) ? $out[(int) $one['pid']] : 0) | $flags;
            }
        }
        return $out;
    }

    /**
     * Bing's crawl issue of pages, for the audit's rows: path id => http,
     * issues (names).
     *
     * @param int[] $ids Path ids.
     * @return array<int,array{http:int,issues:string[],url:string}>
     */
    public static function of_pages(array $ids) {
        $want = array_flip(array_map('intval', $ids));
        $out  = array();
        foreach (self::state()['issues'] as $one) {
            $pid = (int) $one['pid'];
            if ($pid > 0 && isset($want[$pid]) && !isset($out[$pid])) {
                $out[$pid] = array('http' => (int) $one['http'], 'issues' => self::names((int) $one['flags']), 'url' => (string) $one['url']);
            }
        }
        return $out;
    }

    /**
     * The bing_crawl finding in a few words.
     *
     * @param array<string,mixed>|null $bing of_pages() of the page.
     * @return string
     */
    public static function phrase($bing) {
        $http = is_array($bing) ? (int) $bing['http'] : 0;
        /* translators: %d: HTTP status code */
        return $http ? sprintf(__('Bing’s crawler had an error on it (HTTP %d)', 'seoproanalytics'), $http) : __('Bing’s crawler could not crawl it', 'seoproanalytics');
    }

    /**
     * What to do about the bing_crawl finding.
     *
     * @return string
     */
    public static function fix() {
        return __('Make the address answer with the page (or redirect it to the right one) and allow Bingbot in robots.txt, then ask Bing to crawl it again in Bing Webmaster Tools.', 'seoproanalytics');
    }

    /**
     * The names of a row's flags, in ISSUES order.
     *
     * @param int $flags Flags.
     * @return string[]
     */
    public static function names($flags) {
        $out = array();
        foreach (self::ISSUES as $name => $bit) {
            if ($flags & $bit) {
                $out[] = $name;
            }
        }
        return $out;
    }

    /**
     * One crawl issue as the report gives it.
     *
     * @param array<string,mixed> $one Kept issue.
     * @return array<string,mixed>
     */
    private static function issue_out(array $one) {
        return array(
            'url'     => (string) $one['url'],
            'path'    => (string) $one['path'],
            'http'    => (int) $one['http'],
            'issues'  => self::names((int) $one['flags']),
            'error'   => (bool) ((int) $one['flags'] & self::ERRORS),
            'inlinks' => (int) $one['inlinks'],
        );
    }

    /**
     * One sitemap as the report gives it.
     *
     * @param array<string,mixed> $one Kept sitemap.
     * @return array<string,mixed>
     */
    private static function sitemap_out(array $one) {
        return array(
            'url'       => (string) $one['url'],
            'type'      => (string) $one['type'],
            'status'    => (string) $one['status'],
            'submitted' => self::iso((int) $one['submitted']),
            'crawled'   => self::iso((int) $one['crawled']),
            'urls'      => (int) $one['urls'],
        );
    }

    /**
     * The links read's progress as given.
     *
     * @param array<string,mixed> $links Kept progress.
     * @return array<string,mixed>
     */
    private static function links_out(array $links) {
        return array(
            'read'    => self::iso((int) $links['read']),
            'started' => self::iso((int) $links['started']),
            'running' => $links['cursor'] !== null,
            'targets' => (int) $links['targets'],
            'links'   => (int) $links['links'],
            'error'   => $links['error'],
        );
    }

    // ------------------------------------------------------------------
    // State.

    /**
     * What was read for the current data set; empty when it was read for
     * another site than the one connected now.
     *
     * @return array{site:string,daily_read:int,daily_error:string|null,issues:array<int,array<string,mixed>>,stats:array<int,array<string,mixed>>,sitemaps:array<int,array<string,mixed>>,quota:array{daily:int,monthly:int}|null,links:array<string,mixed>,version:int}
     */
    public static function state() {
        $state = get_option(SEOProAnalytics_Schema::option(self::OPTION), array());
        $state = is_array($state) ? $state : array();
        $site  = isset($state['site']) ? (string) $state['site'] : '';
        if (SEOProAnalytics_Schema::set() === 'live' && $site !== '' && $site !== self::property()) {
            $state = array();
            $site  = '';
        }
        $links = isset($state['links']) && is_array($state['links']) ? $state['links'] : array();
        $quota = isset($state['quota']) && is_array($state['quota']) ? $state['quota'] : null;
        return array(
            'site'        => $site,
            'daily_read'  => isset($state['daily_read']) ? (int) $state['daily_read'] : 0,
            'daily_error' => isset($state['daily_error']) ? (string) $state['daily_error'] : null,
            'issues'      => isset($state['issues']) && is_array($state['issues']) ? $state['issues'] : array(),
            'stats'       => isset($state['stats']) && is_array($state['stats']) ? $state['stats'] : array(),
            'sitemaps'    => isset($state['sitemaps']) && is_array($state['sitemaps']) ? $state['sitemaps'] : array(),
            'quota'       => $quota ? array('daily' => (int) $quota['daily'], 'monthly' => (int) $quota['monthly']) : null,
            'links'       => array(
                'read'    => isset($links['read']) ? (int) $links['read'] : 0,
                'started' => isset($links['started']) ? (int) $links['started'] : 0,
                'targets' => isset($links['targets']) ? (int) $links['targets'] : 0,
                'links'   => isset($links['links']) ? (int) $links['links'] : 0,
                'error'   => isset($links['error']) ? (string) $links['error'] : null,
                'cursor'  => isset($links['cursor']) && is_array($links['cursor']) ? $links['cursor'] : null,
            ),
            'version'     => isset($state['version']) ? (int) $state['version'] : 0,
        );
    }

    /**
     * Keep what was read (also the demo's); null removes a value.
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
     * Start over when what is kept was read for another site.
     *
     * @param string $site The site connected now.
     */
    private static function own($site) {
        $kept = get_option(SEOProAnalytics_Schema::option(self::OPTION), array());
        if (!is_array($kept) || !isset($kept['site']) || (string) $kept['site'] !== $site) {
            update_option(SEOProAnalytics_Schema::option(self::OPTION), array('site' => $site, 'version' => time()), false);
        }
    }

    /**
     * Delete the current data set's facts (disconnect, demo removal,
     * uninstall); the lock with live data only.
     */
    public static function reset() {
        delete_option(SEOProAnalytics_Schema::option(self::OPTION));
        if (SEOProAnalytics_Schema::set() === 'live') {
            delete_option(self::LOCK_OPTION);
        }
    }

    /**
     * Whether Bing is connected.
     *
     * @return bool
     */
    public static function connected() {
        require_once __DIR__ . '/class-seoproanalytics-connections.php';
        return (bool) SEOProAnalytics_Connections::get(self::SOURCE);
    }

    /**
     * The site connected now, or ''.
     *
     * @return string
     */
    private static function property() {
        require_once __DIR__ . '/class-seoproanalytics-connections.php';
        $conn = SEOProAnalytics_Connections::get(self::SOURCE);
        return $conn && isset($conn['settings']['property']) ? (string) $conn['settings']['property'] : '';
    }

    /**
     * The API key and site.
     *
     * @return array{0:string,1:string}|WP_Error
     */
    private static function ready() {
        require_once __DIR__ . '/class-seoproanalytics-connections.php';
        require_once __DIR__ . '/class-seoproanalytics-search-import.php';
        if (!self::connected()) {
            return new WP_Error('seoproanalytics_not_connected', __('Bing Webmaster Tools is not connected. Connect it in SEO Pro Analytics → Settings → Connections.', 'seoproanalytics'), array('status' => 400));
        }
        $ready = SEOProAnalytics_Search_Import::ready(self::SOURCE);
        if (is_wp_error($ready)) {
            return $ready;
        }
        return array((string) $ready[1], (string) $ready[2]);
    }

    /**
     * Note that links were written, so cached backlinks reports are made
     * again.
     */
    private static function touch_backlinks() {
        $option = SEOProAnalytics_Schema::option(SEOProAnalytics_Backlinks::OPTION);
        $state  = get_option($option, array());
        $state  = is_array($state) ? $state : array();
        $state['version'] = max(time(), isset($state['version']) ? (int) $state['version'] + 1 : 0);
        update_option($option, $state, false);
    }

    // ------------------------------------------------------------------
    // Helpers.

    /**
     * An address's path on the site (no query), '' when it is not on the
     * site.
     *
     * @param string $url Address.
     * @return string
     */
    private static function path_of($url) {
        $host  = strtolower((string) preg_replace('/^www\./i', '', (string) wp_parse_url($url, PHP_URL_HOST)));
        $home  = strtolower((string) preg_replace('/^www\./i', '', (string) wp_parse_url(home_url('/'), PHP_URL_HOST)));
        if ($host === '' || $host !== $home) {
            return '';
        }
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        return '/' . ltrim(rawurldecode($path), '/');
    }

    /**
     * A Bing date (/Date(1789689600000)/) as Y-m-d, or ''.
     *
     * @param string $value Bing's date.
     * @return string
     */
    private static function day($value) {
        $ts = self::time($value);
        return $ts ? gmdate('Y-m-d', $ts) : '';
    }

    /**
     * A Bing date as a timestamp, or 0.
     *
     * @param string $value Bing's date.
     * @return int
     */
    private static function time($value) {
        if (!preg_match('/\((-?\d+)/', $value, $m)) {
            return 0;
        }
        $ts = (int) floor((int) $m[1] / 1000);
        return $ts > 0 ? $ts : 0;
    }

    /**
     * A timestamp as ISO 8601 (UTC), or null.
     *
     * @param int $ts Timestamp.
     * @return string|null
     */
    private static function iso($ts) {
        return $ts > 0 ? gmdate('c', $ts) : null;
    }

    /**
     * Take the run lock.
     *
     * @return bool Whether this request has it.
     */
    private static function lock() {
        if (add_option(self::LOCK_OPTION, time(), '', false)) {
            return true;
        }
        if ((int) get_option(self::LOCK_OPTION, 0) > time() - self::LOCK_TIME) {
            return false;
        }
        delete_option(self::LOCK_OPTION);
        return add_option(self::LOCK_OPTION, time(), '', false);
    }

    /**
     * Give the run lock back.
     */
    private static function unlock() {
        delete_option(self::LOCK_OPTION);
    }
}
