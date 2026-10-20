<?php
/**
 * The site as an imported crawl saw it (SEOProAnalytics_Crawl_Import): what
 * each page answered once the theme, plugins, caching and the host had
 * their say, and links that fail or redirect, in menus, footers and text
 * alike. WordPress knows what it stores; a crawler sees what it serves.
 *
 * Findings in the content audit (SEOProAnalytics_Audit), and so Plan items:
 * crawl_error (a published page answered 4xx, 5xx or nothing),
 * served_noindex and served_canonical (served while WordPress does not ask
 * for it), broken_links and redirect_links (links on the page). Clicked
 * broken links: outbound and affiliate destinations people clicked whose
 * crawl answer failed, lost referrals and commissions (Plan items too).
 * External 401, 403, 429 and 999 answers are blocked, not broken: the
 * linked site refused the crawler; check by hand. Internal errors remain
 * failures. Stored statuses are unchanged, including on older imports.
 *
 * Reads: crawl_pages by its status, hops and flags keys and the primary
 * key; crawl_links by its status and hops keys, the primary key's
 * from_path_id prefix, and target_id; clicks by their ts key. Counts are
 * made when an import is written (recount()), never in a report. Design:
 * docs/seo-loop.md → Imported crawls.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 * Provenance: wpas-c8662019b99fb07b. Copies, even of part, keep these lines.
 *
 * @package SEOProAnalytics
 * @since 1.11.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProAnalytics_Crawl {

    /** What was imported last, per data set (autoload off): version, import, format, files, read, counts. */
    const OPTION = 'seoproanalytics_crawl';

    /** crawl_pages.flags: what the page served. */
    const FLAGS = array(
        'noindex'       => 1,
        'nofollow'      => 2,
        'canonical'     => 4,
        'title_missing' => 8,
        'title_differs' => 16,
        'desc_differs'  => 32,
        'desc_missing'  => 64,
    );

    /** crawl_links.placement: where a link sits. */
    const PLACEMENTS = array(0 => 'other', 1 => 'navigation', 2 => 'footer', 3 => 'body', 4 => 'image', 5 => 'sidebar');

    /** Content audit findings from the crawl, most serious first, with their bits in flagged(). */
    const FINDINGS = array(
        'crawl_error'      => 1,
        'served_noindex'   => 2,
        'served_canonical' => 4,
        'broken_links'     => 8,
        'redirect_links'   => 16,
        'served_title'     => 32,
        'served_description' => 64,
        'unreachable'     => 512,
        'deep'            => 1024,
        'slow'            => 2048,
    );

    /** Internal flags bit: last response-time state confirmed by two imports. */
    const SLOW_CONFIRMED = 128;

    /** HTML bytes, not the whole page's assets. */
    const HTML_LIMIT = 1048576;

    /** @return int Field TTFB's poor threshold, in milliseconds. */
    public static function slow_threshold() {
        require_once __DIR__ . '/class-seoproanalytics-vitals.php';
        return SEOProAnalytics_Vitals::THRESHOLDS['ttfb'][1];
    }

    /** Internal candidate bits: served H1 findings also need pages without WordPress flags. */
    const H1_NONE = 128;
    const H1_SEVERAL = 256;

    /** Report lists. */
    const KINDS = array('pages', 'links', 'clicked');

    /** Route/ability filters; blocked is links only, served flags pages only. */
    const STATUSES = array('errors', 'redirects', 'blocked', 'noindex', 'canonical', 'title_missing');

    /** Link-only filter and fixed indexed predicates; keep in step with blocked(). */
    const LINK_STATUSES = array('errors', 'redirects', 'blocked');
    const BLOCKED_SQL = 'internal = 0 AND status IN (401,403,429,999)';
    const LINK_ERROR_SQL = 'status >= 400 AND (internal = 1 OR status NOT IN (401,403,429,999))';

    /** Rows read per query at most; rows listed by default and at most. */
    const MAX_ROWS  = 5000;
    const LIMIT     = 50;
    const MAX_LIMIT = 500;

    /** Pages named per clicked destination. */
    const PAGES = 5;

    /** Clicks flags of outbound and affiliate links (SEOProAnalytics_Clicks::OUTBOUND | AFFILIATE). */
    const AWAY = 6;

    /** @var array<string,array<int,int>> flagged() per data set and version, within a request. */
    private static $flagged = array();

    /** @var array<string,array<int,array<string,mixed>>> Local field samples within this request. */
    private static $speed = array();

    /**
     * Prime local speed samples once per path in this request.
     *
     * @param int[] $ids Path ids.
     */
    private static function prime_speed(array $ids) {
        require_once __DIR__ . '/class-seoproanalytics-vitals.php';
        $prefix = SEOProAnalytics_Schema::set() . ':';
        $need = array();
        foreach ($ids as $id) {
            if (!isset(self::$speed[$prefix . $id])) {
                $need[] = $id;
                self::$speed[$prefix . $id] = array();
            }
        }
        if (!$need) {
            return;
        }
        foreach (SEOProAnalytics_Vitals::speed_samples($need) as $id => $samples) {
            self::$speed[$prefix . $id] = $samples;
        }
    }

    /**
     * Crawl speed evidence, with fresh page-level field data taking precedence.
     * Origin samples are never substituted for a page; no provider is called.
     *
     * @param array<string,mixed> $row Crawl row.
     * @return array<string,mixed>
     */
    private static function speed(array $row) {
        require_once __DIR__ . '/class-seoproanalytics-vitals.php';
        $key = SEOProAnalytics_Schema::set() . ':' . (int) $row['path_id'];
        self::prime_speed(array((int) $row['path_id']));
        $field = self::$speed[$key];
        $poor = false;
        foreach ($field as $sample) {
            $poor = $poor || $sample['status'] === 'poor';
        }
        $time = (int) $row['response_ms'] > self::slow_threshold();
        $confirmed = $time && $row['prev_response_ms'] !== null && (int) $row['prev_response_ms'] > self::slow_threshold();
        return array(
            'response_ms' => (int) $row['response_ms'],
            'prev_response_ms' => $row['prev_response_ms'] === null ? null : (int) $row['prev_response_ms'],
            'bytes' => (int) $row['bytes'],
            'field' => $field,
            'field_poor' => $poor,
            'slow' => ($time || (int) $row['bytes'] > self::HTML_LIMIT) && ($field ? $poor : ($confirmed || (int) $row['bytes'] > self::HTML_LIMIT)),
        );
    }

    // ------------------------------------------------------------------
    // Answers.

    /**
     * An HTTP status's class: ok (2xx), redirect (3xx), error (4xx, 5xx,
     * other) or none (no answer).
     *
     * @param int $status Status.
     * @param bool $internal Whether this is a page or internal link (external links can be blocked).
     * @return string
     */
    public static function answer($status, $internal = true) {
        $status = (int) $status;
        if (self::blocked($status, $internal)) {
            return 'blocked';
        }
        if ($status === 0) {
            return 'none';
        }
        if ($status >= 200 && $status < 300) {
            return 'ok';
        }
        return $status >= 300 && $status < 400 ? 'redirect' : 'error';
    }

    /**
     * Whether a status failed: 4xx, 5xx or no answer.
     *
     * @param int $status Status.
     * @param bool $internal Page or internal link.
     * @return bool
     */
    public static function failed($status, $internal = true) {
        return in_array(self::answer($status, $internal), array('error', 'none'), true);
    }

    /**
     * Refusal is not evidence that an external destination is broken.
     *
     * @param int $status Stored HTTP status.
     * @param bool $internal Internal destination.
     * @return bool
     */
    public static function blocked($status, $internal) {
        return !$internal && in_array((int) $status, array(401, 403, 429, 999), true);
    }

    /**
     * A page's answer in words for a change: the status, then noindex and
     * canonical elsewhere when served.
     *
     * @param int $status Status.
     * @param int $flags  FLAGS.
     * @return string
     */
    public static function served($status, $flags) {
        $out = array((int) $status ? (string) (int) $status : __('no answer', 'seoproanalytics'));
        if ((int) $flags & self::FLAGS['noindex']) {
            $out[] = 'noindex';
        }
        if ((int) $flags & self::FLAGS['canonical']) {
            $out[] = __('canonical elsewhere', 'seoproanalytics');
        }
        return implode(', ', $out);
    }

    /**
     * The names of a row's flags, in FLAGS order.
     *
     * @param int $flags Flags.
     * @return string[]
     */
    public static function flag_names($flags) {
        $out = array();
        foreach (self::FLAGS as $name => $bit) {
            if ((int) $flags & $bit) {
                $out[] = $name;
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // The content audit's findings.

    /**
     * Pages with a crawl finding: path id => FINDINGS bits. served_noindex
     * and served_canonical are what the page served; the audit keeps them
     * only where WordPress does not ask for the same.
     *
     * @return array<int,int>
     */
    public static function flagged() {
        $key = SEOProAnalytics_Schema::set() . ':' . self::state()['version'];
        if (isset(self::$flagged[$key])) {
            return self::$flagged[$key];
        }
        $out  = array();
        $mark = static function (array $ids, $bit) use (&$out) {
            foreach ($ids as $id) {
                $out[(int) $id] = (isset($out[(int) $id]) ? $out[(int) $id] : 0) | $bit;
            }
        };
        if (self::has_crawl()) {
            $pages = SEOProAnalytics_Schema::table('crawl_pages');
            $links = SEOProAnalytics_Schema::table('crawl_links');
            $mark(self::ids($pages, 'path_id', 'status', 'status = 0'), self::FINDINGS['crawl_error']);
            $mark(self::ids($pages, 'path_id', 'status', 'status >= 400'), self::FINDINGS['crawl_error']);
            foreach (self::flag_rows() as $row) {
                if ((int) $row['flags'] & self::FLAGS['noindex']) {
                    $mark(array($row['path_id']), self::FINDINGS['served_noindex']);
                }
                if ((int) $row['flags'] & self::FLAGS['canonical']) {
                    $mark(array($row['path_id']), self::FINDINGS['served_canonical']);
                }
                if ((int) $row['flags'] & self::FLAGS['title_differs']) {
                    $mark(array($row['path_id']), self::FINDINGS['served_title']);
                }
                if ((int) $row['flags'] & (self::FLAGS['desc_differs'] | self::FLAGS['desc_missing'])) {
                    $mark(array($row['path_id']), self::FINDINGS['served_description']);
                }
            }
            $mark(self::ids($pages, 'path_id', 'h1', 'h1 = 0'), self::H1_NONE);
            $mark(self::ids($pages, 'path_id', 'h1', 'h1 > 1'), self::H1_SEVERAL);
            $mark(self::ids($pages, 'path_id', 'depth', 'depth >= 4'), self::FINDINGS['deep']);
            $slow = array_unique(array_merge(
                self::ids($pages, 'path_id', 'response_ms', 'response_ms > ' . self::slow_threshold()),
                self::ids($pages, 'path_id', 'bytes', 'bytes > ' . self::HTML_LIMIT)
            ));
            self::prime_speed($slow);
            foreach (self::page_rows($slow) as $id => $row) {
                if (self::speed($row)['slow']) {
                    $mark(array($id), self::FINDINGS['slow']);
                }
            }
            if (self::state()['complete'] && SEOProAnalytics_Crawl_Import::status()['status'] !== 'running') {
                global $wpdb;
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- bounded post_id range and primary-key lookup per published fact.
                $missing = (array) $wpdb->get_col($wpdb->prepare('SELECT f.path_id FROM %i f FORCE INDEX (`post_id`) LEFT JOIN %i c ON c.path_id = f.path_id WHERE f.post_id > 0 AND f.noindex = 0 AND f.canonical_away = 0 AND c.path_id IS NULL LIMIT %d', SEOProAnalytics_Schema::table('page_facts'), $pages, self::MAX_ROWS));
                $mark($missing, self::FINDINGS['unreachable']);
            }
            $mark(self::ids($links, 'from_path_id', 'status', 'status = 0'), self::FINDINGS['broken_links']);
            $mark(self::ids($links, 'from_path_id', 'status', self::LINK_ERROR_SQL), self::FINDINGS['broken_links']);
            $mark(self::ids($links, 'from_path_id', 'status', 'status >= 300 AND status < 400 AND internal = 1'), self::FINDINGS['redirect_links']);
            $mark(self::ids($links, 'from_path_id', 'hops', 'hops > 0 AND internal = 1'), self::FINDINGS['redirect_links']);
        }
        self::$flagged = array($key => $out);
        return $out;
    }

    /**
     * The crawl's facts of pages, for the audit's rows: path id => status,
     * hops, redirect, flags, broken (links that fail), redirects (internal
     * links through redirects), crawled.
     *
     * @param int[] $ids Path ids.
     * @return array<int,array<string,mixed>>
     */
    public static function of_pages(array $ids) {
        global $wpdb;
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids || !self::has_crawl()) {
            return array();
        }
        $rows    = self::page_rows($ids);
        self::prime_speed(array_keys($rows));
        $counts  = self::link_counts($ids);
        $targets = SEOProAnalytics_Dict::values(array_column($rows, 'redirect_id'));
        $out     = array();
        foreach ($ids as $id) {
            if (!isset($rows[$id]) && !isset($counts[$id])) {
                continue;
            }
            $row      = isset($rows[$id]) ? $rows[$id] : null;
            $out[$id] = ($row ? self::speed($row) : array('response_ms' => null, 'prev_response_ms' => null, 'bytes' => null, 'field' => array(), 'field_poor' => false, 'slow' => false)) + array(
                'status'    => $row ? (int) $row['status'] : null,
                'hops'      => $row ? (int) $row['hops'] : 0,
                'redirect'  => $row && isset($targets[(int) $row['redirect_id']]) ? (string) $targets[(int) $row['redirect_id']] : null,
                'flags'     => $row ? self::flag_names((int) $row['flags']) : array(),
                'h1'        => $row && $row['h1'] !== null ? (int) $row['h1'] : null,
                'depth'     => $row ? (int) $row['depth'] : null,
                'links_in'  => $row && $row['links_in'] !== null ? (int) $row['links_in'] : null,
                'links_in_body' => $row && $row['links_in_body'] !== null ? (int) $row['links_in_body'] : null,
                'broken'    => isset($counts[$id]) ? $counts[$id]['broken'] : 0,
                'redirects' => isset($counts[$id]) ? $counts[$id]['redirects'] : 0,
                'crawled'   => $row && (int) $row['crawled'] ? gmdate('c', (int) $row['crawled']) : null,
            );
        }
        return $out;
    }

    /**
     * Exact verdict for a saved finding; null means this import cannot prove it.
     * Never infer recovery from absence in a capped report or an untouched page.
     *
     * @param array<string,mixed> $item Saved Plan item.
     * @param array<string,mixed> $job Import job.
     * @return bool|null Still found, fixed, or unknown.
     */
    public static function verify_finding(array $item, array $job) {
        global $wpdb;
        $id = (int) ($item['path_id'] ?? 0);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- exact primary-key coverage lookup.
        $page = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE path_id = %d AND import_id = %d', SEOProAnalytics_Schema::table('crawl_pages'), $id, (int) $job['id']), ARRAY_A);
        if (!$page) {
            return null;
        }
        $finding = (string) $item['finding'];
        if ($finding === 'crawl_error') {
            return self::failed((int) $page['status']);
        }
        if (self::answer((int) $page['status']) !== 'ok' || (int) $page['hops'] > 0) {
            return null;
        }
        $observed = (array) ($job['observed'] ?? array());
        if (in_array($finding, array('broken_links', 'redirect_links', 'clicked_broken'), true)) {
            if (empty($job['kinds']['links']) || !empty($job['links_uncertain'])) {
                return null;
            }
            $counts = self::link_counts(array($id));
            if ($finding === 'clicked_broken') {
                $targets = SEOProAnalytics_Dict::find(SEOProAnalytics_Schema::DICT_TARGET, array((string) ($item['figures']['target'] ?? '')));
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- source/target primary key, retaining only failing or redirecting links.
                $link = $wpdb->get_row($wpdb->prepare('SELECT status, internal FROM %i WHERE from_path_id = %d AND target_id = %d', SEOProAnalytics_Schema::table('crawl_links'), $id, (int) ($targets[0] ?? 0)), ARRAY_A);
                // A refusal cannot prove recovery or reopen a completed clicked-link fix.
                return $link && self::blocked((int) $link['status'], (bool) $link['internal']) ? null : (bool) $link;
            }
            return (int) ($counts[$id][$finding === 'broken_links' ? 'broken' : 'redirects'] ?? 0) > 0;
        }
        switch ($finding) {
            case 'served_noindex':
                return !in_array('robots', $observed, true) ? null : (bool) ((int) $page['flags'] & self::FLAGS['noindex']);
            case 'served_canonical':
                return !in_array('canonical', $observed, true) ? null : (bool) ((int) $page['flags'] & self::FLAGS['canonical']);
            case 'served_title':
                return $page['title_hash'] === null || !SEOProAnalytics_Audit::stored_text(array($id)) ? null : (bool) ((int) $page['flags'] & self::FLAGS['title_differs']);
            case 'served_description':
                return $page['desc_hash'] === null || !SEOProAnalytics_Audit::stored_text(array($id)) ? null : (bool) ((int) $page['flags'] & (self::FLAGS['desc_differs'] | self::FLAGS['desc_missing']));
            case 'unreachable':
                return !empty($job['partial']) ? null : false;
            case 'deep':
                return !empty($job['partial']) || !in_array('depth', $observed, true) ? null : (int) $page['depth'] >= 4;
            case 'slow':
                if (!(int) $page['response_ms'] && !(int) $page['bytes']) {
                    return null;
                }
                self::prime_speed(array($id));
                return self::speed($page)['slow'];
        }
        return null;
    }

    /**
     * Observed heading counts for audit candidates, through the primary key.
     *
     * @param int[] $ids Path ids.
     * @return array<int,int>
     */
    public static function headings(array $ids) {
        $out = array();
        if (self::has_crawl()) {
            foreach (self::page_rows($ids) as $id => $row) {
                if ($row['h1'] !== null) {
                    $out[$id] = (int) $row['h1'];
                }
            }
        }
        return $out;
    }

    /**
     * A crawl finding in a few words, for a sentence.
     *
     * @param string                   $finding One of FINDINGS.
     * @param array<string,mixed>|null $crawl   of_pages() of the page.
     * @return string
     */
    public static function phrase($finding, $crawl) {
        $crawl = is_array($crawl) ? $crawl : array();
        switch ($finding) {
            case 'slow':
                /* translators: 1: response milliseconds, 2: HTML bytes. */
                return sprintf(__('slow as the crawler measured: %1$s ms, %2$s HTML bytes; fresh field data takes precedence when available', 'seoproanalytics'), number_format_i18n((int) ($crawl['response_ms'] ?? 0)), number_format_i18n((int) ($crawl['bytes'] ?? 0)));
            case 'unreachable':
                return __('the complete imported crawl did not reach this published, indexable page', 'seoproanalytics');
            case 'deep':
                return __('it is at least four clicks from the crawl start and has search impressions or conversions', 'seoproanalytics');
            case 'crawl_error':
                $status = isset($crawl['status']) ? (int) $crawl['status'] : 0;
                /* translators: %d: HTTP status code */
                return $status ? sprintf(__('it answered HTTP %d to the imported crawl', 'seoproanalytics'), $status) : __('it did not answer the imported crawl', 'seoproanalytics');
            case 'served_noindex':
                return __('it is served with noindex, which WordPress does not set', 'seoproanalytics');
            case 'served_canonical':
                return __('it is served with a canonical address elsewhere, which WordPress does not set', 'seoproanalytics');
            case 'served_title':
                return __('its title as served is empty, only the site name, or does not contain the stored title', 'seoproanalytics');
            case 'served_description':
                return __('its description as served is missing or differs from WordPress', 'seoproanalytics');
            case 'broken_links':
                $n = isset($crawl['broken']) ? max(1, (int) $crawl['broken']) : 1;
                /* translators: %s: number of links */
                return sprintf(_n('%s link on it leads to an address that fails', '%s links on it lead to addresses that fail', $n, 'seoproanalytics'), number_format_i18n($n));
            case 'redirect_links':
                $n = isset($crawl['redirects']) ? max(1, (int) $crawl['redirects']) : 1;
                /* translators: %s: number of links */
                return sprintf(_n('%s internal link on it goes through a redirect', '%s internal links on it go through redirects', $n, 'seoproanalytics'), number_format_i18n($n));
            default:
                return (string) $finding;
        }
    }

    /**
     * What to do about a crawl finding.
     *
     * @param string $finding One of FINDINGS, or clicked_broken.
     * @return string
     */
    public static function fix($finding) {
        switch ($finding) {
            case 'slow':
                return __('Check caching, server response and HTML size, then crawl again. Compare field TTFB and LCP where available; crawler timing varies with location and load.', 'seoproanalytics');
            case 'unreachable':
            case 'deep':
                return __('Link to it from a relevant page with traffic and many incoming links. Use Internal links → Converting and Missing links to choose a useful connection, then crawl again.', 'seoproanalytics');
            case 'crawl_error':
                return __('Make the address answer with the page again (or redirect it to the right one), then crawl the site again and import the crawl.', 'seoproanalytics');
            case 'served_noindex':
                return __('Find what adds noindex (another plugin, the theme or a server header) and remove it, unless the page should leave search.', 'seoproanalytics');
            case 'served_canonical':
                return __('Find what changes the canonical address (another plugin, the theme or a cache) and let it point at the page itself.', 'seoproanalytics');
            case 'served_title':
            case 'served_description':
                return __('Compare the served and stored text in Site crawl. Fix the theme, SEO template or plugin that overrides it, clear the cache, then import another crawl.', 'seoproanalytics');
            case 'broken_links':
                return __('Fix or remove the links that fail (in Search → Audit → Site crawl), in the text, menus, widgets and footer alike.', 'seoproanalytics');
            case 'redirect_links':
                return __('Point the links straight at where they end up.', 'seoproanalytics');
            case 'clicked_broken':
                return __('Replace the link with one that works (for an affiliate link, the programme’s current address), or remove it.', 'seoproanalytics');
            default:
                return '';
        }
    }

    // ------------------------------------------------------------------
    // The report.

    /**
     * The crawl report: the imported crawl's date, source and counts, and
     * one list: pages with a problem, links that fail or redirect, or
     * clicked destinations that fail.
     *
     * @param array<string,mixed> $req     From SEOProAnalytics_Query::request() (range for clicked, limit, offset).
     * @param string              $kind    One of KINDS; '' for pages.
     * @param string              $status  One of STATUSES; '' for all.
     * @param string              $finding One of FINDINGS (pages only); '' for all.
     * @return array<string,mixed>|WP_Error
     */
    public static function report(array $req, $kind = '', $status = '', $finding = '', $goal = '', $engine = 'google') {
        $kind    = $kind === '' ? 'pages' : (string) $kind;
        $status  = (string) $status;
        $finding = (string) $finding;
        if (!in_array($kind, self::KINDS, true)) {
            /* translators: %s: list of lists */
            return new WP_Error('seoproanalytics_crawl_kind', sprintf(__('The kind is one of: %s.', 'seoproanalytics'), implode(', ', self::KINDS)), array('status' => 400));
        }
        $statuses = $kind === 'links' ? self::LINK_STATUSES : array_diff(self::STATUSES, array('blocked'));
        if ($status !== '' && !in_array($status, $statuses, true)) {
            /* translators: %s: list of statuses */
            return new WP_Error('seoproanalytics_crawl_status', sprintf(__('The status is one of: %s.', 'seoproanalytics'), implode(', ', $statuses)), array('status' => 400));
        }
        if ($finding !== '' && !isset(self::FINDINGS[$finding])) {
            /* translators: %s: list of findings */
            return new WP_Error('seoproanalytics_crawl_finding', sprintf(__('The finding is one of: %s.', 'seoproanalytics'), implode(', ', array_keys(self::FINDINGS))), array('status' => 400));
        }
        self::load();
        $state = self::state();
        $ask   = array('kind' => $kind, 'status' => $status, 'finding' => $finding, 'limit' => isset($req['limit']) ? (int) $req['limit'] : self::LIMIT, 'offset' => isset($req['offset']) ? (int) $req['offset'] : 0, 'crawl' => $state['version']);
        if ($kind === 'clicked' || $finding === 'slow') {
            $ask += array('range' => $req['range'], 'from' => isset($req['from']) ? $req['from'] : '', 'to' => isset($req['to']) ? $req['to'] : '');
        }
        $ask += array('goal' => $goal, 'engine' => $engine);
        return SEOProAnalytics_Query::cached('crawl', $ask, static function () use ($req, $kind, $status, $finding, $state, $goal, $engine) {
            $answer = self::build($req, $kind, $status, $finding, $state);
            if ($kind === 'pages' && $finding === 'slow') {
                $rows = self::slow_rows($req, $engine, $goal, $status);
                $limit = max(1, min(self::MAX_LIMIT, (int) ($req['limit'] ?? self::LIMIT)));
                $offset = max(0, (int) ($req['offset'] ?? 0));
                $answer['range'] = SEOProAnalytics_Query::range_out(SEOProAnalytics_Query::range($req));
                $answer['total'] = count($rows);
                $answer['more'] = $offset + $limit < count($rows);
                $answer['rows'] = array_slice($rows, $offset, $limit);
            }
            return $answer;
        });
    }

    /**
     * Slow pages with visits in the requested period, by conversions when
     * a goal is selected, otherwise visits. All channels, not just search.
     *
     * @param array<string,mixed> $req Request.
     * @param string $engine Search engine.
     * @param string $goal Goal.
     * @param string $status Status filter.
     * @return array<int,array<string,mixed>>
     */
    public static function slow_rows(array $req, $engine = 'google', $goal = '', $status = '') {
        require_once __DIR__ . '/class-seoproanalytics-goals.php';
        require_once __DIR__ . '/class-seoproanalytics-conversions.php';
        $range = SEOProAnalytics_Query::range($req);
        $goals = SEOProAnalytics_Goals::goals();
        $chosen = $goals ? $goals[0] : null;
        foreach ($goals as $one) {
            if ($one['id'] === $goal) {
                $chosen = $one;
            }
        }
        $list = self::page_list($status, 'slow');
        $ignored = array();
        $pages = SEOProAnalytics_Search::page_ids($req['filters'], '', $ignored);
        if ($pages !== null) {
            $list = array_values(array_filter($list, static function ($row) use ($pages) { return in_array($row['path_id'], $pages, true); }));
        }
        $rows = self::shown('pages', $list);
        $traffic = self::speed_traffic(array_column($rows, 'path_id'), $range, $chosen);
        $code = SEOProAnalytics_Search::ENGINES[SEOProAnalytics_Search::engine_name($engine)];
        $bounds = SEOProAnalytics_Search::bounds($code);
        $days = $bounds['to'] !== '' ? SEOProAnalytics_Search::days($range, $bounds) : null;
        $search = $days && $rows ? SEOProAnalytics_Opportunities::sums('gsc_pages', $code, $days, array_column($rows, 'path_id')) : array();
        $out = array();
        foreach ($rows as $row) {
            $page = $traffic[$row['path_id']] ?? array('visits' => 0, 'conversions' => 0);
            if ($page['visits'] > 0) {
                $out[] = $row + $page + array('clicks' => (int) ($search[(string) $row['path_id']]['c'] ?? 0), 'goal' => $chosen ? array('id' => $chosen['id'], 'name' => $chosen['name']) : null);
            }
        }
        $metric = $chosen ? 'conversions' : 'visits';
        usort($out, static function ($a, $b) use ($metric) {
            return array($b[$metric], $b['visits'], $b['clicks'], $a['path_id']) <=> array($a[$metric], $a['visits'], $a['clicks'], $b['path_id']);
        });
        return $out;
    }

    /**
     * All-channel landing visits and goal conversions of bounded candidates.
     *
     * @param int[] $paths Paths.
     * @param array<string,mixed> $range Period.
     * @param array<string,mixed>|null $chosen Goal.
     * @return array<int,array{visits:int,conversions:int}>
     */
    private static function speed_traffic(array $paths, array $range, $chosen) {
        global $wpdb;
        $traffic = array();
        foreach (array_chunk($paths, 500) as $ids) {
            $holders = implode(',', array_fill(0, count($ids), '%d'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- dim_val_day equality/ranges for bounded candidate ids; placeholders only.
            $daily = $wpdb->get_results($wpdb->prepare("SELECT val, SUM(visits) AS visits FROM %i WHERE dim = 13 AND val IN ($holders) AND day >= %s AND day < %s GROUP BY val", array_merge(array(SEOProAnalytics_Schema::table('daily')), $ids, array(wp_date('Y-m-d', $range['from']), wp_date('Y-m-d', $range['to'])))), ARRAY_A);
            foreach ((array) $daily as $day) {
                $traffic[(int) $day['val']] = array('visits' => (int) $day['visits'], 'conversions' => 0);
            }
        }
        if ($chosen && $paths) {
            foreach (SEOProAnalytics_Query::CHANNELS as $channel) {
                foreach (SEOProAnalytics_Conversions::by_entry($chosen, $range, $channel) as $id => $count) {
                    if (isset($traffic[$id])) {
                        $traffic[$id]['conversions'] += $count;
                    }
                }
            }
        }
        return $traffic;
    }

    /**
     * The report, made.
     *
     * @param array<string,mixed> $req     Request.
     * @param string              $kind    List.
     * @param string              $status  Status filter.
     * @param string              $finding Finding filter.
     * @param array<string,mixed> $state   state().
     * @return array<string,mixed>
     */
    private static function build(array $req, $kind, $status, $finding, array $state) {
        $limit  = max(1, min(self::MAX_LIMIT, isset($req['limit']) ? (int) $req['limit'] : self::LIMIT));
        $offset = max(0, isset($req['offset']) ? (int) $req['offset'] : 0);
        $answer = array(
            'kind'     => $kind,
            'status'   => $status,
            'finding'  => $finding,
            'import'   => $state['import'] ? (int) $state['import'] : null,
            'format'   => $state['format'] !== '' ? $state['format'] : null,
            'files'    => $state['files'],
            'read'     => $state['read'] ? gmdate('c', (int) $state['read']) : null,
            'counts'   => $state['counts'],
            'complete' => $state['complete'],
            'coverage_note' => $state['complete'] ? '' : __('Unreachable pages are not checked: this crawl is not confirmed complete.', 'seoproanalytics'),
            'findings' => self::finding_counts(),
            'trend'    => self::trend(),
            'range'    => null,
            'rows'     => array(),
            'total'    => 0,
            'more'     => false,
            'rules'    => array('max_pages' => SEOProAnalytics_Crawl_Import::MAX_PAGES, 'max_links' => SEOProAnalytics_Crawl_Import::MAX_LINKS, 'max_rows' => self::MAX_ROWS, 'formats' => SEOProAnalytics_Crawl_Import::FORMATS),
        );
        if ($kind === 'clicked') {
            $range           = SEOProAnalytics_Query::range($req);
            $answer['range'] = SEOProAnalytics_Query::range_out($range);
            $list            = self::clicked_rows($range);
        } elseif ($kind === 'links') {
            $list = self::link_list($status);
        } else {
            $list = self::page_list($status, $finding);
        }
        $answer['total'] = count($list);
        $answer['more']  = $offset + $limit < count($list);
        $answer['rows']  = self::shown($kind, array_slice($list, $offset, $limit));
        return $answer;
    }

    /**
     * Pages per finding (served noindex and canonical as served).
     *
     * @return array<string,int>
     */
    private static function finding_counts() {
        $counts = array_fill_keys(array_keys(self::FINDINGS), 0);
        foreach (self::flagged() as $bits) {
            foreach (self::FINDINGS as $name => $bit) {
                $counts[$name] += ($bits & $bit) ? 1 : 0;
            }
        }
        return $counts;
    }

    /**
     * Pages with a problem: an error, a redirect, a served flag, or links
     * that fail or redirect; errors first, then most failing links.
     *
     * @param string $status  Status filter.
     * @param string $finding Finding filter.
     * @return array<int,array<string,mixed>>
     */
    private static function page_list($status, $finding) {
        if (!self::has_crawl()) {
            return array();
        }
        $table = SEOProAnalytics_Schema::table('crawl_pages');
        $ids   = array();
        $conds = array(
            'errors'        => array(array('status', 'status = 0'), array('status', 'status >= 400')),
            'redirects'     => array(array('status', 'status >= 300 AND status < 400'), array('hops', 'hops > 0')),
            'noindex'       => array(array('flags', 'flags > 0')),
            'canonical'     => array(array('flags', 'flags > 0')),
            'title_missing' => array(array('flags', 'flags > 0')),
        );
        foreach ($status === '' ? $conds : array($status => $conds[$status]) as $list) {
            foreach ($list as $cond) {
                $ids = array_merge($ids, self::ids($table, 'path_id', $cond[0], $cond[1]));
            }
        }
        $flagged = self::flagged();
        if ($status === '') {
            $ids = array_merge($ids, array_keys($flagged));
        }
        $ids    = array_values(array_unique(array_map('intval', $ids)));
        $rows   = self::page_rows($ids);
        $counts = self::link_counts($ids);
        $list   = array();
        foreach ($ids as $id) {
            $row  = isset($rows[$id]) ? $rows[$id] : null;
            $bits = isset($flagged[$id]) ? $flagged[$id] : 0;
            if (($finding !== '' && !($bits & self::FINDINGS[$finding])) || !self::page_matches($row, $status)) {
                continue;
            }
            $list[] = array('path_id' => $id, 'row' => $row, 'bits' => $bits, 'broken' => isset($counts[$id]) ? $counts[$id]['broken'] : 0, 'redirects' => isset($counts[$id]) ? $counts[$id]['redirects'] : 0);
        }
        usort($list, static function ($a, $b) {
            $fa = $a['row'] ? (int) self::failed((int) $a['row']['status']) : 0;
            $fb = $b['row'] ? (int) self::failed((int) $b['row']['status']) : 0;
            return array($fb, $b['broken'], $b['redirects'], $a['path_id']) <=> array($fa, $a['broken'], $a['redirects'], $b['path_id']);
        });
        return $list;
    }

    /**
     * Whether a page's row has the status asked for.
     *
     * @param array<string,mixed>|null $row    Row.
     * @param string                   $status Status filter.
     * @return bool
     */
    private static function page_matches($row, $status) {
        if ($status === '') {
            return true;
        }
        if ($row === null) {
            return false;
        }
        $flags = (int) $row['flags'];
        switch ($status) {
            case 'errors':
                return self::failed((int) $row['status']);
            case 'redirects':
                return (int) $row['hops'] > 0 || self::answer((int) $row['status']) === 'redirect';
            default:
                return (bool) ($flags & self::FLAGS[$status]);
        }
    }

    /**
     * Links that fail or redirect: failing first, then by target.
     *
     * @param string $status errors, redirects or ''.
     * @return array<int,array<string,mixed>>
     */
    private static function link_list($status) {
        global $wpdb;
        if (!self::has_crawl()) {
            return array();
        }
        $table = SEOProAnalytics_Schema::table('crawl_links');
        $conds = array();
        if ($status === 'blocked') {
            $conds[] = array('status', self::BLOCKED_SQL);
        } elseif ($status !== 'redirects') {
            $conds[] = array('status', 'status = 0');
            $conds[] = array('status', $status === 'errors' ? self::LINK_ERROR_SQL : 'status >= 400');
        }
        if ($status === '' || $status === 'redirects') {
            $conds[] = array('status', 'status >= 300 AND status < 400');
            $conds[] = array('hops', 'hops > 0');
        }
        $rows = array();
        foreach ($conds as $cond) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table, by the named key; $cond holds fixed SQL.
            foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT * FROM %i FORCE INDEX (`{$cond[0]}`) WHERE {$cond[1]} LIMIT %d", $table, self::MAX_ROWS), ARRAY_A) as $row) {
                $rows[$row['from_path_id'] . ':' . $row['target_id']] = $row;
            }
        }
        $list = array();
        foreach ($rows as $row) {
            if ($status === 'redirects' && (self::failed((int) $row['status']) || self::blocked((int) $row['status'], (bool) $row['internal']))) {
                continue;
            }
            $list[] = $row;
        }
        usort($list, static function ($a, $b) {
            return array((int) self::failed((int) $b['status'], (bool) $b['internal']), (int) $a['target_id'], (int) $a['from_path_id']) <=> array((int) self::failed((int) $a['status'], (bool) $a['internal']), (int) $b['target_id'], (int) $b['from_path_id']);
        });
        return $list;
    }

    /**
     * Clicked destinations whose crawl answer failed: outbound and
     * affiliate links clicked in the range, most clicks first, each with
     * the pages it was clicked on and the pages that link to it.
     *
     * @param array<string,mixed> $range From SEOProAnalytics_Query::range().
     * @return array<int,array<string,mixed>>
     */
    public static function clicked_rows(array $range) {
        global $wpdb;
        if (!self::has_crawl()) {
            return array();
        }
        $links  = SEOProAnalytics_Schema::table('crawl_links');
        $status = array();
        foreach (array(array('status', 'status = 0'), array('status', self::LINK_ERROR_SQL), array('hops', 'hops > 0 AND NOT (' . self::BLOCKED_SQL . ')')) as $cond) {
            // Affiliate links are often the site's own /go/ addresses, so internal targets count too.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table, by its status key; $cond is fixed SQL.
            foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT target_id, status, hops, redirect_id FROM %i FORCE INDEX (`{$cond[0]}`) WHERE {$cond[1]} LIMIT %d", $links, self::MAX_ROWS), ARRAY_A) as $row) {
                $status[(int) $row['target_id']] = $row;
            }
        }
        if (!$status) {
            return array();
        }
        $by = array();
        foreach (array_chunk(array_keys($status), 500) as $chunk) {
            $holders = implode(', ', array_fill(0, count($chunk), '%d'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table, by its ts key over the range; $holders holds only placeholders.
            $rows = $wpdb->get_results($wpdb->prepare("SELECT target_id, path_id, COUNT(*) AS n, BIT_OR(flags) AS f FROM %i WHERE ts >= %d AND ts < %d AND kind = %d AND flags & %d > 0 AND target_id IN ($holders) GROUP BY target_id, path_id", array_merge(array(SEOProAnalytics_Schema::table('clicks'), (int) $range['from'], (int) $range['to'], SEOProAnalytics_Schema::CLICK, self::AWAY), $chunk)), ARRAY_A);
            foreach ((array) $rows as $row) {
                $t = (int) $row['target_id'];
                if (!isset($by[$t])) {
                    $by[$t] = array('target_id' => $t, 'status' => (int) $status[$t]['status'], 'hops' => (int) $status[$t]['hops'], 'redirect_id' => (int) $status[$t]['redirect_id'], 'clicks' => 0, 'flags' => 0, 'pages' => array());
                }
                $by[$t]['clicks']                    += (int) $row['n'];
                $by[$t]['flags']                     |= (int) $row['f'];
                $by[$t]['pages'][(int) $row['path_id']] = (int) $row['n'];
            }
        }
        foreach ($by as &$one) {
            arsort($one['pages']);
            $one['pages'] = array_slice($one['pages'], 0, self::PAGES, true);
        }
        unset($one);
        usort($by, static function ($a, $b) {
            return array($b['clicks'], $a['target_id']) <=> array($a['clicks'], $b['target_id']);
        });
        return $by;
    }

    /**
     * Rows as the answer gives them.
     *
     * @param string                         $kind List.
     * @param array<int,array<string,mixed>> $list Rows shown.
     * @return array<int,array<string,mixed>>
     */
    private static function shown($kind, array $list) {
        if ($kind === 'links') {
            return self::links_out($list);
        }
        if ($kind === 'clicked') {
            return self::clicked_out($list);
        }
        $ids  = array_column($list, 'path_id');
        self::prime_speed($ids);
        $text = SEOProAnalytics_Dict::values(array_merge($ids, array_filter(array_map(static function ($item) {
            return $item['row'] ? (int) $item['row']['redirect_id'] : 0;
        }, $list))));
        $labels = array();
        foreach ($list as $item) {
            if ($item['row']) {
                $labels[] = (int) $item['row']['title_id'];
                $labels[] = (int) $item['row']['desc_id'];
            }
        }
        $labels = SEOProAnalytics_Dict::values($labels);
        require_once __DIR__ . '/class-seoproanalytics-audit.php';
        $stored = SEOProAnalytics_Audit::stored_text($ids);
        $out = array();
        foreach ($list as $item) {
            $row   = $item['row'];
            $path  = isset($text[$item['path_id']]) ? (string) $text[$item['path_id']] : '';
            $found = array();
            foreach (self::FINDINGS as $name => $bit) {
                if ($item['bits'] & $bit) {
                    $found[] = $name;
                }
            }
            $out[] = ($row ? self::speed($row) : array('field' => array(), 'field_poor' => false, 'slow' => false, 'prev_response_ms' => null)) + array(
                'path_id'     => (int) $item['path_id'],
                'path'        => $path,
                'url'         => $path !== '' ? home_url($path) : '',
                'status'      => $row ? (int) $row['status'] : null,
                'answer'      => $row ? self::answer((int) $row['status']) : null,
                'prev_status' => $row && $row['prev_status'] !== null ? (int) $row['prev_status'] : null,
                'hops'        => $row ? (int) $row['hops'] : 0,
                'redirect'    => $row && isset($text[(int) $row['redirect_id']]) ? (string) $text[(int) $row['redirect_id']] : null,
                'flags'       => $row ? self::flag_names((int) $row['flags']) : array(),
                'title'       => self::kept_text($row, 'title', $labels),
                'description' => self::kept_text($row, 'desc', $labels),
                'stored_title' => isset($stored[$item['path_id']]) ? $stored[$item['path_id']]['title'] : null,
                'stored_description' => isset($stored[$item['path_id']]) ? $stored[$item['path_id']]['description'] : null,
                'h1'          => $row && $row['h1'] !== null ? (int) $row['h1'] : null,
                'findings'    => $found,
                'broken'      => (int) $item['broken'],
                'redirects'   => (int) $item['redirects'],
                'depth'       => $row ? (int) $row['depth'] : null,
                'links_in'    => $row && $row['links_in'] !== null ? (int) $row['links_in'] : null,
                'links_in_body' => $row && $row['links_in_body'] !== null ? (int) $row['links_in_body'] : null,
                'response_ms' => $row ? (int) $row['response_ms'] : null,
                'bytes'       => $row ? (int) $row['bytes'] : null,
                'crawled'     => $row && (int) $row['crawled'] ? gmdate('c', (int) $row['crawled']) : null,
            );
        }
        return $out;
    }

    /**
     * Empty observed text is distinct from unknown or discarded text.
     *
     * @param array<string,mixed>|null $row Crawl row.
     * @param string $field title or desc.
     * @param array<int,string> $labels Retained text.
     * @return string|null
     */
    private static function kept_text($row, $field, array $labels) {
        if (!$row || $row[$field . '_hash'] === null) {
            return null;
        }
        if (bin2hex((string) $row[$field . '_hash']) === SEOProAnalytics_Audit::NONE) {
            return '';
        }
        return $labels[(int) $row[$field . '_id']] ?? null;
    }

    /**
     * Link rows as the answer gives them.
     *
     * @param array<int,array<string,mixed>> $list Rows.
     * @return array<int,array<string,mixed>>
     */
    private static function links_out(array $list) {
        $text = SEOProAnalytics_Dict::values(array_merge(array_column($list, 'from_path_id'), array_column($list, 'target_id'), array_column($list, 'anchor_id')));
        $out  = array();
        foreach ($list as $row) {
            $from  = isset($text[(int) $row['from_path_id']]) ? (string) $text[(int) $row['from_path_id']] : '';
            $out[] = array(
                'from'      => $from,
                'from_url'  => $from !== '' ? home_url($from) : '',
                'target'    => isset($text[(int) $row['target_id']]) ? (string) $text[(int) $row['target_id']] : '',
                'internal'  => (bool) (int) $row['internal'],
                'status'    => (int) $row['status'],
                'answer'    => self::answer((int) $row['status'], (bool) $row['internal']),
                'failed'    => self::failed((int) $row['status'], (bool) $row['internal']),
                'blocked'   => self::blocked((int) $row['status'], (bool) $row['internal']),
                'hops'      => (int) $row['hops'],
                'placement' => isset(self::PLACEMENTS[(int) $row['placement']]) ? self::PLACEMENTS[(int) $row['placement']] : 'other',
                'anchor'    => isset($text[(int) $row['anchor_id']]) ? (string) $text[(int) $row['anchor_id']] : '',
            );
        }
        return $out;
    }

    /**
     * Clicked rows as the answer gives them.
     *
     * @param array<int,array<string,mixed>> $list Rows.
     * @return array<int,array<string,mixed>>
     */
    private static function clicked_out(array $list) {
        global $wpdb;
        $ids  = array_column($list, 'target_id');
        $from = array();
        foreach ($ids as $id) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, by its target_id key; a few rows.
            $from[(int) $id] = array_map('intval', (array) $wpdb->get_col($wpdb->prepare('SELECT from_path_id FROM %i WHERE target_id = %d LIMIT %d', SEOProAnalytics_Schema::table('crawl_links'), (int) $id, self::PAGES)));
        }
        $all = array_merge($ids, array_column($list, 'redirect_id'));
        foreach ($list as $row) {
            $all = array_merge($all, array_keys($row['pages']), $from[(int) $row['target_id']]);
        }
        $text  = SEOProAnalytics_Dict::values($all);
        $named = static function (array $path_ids) use ($text) {
            $out = array();
            foreach ($path_ids as $id) {
                if (isset($text[(int) $id])) {
                    $out[] = (string) $text[(int) $id];
                }
            }
            return $out;
        };
        $out = array();
        foreach ($list as $row) {
            $top   = array_keys($row['pages']);
            $out[] = array(
                'target'    => isset($text[(int) $row['target_id']]) ? (string) $text[(int) $row['target_id']] : '',
                'status'    => (int) $row['status'],
                'answer'    => self::answer((int) $row['status']),
                'clicks'    => (int) $row['clicks'],
                'hops'      => (int) $row['hops'],
                'redirect'  => $text[(int) $row['redirect_id']] ?? null,
                'outbound'  => (bool) ((int) $row['flags'] & 2),
                'affiliate' => (bool) ((int) $row['flags'] & 4),
                'path_id'   => $top ? (int) $top[0] : 0,
                'pages'     => $named($top),
                'linked_on' => $named($from[(int) $row['target_id']]),
            );
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Reads.

    /**
     * Ids from one column of rows matching a fixed condition, by a key.
     *
     * @param string $table  Table.
     * @param string $column Column (fixed).
     * @param string $key    Key name (fixed).
     * @param string $cond   Condition (fixed SQL).
     * @return int[]
     */
    private static function ids($table, $column, $key, $cond) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table, by the named key; $column, $key and $cond are fixed by the callers.
        return array_map('intval', (array) $wpdb->get_col($wpdb->prepare("SELECT $column FROM %i FORCE INDEX (`$key`) WHERE $cond LIMIT %d", $table, self::MAX_ROWS)));
    }

    /**
     * Pages with served flags, by the flags key.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function flag_rows() {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, by its flags key.
        return (array) $wpdb->get_results($wpdb->prepare('SELECT path_id, flags FROM %i FORCE INDEX (`flags`) WHERE flags > 0 LIMIT %d', SEOProAnalytics_Schema::table('crawl_pages'), self::MAX_ROWS), ARRAY_A);
    }

    /**
     * crawl_pages rows by the primary key.
     *
     * @param int[] $ids Path ids.
     * @return array<int,array<string,mixed>>
     */
    private static function page_rows(array $ids) {
        global $wpdb;
        $out = array();
        foreach (array_chunk($ids, 500) as $chunk) {
            $holders = implode(', ', array_fill(0, count($chunk), '%d'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table by primary key; $holders holds only placeholders.
            foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT * FROM %i WHERE path_id IN ($holders)", array_merge(array(SEOProAnalytics_Schema::table('crawl_pages')), $chunk)), ARRAY_A) as $row) {
                $out[(int) $row['path_id']] = $row;
            }
        }
        return $out;
    }

    /**
     * Links on pages that fail, and internal ones through redirects, by
     * the primary key's from_path_id prefix.
     *
     * @param int[] $ids Path ids.
     * @return array<int,array{broken:int,redirects:int}>
     */
    private static function link_counts(array $ids) {
        global $wpdb;
        $out = array();
        foreach (array_chunk($ids, 500) as $chunk) {
            $holders = implode(', ', array_fill(0, count($chunk), '%d'));
            $error = self::LINK_ERROR_SQL;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table, by the primary key's prefix; $holders holds only placeholders.
            $rows = $wpdb->get_results($wpdb->prepare("SELECT from_path_id, SUM(status = 0 OR ($error)) AS b, SUM(internal = 1 AND (hops > 0 OR (status >= 300 AND status < 400))) AS r FROM %i WHERE from_path_id IN ($holders) GROUP BY from_path_id", array_merge(array(SEOProAnalytics_Schema::table('crawl_links')), $chunk)), ARRAY_A);
            foreach ((array) $rows as $row) {
                $out[(int) $row['from_path_id']] = array('broken' => (int) $row['b'], 'redirects' => (int) $row['r']);
            }
        }
        return $out;
    }

    /**
     * Whether a crawl was imported into this data set (no query without).
     *
     * @return bool
     */
    private static function has_crawl() {
        $state = self::state();
        return SEOProAnalytics_Schema::is_current() && ($state['import'] > 0 || $state['counts']['pages'] > 0 || $state['counts']['links'] > 0);
    }

    // ------------------------------------------------------------------
    // State.

    /**
     * What was imported last, for the current data set.
     *
     * @return array{version:int,import:int,format:string,files:array<int,mixed>,read:int,counts:array<string,mixed>,complete:bool,link_counts:bool}
     */
    public static function state() {
        $state = get_option(SEOProAnalytics_Schema::option(self::OPTION), array());
        $state = is_array($state) ? $state : array();
        // One-time classification upgrade of retained rows, without reimporting or changing statuses.
        if (isset($state['counts']) && is_array($state['counts']) && !isset($state['counts']['blocked_links']) && SEOProAnalytics_Schema::is_current()) {
            self::recount();
            // Do not recurse indefinitely if an option write fails.
            $updated = get_option(SEOProAnalytics_Schema::option(self::OPTION), array());
            $state = is_array($updated) ? $updated : $state;
        }
        $empty = array('pages' => 0, 'ok' => 0, 'redirect' => 0, 'error' => 0, 'none' => 0, 'redirected' => 0, 'noindex' => 0, 'canonical' => 0, 'title_missing' => 0, 'title_differs' => 0, 'desc_differs' => 0, 'desc_missing' => 0, 'links' => 0, 'broken_links' => 0, 'blocked_links' => 0, 'redirect_links' => 0);
        return array(
            'version' => isset($state['version']) ? (int) $state['version'] : 0,
            'import'  => isset($state['import']) ? (int) $state['import'] : 0,
            'format'  => isset($state['format']) ? (string) $state['format'] : '',
            'files'   => isset($state['files']) && is_array($state['files']) ? array_values($state['files']) : array(),
            'read'    => isset($state['read']) ? (int) $state['read'] : 0,
            'counts'  => (isset($state['counts']) && is_array($state['counts']) ? array_map('intval', $state['counts']) : array()) + $empty,
            'complete' => !empty($state['complete']),
            'link_counts' => !empty($state['link_counts']),
        );
    }

    /**
     * Note a write, so cached reports are made again (monotonic, even
     * within one second).
     *
     * @param array<string,mixed> $values Values to keep with it.
     */
    public static function touch(array $values = array()) {
        $option = SEOProAnalytics_Schema::option(self::OPTION);
        $state  = get_option($option, array());
        $state  = is_array($state) ? $state : array();
        $state  = array_merge($state, $values);
        $state['version'] = max(time(), isset($state['version']) ? (int) $state['version'] + 1 : 0);
        update_option($option, $state, false);
        self::$flagged = array();
    }

    /**
     * Count what the crawl found (when an import is written or undone,
     * never in a report), and keep it with values.
     *
     * @param array<string,mixed> $values import, format, files, read.
     */
    public static function recount(array $values = array()) {
        global $wpdb;
        $pages  = SEOProAnalytics_Schema::table('crawl_pages');
        $links  = SEOProAnalytics_Schema::table('crawl_links');
        $counts = array('pages' => 0, 'ok' => 0, 'redirect' => 0, 'error' => 0, 'none' => 0, 'redirected' => 0, 'noindex' => 0, 'canonical' => 0, 'title_missing' => 0, 'title_differs' => 0, 'desc_differs' => 0, 'desc_missing' => 0, 'links' => 0, 'broken_links' => 0, 'blocked_links' => 0, 'redirect_links' => 0);
        // phpcs:disable WordPress.DB.DirectDatabaseQuery -- our own tables, by their status, hops and flags keys; once per import, never in a report.
        foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT status, COUNT(*) AS n FROM %i GROUP BY status', $pages), ARRAY_A) as $row) {
            $counts['pages']                          += (int) $row['n'];
            $counts[self::answer((int) $row['status'])] += (int) $row['n'];
        }
        $counts['redirected'] = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE hops > 0', $pages));
        foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT flags, COUNT(*) AS n FROM %i WHERE flags > 0 GROUP BY flags', $pages), ARRAY_A) as $row) {
            foreach (array('noindex', 'canonical', 'title_missing', 'title_differs', 'desc_differs', 'desc_missing') as $name) {
                $counts[$name] += ((int) $row['flags'] & self::FLAGS[$name]) ? (int) $row['n'] : 0;
            }
        }
        foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT status, internal, COUNT(*) AS n FROM %i GROUP BY status, internal', $links), ARRAY_A) as $row) {
            $counts['links'] += (int) $row['n'];
            if (self::blocked((int) $row['status'], (bool) $row['internal'])) {
                $counts['blocked_links'] += (int) $row['n'];
            } elseif (self::failed((int) $row['status'], (bool) $row['internal'])) {
                $counts['broken_links'] += (int) $row['n'];
            }
        }
        $counts['redirect_links'] = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE internal = 1 AND (hops > 0 OR (status >= 300 AND status < 400))', $links));
        // phpcs:enable
        self::touch(array_merge($values, array('counts' => $counts)));
    }

    /**
     * Exact totals once the latest-state tables are finished, never on a
     * report read. Page findings count pages, link findings count links,
     * before the audit's exclusions, not the report's bounded sample.
     *
     * @param int $duration Import duration in seconds.
     * @return array<string,mixed>
     */
    public static function totals($duration) {
        global $wpdb;
        $counts = self::state()['counts'];
        $statuses = array('2xx' => 0, '3xx' => 0, '4xx' => 0, '5xx' => 0, 'none' => 0, 'other' => 0);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- indexed status totals, once per import only.
        foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT status, COUNT(*) AS n FROM %i GROUP BY status', SEOProAnalytics_Schema::table('crawl_pages')), ARRAY_A) as $row) {
            $class = (int) floor((int) $row['status'] / 100);
            $key = (int) $row['status'] === 0 ? 'none' : ($class >= 2 && $class <= 5 ? $class . 'xx' : 'other');
            $statuses[$key] += (int) $row['n'];
        }
        $findings = array('crawl_error' => $counts['error'] + $counts['none'], 'served_noindex' => $counts['noindex'], 'served_canonical' => $counts['canonical'], 'broken_links' => $counts['broken_links'], 'redirect_links' => $counts['redirect_links'], 'served_title' => $counts['title_differs'], 'served_description' => $counts['desc_differs'] + $counts['desc_missing']);
        // Import-only, ordered covering index: no page timing history is kept.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- response_ms range, once per completed import.
        $n = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE response_ms > 0', SEOProAnalytics_Schema::table('crawl_pages')));
        $timing = array('median' => null, 'p90' => null);
        foreach (array('median' => 0.5, 'p90' => 0.9) as $key => $fraction) {
            if ($n > 0) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- covering response_ms index, nearest-rank percentile at import only.
                $timing[$key] = (int) $wpdb->get_var($wpdb->prepare('SELECT response_ms FROM %i WHERE response_ms > 0 ORDER BY response_ms LIMIT 1 OFFSET %d', SEOProAnalytics_Schema::table('crawl_pages'), max(0, (int) ceil($n * $fraction) - 1)));
            }
        }
        return array('response_ms' => $timing, 'pages' => $counts['pages'], 'links' => $counts['links'], 'statuses' => $statuses, 'findings' => $findings, 'complete' => self::state()['complete'], 'link_counts' => self::state()['link_counts'], 'duration' => max(0, (int) $duration));
    }

    /**
     * Completed import totals in chronological order, read through the
     * source_status index. Old imports without totals are not zero samples.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function trend() {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- source_status plus InnoDB's implicit id, bounded history read.
        $rows = $wpdb->get_results($wpdb->prepare('SELECT id, started, meta FROM %i WHERE source = %s AND status = %d ORDER BY id DESC LIMIT %d', SEOProAnalytics_Schema::table('imports'), 'crawl', SEOProAnalytics_Crawl_Import::DONE, 5000), ARRAY_A);
        $out = array();
        foreach ((array) $rows as $row) {
            $meta = json_decode((string) $row['meta'], true);
            if (is_array($meta) && empty($meta['partial']) && isset($meta['totals']) && is_array($meta['totals'])) {
                $out[] = array('id' => (int) $row['id'], 'started' => gmdate('c', (int) $row['started']), 'totals' => $meta['totals']);
            }
        }
        return array_reverse($out);
    }

    /**
     * Crawl table estimates and allocated bytes, only in admin or WP-CLI.
     * SHOW TABLE STATUS is cached for one day, per site and data set.
     *
     * @return array<string,array{rows:int,data:int,index:int}>
     */
    public static function sizes() {
        global $wpdb;
        if (!is_admin() && !(defined('WP_CLI') && WP_CLI)) {
            return array();
        }
        $key = SEOProAnalytics_Schema::option('seoproanalytics_crawl_sizes');
        $cached = get_transient($key);
        if (is_array($cached)) {
            return $cached;
        }
        $out = array();
        foreach (array('crawl_pages', 'crawl_links') as $name) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- admin/CLI table metadata only; cached for a day.
            $row = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s', $wpdb->esc_like(SEOProAnalytics_Schema::table($name))), ARRAY_A);
            if ($row) {
                $out[$name] = array('rows' => (int) $row['Rows'], 'data' => (int) $row['Data_length'], 'index' => (int) $row['Index_length']);
            }
        }
        set_transient($key, $out, DAY_IN_SECONDS);
        return $out;
    }

    /**
     * Forget what was imported (with the tables' rows, by the import's
     * reset()).
     */
    public static function reset() {
        delete_option(SEOProAnalytics_Schema::option(self::OPTION));
        delete_transient(SEOProAnalytics_Schema::option('seoproanalytics_crawl_sizes'));
        self::$flagged = array();
    }

    /**
     * Load the classes the report uses.
     */
    private static function load() {
        require_once __DIR__ . '/class-seoproanalytics-dict.php';
        require_once __DIR__ . '/class-seoproanalytics-query.php';
        require_once __DIR__ . '/class-seoproanalytics-crawl-import.php';
    }
}
