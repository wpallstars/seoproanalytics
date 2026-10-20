<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 * Provenance: wpas-c8662019b99fb07b. Copies, even of part, keep these lines.
 *
 * The decision queue (Search → Plan): one ranked list of things to do,
 * made from what Opportunities finds, each with why it is listed and how
 * its score is made:
 *
 *     score = potential clicks per 28 days × value × confidence ÷ effort
 *
 * - potential clicks: the opportunity's (striking: clicks it could gain
 *   in the top three; low CTR: clicks missed; decay: clicks lost;
 *   missing: impressions × the site's expected CTR at its position ×
 *   MISSING_SHARE; overlap: the clicks the query's pages would have with
 *   the best of their CTRs, so items where no page does better are left
 *   out; audit: the page's expected clicks at its position × the
 *   finding's share, SEOProAnalytics_Audit::SHARE; links: the page's
 *   expected clicks at its position × LINKS_SHARE, for a missing link the
 *   impressions of the searches on the page that should link), scaled to
 *   28 days; index: a typical shown page's clicks per 28 days ×
 *   INDEX_SHARE (SEOProAnalytics_Indexation);
 * - value: how well visits from search to the page convert against the
 *   site (the Content report's goal), smoothed toward the site's rate
 *   with SMOOTH visits; at least 1 (a page with no goal data is 1) and at
 *   most MAX_VALUE;
 * - confidence: the kind's own × √(impressions per 28 days ÷
 *   FULL_IMPRESSIONS), at most the kind's own (index: the kind's own);
 * - effort: the kind's (an audit finding's), unless a person set another.
 *
 * Audit items are one per page and finding (the key's query is the
 * finding), on the pages with most impressions (SEOProAnalytics_Audit).
 * Links items (SEOProAnalytics_Links) are one per page and list: orphan,
 * converting, or missing (on the page the link should go to; the key's
 * query names the page that should link). Index items
 * (SEOProAnalytics_Indexation) are one per page or sitemap address search
 * has not shown lately (the key's query is the list). A losing page with
 * content facts gets a refresh item (SEOProAnalytics_Refresh: update, leave,
 * protect or merge; the key's query is the proposal) in place of its
 * decay item: potential clicks those lost × the proposal's share; done on
 * leave opens no experiment, as nothing changes. Target items
 * (SEOProAnalytics_Targets) are one per search target and finding: shown with
 * another page than the one meant for it (wrong_page), or a high-priority
 * target in striking distance, in place of its plain striking item (the
 * key's query is the finding and the query). Sitemap items
 * (SEOProAnalytics_Inspections, Google only) are one per Search Console
 * sitemap and problem: errors, warnings, not downloaded lately, or the
 * site's own sitemap not submitted (the key's query is the problem and
 * the sitemap's address); potential clicks a typical shown page's ×
 * SEOProAnalytics_Inspections::SITEMAP_SHARE, and done opens no experiment.
 * Backlinks items (SEOProAnalytics_Backlink_Needs, once backlinks are read)
 * are one per page that needs links (fewer useful referring domains than
 * the median of the site's pages in the top three; the key's query is
 * needs): scored as striking distance (potential clicks the clicks its
 * searches could gain in the top three), measured by position.
 *
 * Items are worked out when the list is read; only those a person or
 * agent acted on (accepted, done, dismissed, or given an effort or note)
 * are stored, by an 8-byte key of kind, engine, page and query. Pages
 * with a running experiment are left out of new items: a second change
 * would spoil its measurement. Done opens an experiment on the item's
 * page with the kind's measure, and the item shows its result. Dismissed
 * items stay hidden for HIDE_DAYS days, then come back if still found.
 *
 * Reads: the Opportunities and Content reports (cached), the running
 * experiments by key status_review, and the queue by its unique ikey and
 * by status_updated. Design: docs/seo-loop.md → Decision queue.
 *
 * @package SEOProAnalytics
 * @since 0.9.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProAnalytics_Queue {

    /** Kinds of item: code (the kind column) => name. Codes never change meaning. */
    const KINDS = array(
        1 => 'ctr',
        2 => 'missing',
        3 => 'striking',
        4 => 'decay',
        5 => 'overlap',
        6 => 'audit',
        7 => 'links',
        8 => 'index',
        9 => 'refresh',
        10 => 'target',
        11 => 'sitemap',
        12 => 'backlinks',
    );

    /** States stored: code => name. 0 (new) is stored only with an effort or note. */
    const STATUSES = array(
        0 => 'new',
        1 => 'accepted',
        2 => 'done',
        3 => 'dismissed',
        4 => 'confirmed',
        5 => 'reopened',
    );

    /** States a list can ask for: open is new and accepted. */
    const FILTERS = array('open', 'new', 'accepted', 'done', 'dismissed', 'all');

    /** Actions on an item. */
    const ACTIONS = array('accept', 'done', 'dismiss', 'restore', 'effort', 'note');

    /** Effort by kind (1 least), and the most a person can set. */
    const EFFORT     = array('ctr' => 1, 'missing' => 2, 'striking' => 2, 'decay' => 3, 'overlap' => 3, 'audit' => 1, 'links' => 1, 'index' => 2, 'refresh' => 3, 'target' => 2, 'sitemap' => 1, 'backlinks' => 3);
    const MAX_EFFORT = 5;

    /** Effort of audit findings, links and indexation lists and sitemap problems other than their kind's. */
    const AUDIT_EFFORT   = array('slow' => 3, 'thin' => 3, 'not_indexed' => 3, 'google_canonical' => 2, 'bing_crawl' => 2, 'crawl_error' => 2, 'served_noindex' => 2, 'served_canonical' => 2, 'broken_links' => 2, 'rich_errors' => 2, 'page_experience' => 2, 'clicked_broken' => 1);

    /** Confidence of imported speed evidence: crawl only, or agreeing field data. */
    const SLOW_CONFIDENCE = array('crawl' => 0.4, 'field' => 0.7);

    /** Confidence of a clicked link that fails (people clicked it; the crawl saw it fail). */
    const CLICKED_CONFIDENCE = 0.7;
    const LINKS_EFFORT   = array('converting' => 2);
    const INDEX_EFFORT   = array('sitemap' => 1);
    const SITEMAP_EFFORT = array('errors' => 2, 'stale' => 2);

    /**
     * The kind's own confidence, before the impressions are weighed;
     * indexation and sitemap items have no impressions, so theirs is not
     * weighed.
     */
    const CONFIDENCE = array('decay' => 0.8, 'ctr' => 0.7, 'striking' => 0.6, 'missing' => 0.5, 'overlap' => 0.4, 'audit' => 0.5, 'links' => 0.4, 'index' => 0.3, 'refresh' => 0.8, 'target' => 0.6, 'sitemap' => 0.4, 'backlinks' => 0.4);

    /** The measure of the experiment done opens, by kind (target: by finding, TARGET_METRIC; sitemap items open none). */
    const METRIC = array('ctr' => 'ctr', 'missing' => 'clicks', 'striking' => 'position', 'decay' => 'clicks', 'overlap' => 'clicks', 'audit' => 'clicks', 'links' => 'clicks', 'index' => 'impressions', 'refresh' => 'clicks', 'target' => 'clicks', 'sitemap' => 'impressions', 'backlinks' => 'position');

    /** Search target findings measured otherwise than the target kind's. */
    const TARGET_METRIC = array('striking' => 'position');

    /**
     * Share of a search's expected clicks at its position put at stake when
     * search shows another page than the one meant for it.
     */
    const TARGET_SHARE = 0.5;

    /** Refresh proposals done without an experiment: nothing on the page changes. */
    const NO_CHANGE = array('leave');

    /** Share of a page's expected clicks links put at stake, by links list. */
    const LINKS_SHARE = array('missing' => 0.2, 'orphans' => 0.1, 'converting' => 0.1);

    /**
     * Share of a typical page's clicks (SEOProAnalytics_Indexation's typical)
     * a page search does not show could earn, by indexation list.
     */
    const INDEX_SHARE = array('pages' => 0.5, 'sitemap' => 0.2);

    /** Audit findings measured otherwise than the audit kind's. */
    const AUDIT_METRIC = array(
        'noindex'               => 'impressions',
        'canonical'             => 'impressions',
        'robots_blocked'        => 'impressions',
        'not_indexed'           => 'impressions',
        'google_canonical'      => 'impressions',
        'bing_crawl'            => 'impressions',
        'crawl_error'           => 'impressions',
        'served_noindex'        => 'impressions',
        'served_canonical'      => 'impressions',
        'served_title'          => 'ctr',
        'served_description'    => 'ctr',
        'title_missing'         => 'ctr',
        'title_duplicate'       => 'ctr',
        'title_long'            => 'ctr',
        'description_missing'   => 'ctr',
        'description_duplicate' => 'ctr',
        'description_long'      => 'ctr',
    );

    /** Potential clicks are given per this many days. */
    const SCALE_DAYS = 28;

    /** Share of the expected clicks a page earns by covering a missing query. */
    const MISSING_SHARE = 0.3;

    /** Visits that smooth a page's conversion rate toward the site's. */
    const SMOOTH = 20;

    /** Most value a page's conversions give. */
    const MAX_VALUE = 5;

    /** Impressions per 28 days that give a kind its whole confidence. */
    const FULL_IMPRESSIONS = 1000;

    /** Days a dismissed item stays hidden. */
    const HIDE_DAYS = 90;

    /** Opportunities read per kind, best first. */
    const PER_KIND = 100;

    /** Pages whose visits and conversions are read (most visits from search first). */
    const VALUE_PAGES = 1000;

    /** Accepted and done items read each that are no longer found, newest first. */
    const KEPT = 200;

    /** Items listed: default and most. */
    const LIMIT     = 50;
    const MAX_LIMIT = 200;

    /** Most bytes of a note. */
    const NOTE_BYTES = 190;

    // ------------------------------------------------------------------
    // Reading.

    /**
     * The ranked list.
     *
     * @param array<string,mixed> $req    From SEOProAnalytics_Query::request() (range, filters, limit, offset).
     * @param string              $engine google or bing.
     * @param string              $status One of FILTERS.
     * @param string              $goal   Goal id for value; '' for the first goal.
     * @param string              $kind   Only items of this kind (one of KINDS); '' for all.
     * @param int|null            $owner  Only items of this owner (SEOProAnalytics_Owners::filter(): 0 for none); null for any.
     * @return array<string,mixed>|WP_Error
     */
    public static function report(array $req, $engine = 'google', $status = 'open', $goal = '', $kind = '', $owner = null) {
        require_once __DIR__ . '/class-seoproanalytics-owners.php';
        $status = $status === '' ? 'open' : (string) $status;
        $kind   = (string) $kind;
        if (!in_array($status, self::FILTERS, true)) {
            /* translators: %s: list of states */
            return self::error('seoproanalytics_queue_status', sprintf(__('The status is one of: %s.', 'seoproanalytics'), implode(', ', self::FILTERS)));
        }
        if ($kind !== '' && !in_array($kind, self::KINDS, true)) {
            /* translators: %s: list of kinds */
            return self::error('seoproanalytics_queue_kind', sprintf(__('The kind is one of: %s.', 'seoproanalytics'), implode(', ', self::KINDS)));
        }
        $built  = self::build($req, $engine, $goal);
        $left   = 0;
        $items  = self::with_states($built['items'], $built['answer']['engine'], $built['running'], $left);
        if ($kind !== '') {
            $items = array_values(array_filter($items, static function ($item) use ($kind) {
                return $item['kind'] === $kind;
            }));
        }
        if ($owner !== null) {
            $items = array_values(array_filter(self::with_owners($items), static function ($item) use ($owner) {
                return SEOProAnalytics_Owners::matches($item['owner'], $owner);
            }));
        }
        $built['answer']['left_out'] = $left;
        $built['answer']['kind']     = $kind === '' ? null : $kind;
        $built['answer']['owner']    = $owner;
        $counts = array_fill_keys(array('new', 'accepted', 'done', 'dismissed'), 0);
        $done_count = 0;
        foreach ($items as $item) {
            ++$counts[$item['status']];
            $done_count += $item['status'] === 'done' || isset($item['verified']) ? 1 : 0;
        }
        $items = array_values(array_filter($items, static function ($item) use ($status) {
            return $status === 'all' || $item['status'] === $status || ($status === 'done' && isset($item['verified'])) || ($status === 'open' && in_array($item['status'], array('new', 'accepted'), true));
        }));
        usort($items, static function ($a, $b) {
            return array($b['score'], $b['parts']['clicks'], $a['key']) <=> array($a['score'], $a['parts']['clicks'], $b['key']);
        });
        $limit  = max(1, min(self::MAX_LIMIT, isset($req['limit']) ? (int) $req['limit'] : self::LIMIT));
        $offset = max(0, isset($req['offset']) ? (int) $req['offset'] : 0);
        $shown  = array_slice($items, $offset, $limit);
        if ($owner === null && SEOProAnalytics_Owners::shown()) {
            $shown = self::with_owners($shown);
        }
        foreach ($shown as &$item) {
            $item = self::finish($item);
        }
        unset($item);

        return $built['answer'] + array(
            'status' => $status,
            'counts' => $counts,
            'done_count' => $done_count,
            'items'  => $shown,
            'total'  => count($items),
            'more'   => $offset + $limit < count($items),
        );
    }

    /**
     * The items found now, scored, with the answer's header; no states.
     *
     * @param array<string,mixed> $req    From SEOProAnalytics_Query::request().
     * @param string              $engine google or bing.
     * @param string              $goal   Goal id; '' for the first goal.
     * @return array{answer:array<string,mixed>,items:array<string,array<string,mixed>>,running:array<int,int>}
     */
    private static function build(array $req, $engine, $goal) {
        require_once __DIR__ . '/class-seoproanalytics-search.php';
        require_once __DIR__ . '/class-seoproanalytics-opportunities.php';
        require_once __DIR__ . '/class-seoproanalytics-content.php';
        require_once __DIR__ . '/class-seoproanalytics-experiments.php';
        require_once __DIR__ . '/class-seoproanalytics-dict.php';
        require_once __DIR__ . '/class-seoproanalytics-clicks.php';
        require_once __DIR__ . '/class-seoproanalytics-audit.php';
        require_once __DIR__ . '/class-seoproanalytics-links.php';
        require_once __DIR__ . '/class-seoproanalytics-indexation.php';
        require_once __DIR__ . '/class-seoproanalytics-refresh.php';
        require_once __DIR__ . '/class-seoproanalytics-targets.php';
        require_once __DIR__ . '/class-seoproanalytics-inspections.php';
        require_once __DIR__ . '/class-seoproanalytics-vitals.php';
        $engine = SEOProAnalytics_Search::engine_name($engine);
        $ask    = array_merge($req, array('limit' => self::PER_KIND, 'offset' => 0));

        $found = array();
        foreach (SEOProAnalytics_Opportunities::KINDS as $kind) {
            $found[$kind] = SEOProAnalytics_Opportunities::report($ask, $kind, $engine);
        }
        $audit   = self::audit_rows($ask, $engine);
        $links   = self::links_rows($ask, $engine, $goal);
        $index   = self::index_rows($ask, $engine);
        $targets = self::target_rows($ask, $engine);
        $needs   = self::backlink_rows($ask, $engine);
        $head    = $found['striking'];
        $days    = (int) $head['days'];
        $curve   = isset($head['curve']['ctr']) && is_array($head['curve']['ctr']) ? $head['curve']['ctr'] : SEOProAnalytics_Opportunities::DEFAULT_CURVE;
        $value   = self::values(array_merge($req, array('limit' => self::VALUE_PAGES, 'offset' => 0, 'compare' => 'none')), $engine, $goal);

        $running = SEOProAnalytics_Experiments::running_pages();
        // Losing pages with content facts get a refresh proposal in place of their decay item.
        $facts   = SEOProAnalytics_Refresh::facts(array_column($found['decay']['rows'], 'path_id'));
        $seasonal = SEOProAnalytics_Opportunities::seasonal($ask, $found['decay']['rows'], $engine);
        foreach ($found['decay']['rows'] as &$row) {
            if (isset($seasonal[(int) $row['path_id']])) {
                $row['year_earlier'] = $seasonal[(int) $row['path_id']];
            }
        }
        unset($row);
        $items   = array();
        self::add_slow($items, $ask, $engine, $goal);
        if ($days > 0) {
            self::add_found($items, $found, $facts, $engine, $days, $curve, $value);
            self::add_audit($items, $audit, $engine, $days, $curve, $value);
            self::add_vitals($items, $ask, $goal, $engine, $days, $value);
            self::add_clicked($items, $ask, $engine, $value);
            self::add_links($items, $links, $engine, $days, $curve, $value);
            self::add_index($items, $index, $engine, $value);
            self::add_targets($items, $targets, $engine, $days, $curve, $value);
            self::add_sitemaps($items, $engine, $index['typical']);
            foreach ($needs['rows'] as $row) {
                self::add($items, self::backlinks_item($engine, $row, $needs['days'], $value));
            }
        }

        return array(
            'answer'  => self::header($engine, $head, $days, $found['decay'], $value),
            'items'   => $items,
            'running' => $running,
        );
    }

    /**
     * Traffic-weighted speed work, even without search impressions.
     *
     * @param array<array<string,mixed>> $items Items, updated.
     * @param array<string,mixed> $ask Request.
     * @param string $engine Engine.
     * @param string $goal Goal.
     */
    private static function add_slow(array &$items, array $ask, $engine, $goal) {
        require_once __DIR__ . '/class-seoproanalytics-crawl.php';
        require_once __DIR__ . '/class-seoproanalytics-crawl-import.php';
        $days = max(1, SEOProAnalytics_Search::length(SEOProAnalytics_Query::range($ask)));
        foreach (array_slice(SEOProAnalytics_Crawl::slow_rows($ask, $engine, $goal), 0, self::PER_KIND) as $row) {
            $parts = array(
                'clicks' => round($row['visits'] * SEOProAnalytics_Audit::SHARE['slow'] * self::SCALE_DAYS / $days, 1),
                'value' => $row['goal'] ? $row['conversions'] / $row['visits'] : 1.0,
                'confidence' => self::SLOW_CONFIDENCE[$row['field_poor'] ? 'field' : 'crawl'],
                'effort' => self::AUDIT_EFFORT['slow'],
            );
            self::add($items, array(
                'key' => self::key('audit', $engine, $row['path'], 'slow'),
                'kind' => 'audit', 'engine' => $engine, 'status' => 'new', 'found' => true,
                'path_id' => $row['path_id'], 'path' => $row['path'], 'url' => $row['url'],
                'post_id' => 0, 'edit_url' => null, 'query' => null, 'finding' => 'slow',
                'why' => SEOProAnalytics_Crawl::phrase('slow', $row),
                'todo' => SEOProAnalytics_Crawl::fix('slow'), 'figures' => $row,
                'metric' => $row['goal'] ? 'conversions' : 'visits',
                'parts' => $parts, 'score' => self::score($parts),
            ));
        }
    }

    /**
     * Audit findings on the pages with most impressions.
     *
     * @param array<string,mixed> $ask    Request, PER_KIND rows.
     * @param string              $engine Engine name.
     * @return array<int,array<string,mixed>>
     */
    private static function audit_rows(array $ask, $engine) {
        $audit = SEOProAnalytics_Audit::report($ask, $engine);
        return is_wp_error($audit) ? array() : $audit['rows'];
    }

    /**
     * Internal links: each list's first rows (one cached report).
     *
     * @param array<string,mixed> $ask    Request, PER_KIND rows.
     * @param string              $engine Engine name.
     * @param string              $goal   Goal id.
     * @return array<string,array<int,array<string,mixed>>> List => rows.
     */
    private static function links_rows(array $ask, $engine, $goal) {
        $links = array();
        foreach (SEOProAnalytics_Links::KINDS as $list) {
            $answer       = SEOProAnalytics_Links::report($ask, $engine, $list, $goal);
            $links[$list] = is_wp_error($answer) ? array() : $answer['rows'];
        }
        return $links;
    }

    /**
     * Indexation: each list's first rows (one cached report), with what a
     * page shown in search earns here and the newest search day.
     *
     * @param array<string,mixed> $ask    Request, PER_KIND rows.
     * @param string              $engine Engine name.
     * @return array{lists:array<string,array<int,array<string,mixed>>>,typical:float,through:string}
     */
    private static function index_rows(array $ask, $engine) {
        $index = array('lists' => array(), 'typical' => 0.0, 'through' => '');
        foreach (SEOProAnalytics_Indexation::KINDS as $list) {
            $answer = SEOProAnalytics_Indexation::report($ask, $engine, $list);
            if (!is_wp_error($answer)) {
                $index['lists'][$list] = $answer['rows'];
                $index['typical']      = (float) $answer['typical'];
                $index['through']      = (string) $answer['through'];
            }
        }
        return $index;
    }

    /**
     * Search targets in an open status (one cached report, every target).
     *
     * @param array<string,mixed> $ask    Request, PER_KIND rows.
     * @param string              $engine Engine name.
     * @return array<int,array<string,mixed>>
     */
    private static function target_rows(array $ask, $engine) {
        $targets = SEOProAnalytics_Targets::report(array_merge($ask, array('limit' => SEOProAnalytics_Targets::MAX_TARGETS, 'compare' => 'none')), $engine, 'open');
        return is_wp_error($targets) ? array() : $targets['rows'];
    }

    /**
     * Pages that need links (Backlinks → Needs links), only once backlinks
     * have been read (a referring page checked, or a live link known):
     * without any, nothing is learnt from links. A check that found no
     * live links still counts: pages with none can need them.
     *
     * @param array<string,mixed> $ask    Request, PER_KIND rows.
     * @param string              $engine Engine name.
     * @return array{rows:array<int,array<string,mixed>>,days:int}
     */
    private static function backlink_rows(array $ask, $engine) {
        require_once __DIR__ . '/class-seoproanalytics-backlinks.php';
        $answer = SEOProAnalytics_Backlinks::report(array_merge($ask, array('engine' => $engine, 'source' => '', 'triage' => array())), 'needs');
        if (is_wp_error($answer) || (empty($answer['read']['checked']) && empty($answer['totals']['links'])) || empty($answer['search']['days'])) {
            return array('rows' => array(), 'days' => 0);
        }
        return array('rows' => (array) $answer['rows'], 'days' => (int) $answer['search']['days']);
    }

    /**
     * Add an item unless one of its key is there already (the first stays).
     * Keys are item keys; PHP makes an all-digit one an integer key.
     *
     * @param array<array<string,mixed>> $items Items by key, added to.
     * @param array<string,mixed>|null   $item  Item, or null for none.
     */
    private static function add(array &$items, $item) {
        if ($item && !isset($items[$item['key']])) {
            $items[$item['key']] = $item;
        }
    }

    /**
     * Add the Opportunities rows' items: a losing page with content facts
     * gets its refresh proposal in place of its decay item.
     *
     * @param array<array<string,mixed>>         $items  Items by key, added to.
     * @param array<string,array<string,mixed>> $found  Opportunities answers by kind.
     * @param array<int,array{post_id:int,modified:int,published:int,words:int,links_in:int}> $facts Content facts by path id (SEOProAnalytics_Refresh::facts()).
     * @param string                            $engine Engine name.
     * @param int                               $days   Days of the period.
     * @param array<int,float>                  $curve  Expected CTR by position.
     * @param array<string,mixed>               $value  From values().
     */
    private static function add_found(array &$items, array $found, array $facts, $engine, $days, array $curve, array $value) {
        foreach ($found as $kind => $answer) {
            // Losing clicks may read fewer days than the rest (SEOProAnalytics_Opportunities::decay_answer()).
            $read = $answer['days'] > 0 ? (int) $answer['days'] : $days;
            foreach ($answer['rows'] as $row) {
                if ($kind === 'overlap' && (int) $row['potential'] < 1) {
                    // No page's CTR is better than the others': nothing to win by choosing one.
                    continue;
                }
                self::add($items, $kind === 'decay' && isset($facts[(int) $row['path_id']])
                    ? self::refresh_item($engine, $row, $facts[(int) $row['path_id']], $read, $value)
                    : self::item($kind, $engine, $row, $read, $curve, $value));
            }
        }
    }

    /**
     * Add an item for each audit finding.
     *
     * @param array<array<string,mixed>>        $items  Items by key, added to.
     * @param array<int,array<string,mixed>>    $audit  Audit rows.
     * @param string                            $engine Engine name.
     * @param int                               $days   Days of the period.
     * @param array<int,float>                  $curve  Expected CTR by position.
     * @param array<string,mixed>               $value  From values().
     */
    private static function add_audit(array &$items, array $audit, $engine, $days, array $curve, array $value) {
        foreach ($audit as $row) {
            foreach ((array) $row['findings'] as $finding) {
                self::add($items, self::audit_item($engine, $row, (string) $finding, $days, $curve, $value));
            }
        }
    }

    /**
     * Add a page experience item for each page with search clicks whose
     * Chrome UX Report field data fails Core Web Vitals.
     *
     * @param array<array<string,mixed>> $items  Items by key, added to.
     * @param array<string,mixed>        $ask    Request, PER_KIND rows.
     * @param string                     $goal   Goal id.
     * @param string                     $engine Engine name.
     * @param int                        $days   Days of the period.
     * @param array<string,mixed>        $value  From values().
     */
    private static function add_vitals(array &$items, array $ask, $goal, $engine, $days, array $value) {
        $failing = array();
        foreach (SEOProAnalytics_Vitals::report()['rows'] as $row) {
            if ($row['failing']) {
                $failing[(int) $row['path_id']] = $row['samples'];
            }
        }
        if (!$failing) {
            return;
        }
        foreach (SEOProAnalytics_Content::report($ask, 'clicks', $goal, $engine)['rows'] as $row) {
            if ((int) $row['clicks'] < 1 || !isset($failing[(int) $row['path_id']])) {
                continue;
            }
            $parts = array('clicks' => max(1.0, round((int) $row['clicks'] * 28 / max(1, $days) * 0.1, 1)), 'value' => round(self::worth((int) $row['path_id'], $value), 2), 'confidence' => 0.5, 'effort' => 2);
            $key   = self::key('audit', $engine, (string) $row['path'], 'page_experience');
            self::add($items, array(
                'key' => $key, 'kind' => 'audit', 'engine' => $engine, 'status' => 'new', 'found' => true,
                'path_id' => (int) $row['path_id'], 'path' => (string) $row['path'], 'url' => (string) $row['url'],
                'post_id' => (int) $row['post_id'], 'edit_url' => $row['edit_url'] ?? null, 'query' => null,
                'finding' => 'page_experience', 'metric' => 'clicks', 'parts' => $parts, 'score' => self::score($parts),
                'why' => __('CrUX reports a poor p75 for LCP, INP or CLS on a page receiving search clicks. The score is a prioritisation estimate, not a predicted ranking gain.', 'seoproanalytics'),
                'todo' => __('Review the field metrics and run an optional Lighthouse lab test to investigate the cause. Record a measured experiment before changing the page.', 'seoproanalytics'),
                'figures' => array('finding' => 'page_experience', 'clicks' => (int) $row['clicks'], 'impressions' => (int) $row['impressions'], 'position' => $row['position'], 'samples' => $failing[(int) $row['path_id']]),
            ));
        }
    }

    /**
     * Add an audit item (finding clicked_broken) for each outbound or
     * affiliate destination people clicked in the period whose imported
     * crawl answer failed or redirected: referrals and commissions at stake. Potential
     * clicks: its clicks per 28 days; value: the page it was clicked on
     * most; the key's query names the finding and the destination. Done
     * opens no experiment (no_change()): search clicks do not measure it.
     *
     * @param array<array<string,mixed>> $items  Items by key, added to.
     * @param array<string,mixed>        $ask    Request, PER_KIND rows.
     * @param string                     $engine Engine name.
     * @param array<string,mixed>        $value  From values().
     */
    private static function add_clicked(array &$items, array $ask, $engine, array $value) {
        require_once __DIR__ . '/class-seoproanalytics-crawl.php';
        require_once __DIR__ . '/class-seoproanalytics-crawl-import.php';
        $answer = SEOProAnalytics_Crawl::report($ask, 'clicked');
        if (is_wp_error($answer) || !$answer['rows'] || !$answer['range']) {
            return;
        }
        $range = SEOProAnalytics_Query::range($ask);
        $days  = max(1, (int) round(($range['to'] - $range['from']) / DAY_IN_SECONDS));
        foreach ($answer['rows'] as $row) {
            $path = isset($row['pages'][0]) ? (string) $row['pages'][0] : '';
            if ($path === '' || (int) $row['clicks'] < 1) {
                continue;
            }
            $parts = array(
                'clicks'     => max(1.0, round((int) $row['clicks'] * self::SCALE_DAYS / $days, 1)),
                'value'      => round(self::worth((int) $row['path_id'], $value), 2),
                'confidence' => self::CLICKED_CONFIDENCE,
                'effort'     => self::effort_of('audit', 'clicked_broken'),
            );
            $status = (int) $row['status'];
            /* translators: 1: number of clicks, 2: the destination, 3: its HTTP status, or "no answer" */
            $why = sprintf(_n('%1$s click in the period went to %2$s, which answered %3$s to the imported crawl.', '%1$s clicks in the period went to %2$s, which answered %3$s to the imported crawl.', (int) $row['clicks'], 'seoproanalytics'), number_format_i18n((int) $row['clicks']), (string) $row['target'], $status ? 'HTTP ' . $status : __('nothing', 'seoproanalytics'));
            if ((int) $row['hops'] > 0) {
                /* translators: 1: redirect count, 2: final address or unknown. */
                $why .= ' ' . sprintf(__('It followed %1$d redirects; final address: %2$s. Redirects cost time and may drop affiliate tracking.', 'seoproanalytics'), (int) $row['hops'], $row['redirect'] ?? __('not exported', 'seoproanalytics'));
            }
            self::add($items, array(
                'key' => self::key('audit', $engine, $path, 'clicked_broken ' . $row['target']), 'kind' => 'audit', 'engine' => $engine, 'status' => 'new', 'found' => true,
                'path_id' => (int) $row['path_id'], 'path' => $path, 'url' => home_url($path),
                'post_id' => 0, 'edit_url' => null, 'query' => null,
                'finding' => 'clicked_broken', 'metric' => 'clicks', 'parts' => $parts, 'score' => self::score($parts),
                'why' => $why . ' ' . __('The score weighs those clicks by what the page converts; it prioritises referrals at stake, not measured losses or search clicks.', 'seoproanalytics'),
                'todo' => (int) $row['hops'] > 0 && !SEOProAnalytics_Crawl::failed($status) ? __('Check whether the redirect chain can be shortened. Keep any redirects the affiliate programme requires for tracking; do not bypass them just to remove hops.', 'seoproanalytics') : SEOProAnalytics_Crawl::fix('clicked_broken'),
                'figures' => array('finding' => 'clicked_broken', 'target' => (string) $row['target'], 'status' => $status, 'clicks' => (int) $row['clicks'], 'impressions' => 0, 'position' => null, 'affiliate' => (bool) $row['affiliate'], 'outbound' => (bool) $row['outbound'], 'clicked_on' => $row['pages'], 'linked_on' => $row['linked_on']),
            ));
        }
    }

    /**
     * Add an item for each internal links row.
     *
     * @param array<array<string,mixed>>                   $items  Items by key, added to.
     * @param array<string,array<int,array<string,mixed>>> $links  List => rows.
     * @param string                                       $engine Engine name.
     * @param int                                          $days   Days of the period.
     * @param array<int,float>                             $curve  Expected CTR by position.
     * @param array<string,mixed>                          $value  From values().
     */
    private static function add_links(array &$items, array $links, $engine, $days, array $curve, array $value) {
        foreach ($links as $list => $rows) {
            foreach ($rows as $row) {
                self::add($items, self::links_item($engine, $row, (string) $list, $days, $curve, $value));
            }
        }
    }

    /**
     * Add an item for each indexation row.
     *
     * @param array<array<string,mixed>>        $items  Items by key, added to.
     * @param array<string,mixed>               $index  From index_rows().
     * @param string                            $engine Engine name.
     * @param array<string,mixed>               $value  From values().
     */
    private static function add_index(array &$items, array $index, $engine, array $value) {
        foreach ($index['lists'] as $list => $rows) {
            foreach ($rows as $row) {
                self::add($items, self::index_item($engine, $row, (string) $list, $index['typical'], $index['through'], $value));
            }
        }
    }

    /**
     * Add Search Console's sitemap problems (Google only).
     *
     * @param array<array<string,mixed>>        $items   Items by key, added to.
     * @param string                            $engine  Engine name.
     * @param float                             $typical What a page shown in search earns here.
     */
    private static function add_sitemaps(array &$items, $engine, $typical) {
        if ($engine !== 'google') {
            return;
        }
        foreach (SEOProAnalytics_Inspections::sitemap_problems() as $problem) {
            self::add($items, self::sitemap_item($engine, $problem, $typical));
        }
    }

    /**
     * Add the search targets' items: a target in striking distance takes
     * the place of its plain striking item.
     *
     * @param array<array<string,mixed>>        $items   Items by key, added to.
     * @param array<int,array<string,mixed>>    $targets Target rows.
     * @param string                            $engine  Engine name.
     * @param int                               $days    Days of the period.
     * @param array<int,float>                  $curve   Expected CTR by position.
     * @param array<string,mixed>               $value   From values().
     */
    private static function add_targets(array &$items, array $targets, $engine, $days, array $curve, array $value) {
        foreach ($targets as $row) {
            $item = self::target_item($engine, $row, $days, $curve, $value);
            if (!$item || isset($items[$item['key']])) {
                continue;
            }
            unset($items[self::key('striking', $engine, (string) $item['path'], (string) $item['query'])]);
            $items[$item['key']] = $item;
        }
    }

    /**
     * The answer's header: the period, goal and rules.
     *
     * @param string              $engine Engine name.
     * @param array<string,mixed> $head   The striking Opportunities answer.
     * @param int                 $days   Days of the period.
     * @param array<string,mixed> $decay  The decay Opportunities answer.
     * @param array<string,mixed> $value  From values().
     * @return array<string,mixed>
     */
    private static function header($engine, array $head, $days, array $decay, array $value) {
        return array(
            'engine'    => $engine,
            'engines'   => $head['engines'],
            'range'     => $head['range'],
            'days'      => $days,
            'cut'       => (bool) $head['cut'],
            'decay'     => self::decay_period($decay),
            'through'   => $head['through'],
            'connected' => (bool) $head['connected'],
            'ignored'   => $head['ignored'],
            'goal'      => $value['goal'],
            'goals'     => $value['goals'],
            'site_rate' => $value['site'],
            'rules'     => array(
                'scale_days'       => self::SCALE_DAYS,
                'effort'           => self::EFFORT,
                'audit_effort'     => self::AUDIT_EFFORT,
                'links_effort'     => self::LINKS_EFFORT,
                'links_share'      => self::LINKS_SHARE,
                'index_effort'     => self::INDEX_EFFORT,
                'index_share'      => self::INDEX_SHARE,
                'sitemap_effort'   => self::SITEMAP_EFFORT,
                'sitemap_share'    => SEOProAnalytics_Inspections::SITEMAP_SHARE,
                'refresh_effort'   => SEOProAnalytics_Refresh::EFFORT,
                'refresh_share'    => SEOProAnalytics_Refresh::SHARE,
                'refresh'          => array(
                    'old_days'            => SEOProAnalytics_Refresh::OLD_DAYS,
                    'protect_value'       => SEOProAnalytics_Refresh::PROTECT_VALUE,
                    'protect_conversions' => SEOProAnalytics_Refresh::PROTECT_CONVERSIONS,
                ),
                'target'           => array(
                    'share'         => self::TARGET_SHARE,
                    'priority'      => SEOProAnalytics_Targets::PRIORITY,
                    'high_priority' => SEOProAnalytics_Targets::HIGH_PRIORITY,
                    'statuses'      => SEOProAnalytics_Targets::OPEN,
                ),
                'confidence'       => self::CONFIDENCE,
                'full_impressions' => self::FULL_IMPRESSIONS,
                'missing_share'    => self::MISSING_SHARE,
                'smooth_visits'    => self::SMOOTH,
                'max_value'        => self::MAX_VALUE,
                'hide_days'        => self::HIDE_DAYS,
                'per_kind'         => self::PER_KIND,
            ),
            'left_out'  => 0,
        );
    }

    /**
     * The periods losing clicks were compared in when they were shortened
     * so the earlier one holds only days with search data; null when they
     * were not (losing clicks read the queue's period).
     *
     * @param array<string,mixed> $decay The Opportunities answer of kind decay.
     * @return array{range:array<string,string>,days:int,compare:array<string,string>}|null
     */
    private static function decay_period(array $decay) {
        if (empty($decay['compare']['cut'])) {
            return null;
        }
        return array(
            'range'   => $decay['range'],
            'days'    => (int) $decay['days'],
            'compare' => $decay['compare']['range'],
        );
    }

    /**
     * Value by page: visits from search and conversions of the goal, from
     * the Content report (most visits first, VALUE_PAGES pages).
     *
     * @param array<string,mixed> $req    Request.
     * @param string              $engine Engine name.
     * @param string              $goal   Goal id; '' for the first goal.
     * @return array{goal:array{id:string,name:string}|null,goals:array<int,array{id:string,name:string}>,site:float|null,pages:array<int,array{visits:int,conversions:int}>}
     */
    private static function values(array $req, $engine, $goal) {
        $content = SEOProAnalytics_Content::report($req, 'visits', $goal, $engine);
        $out     = array('goal' => null, 'goals' => array(), 'site' => null, 'pages' => array());
        foreach (isset($content['goals']) ? (array) $content['goals'] : array() as $one) {
            $out['goals'][] = array('id' => (string) $one['id'], 'name' => (string) $one['name']);
        }
        if (empty($content['goal'])) {
            return $out;
        }
        $out['goal'] = array('id' => (string) $content['goal']['id'], 'name' => (string) $content['goal']['name']);
        $visits      = (int) $content['totals']['visits'];
        $out['site'] = $visits ? round((int) $content['totals']['conversions'] / $visits, 4) : null;
        foreach ($content['rows'] as $row) {
            $out['pages'][(int) $row['path_id']] = array('visits' => (int) $row['visits'], 'conversions' => (int) $row['conversions']);
        }
        return $out;
    }

    /**
     * One item from an opportunity row: its key, page, query, figures, why
     * and score parts.
     *
     * @param string              $kind   Kind name.
     * @param string              $engine Engine name.
     * @param array<string,mixed> $row    Opportunity row.
     * @param int                 $days   Days of the period.
     * @param array<int,float>    $curve  Expected CTR by position.
     * @param array<string,mixed> $value  From values().
     * @return array<string,mixed>
     */
    private static function item($kind, $engine, array $row, $days, array $curve, array $value) {
        $scale = self::SCALE_DAYS / max(1, (int) $days);
        $query = isset($row['query']) ? (string) $row['query'] : '';
        $impr  = (int) $row['impressions'];
        if ($kind === 'decay') {
            $impr    = max($impr, (int) $row['compare']['impressions']);
            $clicks  = (int) $row['lost'];
            $figures = array(
                'clicks'        => (int) $row['clicks'],
                'then_clicks'   => (int) $row['compare']['clicks'],
                'lost'          => (int) $row['lost'],
                'impressions'   => (int) $row['impressions'],
                'position'      => $row['impressions'] ? $row['position'] : null,
                'then_position' => $row['compare']['position'],
                'cause'         => (string) $row['cause'],
            );
        } else {
            $figures = array(
                'clicks'      => (int) $row['clicks'],
                'impressions' => (int) $row['impressions'],
                'ctr'         => $row['ctr'],
                'position'    => $row['position'],
            );
            if ($kind === 'overlap') {
                $figures['potential'] = (int) $row['potential'];
                $figures['switched']  = (bool) $row['switched'];
                $figures['pages']     = array_map(static function ($page) {
                    return array(
                        'path_id'     => (int) $page['path_id'],
                        'path'        => (string) $page['path'],
                        'clicks'      => (int) $page['clicks'],
                        'impressions' => (int) $page['impressions'],
                        'position'    => $page['position'],
                        'share'       => $page['share'],
                    );
                }, (array) $row['pages']);
                $clicks = (int) $row['potential'];
            } elseif ($kind === 'missing') {
                $place                   = max(1, min(20, (int) round((float) $row['position'])));
                $figures['expected_ctr'] = round((float) $curve[$place], 4);
                $figures['match']        = (string) $row['match'];
                $figures['missing']      = array_values((array) $row['missing']);
                $figures['question']     = (bool) $row['question'];
                $clicks                  = $impr * (float) $curve[$place] * self::MISSING_SHARE;
            } else {
                $figures['expected_ctr'] = $row['expected_ctr'];
                $figures['potential']    = (int) $row['potential'];
                $clicks                  = (int) $row['potential'];
            }
        }
        $parts = array(
            'clicks'     => round($clicks * $scale, 1),
            'value'      => round(self::worth((int) $row['path_id'], $value), 2),
            'confidence' => round(self::CONFIDENCE[$kind] * min(1.0, sqrt($impr * $scale / self::FULL_IMPRESSIONS)), 2),
            'effort'     => self::EFFORT[$kind],
        );
        return array(
            'key'      => self::key($kind, $engine, (string) $row['path'], $query),
            'kind'     => $kind,
            'engine'   => $engine,
            'status'   => 'new',
            'found'    => true,
            'path_id'  => (int) $row['path_id'],
            'path'     => (string) $row['path'],
            'url'      => (string) $row['url'],
            'post_id'  => (int) $row['post_id'],
            'edit_url' => isset($row['edit_url']) ? $row['edit_url'] : null,
            'query'    => $kind === 'decay' ? null : $query,
            'finding'  => null,
            'why'      => self::why($kind, $row, $figures),
            'todo'     => self::todo($kind),
            'figures'  => $figures,
            'metric'   => self::METRIC[$kind],
            'parts'    => $parts,
            'score'    => self::score($parts),
        );
    }

    /**
     * One item from an audit finding on a page: the page's expected
     * clicks at its position (the site's curve) × the finding's share
     * (SEOProAnalytics_Audit::SHARE). Null without potential clicks.
     *
     * @param string              $engine  Engine name.
     * @param array<string,mixed> $row     Audit row.
     * @param string              $finding Finding.
     * @param int                 $days    Days of the period.
     * @param array<int,float>    $curve   Expected CTR by position.
     * @param array<string,mixed> $value   From values().
     * @return array<string,mixed>|null
     */
    private static function audit_item($engine, array $row, $finding, $days, array $curve, array $value) {
        if ($finding === 'slow') {
            // Traffic-based items are built independently of search impressions.
            return null;
        }
        $impr = (int) $row['impressions'];
        if ($impr < 1 || $row['position'] === null) {
            return null;
        }
        $scale    = self::SCALE_DAYS / max(1, (int) $days);
        $place    = max(1, min(20, (int) round((float) $row['position'])));
        $expected = $impr * (float) $curve[$place];
        $clicks   = $expected * SEOProAnalytics_Audit::share(array($finding));
        if ($clicks * $scale < 1) {
            return null;
        }
        // The other pages with the same title or description.
        $same = array();
        if ($finding === 'title_duplicate') {
            $same = array_values((array) $row['same_title']);
        } elseif ($finding === 'description_duplicate') {
            $same = array_values((array) $row['same_description']);
        }
        $figures = array(
            'finding'      => $finding,
            'clicks'       => (int) $row['clicks'],
            'impressions'  => $impr,
            'ctr'          => $row['ctr'],
            'position'     => $row['position'],
            'expected_ctr' => round((float) $curve[$place], 4),
            'share'        => SEOProAnalytics_Audit::share(array($finding)),
            'facts'        => (array) $row['facts'],
            'same'         => $same,
        );
        $parts = array(
            'clicks'     => round($clicks * $scale, 1),
            'value'      => round(self::worth((int) $row['path_id'], $value), 2),
            'confidence' => round(self::CONFIDENCE['audit'] * min(1.0, sqrt($impr * $scale / self::FULL_IMPRESSIONS)), 2),
            'effort'     => self::effort_of('audit', $finding),
        );
        /* translators: 1: what the content audit found, 2: impressions, 3: average position */
        $why = sprintf(__('The content audit found %1$s on a page with %2$s impressions at position %3$s.', 'seoproanalytics'), SEOProAnalytics_Audit::phrase($finding, $row), number_format_i18n($impr), number_format_i18n((float) $row['position'], 1));
        return array(
            'key'      => self::key('audit', $engine, (string) $row['path'], $finding),
            'kind'     => 'audit',
            'engine'   => $engine,
            'status'   => 'new',
            'found'    => true,
            'path_id'  => (int) $row['path_id'],
            'path'     => (string) $row['path'],
            'url'      => (string) $row['url'],
            'post_id'  => (int) $row['post_id'],
            'edit_url' => isset($row['edit_url']) ? $row['edit_url'] : null,
            'query'    => null,
            'finding'  => $finding,
            'why'      => $why,
            'todo'     => SEOProAnalytics_Audit::todo(array($finding)),
            'figures'  => $figures,
            'metric'   => isset(self::AUDIT_METRIC[$finding]) ? self::AUDIT_METRIC[$finding] : self::METRIC['audit'],
            'parts'    => $parts,
            'score'    => self::score($parts),
        );
    }

    /**
     * One item from an internal links row (SEOProAnalytics_Links): an orphan
     * page, a converting page with few links in, or a missing link (on the
     * page it should go to). Potential clicks: the page's impressions (a
     * missing link: those of the searches on the page that should link) ×
     * the site's expected CTR at the page's position × LINKS_SHARE. Null
     * without potential clicks.
     *
     * @param string              $engine Engine name.
     * @param array<string,mixed> $row    Links row.
     * @param string              $list   orphans, converting or missing.
     * @param int                 $days   Days of the period.
     * @param array<int,float>    $curve  Expected CTR by position.
     * @param array<string,mixed> $value  From values().
     * @return array<string,mixed>|null
     */
    private static function links_item($engine, array $row, $list, $days, array $curve, array $value) {
        $missing = $list === 'missing';
        $page    = $missing ? (array) $row['to'] : $row;
        $impr    = (int) $row['impressions'];
        if ($impr < 1 || (int) $page['impressions'] < 1 || !isset(self::LINKS_SHARE[$list])) {
            return null;
        }
        $scale  = self::SCALE_DAYS / max(1, (int) $days);
        $place  = max(1, min(20, (int) round((float) $page['position'])));
        $clicks = $impr * (float) $curve[$place] * self::LINKS_SHARE[$list];
        if ($clicks * $scale < 1) {
            return null;
        }
        $figures = array(
            'list'         => $list,
            'clicks'       => (int) $page['clicks'],
            'impressions'  => (int) $page['impressions'],
            'ctr'          => $page['ctr'],
            'position'     => $page['position'],
            'expected_ctr' => round((float) $curve[$place], 4),
            'share'        => self::LINKS_SHARE[$list],
        );
        $num = static function ($n) {
            return number_format_i18n((int) $n);
        };
        if ($missing) {
            $searches = array_map(static function ($query) {
                return '“' . (string) $query['query'] . '”';
            }, (array) $row['queries']);
            $figures += array(
                'link_from'        => array('path_id' => (int) $row['path_id'], 'path' => (string) $row['path'], 'url' => (string) $row['url']),
                'from_impressions' => $impr,
                'from_position'    => $row['position'],
                'queries'          => array_values((array) $row['queries']),
                'query_count'      => (int) $row['query_count'],
            );
            /* translators: 1: page path that should link, 2: impressions, 3: searches, 4: page path it should link to */
            $why  = sprintf(__('%1$s shows for searches this page gets most clicks for (%2$s impressions: %3$s), but does not link to %4$s.', 'seoproanalytics'), (string) $row['path'], $num($impr), implode(', ', $searches), (string) $page['path']);
            /* translators: 1: page path it should link to, 2: page path that should link */
            $todo = sprintf(__('Link to %1$s from %2$s, in the words of the searches.', 'seoproanalytics'), (string) $page['path'], (string) $row['path']);
        } else {
            $figures += array(
                'links_in'    => (int) $row['links_in'],
                'from'        => array_values((array) $row['from']),
                'visits'      => (int) $row['visits'],
                'conversions' => $row['conversions'],
            );
            if ($list === 'orphans') {
                /* translators: 1: impressions, 2: average position */
                $why  = sprintf(__('No other page links to it, though it has %1$s impressions at position %2$s.', 'seoproanalytics'), $num($impr), number_format_i18n((float) $page['position'], 1));
                $todo = __('Link to it from related pages, in the words of its searches.', 'seoproanalytics');
            } else {
                $links = (int) $row['links_in'];
                /* translators: 1: conversions, 2: number of pages linking to it */
                $why  = sprintf(_n('Its visits from search reached the goal %1$s times, but only %2$s page links to it.', 'Its visits from search reached the goal %1$s times, but only %2$s pages link to it.', $links, 'seoproanalytics'), $num($row['conversions']), $num($links));
                $todo = __('Link to it from more related pages.', 'seoproanalytics');
            }
        }
        $parts = array(
            'clicks'     => round($clicks * $scale, 1),
            'value'      => round(self::worth((int) $page['path_id'], $value), 2),
            'confidence' => round(self::CONFIDENCE['links'] * min(1.0, sqrt($impr * $scale / self::FULL_IMPRESSIONS)), 2),
            'effort'     => self::effort_of('links', $list),
        );
        return array(
            'key'      => self::key('links', $engine, (string) $page['path'], $missing ? 'missing ' . (string) $row['path'] : $list),
            'kind'     => 'links',
            'engine'   => $engine,
            'status'   => 'new',
            'found'    => true,
            'path_id'  => (int) $page['path_id'],
            'path'     => (string) $page['path'],
            'url'      => (string) $page['url'],
            'post_id'  => (int) $page['post_id'],
            'edit_url' => isset($page['edit_url']) ? $page['edit_url'] : null,
            'query'    => null,
            'finding'  => $list,
            'why'      => $why,
            'todo'     => $todo,
            'figures'  => $figures,
            'metric'   => self::METRIC['links'],
            'parts'    => $parts,
            'score'    => self::score($parts),
        );
    }

    /**
     * One item from an indexation row (SEOProAnalytics_Indexation): a page or
     * sitemap address search has not shown lately. Potential clicks: what
     * a page shown in search earns here per 28 days (the report's typical)
     * × INDEX_SHARE; confidence is the kind's own, as there are no
     * impressions to weigh. Null without potential clicks.
     *
     * @param string              $engine  Engine name.
     * @param array<string,mixed> $row     Indexation row.
     * @param string              $list    pages or sitemap.
     * @param float               $typical A shown page's clicks per 28 days.
     * @param string              $through The newest search day.
     * @param array<string,mixed> $value   From values().
     * @return array<string,mixed>|null
     */
    private static function index_item($engine, array $row, $list, $typical, $through, array $value) {
        if (!isset(self::INDEX_SHARE[$list])) {
            return null;
        }
        $clicks = (float) $typical * self::INDEX_SHARE[$list];
        if ($clicks < 1) {
            return null;
        }
        $figures = array(
            'list'            => $list,
            'state'           => (string) $row['state'],
            'last_impression' => $row['last_impression'],
            'age'             => (int) $row['age'],
            'typical'         => (float) $typical,
            'share'           => self::INDEX_SHARE[$list],
        );
        foreach (array('published', 'words', 'links_in', 'first_seen', 'source') as $field) {
            if (array_key_exists($field, $row)) {
                $figures[$field] = $row[$field];
            }
        }
        $parts = array(
            'clicks'     => round($clicks, 1),
            'value'      => round(self::worth((int) $row['path_id'], $value), 2),
            'confidence' => self::CONFIDENCE['index'],
            'effort'     => self::effort_of('index', $list),
        );
        return array(
            'key'      => self::key('index', $engine, (string) $row['path'], $list),
            'kind'     => 'index',
            'engine'   => $engine,
            'status'   => 'new',
            'found'    => true,
            'path_id'  => (int) $row['path_id'],
            'path'     => (string) $row['path'],
            'url'      => (string) $row['url'],
            'post_id'  => (int) $row['post_id'],
            'edit_url' => isset($row['edit_url']) ? $row['edit_url'] : null,
            'query'    => null,
            'finding'  => $list,
            'why'      => SEOProAnalytics_Indexation::why($list, $row, $through),
            'todo'     => SEOProAnalytics_Indexation::todo($list),
            'figures'  => $figures,
            'metric'   => self::METRIC['index'],
            'parts'    => $parts,
            'score'    => self::score($parts),
        );
    }

    /**
     * One item from a Needs links row (SEOProAnalytics_Backlink_Needs): a page
     * whose searches sit in striking distance with fewer useful referring
     * domains than the median of the site's pages in the top three. Scored
     * as striking distance: potential clicks the clicks its searches could
     * gain in the top three, scaled to 28 days; confidence the kind's own
     * weighed by impressions. An estimate to rank work by: links are one
     * of many things that move a page. Null without potential clicks.
     *
     * @param string              $engine Engine name.
     * @param array<string,mixed> $row    Needs links row (shown()).
     * @param int                 $days   Days the row's search data covers.
     * @param array<string,mixed> $value  From values().
     * @return array<string,mixed>|null
     */
    private static function backlinks_item($engine, array $row, $days, array $value) {
        $scale  = self::SCALE_DAYS / max(1, (int) $days);
        $impr   = (int) $row['impressions'];
        $clicks = (int) $row['potential'];
        if ($impr < 1 || $clicks * $scale < 1 || (string) $row['path'] === '') {
            return null;
        }
        $queries = array_values(array_filter(array_map(static function ($query) {
            return (string) $query['query'];
        }, (array) $row['queries'])));
        $figures = array(
            'clicks'      => (int) $row['clicks'],
            'impressions' => $impr,
            'position'    => $row['position'],
            'potential'   => (int) $row['potential'],
            'need'        => (int) $row['need'],
            'domains'     => (int) $row['domains'],
            'followed'    => (int) $row['followed'],
            'useful'      => (int) $row['useful'],
            'top_median'  => (float) $row['top_median'],
            'gap'         => (float) $row['gap'],
            'links'       => (int) $row['links'],
            'link_value'  => (int) $row['value'],
            'searches'    => array_values((array) $row['queries']),
        );
        $useful = (int) $row['useful'];
        /* translators: 1: average position, 2: impressions, 3: searches, 4: clicks within reach, 5: number of useful sites linking, 6: median useful sites linking to the site's pages in the top three */
        $why = sprintf(_n('Its searches sit at position %1$s (%2$s impressions: %3$s), %4$s clicks from the top three. %5$s useful site links to it; the pages of this site in the top three have a median of %6$s.', 'Its searches sit at position %1$s (%2$s impressions: %3$s), %4$s clicks from the top three. %5$s useful sites link to it; the pages of this site in the top three have a median of %6$s.', $useful, 'seoproanalytics'), number_format_i18n((float) $row['position'], 1), number_format_i18n($impr), implode(', ', array_map(static function ($query) {
            return '“' . $query . '”';
        }, $queries)), number_format_i18n((int) $row['potential']), number_format_i18n($useful), number_format_i18n((float) $row['top_median'], 1));
        $parts = array(
            'clicks'     => round($clicks * $scale, 1),
            'value'      => round(self::worth((int) $row['path_id'], $value), 2),
            'confidence' => round(self::CONFIDENCE['backlinks'] * min(1.0, sqrt($impr * $scale / self::FULL_IMPRESSIONS)), 2),
            'effort'     => self::EFFORT['backlinks'],
        );
        return array(
            'key'      => self::key('backlinks', $engine, (string) $row['path'], 'needs'),
            'kind'     => 'backlinks',
            'engine'   => $engine,
            'status'   => 'new',
            'found'    => true,
            'path_id'  => (int) $row['path_id'],
            'path'     => (string) $row['path'],
            'url'      => (string) $row['url'],
            'post_id'  => (int) $row['post_id'],
            'edit_url' => isset($row['edit_url']) ? $row['edit_url'] : null,
            'query'    => null,
            'finding'  => 'needs',
            'why'      => $why,
            'todo'     => __('Earn links to it from related sites: research who links to pages like it (Backlinks → Needs links → Research), and offer something worth linking to.', 'seoproanalytics'),
            'figures'  => $figures,
            'metric'   => self::METRIC['backlinks'],
            'parts'    => $parts,
            'score'    => self::score($parts),
        );
    }

    /**
     * One item from a Search Console sitemap problem
     * (SEOProAnalytics_Inspections::sitemap_problems()): errors, warnings, not
     * downloaded lately, or the site's own sitemap not submitted. Potential
     * clicks: what a page shown in search earns here per 28 days × the
     * problem's share (SEOProAnalytics_Inspections::SITEMAP_SHARE); confidence
     * the kind's own. Done opens no experiment: a sitemap is about the
     * whole site. Null without potential clicks.
     *
     * @param string              $engine  Engine name.
     * @param array<string,mixed> $problem The problem.
     * @param float               $typical A shown page's clicks per 28 days.
     * @return array<string,mixed>|null
     */
    private static function sitemap_item($engine, array $problem, $typical) {
        $name   = (string) $problem['problem'];
        $share  = isset(SEOProAnalytics_Inspections::SITEMAP_SHARE[$name]) ? SEOProAnalytics_Inspections::SITEMAP_SHARE[$name] : 0.0;
        $clicks = (float) $typical * $share;
        if ($clicks < 1) {
            return null;
        }
        $parts = array(
            'clicks'     => round($clicks, 1),
            'value'      => 1.0,
            'confidence' => self::CONFIDENCE['sitemap'],
            'effort'     => self::effort_of('sitemap', $name),
        );
        return array(
            'key'      => self::key('sitemap', $engine, (string) $problem['path'], $name . ' ' . (string) $problem['url']),
            'kind'     => 'sitemap',
            'engine'   => $engine,
            'status'   => 'new',
            'found'    => true,
            'path_id'  => 0,
            'path'     => (string) $problem['path'],
            'url'      => (string) $problem['url'],
            'post_id'  => 0,
            'edit_url' => null,
            'query'    => null,
            'finding'  => $name,
            'why'      => SEOProAnalytics_Inspections::why($problem),
            'todo'     => SEOProAnalytics_Inspections::todo($name),
            'figures'  => array(
                'clicks'      => 0,
                'impressions' => 0,
                'position'    => null,
                'typical'     => (float) $typical,
                'share'       => $share,
                'sitemap'     => array(
                    'url'        => (string) $problem['url'],
                    'errors'     => (int) $problem['errors'],
                    'warnings'   => (int) $problem['warnings'],
                    'downloaded' => $problem['downloaded'],
                    'submitted'  => $problem['submitted'],
                ),
            ),
            'metric'   => self::METRIC['sitemap'],
            'parts'    => $parts,
            'score'    => self::score($parts),
        );
    }

    /**
     * One refresh item from a losing page with content facts
     * (SEOProAnalytics_Refresh): update, leave, protect or merge. Potential
     * clicks: those lost × the proposal's share; confidence the kind's,
     * weighed by impressions as decay's.
     *
     * @param string              $engine Engine name.
     * @param array<string,mixed> $row    Decay row.
     * @param array{post_id:int,modified:int,published:int,words:int,links_in:int} $facts The page's content facts (SEOProAnalytics_Refresh::facts()).
     * @param int                 $days   Days of the period.
     * @param array<string,mixed> $value  From values().
     * @return array<string,mixed>
     */
    private static function refresh_item($engine, array $row, array $facts, $days, array $value) {
        $path_id  = (int) $row['path_id'];
        $worth    = self::worth($path_id, $value);
        $page     = null;
        if ($value['site']) {
            $page = isset($value['pages'][$path_id]) ? $value['pages'][$path_id] : array('visits' => 0, 'conversions' => 0);
        }
        $made     = SEOProAnalytics_Refresh::propose($row, $facts, $page, $worth, $days);
        $proposal = $made['proposal'];
        $figures  = $made['figures'];
        $scale    = self::SCALE_DAYS / max(1, (int) $days);
        $impr     = max((int) $row['impressions'], (int) $row['compare']['impressions']);
        $parts    = array(
            'clicks'     => round((int) $row['lost'] * SEOProAnalytics_Refresh::SHARE[$proposal] * $scale, 1),
            'value'      => round($worth, 2),
            'confidence' => round(self::CONFIDENCE['refresh'] * min(1.0, sqrt($impr * $scale / self::FULL_IMPRESSIONS)), 2),
            'effort'     => self::effort_of('refresh', $proposal),
        );
        return array(
            'key'      => self::key('refresh', $engine, (string) $row['path'], $proposal),
            'kind'     => 'refresh',
            'engine'   => $engine,
            'status'   => 'new',
            'found'    => true,
            'path_id'  => $path_id,
            'path'     => (string) $row['path'],
            'url'      => (string) $row['url'],
            'post_id'  => (int) $row['post_id'],
            'edit_url' => isset($row['edit_url']) ? $row['edit_url'] : null,
            'query'    => null,
            'finding'  => $proposal,
            'why'      => SEOProAnalytics_Refresh::why($row, $figures, $value['site']),
            'todo'     => SEOProAnalytics_Refresh::todo($figures),
            'figures'  => $figures,
            'metric'   => self::METRIC['refresh'],
            'parts'    => $parts,
            'score'    => self::score($parts),
        );
    }

    /**
     * One item from a search target (SEOProAnalytics_Targets), or null:
     *
     * - wrong_page: search shows another page than the one meant for it;
     *   potential clicks the search's impressions × the site's CTR at its
     *   position × TARGET_SHARE; done measures both pages' clicks;
     * - striking: priority HIGH_PRIORITY or more, positions 4–20, on its
     *   own page or none chosen; potential clicks those of the top three
     *   less those now, as Opportunities' striking distance; measured by
     *   position.
     *
     * Both × the priority ÷ PRIORITY (a target of 100 counts twice one of
     * 50), scaled to 28 days; confidence the kind's, weighed by impressions.
     *
     * @param string              $engine Engine name.
     * @param array<string,mixed> $row    Targets report row.
     * @param int                 $days   Days of the period.
     * @param array<int,float>    $curve  Expected CTR by position.
     * @param array<string,mixed> $value  From values().
     * @return array<string,mixed>|null
     */
    private static function target_item($engine, array $row, $days, array $curve, array $value) {
        $impr = (int) $row['impressions'];
        if (!$impr || $row['position'] === null) {
            return null;
        }
        $place  = max(1, min(20, (int) round((float) $row['position'])));
        $weight = (int) $row['priority'] / SEOProAnalytics_Targets::PRIORITY;
        if ($row['state'] === 'wrong_page') {
            $finding  = 'wrong_page';
            $page     = $row['page'];
            $expected = (float) $curve[$place];
            $clicks   = $impr * $expected * self::TARGET_SHARE;
        } elseif (in_array($row['state'], array('ranking', 'no_page'), true) && $row['band'] === 'striking' && (int) $row['priority'] >= SEOProAnalytics_Targets::HIGH_PRIORITY) {
            $finding  = 'striking';
            $page     = $row['state'] === 'ranking' ? $row['page'] : $row['shown'];
            $expected = (float) $curve[SEOProAnalytics_Opportunities::TARGET];
            $clicks   = max(0.0, $impr * $expected - (int) $row['clicks']);
        } else {
            return null;
        }
        // An item is on a page: none when search gave no page for the query and none is chosen.
        if (!is_array($page) || ($finding === 'wrong_page' && !is_array($row['shown']))) {
            return null;
        }
        $scale = self::SCALE_DAYS / max(1, (int) $days);
        $parts = array(
            'clicks'     => round($clicks * $weight * $scale, 1),
            'value'      => round(self::worth((int) $page['path_id'], $value), 2),
            'confidence' => round(self::CONFIDENCE['target'] * min(1.0, sqrt($impr * $scale / self::FULL_IMPRESSIONS)), 2),
            'effort'     => self::EFFORT['target'],
        );
        if ($parts['clicks'] <= 0) {
            return null;
        }
        $figures = array(
            'clicks'        => (int) $row['clicks'],
            'impressions'   => $impr,
            'ctr'           => $row['ctr'],
            'position'      => $row['position'],
            'expected_ctr'  => round($expected, 4),
            'potential'     => (int) round($clicks),
            'priority'      => (int) $row['priority'],
            'target_status' => (string) $row['status'],
        );
        if ($finding === 'wrong_page') {
            $figures['pages'] = array_map(static function ($one) {
                return array(
                    'path_id'     => (int) $one['path_id'],
                    'path'        => (string) $one['path'],
                    'clicks'      => (int) $one['clicks'],
                    'impressions' => (int) $one['impressions'],
                    'position'    => $one['position'],
                    'share'       => $one['share'],
                );
            }, array($row['page'], $row['shown']));
        }
        return array(
            'key'      => self::key('target', $engine, (string) $page['path'], $finding . "\n" . (string) $row['query']),
            'kind'     => 'target',
            'engine'   => $engine,
            'status'   => 'new',
            'found'    => true,
            'path_id'  => (int) $page['path_id'],
            'path'     => (string) $page['path'],
            'url'      => (string) $page['url'],
            'post_id'  => (int) $page['post_id'],
            'edit_url' => isset($page['edit_url']) ? $page['edit_url'] : null,
            'query'    => (string) $row['query'],
            'finding'  => $finding,
            'why'      => SEOProAnalytics_Targets::why($finding, $row, (int) round($clicks)),
            'todo'     => SEOProAnalytics_Targets::todo($finding, $row),
            'figures'  => $figures,
            'metric'   => isset(self::TARGET_METRIC[$finding]) ? self::TARGET_METRIC[$finding] : self::METRIC['target'],
            'parts'    => $parts,
            'score'    => self::score($parts),
        );
    }

    /**
     * A page's value: how well its visits from search convert against the
     * site's, smoothed, 1 to MAX_VALUE (1 without a goal).
     *
     * @param int                 $path_id Path id.
     * @param array<string,mixed> $value   From values().
     * @return float
     */
    private static function worth($path_id, array $value) {
        $site = $value['site'];
        if (!$site) {
            return 1.0;
        }
        $page = isset($value['pages'][(int) $path_id]) ? $value['pages'][(int) $path_id] : array('visits' => 0, 'conversions' => 0);
        $rate = ($page['conversions'] + self::SMOOTH * $site) / ($page['visits'] + self::SMOOTH);
        return min((float) self::MAX_VALUE, max(1.0, $rate / $site));
    }

    /**
     * The effort of an item's kind (and audit finding or links list).
     *
     * @param string      $kind    Kind name.
     * @param string|null $finding Audit finding, or links list.
     * @return int
     */
    private static function effort_of($kind, $finding = null) {
        if ($kind === 'audit' && $finding !== null && isset(self::AUDIT_EFFORT[$finding])) {
            return self::AUDIT_EFFORT[$finding];
        }
        if ($kind === 'links' && $finding !== null && isset(self::LINKS_EFFORT[$finding])) {
            return self::LINKS_EFFORT[$finding];
        }
        if ($kind === 'index' && $finding !== null && isset(self::INDEX_EFFORT[$finding])) {
            return self::INDEX_EFFORT[$finding];
        }
        if ($kind === 'sitemap' && $finding !== null && isset(self::SITEMAP_EFFORT[$finding])) {
            return self::SITEMAP_EFFORT[$finding];
        }
        if ($kind === 'refresh' && $finding !== null && isset(SEOProAnalytics_Refresh::EFFORT[$finding])) {
            return SEOProAnalytics_Refresh::EFFORT[$finding];
        }
        return self::EFFORT[$kind];
    }

    /**
     * The score from its parts.
     *
     * @param array<string,int|float> $parts clicks, value, confidence, effort.
     * @return float
     */
    private static function score(array $parts) {
        return round((float) $parts['clicks'] * (float) $parts['value'] * (float) $parts['confidence'] / max(1, (int) $parts['effort']), 1);
    }

    /**
     * Join the stored states to the items found, add the stored ones no
     * longer found, and leave out new items on pages with a running
     * experiment and dismissed ones still hidden.
     *
     * @param array<string,array<string,mixed>> $items   From build().
     * @param string                            $engine  Engine name.
     * @param array<int,int>                    $running  Path id => experiment id.
     * @param int                               $left_out Set to the new items left out for a running experiment.
     * @return array<int,array<string,mixed>>
     */
    private static function with_states(array $items, $engine, array $running, &$left_out) {
        $left_out = 0;
        $stored = self::stored(array_keys($items));
        $hidden = time() - self::HIDE_DAYS * DAY_IN_SECONDS;
        $out    = array();
        foreach ($items as $key => $item) {
            if (isset($stored[$key])) {
                $item = self::apply($item, $stored[$key]);
                unset($stored[$key]);
            }
            if ($item['status'] === 'dismissed' && $item['updated_ts'] < $hidden) {
                // Back after HIDE_DAYS days, as new.
                $item['status'] = 'new';
            }
            if ($item['status'] === 'new' && !isset($item['verified']) && array_intersect_key($running, array_flip(self::held_by($item)))) {
                ++$left_out;
                continue;
            }
            $out[] = $item;
        }
        // Accepted and done items no longer found: as they were when acted on.
        foreach ($stored as $row) {
            $item = self::from_row($row);
            if ($item && $item['engine'] === $engine) {
                $out[] = $item;
            }
        }
        return $out;
    }

    /**
     * Items with their owners: the one set for the item, else the default
     * (its page's, or whoever Settings → Data → Owners names).
     *
     * @param array<int,array<string,mixed>> $items Items.
     * @return array<int,array<string,mixed>> The items, each with owner (SEOProAnalytics_Owners::pick()).
     */
    private static function with_owners(array $items) {
        require_once __DIR__ . '/class-seoproanalytics-owners.php';
        $refs  = array();
        $paths = array();
        foreach ($items as $i => $item) {
            $refs[$i]          = SEOProAnalytics_Owners::item_ref((string) $item['key']);
            $paths[$refs[$i]] = isset($item['path_id']) ? (int) $item['path_id'] : 0;
        }
        $owners = SEOProAnalytics_Owners::of('item', SEOProAnalytics_Owners::on_pages('item', $paths));
        foreach ($items as $i => &$item) {
            $item['owner'] = $owners[$refs[$i]];
        }
        unset($item);
        return $items;
    }

    /**
     * The open items (new and accepted) per owner: how many and their
     * potential clicks per 28 days, for SEOProAnalytics_Owners::summary().
     *
     * @param array<string,mixed> $req    From SEOProAnalytics_Query::request().
     * @param string              $engine google or bing.
     * @param string              $goal   Goal id; '' for the first goal.
     * @return array<string,mixed> range, engine, through and owners (user id, 0 for none => items, clicks).
     */
    public static function owner_counts(array $req, $engine = 'google', $goal = '') {
        $built = self::build($req, $engine, $goal);
        $left  = 0;
        $items = array_values(array_filter(self::with_states($built['items'], $built['answer']['engine'], $built['running'], $left), static function ($item) {
            return in_array($item['status'], array('new', 'accepted'), true);
        }));
        $out = array();
        foreach (self::with_owners($items) as $item) {
            $id = $item['owner']['id'] === null ? 0 : (int) $item['owner']['id'];
            if (!isset($out[$id])) {
                $out[$id] = array('items' => 0, 'clicks' => 0.0);
            }
            ++$out[$id]['items'];
            $out[$id]['clicks'] += (float) $item['parts']['clicks'];
        }
        return array(
            'engine'  => $built['answer']['engine'],
            'range'   => $built['answer']['range'],
            'through' => $built['answer']['through'],
            'owners'  => $out,
        );
    }

    /**
     * Stored rows: those of the keys given (by ikey) and the newest KEPT
     * accepted and done ones (by status_updated).
     *
     * @param string[] $keys Item keys (hex).
     * @return array<string,array<string,mixed>> Key => row.
     */
    private static function stored(array $keys) {
        global $wpdb;
        if (!SEOProAnalytics_Schema::is_current()) {
            return array();
        }
        $table = SEOProAnalytics_Schema::table('queue');
        $cols  = 'id, LOWER(HEX(ikey)) AS k, kind, engine, path_id, query_id, status, effort, experiment_id, created, updated, user_id, note, meta';
        $out   = array();
        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table, by its unique key ikey or key status_updated; $cols is a fixed column list and $holders only placeholders.
        foreach (array_chunk($keys, 200) as $chunk) {
            $holders = implode(', ', array_fill(0, count($chunk), 'UNHEX(%s)'));
            foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT $cols FROM %i WHERE ikey IN ($holders)", array_merge(array($table), $chunk)), ARRAY_A) as $row) {
                $out[(string) $row['k']] = $row;
            }
        }
        foreach (array(1, 2, 4, 5) as $status) {
            foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT $cols FROM %i FORCE INDEX (`status_updated`) WHERE status = %d ORDER BY updated DESC LIMIT %d", $table, $status, self::KEPT), ARRAY_A) as $row) {
                $out[(string) $row['k']] = $row;
            }
        }
        // phpcs:enable
        return $out;
    }

    /**
     * One stored row by key, or null.
     *
     * @param string $key Item key (hex).
     * @return array<string,mixed>|null
     */
    private static function row($key) {
        global $wpdb;
        if (!self::valid_key($key) || !SEOProAnalytics_Schema::is_current()) {
            return null;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, by its unique key ikey.
        $row = $wpdb->get_row($wpdb->prepare('SELECT id, LOWER(HEX(ikey)) AS k, kind, engine, path_id, query_id, status, effort, experiment_id, created, updated, user_id, note, meta FROM %i WHERE ikey = UNHEX(%s)', SEOProAnalytics_Schema::table('queue'), $key), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /**
     * An item with its stored state: status, effort, note, experiment.
     *
     * @param array<string,mixed> $item Item.
     * @param array<string,mixed> $row  Stored row.
     * @return array<string,mixed>
     */
    private static function apply(array $item, array $row) {
        $item['status']        = isset(self::STATUSES[(int) $row['status']]) ? self::STATUSES[(int) $row['status']] : 'new';
        unset($item['verified'], $item['verified_at']);
        if (in_array((int) $row['status'], array(4, 5), true)) {
            $item['verified'] = self::STATUSES[(int) $row['status']];
            $item['status'] = (int) $row['status'] === 4 ? 'done' : 'new';
            $meta = self::meta($row);
            $item['verified_at'] = gmdate('c', (int) ($meta['verified_at'] ?? $row['updated']));
        }
        $item['note']          = (string) $row['note'];
        $item['updated']       = (string) wp_date('c', (int) $row['updated']);
        $item['updated_ts']    = (int) $row['updated'];
        $item['user']          = self::user_name((int) $row['user_id']);
        $item['experiment_id'] = (int) $row['experiment_id'] ? (int) $row['experiment_id'] : null;
        if ((int) $row['effort']) {
            $item['parts']['effort'] = (int) $row['effort'];
            $item['effort_set']      = true;
            $item['score']           = self::score($item['parts']);
        }
        return $item;
    }

    /**
     * A stored item no longer found, from what was kept when it was acted on.
     *
     * @param array<string,mixed> $row Stored row.
     * @return array<string,mixed>|null
     */
    private static function from_row(array $row) {
        $meta = self::meta($row);
        if (empty($meta['item']) || !is_array($meta['item']) || !in_array((int) $row['status'], array(1, 2, 4, 5), true)) {
            return null;
        }
        $item          = $meta['item'];
        $item['found'] = false;
        $item['key']   = (string) $row['k'];
        $info          = SEOProAnalytics_Clicks::page_info((string) $item['path']);
        if ($info) {
            $item = array_merge($item, SEOProAnalytics_Clicks::with_edit_url($info));
        }
        return self::apply($item + self::blank(), $row);
    }

    /**
     * An item's fields that only a stored state fills.
     *
     * @return array<string,mixed>
     */
    private static function blank() {
        return array(
            'note'          => '',
            'updated'       => null,
            'updated_ts'    => 0,
            'user'          => null,
            'experiment_id' => null,
            'effort_set'    => false,
        );
    }

    /**
     * An item as the answer gives it: the stored fields filled, and a done
     * item's experiment with its result.
     *
     * @param array<string,mixed> $item Item.
     * @return array<string,mixed>
     */
    private static function finish(array $item) {
        require_once __DIR__ . '/class-seoproanalytics-owners.php';
        $item += self::blank();
        unset($item['updated_ts']);
        $item['experiment'] = $item['experiment_id'] ? self::experiment((int) $item['experiment_id']) : null;
        if (!isset($item['owner']) && SEOProAnalytics_Owners::shown()) {
            $item = self::with_owners(array($item))[0];
        }
        $item['owner'] = isset($item['owner']) ? SEOProAnalytics_Owners::out($item['owner']) : null;
        return $item;
    }

    /**
     * A done item's experiment with its result, or null when it is gone.
     *
     * @param int $id Experiment id.
     * @return array<string,mixed>|null
     */
    private static function experiment($id) {
        $found = SEOProAnalytics_Experiments::get($id);
        if (is_wp_error($found)) {
            return null;
        }
        $m = is_array($found['measurement']) ? $found['measurement'] : array();
        return array(
            'id'        => $found['id'],
            'name'      => $found['name'],
            'status'    => $found['status'],
            'result'    => $found['result'],
            'due'       => $found['due'],
            'review'    => $found['review'],
            'metric'    => $found['metric'],
            'state'     => isset($m['state']) ? $m['state'] : null,
            'effect'    => isset($m['effect']) ? $m['effect'] : null,
            'unit'      => isset($m['unit']) ? $m['unit'] : null,
            'suggested' => isset($m['suggested']) ? $m['suggested'] : null,
            'summary'   => isset($m['summary']) ? $m['summary'] : null,
        );
    }

    /** @param array<string,mixed> $item Item. @return bool A crawl can verify this finding. */
    private static function crawl_item(array $item) {
        require_once __DIR__ . '/class-seoproanalytics-crawl.php';
        return ($item['kind'] ?? '') === 'audit' && (isset(SEOProAnalytics_Crawl::FINDINGS[$item['finding'] ?? '']) || ($item['finding'] ?? '') === 'clicked_broken');
    }

    /**
     * Verify one bounded primary-key range, independently of report limits.
     * A concurrent user action wins: updates compare the status and timestamp.
     *
     * @param array<string,mixed> $job Import job, including verification cursor.
     * @return bool Whether the range is exhausted.
     */
    public static function verify_crawl(array &$job) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- bounded primary-key cursor, only on import completion.
        $rows = (array) $wpdb->get_results($wpdb->prepare('SELECT id, kind, path_id, status, updated, note, meta FROM %i WHERE id > %d ORDER BY id LIMIT %d', SEOProAnalytics_Schema::table('queue'), (int) ($job['verify_cursor'] ?? 0), 200), ARRAY_A);
        if (!empty($job['partial']) && !SEOProAnalytics_Crawl_Import::journal($job, array('queue' => $rows))) {
            return false;
        }
        foreach ($rows as $row) {
            $job['verify_cursor'] = (int) $row['id'];
            if (!in_array((int) $row['status'], array(2, 4, 5), true) || (int) $row['updated'] > (int) ($job['observed_at'] ?? $job['started'])) {
                continue;
            }
            $meta = self::meta($row);
            $item = isset($meta['item']) && is_array($meta['item']) ? $meta['item'] : array();
            if (!self::crawl_item($item)) {
                continue;
            }
            $found = SEOProAnalytics_Crawl::verify_finding($item, $job);
            if ($found === null) {
                continue;
            }
            $meta['verified_at'] = (int) $job['started'];
            $meta['verified_import'] = (int) $job['id'];
            /* translators: %s: crawl date. */
            $message = $found ? __('Still found by the crawl of %s', 'seoproanalytics') : __('Confirmed fixed by the crawl of %s', 'seoproanalytics');
            $note = sprintf($message, wp_date('Y-m-d', (int) $job['started']));
            // Keep the owner's note, with one current verdict rather than an ever-growing history.
            $original = (string) ($meta['verification_note'] ?? $row['note']);
            $meta['verification_note'] = $original;
            $note = self::note($note . ($original !== '' ? '. ' . $original : ''));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- compare-and-set on the primary key protects concurrent actions.
            $wpdb->update(SEOProAnalytics_Schema::table('queue'), array('status' => $found ? 5 : 4, 'note' => $note, 'meta' => (string) wp_json_encode($meta)), array('id' => (int) $row['id'], 'status' => (int) $row['status'], 'updated' => (int) $row['updated'], 'note' => $row['note'], 'meta' => $row['meta']), array('%d', '%s', '%s'), array('%d', '%d', '%d', '%s', '%s'));
        }
        return count($rows) < 200;
    }

    // ------------------------------------------------------------------
    // Acting.

    /**
     * Act on an item: accept, done (opens an experiment), dismiss,
     * restore (forget its state), effort (1–5) or note. An item not yet
     * stored must be in the list for the request now.
     *
     * @param string              $key    Item key (16 hex characters).
     * @param array<string,mixed> $input  action; effort; note; for done: name, days, threshold.
     * @param array<string,mixed> $req    From SEOProAnalytics_Query::request(), the list it was seen in.
     * @param string              $engine google or bing.
     * @param string              $goal   Goal id for value.
     * @return array<string,mixed>|WP_Error The item.
     */
    public static function update($key, array $input, array $req, $engine = 'google', $goal = '') {
        require_once __DIR__ . '/class-seoproanalytics-experiments.php';
        $key    = strtolower(trim((string) $key));
        $action = isset($input['action']) ? (string) $input['action'] : '';
        $error  = self::cannot_update($action, $key);
        if ($error) {
            return $error;
        }
        $row  = self::row($key);
        $item = self::current($key, $row, $req, $engine, $goal);
        if (!$item) {
            return self::error('seoproanalytics_not_found', __('This item is not in the plan for this period now. List the plan again and use a key from it.', 'seoproanalytics'), 404);
        }
        if ($action === 'restore') {
            return self::restore($item, $row);
        }
        $state = self::acted($action, $input, $item, $row);
        if (is_wp_error($state)) {
            return $state;
        }
        if ($state['experiment_id'] && (!$row || (int) $row['experiment_id'] !== $state['experiment_id'])) {
            // The experiment measuring a done item stays with whoever was given the item.
            require_once __DIR__ . '/class-seoproanalytics-owners.php';
            SEOProAnalytics_Owners::carry('item', SEOProAnalytics_Owners::item_ref($key), 'experiment', $state['experiment_id']);
        }
        self::save($key, $item, $row, $state);
        $saved = self::row($key);
        if (!$saved) {
            return self::error('seoproanalytics_queue_failed', __('The item could not be saved.', 'seoproanalytics'), 500);
        }
        if ($action === 'done' && self::crawl_item($item) && SEOProAnalytics_Schema::set() === 'live') {
            /**
             * A crawl finding's Plan item is done: a connected crawler can
             * crawl the page again to confirm the fix.
             *
             * @param string $path The page's path.
             */
            do_action('seoproanalytics_crawl_fix_done', (string) $item['path']);
        }
        return self::finish(self::apply(array_merge($item, self::blank()), $saved));
    }

    /**
     * Why an action cannot be taken, or null once the tables are current.
     *
     * @param string $action Action.
     * @param string $key    Item key.
     * @return WP_Error|null
     */
    private static function cannot_update($action, $key) {
        if (!in_array($action, self::ACTIONS, true)) {
            /* translators: %s: list of actions */
            return self::error('seoproanalytics_queue_action', sprintf(__('The action is one of: %s.', 'seoproanalytics'), implode(', ', self::ACTIONS)));
        }
        if (!self::valid_key($key)) {
            return self::error('seoproanalytics_queue_key', __('An item\'s key is 16 hexadecimal characters, as the list gives it.', 'seoproanalytics'));
        }
        if (!SEOProAnalytics_Schema::maybe_upgrade()) {
            return self::error('seoproanalytics_queue_failed', __('The item could not be saved.', 'seoproanalytics'), 500);
        }
        return null;
    }

    /**
     * Restore an item: forget its stored state.
     *
     * @param array<string,mixed>      $item Item.
     * @param array<string,mixed>|null $row  Its stored row.
     * @return array<string,mixed>
     */
    private static function restore(array $item, $row) {
        global $wpdb;
        if ($row) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, by primary key.
            $wpdb->delete(SEOProAnalytics_Schema::table('queue'), array('id' => (int) $row['id']), array('%d'));
        }
        $item = array_merge($item, array('status' => 'new', 'note' => '', 'experiment_id' => null, 'effort_set' => false));
        $item['parts']['effort'] = self::effort_of($item['kind'], isset($item['finding']) ? (string) $item['finding'] : null);
        $item['score']           = self::score($item['parts']);
        return self::finish($item);
    }

    /**
     * The state an action other than restore leaves.
     *
     * @param string                   $action Action.
     * @param array<string,mixed>      $input  The action's input.
     * @param array<string,mixed>      $item   Item.
     * @param array<string,mixed>|null $row    Its stored row.
     * @return array{status:int,effort:int,note:string,experiment_id:int}|WP_Error
     */
    private static function acted($action, array $input, array $item, $row) {
        $state = self::state_of($row);
        // The note action sets the note (empty clears it); other actions keep it unless one is given.
        if ($action === 'note' || (isset($input['note']) && (string) $input['note'] !== '')) {
            $state['note'] = self::note(isset($input['note']) ? $input['note'] : '');
        }
        if ($action === 'effort') {
            $state = self::with_effort($state, $input);
        } elseif ($action === 'accept') {
            $state['status'] = 1;
        } elseif ($action === 'dismiss') {
            $state['status'] = 3;
        } elseif ($action === 'done') {
            $state = self::done($state, $input, $item);
        }
        return $state;
    }

    /**
     * An item's stored state: status, effort, note and experiment.
     *
     * @param array<string,mixed>|null $row Its stored row; null for none.
     * @return array{status:int,effort:int,note:string,experiment_id:int}
     */
    private static function state_of($row) {
        if (!$row) {
            return array('status' => 0, 'effort' => 0, 'note' => '', 'experiment_id' => 0);
        }
        return array(
            'status'        => (int) $row['status'],
            'effort'        => (int) $row['effort'],
            'note'          => (string) $row['note'],
            'experiment_id' => (int) $row['experiment_id'],
        );
    }

    /**
     * The state with the effort the input gives: 1 (least) to MAX_EFFORT.
     *
     * @param array{status:int,effort:int,note:string,experiment_id:int} $state State.
     * @param array<string,mixed>                                        $input The action's input.
     * @return array{status:int,effort:int,note:string,experiment_id:int}|WP_Error
     */
    private static function with_effort(array $state, array $input) {
        $state['effort'] = isset($input['effort']) ? (int) $input['effort'] : 0;
        if ($state['effort'] < 1 || $state['effort'] > self::MAX_EFFORT) {
            /* translators: %d: most effort */
            return self::error('seoproanalytics_queue_effort', sprintf(__('Effort is 1 (least) to %d.', 'seoproanalytics'), self::MAX_EFFORT));
        }
        return $state;
    }

    /**
     * The state done leaves: done, with an experiment opened to measure
     * the change.
     *
     * @param array{status:int,effort:int,note:string,experiment_id:int} $state State.
     * @param array<string,mixed>                                        $input The action's input: name, days, threshold.
     * @param array<string,mixed>                                        $item  Item.
     * @return array{status:int,effort:int,note:string,experiment_id:int}|WP_Error
     */
    private static function done(array $state, array $input, array $item) {
        if ($state['status'] === 4) {
            return self::error('seoproanalytics_queue_done', __('This item is confirmed fixed already.', 'seoproanalytics'), 409);
        }
        if ($state['status'] === 2 && self::no_change($item)) {
            return self::error('seoproanalytics_queue_done', __('This item is done already.', 'seoproanalytics'), 409);
        }
        if ($state['status'] === 2 && $state['experiment_id']) {
            return self::error('seoproanalytics_queue_done', __('This item is done already; its experiment measures it.', 'seoproanalytics'), 409);
        }
        // A page left as it is changes nothing, so no experiment measures it.
        if (!self::no_change($item) && !$state['experiment_id']) {
            $opened = self::open_experiment($item, $input, $state['note']);
            if (is_wp_error($opened)) {
                return $opened;
            }
            $state['experiment_id'] = (int) $opened['id'];
        }
        $state['status'] = 2;
        return $state;
    }

    /**
     * Open the experiment that measures a done item.
     *
     * @param array<string,mixed> $item  Item.
     * @param array<string,mixed> $input The action's input: name, days, threshold.
     * @param string              $note  The item's note.
     * @return array<string,mixed>|WP_Error The experiment.
     */
    private static function open_experiment(array $item, array $input, $note) {
        return SEOProAnalytics_Experiments::add(array(
            'name'       => isset($input['name']) && trim((string) $input['name']) !== '' ? (string) $input['name'] : self::experiment_name($item),
            'pages'      => self::paths($item),
            'engine'     => $item['engine'],
            'metric'     => $item['metric'],
            'direction'  => 'up',
            'days'       => isset($input['days']) && (int) $input['days'] ? (int) $input['days'] : SEOProAnalytics_Experiments::DAYS,
            'threshold'  => isset($input['threshold']) ? $input['threshold'] : '',
            'hypothesis' => $item['why'] . ' ' . $item['todo'],
            'note'       => $note,
        ));
    }

    /**
     * Store an item's state, with what the item was when acted on so it
     * can be shown if it is no longer found. (Its parts have the kind's
     * effort: a stored effort is applied when read.)
     *
     * @param string                                                     $key   Item key.
     * @param array<string,mixed>                                        $item  Item.
     * @param array<string,mixed>|null                                   $row   Its stored row; null for a new one.
     * @param array{status:int,effort:int,note:string,experiment_id:int} $state From acted().
     */
    private static function save($key, array $item, $row, array $state) {
        global $wpdb;
        $table = SEOProAnalytics_Schema::table('queue');
        $keep  = array_diff_key($item, array_flip(array('status', 'note', 'updated', 'updated_ts', 'user', 'experiment_id', 'effort_set', 'experiment', 'edit_url', 'found', 'key', 'verified', 'verified_at', 'owner')));
        $meta  = array('item' => $keep);
        if ($row && in_array($state['status'], array(4, 5), true)) {
            $before = self::meta($row);
            $meta['verified_at'] = (int) ($before['verified_at'] ?? $row['updated']);
        }
        $set   = array(
            'status'        => $state['status'],
            'effort'        => $state['effort'],
            'experiment_id' => $state['experiment_id'],
            'updated'       => time(),
            'user_id'       => get_current_user_id(),
            'note'          => $state['note'],
            'meta'          => (string) wp_json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
        // phpcs:disable WordPress.DB.DirectDatabaseQuery -- writing our own table, by primary key or a new row.
        if ($row) {
            $wpdb->update($table, $set, array('id' => (int) $row['id']), array('%d', '%d', '%d', '%d', '%d', '%s', '%s'), array('%d'));
        } else {
            $kind  = (int) array_search($item['kind'], self::KINDS, true);
            $query = $item['query'] !== null && $item['query'] !== '' ? SEOProAnalytics_Dict::find(SEOProAnalytics_Schema::DICT_QUERY, array((string) $item['query'])) : array();
            $wpdb->query($wpdb->prepare(
                'INSERT INTO %i (ikey, kind, engine, path_id, query_id, status, effort, experiment_id, created, updated, user_id, note, meta) VALUES (UNHEX(%s), %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %s, %s)',
                $table,
                $key,
                $kind,
                SEOProAnalytics_Search::ENGINES[$item['engine']],
                (int) $item['path_id'],
                $query ? (int) $query[0] : 0,
                $set['status'],
                $set['effort'],
                $set['experiment_id'],
                $set['updated'],
                $set['updated'],
                $set['user_id'],
                $set['note'],
                $set['meta']
            ));
        }
        // phpcs:enable
    }

    /**
     * The item of a key now: found in the list for the request, else as
     * stored when it was acted on.
     *
     * @param string                   $key    Item key.
     * @param array<string,mixed>|null $row    Its stored row.
     * @param array<string,mixed>      $req    Request.
     * @param string                   $engine Engine name.
     * @param string                   $goal   Goal id.
     * @return array<string,mixed>|null
     */
    private static function current($key, $row, array $req, $engine, $goal) {
        if ($row) {
            $engine = (int) $row['engine'] === SEOProAnalytics_Schema::ENGINE_BING ? 'bing' : 'google';
        }
        $built  = self::build($req, $engine, $goal);
        if (isset($built['items'][$key])) {
            return $built['items'][$key] + self::blank();
        }
        if (!$row) {
            return null;
        }
        $meta = self::meta($row);
        if (empty($meta['item']) || !is_array($meta['item'])) {
            return null;
        }
        $info = SEOProAnalytics_Clicks::page_info((string) $meta['item']['path']);
        return array_merge($meta['item'], $info ? SEOProAnalytics_Clicks::with_edit_url($info) : array(), array('key' => $key, 'found' => false, 'status' => 'new')) + self::blank();
    }

    /**
     * The name of the experiment done opens.
     *
     * @param array<string,mixed> $item Item.
     * @return string
     */
    private static function experiment_name(array $item) {
        $page  = (string) $item['path'];
        $query = (string) $item['query'];
        if ($item['kind'] === 'ctr') {
            /* translators: %s: page path */
            return sprintf(__('A new title and description lift CTR on %s', 'seoproanalytics'), $page);
        }
        if ($item['kind'] === 'missing') {
            /* translators: 1: search query, 2: page path */
            return sprintf(__('Answering “%1$s” lifts clicks on %2$s', 'seoproanalytics'), $query, $page);
        }
        if ($item['kind'] === 'striking') {
            /* translators: 1: page path, 2: search query */
            return sprintf(__('A better page and links lift %1$s for “%2$s”', 'seoproanalytics'), $page, $query);
        }
        if ($item['kind'] === 'overlap') {
            /* translators: %s: search query */
            return sprintf(__('One clear page for “%s” lifts its clicks', 'seoproanalytics'), $query);
        }
        if ($item['kind'] === 'audit') {
            /* translators: %s: page path */
            return sprintf(__('Fixing the content audit\'s finding lifts %s', 'seoproanalytics'), $page);
        }
        if ($item['kind'] === 'links') {
            /* translators: %s: page path */
            return sprintf(__('Internal links lift clicks on %s', 'seoproanalytics'), $page);
        }
        if ($item['kind'] === 'index') {
            /* translators: %s: page path */
            return sprintf(__('Search shows %s once it can find it', 'seoproanalytics'), $page);
        }
        if ($item['kind'] === 'target' && $item['finding'] === 'wrong_page') {
            /* translators: 1: page path, 2: search query */
            return sprintf(__('%1$s becomes the page search shows for “%2$s”', 'seoproanalytics'), $page, $query);
        }
        if ($item['kind'] === 'target') {
            /* translators: 1: page path, 2: search query */
            return sprintf(__('A better page and links lift %1$s for “%2$s”', 'seoproanalytics'), $page, $query);
        }
        if ($item['kind'] === 'refresh' && $item['finding'] === 'merge') {
            /* translators: 1: page path, 2: the other page's path */
            return sprintf(__('One clear page for %1$s and %2$s wins back clicks', 'seoproanalytics'), $page, (string) $item['figures']['rival']['path']);
        }
        if ($item['kind'] === 'refresh' && $item['finding'] === 'protect') {
            /* translators: %s: page path */
            return sprintf(__('A careful change to %s wins back clicks and keeps conversions', 'seoproanalytics'), $page);
        }
        /* translators: %s: page path */
        return sprintf(__('Updating %s wins back its clicks', 'seoproanalytics'), $page);
    }

    // ------------------------------------------------------------------
    // Words.

    /**
     * Why an item is listed, in one or two sentences.
     *
     * @param string              $kind    Kind name.
     * @param array<string,mixed> $row     Opportunity row.
     * @param array<string,mixed> $figures Figures.
     * @return string
     */
    private static function why($kind, array $row, array $figures) {
        $pos = static function ($value) {
            return number_format_i18n((float) $value, 1);
        };
        $pct = static function ($value) {
            return number_format_i18n(100 * (float) $value, 1) . '%';
        };
        $num = static function ($value) {
            return number_format_i18n((int) $value);
        };
        if ($kind === 'ctr') {
            /* translators: 1: CTR, 2: average position, 3: the site's own CTR at that position, 4: impressions */
            return sprintf(__('Chosen less than its place earns: CTR %1$s at position %2$s, where the site\'s own is %3$s (%4$s impressions).', 'seoproanalytics'), $pct($figures['ctr']), $pos($figures['position']), $pct($figures['expected_ctr']), $num($figures['impressions']));
        }
        if ($kind === 'missing') {
            $words = implode(', ', (array) $figures['missing']);
            if ($figures['match'] === 'partial') {
                /* translators: 1: words missing, 2: impressions, 3: average position */
                return sprintf(__('Searched for, but the page lacks some of the words: %1$s (%2$s impressions at position %3$s).', 'seoproanalytics'), $words, $num($figures['impressions']), $pos($figures['position']));
            }
            /* translators: 1: impressions, 2: average position */
            return sprintf(__('Searched for, but the page never uses its words (%1$s impressions at position %2$s).', 'seoproanalytics'), $num($figures['impressions']), $pos($figures['position']));
        }
        if ($kind === 'striking') {
            /* translators: 1: average position, 2: impressions, 3: clicks it could gain */
            return sprintf(__('Ranks %1$s with %2$s impressions: in the top three it could gain about %3$s clicks.', 'seoproanalytics'), $pos($figures['position']), $num($figures['impressions']), $num($figures['potential']));
        }
        if ($kind === 'overlap') {
            $parts = array();
            foreach ((array) $figures['pages'] as $page) {
                /* translators: 1: page path, 2: share of the query's impressions, 3: average position */
                $parts[] = sprintf(__('%1$s (%2$s, position %3$s)', 'seoproanalytics'), $page['path'], $pct($page['share']), $pos($page['position']));
            }
            /* translators: 1: number of pages, 2: the pages with their shares and positions, 3: clicks it could gain */
            $text = sprintf(__('%1$d pages share this search: %2$s. With the best of their CTRs it would have about %3$s more clicks.', 'seoproanalytics'), count($parts), implode('; ', $parts), $num($figures['potential']));
            if (!empty($figures['switched'])) {
                $text .= ' ' . __('The page with most impressions changed between the halves of the period.', 'seoproanalytics');
            }
            return $text;
        }
        /* translators: 1: clicks lost, 2: the likely cause in a sentence */
        return sprintf(__('Lost %1$s clicks against the period before. %2$s', 'seoproanalytics'), $num($figures['lost']), isset($row['why']) ? (string) $row['why'] : '');
    }

    /**
     * What to do about an item of a kind.
     *
     * @param string $kind Kind name.
     * @return string
     */
    private static function todo($kind) {
        if ($kind === 'ctr') {
            return __('Rewrite the title and description so searchers choose it.', 'seoproanalytics');
        }
        if ($kind === 'missing') {
            return __('Answer the search on the page, in its own words.', 'seoproanalytics');
        }
        if ($kind === 'striking') {
            return __('Improve the page for this search and link to it from related pages.', 'seoproanalytics');
        }
        if ($kind === 'overlap') {
            return __('A candidate to review: if the pages answer the same need, make one the clear answer and link to it from the others (or merge them); leave it if each serves a different need.', 'seoproanalytics');
        }
        return __('Find what changed (see the cause and the changes on the page), then update it.', 'seoproanalytics');
    }

    // ------------------------------------------------------------------
    // Helpers.

    /**
     * An item's key: 16 hex characters of a hash of kind, engine, page
     * and query.
     *
     * @param string $kind   Kind name.
     * @param string $engine Engine name.
     * @param string $path   Page path.
     * @param string $query  Query ('' for none).
     * @return string
     */
    public static function key($kind, $engine, $path, $query) {
        return SEOProAnalytics_Dict::hash($kind . "\n" . $engine . "\n" . $path . "\n" . $query);
    }

    /**
     * Whether doing an item changes nothing search measures on its pages
     * (a refresh proposal to leave the page as it is, a sitemap fixed,
     * which is about the whole site, or a clicked destination mended,
     * which is about clicks away, not search clicks): done opens no
     * experiment, and holds back no other item on the page.
     *
     * @param array<string,mixed> $item Item.
     * @return bool
     */
    private static function no_change(array $item) {
        if ($item['kind'] === 'audit') {
            return (string) $item['finding'] === 'clicked_broken';
        }
        return $item['kind'] === 'sitemap' || ($item['kind'] === 'refresh' && in_array((string) $item['finding'], self::NO_CHANGE, true));
    }

    /**
     * The pages an item is about: its page, and for overlap every page
     * sharing the query.
     *
     * @param array<string,mixed> $item Item.
     * @return int[]
     */
    private static function page_ids(array $item) {
        $ids = array((int) $item['path_id']);
        if (!empty($item['figures']['pages'])) {
            foreach ((array) $item['figures']['pages'] as $page) {
                $ids[] = isset($page['path_id']) ? (int) $page['path_id'] : 0;
            }
        }
        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * The pages whose running experiment holds a new item back: those it
     * is about, except for a refresh item, which is about its losing page
     * only (a merge's other page running an experiment does not hide the
     * loss, as its decay item would not have been hidden).
     *
     * @param array<string,mixed> $item Item.
     * @return int[]
     */
    private static function held_by(array $item) {
        return $item['kind'] === 'refresh' ? array((int) $item['path_id']) : self::page_ids($item);
    }

    /**
     * The paths an item is about, as page_ids().
     *
     * @param array<string,mixed> $item Item.
     * @return string[]
     */
    private static function paths(array $item) {
        $paths = array((string) $item['path']);
        if (!empty($item['figures']['pages'])) {
            foreach ((array) $item['figures']['pages'] as $page) {
                $paths[] = isset($page['path']) ? (string) $page['path'] : '';
            }
        }
        return array_values(array_unique(array_filter($paths)));
    }

    /**
     * Whether a text is an item key.
     *
     * @param string $key Text.
     * @return bool
     */
    private static function valid_key($key) {
        return (bool) preg_match('/^[0-9a-f]{16}$/', (string) $key);
    }

    /**
     * A row's meta.
     *
     * @param array<string,mixed> $row Table row.
     * @return array<string,mixed>
     */
    private static function meta(array $row) {
        $meta = isset($row['meta']) && $row['meta'] !== '' ? json_decode((string) $row['meta'], true) : array();
        return is_array($meta) ? $meta : array();
    }

    /**
     * A note, cut to the column (190 bytes).
     *
     * @param mixed $text Text.
     * @return string
     */
    private static function note($text) {
        $text = trim(sanitize_text_field(is_scalar($text) ? (string) $text : ''));
        return function_exists('mb_strcut') ? mb_strcut($text, 0, self::NOTE_BYTES, 'UTF-8') : substr($text, 0, self::NOTE_BYTES);
    }

    /**
     * Who acted, for people who may list users (and WP-CLI).
     *
     * @param int $user_id User.
     * @return string|null
     */
    private static function user_name($user_id) {
        if (!$user_id || !((defined('WP_CLI') && WP_CLI) || current_user_can('list_users'))) {
            return null;
        }
        $user = get_userdata($user_id);
        return $user ? (string) $user->display_name : null;
    }

    /**
     * An error for a request.
     *
     * @param string $code    Code.
     * @param string $message Message.
     * @param int    $status  HTTP status.
     * @return WP_Error
     */
    private static function error($code, $message, $status = 400) {
        return new WP_Error($code, $message, array('status' => (int) $status));
    }
}
