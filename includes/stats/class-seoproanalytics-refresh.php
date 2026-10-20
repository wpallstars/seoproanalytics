<?php
/**
 * The refresh planner: for a page losing clicks (Opportunities, decay),
 * a proposal from the cause of the loss, the content's age and words
 * (the content audit's page_facts), its conversions and the other pages
 * that overtook it, checked in this order:
 *
 * - leave: fewer people search (the cause is demand) at about the same
 *   position: nothing on the page to fix;
 * - protect: its visits from search convert at least PROTECT_VALUE times
 *   the site's rate (smoothed, the decision queue's value) with at least
 *   PROTECT_CONVERSIONS conversions: change it carefully, one measured
 *   change at a time;
 * - merge: for a query it lost most clicks on, another page of the site
 *   now ranks better and did not before (SEOProAnalytics_Opportunities): make
 *   one page the clear answer;
 * - update: otherwise (lost position or CTR, or no longer shown), with
 *   what to do from the cause and the content's age: changed within the
 *   periods compared (see what the change did), old (over OLD_DAYS days),
 *   or neither.
 *
 * Proposals only: nothing changes the content. The decision queue makes
 * one refresh item per page with content facts in place of its decay item
 * (SEOProAnalytics_Queue). Reads: page_facts by its primary key for the pages
 * given. Design: docs/seo-loop.md → Refresh planner.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 * Provenance: wpas-c8662019b99fb07b. Copies, even of part, keep these lines.
 *
 * @package SEOProAnalytics
 * @since 0.9.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProAnalytics_Refresh {

    /** Proposals, in the order they are checked after leave. */
    const PROPOSALS = array('leave', 'protect', 'merge', 'update');

    /** Content changed longer ago than this many days is old. */
    const OLD_DAYS = 365;

    /** Protect: value at least this (conversion rate × the site's), and at least this many conversions. */
    const PROTECT_VALUE       = 2.0;
    const PROTECT_CONVERSIONS = 3;

    /**
     * Share of the clicks lost a proposal can win back: fewer searches
     * come back with demand, not with a change.
     */
    const SHARE = array('update' => 1.0, 'merge' => 1.0, 'protect' => 1.0, 'leave' => 0.2);

    /** Effort by proposal (1 least). */
    const EFFORT = array('update' => 3, 'merge' => 3, 'protect' => 3, 'leave' => 1);

    /** Ids per read. */
    const CHUNK = 500;

    /**
     * The content facts of some pages, by page_facts's primary key.
     *
     * @param int[] $path_ids Path ids.
     * @return array<int,array{post_id:int,modified:int,published:int,words:int,links_in:int}> Path id => facts.
     */
    public static function facts(array $path_ids) {
        global $wpdb;
        $path_ids = array_values(array_unique(array_filter(array_map('intval', $path_ids))));
        if (!$path_ids || !SEOProAnalytics_Schema::is_current()) {
            return array();
        }
        $table = SEOProAnalytics_Schema::table('page_facts');
        $out   = array();
        foreach (array_chunk($path_ids, self::CHUNK) as $chunk) {
            $holders = implode(', ', array_fill(0, count($chunk), '%d'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table, by its primary key; $holders holds only placeholders.
            foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT path_id, post_id, modified, published, words, links_in FROM %i WHERE path_id IN ($holders)", array_merge(array($table), $chunk)), ARRAY_A) as $row) {
                $out[(int) $row['path_id']] = array(
                    'post_id'   => (int) $row['post_id'],
                    'modified'  => (int) $row['modified'],
                    'published' => (int) $row['published'],
                    'words'     => (int) $row['words'],
                    'links_in'  => (int) $row['links_in'],
                );
            }
        }
        return $out;
    }

    /**
     * The proposal for a losing page, with its figures.
     *
     * @param array<string,mixed>                                          $row    Decay row (SEOProAnalytics_Opportunities).
     * @param array{post_id:int,modified:int,published:int,words:int,links_in:int} $facts  From facts().
     * @param array{visits:int,conversions:int}|null                      $page   The page's visits from search and conversions; null without goal data.
     * @param float                                                        $value  The page's value (the queue's).
     * @param int                                                          $days   Days in each period compared.
     * @return array{proposal:string,figures:array<string,mixed>}
     */
    public static function propose(array $row, array $facts, $page, $value, $days) {
        $now      = time();
        $modified = (int) $facts['modified'];
        $age      = $modified ? max(0, (int) floor(($now - $modified) / DAY_IN_SECONDS)) : null;
        list($lost, $rival) = self::lost_queries((array) $row['queries']);
        $proposal = self::proposal($row, $page, $value, $rival);

        $figures = array(
            'proposal'      => $proposal,
            'clicks'        => (int) $row['clicks'],
            'then_clicks'   => (int) $row['compare']['clicks'],
            'lost'          => (int) $row['lost'],
            'impressions'   => (int) $row['impressions'],
            'then_impressions' => (int) $row['compare']['impressions'],
            'position'      => $row['impressions'] ? $row['position'] : null,
            'then_position' => $row['compare']['position'],
            'cause'         => (string) $row['cause'],
            'modified'      => $modified ? gmdate('c', $modified) : null,
            'age'           => $age,
            'old'           => $age !== null && $age > self::OLD_DAYS,
            'changed'       => $age !== null && $age <= 2 * max(1, (int) $days),
            'published'     => (int) $facts['published'] ? gmdate('c', (int) $facts['published']) : null,
            'words'         => (int) $facts['words'],
            'links_in'      => (int) $facts['links_in'],
            'visits'        => $page ? (int) $page['visits'] : null,
            'conversions'   => $page ? (int) $page['conversions'] : null,
            'share'         => self::SHARE[$proposal],
            'lost_queries'  => $lost,
        );
        if (isset($row['year_earlier'])) {
            $figures['year_earlier'] = $row['year_earlier'];
            $figures['seasonal'] = empty($row['year_earlier']['losing']);
        }
        // proposal() gives merge only with a rival.
        if ($proposal === 'merge' && $rival !== null) {
            $figures['rival'] = $rival;
            // Both pages, so done measures both (only this page's running experiment holds the item).
            $figures['pages'] = self::merge_pages($row, $rival, $figures['position']);
        }
        return array('proposal' => $proposal, 'figures' => $figures);
    }

    /**
     * The queries a page lost clicks on, as figures, and the first one's
     * rival (the page that took its clicks) with this page's positions.
     *
     * @param array<int,array<string,mixed>> $queries Decay row's queries.
     * @return array{0:array<int,array<string,mixed>>,1:array<string,mixed>|null} Lost queries, rival.
     */
    private static function lost_queries(array $queries) {
        $lost  = array();
        $rival = null;
        foreach ($queries as $query) {
            $lost[] = array(
                'query'         => (string) $query['query'],
                'lost'          => (int) $query['lost'],
                'position'      => $query['position'],
                'then_position' => $query['then_position'],
                'rival'         => empty($query['rival']) ? null : array(
                    'path'          => (string) $query['rival']['path'],
                    'clicks'        => (int) $query['rival']['clicks'],
                    'position'      => $query['rival']['position'],
                    'then_position' => $query['rival']['then_position'],
                    'share'         => $query['rival']['share'],
                ),
            );
            if ($rival === null && !empty($query['rival'])) {
                $rival = array('query' => (string) $query['query'], 'position_here' => $query['position'], 'then_position_here' => $query['then_position']) + (array) $query['rival'];
            }
        }
        return array($lost, $rival);
    }

    /**
     * The proposal: leave a page whose demand fell, protect one whose
     * visits from search convert, merge one a rival overtook, else update.
     *
     * @param array<string,mixed>                     $row   Decay row.
     * @param array{visits:int,conversions:int}|null  $page  The page's visits from search and conversions.
     * @param float                                   $value The page's value.
     * @param array<string,mixed>|null                $rival From lost_queries().
     * @return string leave, protect, merge or update.
     */
    private static function proposal(array $row, $page, $value, $rival) {
        $converts = $page && $value >= self::PROTECT_VALUE && (int) $page['conversions'] >= self::PROTECT_CONVERSIONS;
        if ($row['cause'] === 'demand' || (isset($row['year_earlier']) && empty($row['year_earlier']['losing']))) {
            return 'leave';
        }
        if ($converts) {
            return 'protect';
        }
        return $rival ? 'merge' : 'update';
    }

    /**
     * A merge's two pages, this one and its rival, so done measures both.
     *
     * @param array<string,mixed> $row      Decay row.
     * @param array<string,mixed> $rival    From lost_queries().
     * @param float|null          $position This page's position (null without impressions).
     * @return array<int,array<string,mixed>>
     */
    private static function merge_pages(array $row, array $rival, $position) {
        return array(
            array('path_id' => (int) $row['path_id'], 'path' => (string) $row['path'], 'clicks' => (int) $row['clicks'], 'impressions' => (int) $row['impressions'], 'position' => $position, 'share' => null),
            array('path_id' => (int) $rival['path_id'], 'path' => (string) $rival['path'], 'clicks' => (int) $rival['clicks'], 'impressions' => (int) $rival['impressions'], 'position' => $rival['position'], 'share' => $rival['share']),
        );
    }

    /**
     * Why the page gets its proposal, with the numbers behind it.
     *
     * @param array<string,mixed> $row       Decay row.
     * @param array<string,mixed> $figures   From propose().
     * @param float|null          $site_rate The site's conversion rate of visits from search.
     * @return string
     */
    public static function why(array $row, array $figures, $site_rate) {
        $num = static function ($value) {
            return number_format_i18n((int) $value);
        };
        $pos = static function ($value) {
            return number_format_i18n((float) $value, 1);
        };
        $pct = static function ($value) {
            return number_format_i18n(100 * (float) $value, 1) . '%';
        };
        /* translators: 1: clicks lost, 2: clicks before, 3: clicks now, 4: the likely cause in a sentence */
        $text = sprintf(__('Lost %1$s clicks (%2$s, now %3$s). %4$s', 'seoproanalytics'), $num($figures['lost']), $num($figures['then_clicks']), $num($figures['clicks']), (string) $row['why']);
        if (!empty($figures['seasonal'])) {
            /* translators: 1: previous-period clicks, 2: current clicks, 3: year-earlier clicks */
            return sprintf(__('Down on the previous period (%1$s → %2$s clicks) but not losing by the same rules against a year earlier (%3$s → %2$s): seasonal.', 'seoproanalytics'), $num($figures['then_clicks']), $num($figures['clicks']), $num($figures['year_earlier']['then_clicks']));
        }
        if (isset($figures['year_earlier'])) {
            /* translators: 1: year-earlier clicks, 2: current clicks */
            $text .= ' ' . sprintf(__('Also down on a year earlier (%1$s → %2$s clicks).', 'seoproanalytics'), $num($figures['year_earlier']['then_clicks']), $num($figures['clicks']));
        }
        $proposal = (string) $figures['proposal'];
        if ($proposal === 'protect') {
            /* translators: 1: conversions, 2: visits from search, 3: the page's conversion rate, 4: the site's */
            $text .= ' ' . sprintf(__('Its visits from search convert well: %1$s conversions in %2$s visits (%3$s, the site %4$s).', 'seoproanalytics'), $num($figures['conversions']), $num($figures['visits']), $pct((int) $figures['conversions'] / max(1, (int) $figures['visits'])), $pct((float) $site_rate));
        } elseif ($proposal === 'merge') {
            $rival = (array) $figures['rival'];
            $then  = $rival['then_position'] === null
                ? __('not shown before', 'seoproanalytics')
                /* translators: %s: average position before */
                : sprintf(__('was %s', 'seoproanalytics'), $pos($rival['then_position']));
            /* translators: 1: search query, 2: the other page's path, 3: its average position now, 4: its position before (e.g. "was 12.1"), 5: this page's position now */
            $text .= ' ' . sprintf(__('For “%1$s”, %2$s now ranks %3$s (%4$s), ahead of this page at %5$s.', 'seoproanalytics'), (string) $rival['query'], (string) $rival['path'], $pos($rival['position']), $then, $rival['position_here'] === null ? '–' : $pos($rival['position_here']));
        }
        if ($figures['age'] !== null && $proposal !== 'leave') {
            /* translators: 1: days since the content changed, 2: words */
            $text .= ' ' . sprintf(_n('Content changed %1$s day ago; %2$s words.', 'Content changed %1$s days ago; %2$s words.', (int) $figures['age'], 'seoproanalytics'), $num($figures['age']), $num($figures['words']));
        }
        return $text;
    }

    /**
     * What to do about the proposal.
     *
     * @param array<string,mixed> $figures From propose().
     * @return string
     */
    public static function todo(array $figures) {
        $proposal = (string) $figures['proposal'];
        if (!empty($figures['seasonal'])) {
            return __('Leave it: the fall looks seasonal against a year earlier. Review it in season before changing the page.', 'seoproanalytics');
        }
        if ($proposal === 'leave') {
            return __('Leave it: fewer people search for it and it still ranks where it did. Change it only if its position falls too.', 'seoproanalytics');
        }
        if ($proposal === 'protect') {
            return __('Change it carefully: one small change at a time, each measured, keeping what makes its visitors convert.', 'seoproanalytics');
        }
        if ($proposal === 'merge') {
            /* translators: %s: the other page's path */
            return sprintf(__('Make one page the clear answer: merge this one into %s (move what only it has, then redirect), or make each answer a different need and link between them.', 'seoproanalytics'), (string) $figures['rival']['path']);
        }
        if ($figures['cause'] === 'gone') {
            return __('Check that search can still show it (noindex, canonical, redirects), then update it and ask for a crawl.', 'seoproanalytics');
        }
        if ($figures['cause'] === 'ctr') {
            return __('Update the title and description so searchers choose it again; check what the results around it now offer.', 'seoproanalytics');
        }
        if (!empty($figures['changed'])) {
            return __('It changed while it lost clicks: see what the change removed (Changes), and restore or improve it for the searches it lost.', 'seoproanalytics');
        }
        if (!empty($figures['old'])) {
            return __('Bring it up to date: current facts, examples and the questions its searches ask now.', 'seoproanalytics');
        }
        return __('Update it for the searches it lost: answer them better than the pages now above it, and link to it from related pages.', 'seoproanalytics');
    }
}
