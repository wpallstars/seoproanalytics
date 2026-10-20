<?php
/**
 * Import a crawl of the site made elsewhere (LibreCrawl, the aidevops site
 * crawler, or any CSV or JSON with an address and status column): stage
 * the files in non-autoloaded option chunks, then write them in budgeted
 * cron batches (and while an administrator watches the progress), as the
 * backlink export import does. An explicitly enabled built-in crawl feeds
 * this same pipeline; imports and the optional server connection are separate.
 *
 * Each import is an imports row (source crawl), so it can be undone. Its
 * pages replace the crawl_pages of earlier imports, and its links their
 * crawl_links, once it is written: the newest crawl is the one shown;
 * prev_status and prev_flags keep one step of history, and a page whose
 * answer changed gets a served change on the timeline. Importing the
 * same files again changes nothing.
 *
 * Formats and rows: SEOProAnalytics_Crawl_CSV. Reports and findings:
 * SEOProAnalytics_Crawl. Design: docs/seo-loop.md → Imported crawls.
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

final class SEOProAnalytics_Crawl_Import {

    /** The job (autoload off): status, id, format, files, kinds, total, done, pages, links, skipped, changes, chunks, phase, started, error. */
    const OPTION = 'seoproanalytics_crawl_import';

    /** imports.source of a crawl import. */
    const SOURCE = 'crawl';

    /** imports.status codes, as SEOProAnalytics_Search_Import's. */
    const RUNNING = 1;
    const DONE    = 2;
    const FAILED  = 3;
    const UNDONE  = 4;

    /** Formats: LibreCrawl's exports, the aidevops site crawler's CSVs, and any other CSV or JSON. */
    const FORMATS = array('librecrawl', 'aidevops', 'csv');

    /** Most page rows and link rows read from one import, bytes per file, and files. */
    const MAX_PAGES = 200000;
    const MAX_LINKS = 500000;
    const MAX_BYTES = 52428800;
    const MAX_FILES = 10;

    /** Rows per staged chunk (and per write), rows deleted per query, and seconds of work per cron run and per status poll. */
    const BATCH       = 500;
    const DELETE      = 1000;
    const BUDGET      = 20;
    const POLL_BUDGET = 4;

    /** Most served changes one import puts on the timeline. */
    const MAX_CHANGES = 500;

    /** Flagged pages whose text is kept: a strict storage budget for imported crawls. */
    const MAX_TEXT = 500;

    /** Cron hook, only while a job runs. */
    const HOOK = 'seoproanalytics_crawl_import';

    // ------------------------------------------------------------------
    // The job.

    /**
     * The job's progress, never its staged rows.
     *
     * @return array<string,mixed>
     */
    public static function status() {
        $job = get_option(SEOProAnalytics_Schema::option(self::OPTION), array());
        return (is_array($job) ? $job : array()) + array(
            'status'  => 'idle',
            'id'      => 0,
            'format'  => '',
            'files'   => array(),
            'kinds'   => array('pages' => false, 'links' => false),
            'read'    => 0,
            'total'   => 0,
            'done'    => 0,
            'pages'   => 0,
            'links'   => 0,
            'skipped' => 0,
            'changes' => 0,
            'chunks'  => 0,
            'phase'   => 'rows',
            'started' => 0,
        );
    }

    /**
     * Stage files or rows and start the job (live data only).
     *
     * @param array<int,array<string,mixed>> $sources Each name, and stream (an open CSV or JSON file) or rows (decoded rows).
     * @param string                         $format  One of FORMATS, or '' to tell by the columns.
     * @param bool                           $complete Owner confirms the upload covered the whole site.
     * @param array<string,mixed>             $record LibreCrawl's completed crawl record, when supplied by a source.
     * @param string                         $origin  server for an authenticated server import, otherwise upload.
     * @param bool                           $partial Only replace covered pages (at most 100).
     * @return array<string,mixed>|WP_Error The job.
     */
    public static function start(array $sources, $format = '', $complete = false, array $record = array(), $origin = 'upload', $partial = false) {
        self::load();
        $format = (string) $format;
        if (!SEOProAnalytics_Schema::maybe_upgrade() || SEOProAnalytics_Schema::set() !== 'live') {
            return new WP_Error('seoproanalytics_crawl_schema', __('Import a crawl into the live data only.', 'seoproanalytics'), array('status' => 409));
        }
        if ($format !== '' && !in_array($format, self::FORMATS, true)) {
            /* translators: %s: list of formats */
            return new WP_Error('seoproanalytics_crawl_format', sprintf(__('The format is one of: %s.', 'seoproanalytics'), implode(', ', self::FORMATS)), array('status' => 400));
        }
        if (!$sources || count($sources) > self::MAX_FILES) {
            /* translators: %d: most files */
            return new WP_Error('seoproanalytics_crawl_files', sprintf(__('Import from 1 to %d files of one crawl together.', 'seoproanalytics'), self::MAX_FILES), array('status' => 400));
        }
        if (!self::lock()) {
            return new WP_Error('seoproanalytics_crawl_busy', __('A crawl import is busy.', 'seoproanalytics'), array('status' => 409));
        }
        try {
            if (in_array(self::status()['status'], array('running', 'restoring'), true)) {
                return new WP_Error('seoproanalytics_crawl_busy', __('A crawl import is running.', 'seoproanalytics'), array('status' => 409));
            }
            // LibreCrawl 05a1372de833: crawl_db.get_crawl() decodes
            // config_snapshot; crawler.py stores config.max_urls (not maxUrls).
            // List records omit that snapshot and cannot establish completeness.
            $limit = (int) ($record['config_snapshot']['max_urls'] ?? 0);
            $covered = $record ? ($format === 'librecrawl' && ($record['status'] ?? '') === 'completed' && isset($record['urls_crawled']) && (int) $record['urls_crawled'] > 0 && $limit > (int) $record['urls_crawled']) : $complete === true;
            if ($origin === 'builtin') {
                $covered = $complete === true;
            }
            $job = self::stage($sources, $format, $covered && !$partial, in_array($origin, array('server', 'builtin'), true) ? $origin : 'upload', (bool) $partial);
            if (!is_wp_error($job) && in_array($origin, array('server', 'builtin'), true) && !empty($record['verification_started'])) {
                $job['observed_at'] = min(time(), (int) $record['verification_started']);
                update_option(SEOProAnalytics_Schema::option(self::OPTION), $job, false);
            }
            return $job;
        } finally {
            self::unlock();
        }
    }

    /**
     * Write staged rows for up to budget seconds (cron, WP-CLI, and each
     * status poll while an administrator watches).
     *
     * @param int $budget Seconds.
     * @return array<string,mixed> The job.
     */
    public static function run($budget = self::BUDGET) {
        self::load();
        $job = self::status();
        if (in_array($job['status'], array('running', 'restoring'), true) && !wp_next_scheduled(self::HOOK)) {
            // Recovery is scheduled before the work, so a run that dies resumes.
            wp_schedule_single_event(time() + MINUTE_IN_SECONDS, self::HOOK);
        }
        if (!in_array($job['status'], array('running', 'restoring'), true) || !self::lock()) {
            return $job;
        }
        try {
            if ($job['status'] === 'restoring') {
                self::undo_locked((int) $job['id']);
                return self::status();
            }
            return self::work($budget);
        } finally {
            self::unlock();
        }
    }

    /**
     * Work under the lock: rows, then removing the earlier imports' rows,
     * then the import's record.
     *
     * @param int $budget Seconds.
     * @return array<string,mixed>
     */
    private static function work($budget) {
        $job   = self::status();
        $start = microtime(true);
        if ($job['status'] === 'running' && $job['phase'] !== 'cleanup' && (int) $job['started'] < time() - DAY_IN_SECONDS) {
            self::fail($job, __('The staged crawl expired. Its rows are removed; import the files again.', 'seoproanalytics'));
        }
        while ($job['status'] === 'running' && SEOProAnalytics_Feature::more_time($start, $budget)) {
            if ($job['phase'] === 'prepare_partial') {
                self::prepare_partial($job);
            } elseif ($job['phase'] === 'verify') {
                require_once __DIR__ . '/class-seoproanalytics-queue.php';
                if (SEOProAnalytics_Queue::verify_crawl($job)) {
                    self::finish($job);
                }
            } elseif ($job['phase'] === 'rows') {
                self::step($job);
            } elseif ($job['phase'] === 'counts') {
                self::apply_counts($job);
            } elseif ($job['phase'] === 'count_cleanup') {
                if (self::clear_counts()) {
                    $job['phase'] = 'prune';
                }
            } elseif ($job['phase'] === 'cleanup') {
                self::cleanup($job);
            } elseif (self::prune($job)) {
                SEOProAnalytics_Crawl::touch();
                $job['phase'] = 'verify';
            }
            update_option(SEOProAnalytics_Schema::option(self::OPTION), $job, false);
        }
        SEOProAnalytics_Crawl::touch();
        if ($job['status'] !== 'running') {
            update_option(SEOProAnalytics_Schema::option(self::OPTION), $job, false);
            wp_clear_scheduled_hook(self::HOOK);
            self::clear_chunks((int) $job['chunks']);
        }
        return $job;
    }

    /**
     * Write the next staged chunk and forget it.
     *
     * @param array<string,mixed> $job Job, updated.
     */
    private static function step(array &$job) {
        $index = (int) floor($job['done'] / self::BATCH);
        $rows  = get_option(SEOProAnalytics_Schema::option(self::OPTION . '_' . $index), null);
        if (!is_array($rows)) {
            // Half an import would show two crawls mixed: take back what it wrote, as undo does.
            self::fail($job, __('A staged part of the import was lost, so what it had written is removed; import the files again.', 'seoproanalytics'));
            return;
        }
        $rows   = array_slice($rows, (int) $job['done'] % self::BATCH);
        $pages  = array();
        $links  = array();
        foreach ($rows as $row) {
            if (is_array($row) && isset($row[0]) && $row[0] === 'p') {
                $pages[] = $row;
            } elseif (is_array($row) && isset($row[0]) && $row[0] === 'l') {
                $links[] = $row;
            }
        }
        $room    = max(0, self::MAX_CHANGES - (int) $job['changes']);
        $written = self::write_pages($pages, (int) $job['id'], (int) $job['started'], $room, (string) $job['format'], max(0, self::MAX_TEXT - (int) ($job['text_kept'] ?? 0)), !empty($job['partial']));
        $job['pages']   += $written['pages'];
        $job['changes'] += $written['changes'];
        $job['text_kept'] = (int) ($job['text_kept'] ?? 0) + $written['text_kept'];
        if (empty($job['partial']) && !self::count_links($links)) {
            self::fail($job, __('Incoming link counts could not be staged.', 'seoproanalytics'));
            return;
        }
        $kept            = self::write_links($links, (int) $job['id']);
        $job['links']   += $kept;
        $job['skipped'] += count($links) - $kept;
        $job['done']    += count($rows);
        delete_option(SEOProAnalytics_Schema::option(self::OPTION . '_' . $index));
        if ($job['done'] >= $job['total']) {
            $job['phase'] = empty($job['partial']) && !empty($job['kinds']['links']) ? 'counts' : 'prune';
        }
    }

    /**
     * Count one staged batch, retaining only temporary distinct source sets
     * per target path, never permanent graph rows. At most two capped sets
     * for one target are resident; evict option-cache entries after each use.
     * Replaying a batch after interruption is idempotent.
     *
     * @param array<int,array<int,mixed>> $rows Staged links.
     * @return bool Whether all changed source sets were saved.
     */
    private static function count_links(array $rows) {
        $paths = array();
        foreach ($rows as $row) {
            if ((int) $row[3] === 1) {
                $paths[] = (string) $row[1];
                $paths[] = rawurldecode((string) $row[2]);
            }
        }
        if (!$paths) {
            return true;
        }
        $ids = SEOProAnalytics_Dict::ids(SEOProAnalytics_Schema::DICT_PATH, $paths);
        $by = array();
        foreach ($rows as $row) {
            $from = (int) ($ids[(string) $row[1]] ?? 0);
            $to = (int) ($ids[rawurldecode((string) $row[2])] ?? 0);
            if ((int) $row[3] === 1 && $from && $to && $from !== $to) {
                $by[$to][$from] = ($by[$to][$from] ?? 0) | ((int) $row[6] === 3 ? 3 : 1);
            }
        }
        foreach ($by as $to => $sources) {
            $key = SEOProAnalytics_Schema::option(self::OPTION . '_count_' . $to);
            $sets = get_option($key, array(array(), array()));
            $changed = false;
            foreach ($sources as $from => $bits) {
                foreach (array(0 => 1, 1 => 2) as $at => $bit) {
                    if (($bits & $bit) && count($sets[$at]) < 65535 && !isset($sets[$at][$from])) {
                        $sets[$at][$from] = 1;
                        $changed = true;
                    }
                }
            }
            if ($changed && !update_option($key, $sets, false)) {
                return false;
            }
            wp_cache_delete($key, 'options');
        }
        return true;
    }

    /**
     * Merge counts into the latest page rows, in primary-key batches. A
     * supplied links export makes absent targets zero; without one they
     * remain NULL. Keep temporary sets until all writes finish for replay.
     *
     * @param array<string,mixed> $job Job, updated.
     */
    private static function apply_counts(array &$job) {
        global $wpdb;
        $cursor = (int) ($job['count_cursor'] ?? 0);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- primary-key cursor, one bounded batch.
        $ids = $wpdb->get_col($wpdb->prepare('SELECT path_id FROM %i WHERE path_id > %d ORDER BY path_id LIMIT %d', SEOProAnalytics_Schema::table('crawl_pages'), $cursor, self::BATCH));
        if ($wpdb->last_error !== '') {
            self::fail($job, __('Incoming link counts could not be saved.', 'seoproanalytics'));
            return;
        }
        foreach ((array) $ids as $id) {
            $key = SEOProAnalytics_Schema::option(self::OPTION . '_count_' . (int) $id);
            $sets = get_option($key, array(array(), array()));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- primary-key update, no visitor work.
            $ok = $wpdb->update(SEOProAnalytics_Schema::table('crawl_pages'), array('links_in' => count($sets[0]), 'links_in_body' => count($sets[1])), array('path_id' => (int) $id), array('%d', '%d'), array('%d'));
            wp_cache_delete($key, 'options');
            if ($ok === false) {
                self::fail($job, __('Incoming link counts could not be saved.', 'seoproanalytics'));
                return;
            }
            $job['count_cursor'] = (int) $id;
        }
        if (count((array) $ids) < self::BATCH) {
            $job['phase'] = 'count_cleanup';
        }
    }

    /**
     * Remove one bounded batch of temporary target sets, including targets
     * the crawl did not visit. The prefix is shared by reset/undo cleanup.
     *
     * @return bool Whether all sets are gone.
     */
    private static function clear_counts() {
        global $wpdb;
        $prefix = SEOProAnalytics_Schema::option(self::OPTION . '_count_');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- indexed option_name prefix, bounded cleanup.
        $names = (array) $wpdb->get_col($wpdb->prepare("SELECT option_name FROM $wpdb->options WHERE option_name LIKE %s LIMIT %d", $wpdb->esc_like($prefix) . '%', self::BATCH));
        foreach ($names as $name) {
            delete_option((string) $name);
        }
        return count($names) < self::BATCH;
    }

    /**
     * Begin recoverable failure cleanup.
     *
     * @param array<string,mixed> $job Job.
     * @param string              $error Message.
     */
    private static function fail(array &$job, $error) {
        $job['phase'] = 'cleanup';
        $job['error'] = $error;
        $job['totals'] = null;
        $job['cleanup_table'] = 0;
        $job['cleanup_chunk'] = (int) floor($job['done'] / self::BATCH);
    }

    /**
     * One failure-cleanup batch; work() persists progress and enforces its budget.
     *
     * @param array<string,mixed> $job Job.
     */
    private static function cleanup(array &$job) {
        global $wpdb;
        $tables = array('crawl_pages', 'crawl_links');
        $at = (int) $job['cleanup_table'];
        if (isset($tables[$at])) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- indexed import_id, one bounded cleanup batch.
            $gone = $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE import_id = %d LIMIT %d', SEOProAnalytics_Schema::table($tables[$at]), (int) $job['id'], self::DELETE));
            if ($gone !== false && $gone < self::DELETE) {
                ++$job['cleanup_table'];
            }
            return;
        }
        if (!empty($job['partial']) && !self::restore_partial($job)) {
            return;
        }
        if ((int) $job['cleanup_chunk'] < (int) $job['chunks']) {
            delete_option(SEOProAnalytics_Schema::option(self::OPTION . '_' . (int) $job['cleanup_chunk']));
            ++$job['cleanup_chunk'];
            return;
        }
        $job['chunks'] = 0;
        if (!self::clear_counts()) {
            return;
        }
        $job['status'] = 'error';
        SEOProAnalytics_Crawl::recount(!empty($job['partial']) ? (array) $job['before_state'] : array('complete' => false, 'link_counts' => false));
        self::record($job, self::FAILED);
    }

    /**
     * Delete the rows of earlier imports this one replaces: its pages
     * replace theirs when it had a pages file, its links when it had a
     * links file.
     *
     * @param array<string,mixed> $job Job.
     * @return bool Whether none are left.
     */
    private static function prune(array $job) {
        global $wpdb;
        if (!empty($job['partial'])) {
            if (!empty($job['kinds']['links']) && empty($job['links_uncertain'])) {
                foreach ($job['covered'] as $id) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- covered source only, bounded primary-key delete, replayable after interruption.
                    $gone = $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE from_path_id = %d AND import_id < %d LIMIT %d', SEOProAnalytics_Schema::table('crawl_links'), (int) $id, (int) $job['id'], self::DELETE));
                    if ($gone === false || $gone >= self::DELETE) {
                        return false;
                    }
                }
            }
            return true;
        }
        foreach (array('pages' => 'crawl_pages', 'links' => 'crawl_links') as $kind => $table) {
            if (empty($job['kinds'][$kind])) {
                continue;
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, by its import_id key, in batches.
            $gone = (int) $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE import_id < %d LIMIT %d', SEOProAnalytics_Schema::table($table), (int) $job['id'], self::DELETE));
            if ($gone >= self::DELETE) {
                return false;
            }
        }
        return true;
    }

    /**
     * The import is written: record it, and count what the crawl found.
     *
     * @param array<string,mixed> $job Job, updated.
     */
    private static function finish(array &$job) {
        $job['status'] = 'done';
        // Each successfully written chunk was already removed in step().
        $job['chunks'] = 0;
        SEOProAnalytics_Crawl::recount(!empty($job['partial']) ? array() : array(
            'import' => (int) $job['id'],
            'format' => (string) $job['format'],
            'files'  => (array) $job['files'],
            'read'   => time(),
            'complete' => !empty($job['complete']),
            'link_counts' => !empty($job['kinds']['links']),
        ));
        $job['totals'] = !empty($job['partial']) ? array('pages' => (int) $job['pages'], 'links' => (int) $job['links'], 'duration' => max(0, time() - (int) $job['started']), 'complete' => false) : SEOProAnalytics_Crawl::totals(max(0, time() - (int) $job['started']));
        self::record($job, self::DONE);
    }

    /**
     * Update the import's imports row.
     *
     * @param array<string,mixed> $job    Job.
     * @param int                 $status DONE or FAILED.
     */
    private static function record(array $job, $status) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, by primary key.
        $wpdb->update(SEOProAnalytics_Schema::table('imports'), array(
            'status'     => (int) $status,
            'finished'   => time(),
            'rows_added' => (int) $job['pages'] + (int) $job['links'],
            'meta'       => (string) wp_json_encode(self::meta($job)),
        ), array('id' => (int) $job['id']), array('%d', '%d', '%d', '%s'), array('%d'));
    }

    /**
     * What an imports row keeps of its job.
     *
     * @param array<string,mixed> $job Job.
     * @return array<string,mixed>
     */
    private static function meta(array $job) {
        return array(
            'format'  => (string) $job['format'],
            'origin'  => (string) ($job['origin'] ?? 'upload'),
            'partial' => !empty($job['partial']),
            'files'   => array_values((array) $job['files']),
            'kinds'   => (array) $job['kinds'],
            'read'    => (int) $job['read'],
            'pages'   => (int) $job['pages'],
            'links'   => (int) $job['links'],
            'skipped' => (int) $job['skipped'],
            'changes' => (int) $job['changes'],
            // No totals for an incomplete import, including a lost staged chunk.
            'totals'  => $job['status'] === 'done' && isset($job['totals']) ? $job['totals'] : null,
            'error'   => isset($job['error']) ? (string) $job['error'] : '',
        );
    }

    // ------------------------------------------------------------------
    // Staging.

    /**
     * Stage every file: pages files first, so links to the site's own
     * pages know whether those redirect or fail.
     *
     * @param array<int,array<string,mixed>> $sources Sources.
     * @param string                         $format  Format, or ''.
     * @param bool                           $complete Owner confirmation.
     * @param string                         $origin  server or upload.
     * @param bool                           $partial Covered pages only.
     * @return array<string,mixed>|WP_Error
     */
    private static function stage(array $sources, $format, $complete, $origin, $partial) {
        // Also clear orphan chunks left by a process that died while staging,
        // before it could publish the job's chunk count.
        self::clear_orphans();
        $cleared = self::clear_counts();
        while (!$cleared) {
            $cleared = self::clear_counts();
        }
        $stage = array('started' => time(), 'chunk' => array(), 'chunks' => 0, 'total' => 0, 'read' => array('pages' => 0, 'links' => 0), 'skipped' => 0, 'seen' => array(), 'pages' => array(), 'kinds' => array('pages' => false, 'links' => false), 'formats' => array(), 'files' => array());
        $stage['origin'] = $origin;
        $stage['partial'] = $partial;
        $stage['observed'] = null;
        $stage['links_uncertain'] = false;
        foreach (array('pages', 'links') as $want) {
            foreach ($sources as $source) {
                $done = self::stage_source($source, $want, $format, $stage);
                if (is_wp_error($done)) {
                    self::clear_chunks($stage['chunks'] + 1);
                    return $done;
                }
            }
        }
        if (!$stage['kinds']['pages'] && !$stage['kinds']['links']) {
            return new WP_Error('seoproanalytics_crawl_header', __('No file had an address column (pages) or a source and target column (links).', 'seoproanalytics'), array('status' => 400));
        }
        if ($stage['chunk']) {
            self::save($stage['chunk'], $stage['chunks']++);
        }
        if ($partial && (!$stage['kinds']['pages'] || count($stage['pages']) > 100)) {
            self::clear_chunks($stage['chunks']);
            return new WP_Error('seoproanalytics_crawl_partial', __('A partial crawl needs pages and covers at most 100 addresses.', 'seoproanalytics'), array('status' => 400));
        }
        $stage['complete'] = $complete && $stage['kinds']['pages'];
        return self::open_job($stage, $format !== '' ? $format : self::format_of($stage['formats']));
    }

    /**
     * Stage one source when it holds the kind of rows wanted now.
     *
     * @param array<string,mixed> $source Source.
     * @param string              $want   pages or links.
     * @param string              $format Format, or ''.
     * @param array<string,mixed> $stage  Staging state, updated.
     * @return true|WP_Error
     */
    private static function stage_source(array $source, $want, $format, array &$stage) {
        $reader = self::reader($source);
        if (is_wp_error($reader)) {
            return $reader;
        }
        $kind = SEOProAnalytics_Crawl_CSV::kind($reader['headers']);
        $name = isset($source['name']) ? sanitize_file_name((string) $source['name']) : '';
        if ($kind === '') {
            /* translators: %s: file name */
            return new WP_Error('seoproanalytics_crawl_header', sprintf(__('%s has no address column (pages) or source and target columns (links).', 'seoproanalytics'), $name !== '' ? $name : __('A file', 'seoproanalytics')), array('status' => 400));
        }
        if ($kind !== $want) {
            return true;
        }
        $found = $format !== '' ? $format : SEOProAnalytics_Crawl_CSV::detect($reader['headers']);
        $stage['kinds'][$kind]    = true;
        $stage['formats'][]       = $found;
        $stage['files'][]         = array('name' => $name, 'kind' => $kind, 'format' => $found);
        if ($kind === 'pages') {
            $fields = array_keys(array_filter(SEOProAnalytics_Crawl_CSV::HEADERS, static function ($names) use ($reader) { return (bool) array_intersect($names, $reader['headers']); }));
            $stage['observed'] = $stage['observed'] === null ? $fields : array_values(array_intersect($stage['observed'], $fields));
        }
        $most                     = $kind === 'pages' ? self::MAX_PAGES : self::MAX_LINKS;
        while (($row = $reader['next']()) !== false) {
            if (++$stage['read'][$kind] > $most || (isset($source['stream']) && is_resource($source['stream']) && ftell($source['stream']) > self::MAX_BYTES)) {
                /* translators: 1: most page rows, 2: most link rows, 3: most megabytes */
                return new WP_Error('seoproanalytics_crawl_size', sprintf(__('Import at most %1$s page rows and %2$s link rows, and %3$d MB per file.', 'seoproanalytics'), number_format_i18n(self::MAX_PAGES), number_format_i18n(self::MAX_LINKS), self::MAX_BYTES / MB_IN_BYTES), array('status' => 400));
            }
            $cells = SEOProAnalytics_Crawl_CSV::cells($row);
            $out   = $kind === 'pages' ? SEOProAnalytics_Crawl_CSV::page($cells, $found) : SEOProAnalytics_Crawl_CSV::link($cells, $stage['seen'], false);
            if ($kind === 'links') {
                $status_cells = array_intersect_key($cells, array_flip(SEOProAnalytics_Crawl_CSV::HEADERS['target_status']));
                $status = strtolower(trim((string) (reset($status_cells) ?: '')));
                $unknown = $status === '' || $status === SEOProAnalytics_Crawl_CSV::NOT_CRAWLED;
                if ($out !== null && $out[3] && $out[4] === SEOProAnalytics_Crawl_CSV::UNKNOWN && isset($stage['pages'][rawurldecode((string) $out[2])])) {
                    $out[4] = 200; // A successful page in this import, not an unvisited destination.
                    $unknown = false;
                }
                if ($unknown && ($out === null || $out[4] === SEOProAnalytics_Crawl_CSV::UNKNOWN)) {
                    $stage['links_uncertain'] = true;
                }
            }
            if ($out === null) {
                ++$stage['skipped'];
                continue;
            }
            if ($kind === 'pages') {
                // Intersect actual non-null row fields as well as headers: heterogeneous
                // JSON rows must not inherit observations from the first row.
                $present = array_keys(SEOProAnalytics_Crawl_CSV::cells(array_filter($row, static function ($value) { return $value !== null; })));
                $stage['observed'] = array_values(array_filter((array) $stage['observed'], static function ($field) use ($present) { return (bool) array_intersect(SEOProAnalytics_Crawl_CSV::HEADERS[$field], $present); }));
            }
            if (!empty($stage['partial']) && $kind === 'links' && !isset($stage['pages'][(string) $out[1]])) {
                ++$stage['skipped'];
                continue;
            }
            if ($kind === 'pages' && ($out[2] < 200 || $out[2] >= 300 || $out[7] > 0)) {
                $stage['seen'][$out[1]] = array($out[2], $out[7]);
            }
            if (!empty($stage['partial']) && $kind === 'pages' && !isset($stage['pages'][(string) $out[1]]) && count($stage['pages']) >= 100) {
                return new WP_Error('seoproanalytics_crawl_partial', __('A partial crawl covers at most 100 addresses.', 'seoproanalytics'), array('status' => 400));
            }
            if ($kind === 'pages' && self::stage_page($out, $stage)) {
                continue;
            }
            $stage['chunk'][] = $out;
            ++$stage['total'];
            if (count($stage['chunk']) === self::BATCH) {
                self::save($stage['chunk'], $stage['chunks']++);
                $stage['chunk'] = array();
            }
        }
        return true;
    }

    /**
     * Resolve duplicate pages before writes can replace the previous crawl's
     * comparison baseline. Keep only chunk coordinates, not another text copy.
     * The final answer supplies its observed metadata; otherwise the first
     * observation of each field wins, including an observed empty string.
     *
     * @param array<int,mixed> $row Staged page.
     * @param array<string,mixed> $stage Staging state, updated.
     * @return bool Whether merged into an earlier row.
     */
    private static function stage_page(array $row, array &$stage) {
        $path = (string) $row[1];
        if (!isset($stage['pages'][$path])) {
            $stage['pages'][$path] = array($stage['chunks'], count($stage['chunk']));
            return false;
        }
        list($chunk, $offset) = $stage['pages'][$path];
        $rows = $chunk === $stage['chunks'] ? $stage['chunk'] : get_option(SEOProAnalytics_Schema::option(self::OPTION . '_' . $chunk), array());
        $old = $rows[$offset];
        $final = $old[2] >= 300 && $old[2] < 400 && ($row[2] < 300 || $row[2] >= 400);
        foreach ($row[9] as $field => $value) {
            if ($value !== null && ($final || !isset($old[9][$field]))) {
                $old[9][$field] = $value;
            }
        }
        $old[2] = $final ? $row[2] : ($old[2] ? $old[2] : $row[2]);
        $old[3] |= $row[3];
        foreach (array(4, 5, 6) as $field) {
            $old[$field] = $old[$field] ? $old[$field] : $row[$field];
        }
        $old[7] = max($old[7], $row[7]);
        $old[8] = $row[8] !== '' ? $row[8] : $old[8];
        $rows[$offset] = $old;
        if ($chunk === $stage['chunks']) {
            $stage['chunk'] = $rows;
        } else {
            self::save($rows, $chunk);
        }
        return true;
    }

    /**
     * A source's headers and a function giving its next row (false at the
     * end): a JSON export (an array of rows, or an object with them under
     * data, urls, pages, links or rows) or a CSV.
     *
     * @param array<string,mixed> $source Source.
     * @return array{headers:string[],next:callable}|WP_Error
     */
    private static function reader(array $source) {
        $rows = isset($source['rows']) && is_array($source['rows']) ? $source['rows'] : null;
        if ($rows === null && isset($source['stream']) && is_resource($source['stream'])) {
            $stream = $source['stream'];
            rewind($stream);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- the first bytes of an upload or memory stream already open.
            $head = ltrim((string) fread($stream, 64), "\xEF\xBB\xBF \t\r\n");
            rewind($stream);
            if ($head !== '' && ($head[0] === '{' || $head[0] === '[')) {
                $rows = self::json_rows((string) stream_get_contents($stream, self::MAX_BYTES + 1));
            } else {
                return self::csv_reader($stream);
            }
        }
        if (!is_array($rows)) {
            return new WP_Error('seoproanalytics_crawl_read', __('A file could not be read as CSV or JSON.', 'seoproanalytics'), array('status' => 400));
        }
        $rows  = array_values($rows);
        $first = isset($rows[0]) && is_array($rows[0]) ? $rows[0] : array();
        $at    = 0;
        return array(
            'headers' => array_map(array('SEOProAnalytics_Crawl_CSV', 'header'), array_keys($first)),
            'next'    => static function () use (&$rows, &$at) {
                if (!isset($rows[$at])) {
                    return false;
                }
                $row = $rows[$at];
                // Rows read are let go of as they are staged.
                unset($rows[$at]);
                ++$at;
                return is_array($row) ? $row : array();
            },
        );
    }

    /**
     * Rows of a JSON export.
     *
     * @param string $text JSON.
     * @return array<int,mixed>|null
     */
    private static function json_rows($text) {
        if (strlen($text) > self::MAX_BYTES) {
            return null;
        }
        $data = json_decode($text, true);
        if (!is_array($data)) {
            return null;
        }
        foreach (array('data', 'urls', 'pages', 'links', 'rows') as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                return $data[$key];
            }
        }
        return array_keys($data) === range(0, count($data) - 1) ? $data : null;
    }

    /**
     * Whether a staged value is not empty (array_filter's callback).
     *
     * @param mixed $value Value.
     * @return bool
     */
    public static function filled($value) {
        return is_scalar($value) && (string) $value !== '';
    }

    /**
     * A CSV's headers and rows by them.
     *
     * @param resource $stream Stream.
     * @return array{headers:string[],next:callable}|WP_Error
     */
    private static function csv_reader($stream) {
        $headers = fgetcsv($stream, 0, ',', '"', '');
        if (!is_array($headers) || $headers === array(null)) {
            return new WP_Error('seoproanalytics_crawl_header', __('The export needs a header row.', 'seoproanalytics'), array('status' => 400));
        }
        $headers = array_map(array('SEOProAnalytics_Crawl_CSV', 'header'), $headers);
        return array(
            'headers' => $headers,
            'next'    => static function () use ($stream, $headers) {
                $row = fgetcsv($stream, 0, ',', '"', '');
                if ($row === false) {
                    return false;
                }
                return is_array($row) && count($row) === count($headers) ? array_combine($headers, $row) : array();
            },
        );
    }

    /**
     * The import's format: the crawler most files came from.
     *
     * @param string[] $formats Each file's.
     * @return string
     */
    private static function format_of(array $formats) {
        $counts = array_count_values($formats);
        arsort($counts);
        return $counts ? (string) key($counts) : 'csv';
    }

    /**
     * Save a staged chunk (autoload off).
     *
     * @param array<int,array<int,mixed>> $chunk Rows.
     * @param int                         $index Chunk.
     */
    private static function save(array $chunk, $index) {
        update_option(SEOProAnalytics_Schema::option(self::OPTION . '_' . (int) $index), $chunk, false);
    }

    /**
     * Start the import's imports row and job once its files are staged.
     *
     * @param array<string,mixed> $stage  Staging state.
     * @param string              $format Format.
     * @return array<string,mixed>|WP_Error
     */
    private static function open_job(array $stage, $format) {
        global $wpdb;
        $day = current_time('Y-m-d');
        $job = array(
            'status'  => 'running',
            'id'      => 0,
            'format'  => $format,
            'origin'  => $stage['origin'],
            'files'   => $stage['files'],
            'kinds'   => $stage['kinds'],
            'read'    => $stage['read']['pages'] + $stage['read']['links'],
            'total'   => $stage['total'],
            'done'    => 0,
            'pages'   => 0,
            'links'   => 0,
            'skipped' => $stage['skipped'],
            'changes' => 0,
            'chunks'  => $stage['chunks'],
            'phase'   => $stage['total'] ? 'rows' : (!empty($stage['kinds']['links']) ? 'counts' : 'prune'),
            'started' => (int) $stage['started'],
            'complete' => !empty($stage['complete']),
            'partial' => !empty($stage['partial']),
            'covered' => !empty($stage['partial']) ? array_values(array_filter(SEOProAnalytics_Dict::ids(SEOProAnalytics_Schema::DICT_PATH, array_map('strval', array_keys($stage['pages']))))) : array(),
            'before_state' => SEOProAnalytics_Crawl::state(),
            'undo_chunks' => 0,
            'observed' => (array) $stage['observed'],
            'links_uncertain' => !empty($stage['links_uncertain']),
        );
        if ($job['partial']) {
            $job['phase'] = 'prepare_partial';
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table.
        $ok = $wpdb->insert(SEOProAnalytics_Schema::table('imports'), array(
            'source'   => self::SOURCE,
            'status'   => self::RUNNING,
            'started'  => $job['started'],
            'day_from' => $day,
            'day_to'   => $day,
            'meta'     => (string) wp_json_encode(self::meta($job)),
        ), array('%s', '%d', '%d', '%s', '%s', '%s'));
        if (!$ok) {
            self::clear_chunks($stage['chunks']);
            return new WP_Error('seoproanalytics_crawl_record', __('The import could not be recorded.', 'seoproanalytics'), array('status' => 500));
        }
        $job['id'] = (int) $wpdb->insert_id;
        self::clear_undo();
        update_option(SEOProAnalytics_Schema::option(self::OPTION), $job, false);
        wp_schedule_single_event(time() + 5, self::HOOK);
        return $job;
    }

    /**
     * Delete staged chunks.
     *
     * @param int $chunks How many were staged.
     */
    public static function clear_chunks($chunks) {
        $most = (int) ceil((self::MAX_PAGES + self::MAX_LINKS) / self::BATCH);
        for ($i = 0; $i < min($most, max(0, (int) $chunks)); ++$i) {
            delete_option(SEOProAnalytics_Schema::option(self::OPTION . '_' . $i));
        }
    }

    /** Remove abandoned staged options without probing every possible absent chunk. */
    private static function clear_orphans() {
        global $wpdb;
        $prefix = SEOProAnalytics_Schema::option(self::OPTION . '_');
        $most = (int) ceil((self::MAX_PAGES + self::MAX_LINKS) / self::BATCH);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- indexed option_name prefix, bounded by the staging limit plus the lock option.
        $names = (array) $wpdb->get_col($wpdb->prepare("SELECT option_name FROM $wpdb->options WHERE option_name LIKE %s LIMIT %d", $wpdb->esc_like($prefix) . '%', $most + 1));
        foreach ($names as $name) {
            if (ctype_digit(substr((string) $name, strlen($prefix)))) {
                delete_option((string) $name);
            }
        }
    }

    /** Remove the preceding import's bounded, single-step undo staging. */
    private static function clear_undo() {
        global $wpdb;
        $prefix = SEOProAnalytics_Schema::option(self::OPTION . '_undo_');
        do {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- indexed option_name prefix, bounded batches.
            $names = (array) $wpdb->get_col($wpdb->prepare("SELECT option_name FROM $wpdb->options WHERE option_name LIKE %s LIMIT %d", $wpdb->esc_like($prefix) . '%', self::BATCH));
            foreach ($names as $name) {
                delete_option((string) $name);
            }
        } while (count($names) === self::BATCH);
    }

    /** @param array<string,mixed> $job Job. Snapshot one page/link batch before any replacement. */
    private static function prepare_partial(array &$job) {
        global $wpdb;
        $at = (int) ($job['prepare_at'] ?? 0);
        if (!isset($job['covered'][$at])) {
            $job['phase'] = 'rows';
            return;
        }
        $id = (int) $job['covered'][$at];
        $cursor = (int) ($job['prepare_target'] ?? 0);
        $pages = $cursor === 0 ? self::existing_pages(array($id)) : array();
        $page_error = $wpdb->last_error;
        if ($page_error !== '') {
            self::fail($job, __('The previous page rows could not be read for undo.', 'seoproanalytics'));
            return;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- source/target primary-key range, bounded undo staging.
        $links = !empty($job['kinds']['links']) ? (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE from_path_id = %d AND target_id > %d ORDER BY target_id LIMIT %d', SEOProAnalytics_Schema::table('crawl_links'), $id, $cursor, self::BATCH), ARRAY_A) : array();
        if ($wpdb->last_error !== '' || !self::journal($job, array('pages' => $pages, 'links' => $links))) {
            self::fail($job, __('The previous crawl rows could not be staged for undo.', 'seoproanalytics'));
            return;
        }
        if (count($links) === self::BATCH) {
            $last = end($links);
            $job['prepare_target'] = (int) $last['target_id'];
            return;
        }
        $job['prepare_at'] = $at + 1;
        $job['prepare_target'] = 0;
    }

    /** @param array<string,mixed> $job Job. @param array<string,mixed> $rows Previous rows. @return bool Persisted before replacement. */
    public static function journal(array &$job, array $rows) {
        $name = SEOProAnalytics_Schema::option(self::OPTION . '_undo_' . (int) $job['undo_chunks']);
        $rows['import'] = (int) $job['id'];
        add_option($name, $rows, '', false);
        $saved = get_option($name, null);
        if (!is_array($saved) || (int) ($saved['import'] ?? 0) !== (int) $job['id']) {
            self::fail($job, __('Undo staging could not be saved. The import is rolled back.', 'seoproanalytics'));
            return false;
        }
        ++$job['undo_chunks'];
        return true;
    }

    /** @param array<string,mixed> $job Job retaining the one-step undo manifest. @return bool All batches restored. */
    private static function restore_partial(array &$job) {
        global $wpdb;
        $i = (int) ($job['restore_cursor'] ?? 0);
        if ($i < (int) ($job['undo_chunks'] ?? 0)) {
            $chunk = get_option(SEOProAnalytics_Schema::option(self::OPTION . '_undo_' . $i), null);
            if (!is_array($chunk)) {
                $job['error'] = __('Undo staging is missing; the import cannot be restored yet.', 'seoproanalytics');
                return false;
            }
            self::upsert_pages((array) ($chunk['pages'] ?? array()));
            if ($wpdb->last_error !== '') {
                return false;
            }
            foreach ((array) ($chunk['links'] ?? array()) as $link) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- restoring only staged rows by their primary key.
                if ($wpdb->replace(SEOProAnalytics_Schema::table('crawl_links'), $link) === false) {
                    return false;
                }
            }
            foreach ((array) ($chunk['queue'] ?? array()) as $row) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- exact primary-key lookup before undoing this import's verdict only.
                $current_meta = (string) $wpdb->get_var($wpdb->prepare('SELECT meta FROM %i WHERE id = %d', SEOProAnalytics_Schema::table('queue'), (int) $row['id']));
                $meta = json_decode($current_meta, true);
                if ((int) ($meta['verified_import'] ?? 0) === (int) $job['id']) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- compare-and-set preserves subsequent owner actions.
                    if ($wpdb->update(SEOProAnalytics_Schema::table('queue'), array('status' => $row['status'], 'note' => $row['note'], 'meta' => $row['meta']), array('id' => $row['id'], 'updated' => $row['updated'], 'meta' => $current_meta), array('%d', '%s', '%s'), array('%d', '%d', '%s')) === false) {
                        return false;
                    }
                }
            }
            $job['restore_cursor'] = $i + 1;
        }
        return (int) ($job['restore_cursor'] ?? 0) >= (int) $job['undo_chunks'];
    }

    // ------------------------------------------------------------------
    // Writing (also the demo's).

    /**
     * Write pages: one row per path, the newest import's. A row an earlier
     * import wrote keeps its status and flags as prev_status and
     * prev_flags, and a served change goes on the timeline when its answer
     * changed (live data, up to room). Rows of the same import are merged:
     * a crawler's final answer wins over a redirects file's first hop.
     *
     * @param array<int,array<int,mixed>> $rows    Staged page rows (SEOProAnalytics_Crawl_CSV::page()).
     * @param int                         $import  imports.id.
     * @param int                         $crawled When it was imported.
     * @param int                         $room    Most changes to record (0: none).
     * @param string                      $format  Format, for the changes.
     * @param int                         $text_room Flagged pages whose text may be kept.
     * @param bool                        $partial Preserve whole-site depth and incoming counts.
     * @return array{pages:int,changes:int,text_kept:int}
     */
    public static function write_pages(array $rows, $import, $crawled, $room = 0, $format = '', $text_room = self::MAX_TEXT, $partial = false) {
        global $wpdb;
        if (!$rows) {
            return array('pages' => 0, 'changes' => 0, 'text_kept' => 0);
        }
        self::load();
        $paths   = SEOProAnalytics_Dict::ids(SEOProAnalytics_Schema::DICT_PATH, array_column($rows, 1));
        $targets = SEOProAnalytics_Dict::ids(SEOProAnalytics_Schema::DICT_TARGET, array_filter(array_column($rows, 8), array(__CLASS__, 'filled')));
        $before  = self::existing_pages(array_values(array_filter($paths)));
        $stored  = SEOProAnalytics_Audit::stored_text(array_values(array_filter($paths)));
        $merged  = array();
        $path_of = array();
        foreach ($rows as $row) {
            $id = isset($paths[(string) $row[1]]) ? (int) $paths[(string) $row[1]] : 0;
            if ($id < 1) {
                continue;
            }
            $path_of[$id] = (string) $row[1];
            $was = isset($merged[$id]) ? $merged[$id] : (isset($before[$id]) ? $before[$id] : null);
            $merged[$id] = self::merge_page($was, $row, $id, $import, $crawled, $row[8] !== '' && isset($targets[(string) $row[8]]) ? (int) $targets[(string) $row[8]] : 0);
            if ($partial && $was) {
                foreach (array('depth', 'links_in', 'links_in_body') as $field) {
                    $merged[$id][$field] = $was[$field];
                }
            }
        }
        $changes = 0;
        $kept = 0;
        foreach ($merged as $id => &$page) {
            self::text_flags($page, isset($stored[$id]) ? $stored[$id] : null);
            if ($changes < $room && isset($before[$id]) && (int) $before[$id]['import_id'] !== (int) $import && self::served_change($page)) {
                $changes += self::record_change($path_of[$id], $page, $import, $format) ? 1 : 0;
            }
            if (isset($before[$id]) && (int) $before[$id]['import_id'] !== (int) $import) {
                $changes += self::text_changes($path_of[$id], $before[$id], $page, $import, $format, max(0, $room - $changes));
            }
            if ($kept < $text_room && ((int) $page['flags'] & 112)) {
                $labels = SEOProAnalytics_Dict::ids(SEOProAnalytics_Schema::DICT_LABEL, array((string) ($page['_title'] ?? ''), (string) ($page['_desc'] ?? '')));
                foreach (array('title', 'desc') as $field) {
                    if (isset($page['_' . $field])) {
                        $page[$field . '_id'] = $labels[SEOProAnalytics_Dict::clean((string) $page['_' . $field])] ?? 0;
                    }
                }
                $kept += $page['title_id'] || $page['desc_id'] ? 1 : 0;
            }
        }
        unset($page);
        self::upsert_pages(array_values($merged));
        return array('pages' => count($merged), 'changes' => $changes, 'text_kept' => $kept);
    }

    /**
     * Rows already kept for pages, by the primary key.
     *
     * @param int[] $ids Path ids.
     * @return array<int,array<string,mixed>>
     */
    private static function existing_pages(array $ids) {
        global $wpdb;
        $out = array();
        foreach (array_chunk(array_unique($ids), self::BATCH) as $chunk) {
            $holders = implode(', ', array_fill(0, count($chunk), '%d'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table by primary key; $holders holds only placeholders.
            foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT *, LOWER(HEX(title_hash)) AS title_hash, LOWER(HEX(desc_hash)) AS desc_hash FROM %i WHERE path_id IN ($holders)", array_merge(array(SEOProAnalytics_Schema::table('crawl_pages')), $chunk)), ARRAY_A) as $row) {
                $out[(int) $row['path_id']] = $row;
            }
        }
        return $out;
    }

    /**
     * One page's row after a staged row: a new row, the next import's row
     * over an earlier one's, or the same import's rows merged.
     *
     * @param array<string,mixed>|null $was      The row before, or null.
     * @param array<int,mixed>         $row      Staged page row.
     * @param int                      $id       Path id.
     * @param int                      $import   imports.id.
     * @param int                      $crawled  When.
     * @param int                      $redirect Redirect target id.
     * @return array<string,mixed>
     */
    private static function merge_page($was, array $row, $id, $import, $crawled, $redirect) {
        $meta = isset($row[9]) && is_array($row[9]) ? $row[9] : array();
        $title = isset($meta['title']) ? SEOProAnalytics_Audit::line((string) $meta['title']) : null;
        $desc = isset($meta['description']) ? SEOProAnalytics_Audit::line((string) $meta['description']) : null;
        $new = array(
            'path_id'     => $id,
            'import_id'   => (int) $import,
            'crawled'     => (int) $crawled,
            'status'      => (int) $row[2],
            'prev_status' => null,
            'flags'       => (int) $row[3],
            'prev_flags'  => null,
            'depth'       => (int) $row[4],
            'response_ms' => (int) $row[5],
            'prev_response_ms' => null,
            'bytes'       => (int) $row[6],
            'hops'        => (int) $row[7],
            'redirect_id' => (int) $redirect,
            'title_hash'  => $title === null ? null : SEOProAnalytics_Audit::hash($title),
            'desc_hash'   => $desc === null ? null : SEOProAnalytics_Audit::hash($desc),
            'title_id'    => 0,
            'desc_id'     => 0,
            'h1'          => isset($meta['h1']) ? min(255, max(0, (int) $meta['h1'])) : null,
            '_title'      => $title,
            '_desc'       => $desc,
            'links_in'    => null,
            'links_in_body' => null,
        );
        if ($was === null) {
            return $new;
        }
        if ((int) $was['import_id'] !== (int) $import) {
            $new['prev_status'] = (int) $was['status'];
            $new['prev_flags']  = (int) $was['flags'];
            $new['prev_response_ms'] = (int) $was['response_ms'] ?: null;
            // One compact state bit keeps the last confirmed side of the
            // threshold, so a single noisy crawl cannot write a change.
            $stable = (int) $was['flags'] & SEOProAnalytics_Crawl::SLOW_CONFIRMED;
            $threshold = SEOProAnalytics_Crawl::slow_threshold();
            if ($new['response_ms'] > 0 && $new['prev_response_ms'] !== null) {
                $slow = $new['response_ms'] > $threshold;
                if ($slow === ($new['prev_response_ms'] > $threshold)) {
                    $stable = $slow ? SEOProAnalytics_Crawl::SLOW_CONFIRMED : 0;
                }
            }
            $new['flags'] |= $stable;
            return $new;
        }
        // The same import named the page twice (pages and redirects files).
        foreach (array('title', 'desc') as $field) {
            if (!isset($was[$field . '_hash']) && $new[$field . '_hash'] !== null) {
                $was[$field . '_hash'] = $new[$field . '_hash'];
                $was['_' . $field] = $new['_' . $field];
            }
        }
        $was['h1'] = $was['h1'] ?? $new['h1'];
        $first = (int) $was['status'];
        $final = $first >= 300 && $first < 400 && ($new['status'] < 300 || $new['status'] >= 400) ? $new['status'] : ($first ? $first : $new['status']);
        return array_merge($was, array(
            'status'      => $final,
            'flags'       => (int) $was['flags'] | $new['flags'],
            'depth'       => (int) $was['depth'] ? (int) $was['depth'] : $new['depth'],
            'response_ms' => (int) $was['response_ms'] ? (int) $was['response_ms'] : $new['response_ms'],
            'bytes'       => (int) $was['bytes'] ? (int) $was['bytes'] : $new['bytes'],
            'hops'        => max((int) $was['hops'], $new['hops']),
            'redirect_id' => $new['redirect_id'] ? $new['redirect_id'] : (int) $was['redirect_id'],
        ));
    }

    /**
     * Compare observed text with the WordPress snapshot, never treating an
     * absent export column as empty. Only successful, direct HTML pages.
     *
     * @param array<string,mixed> $page Page, updated.
     * @param array<string,mixed>|null $stored Stored text and snapshot hashes.
     */
    private static function text_flags(array &$page, $stored) {
        if ($stored === null || SEOProAnalytics_Crawl::answer((int) $page['status']) !== 'ok' || (int) $page['hops'] > 0) {
            return;
        }
        if (isset($page['_title'])) {
            $title = (string) $page['_title'];
            $site = SEOProAnalytics_Audit::line(get_bloginfo('name'));
            if ($title === '' || ($site !== '' && SEOProAnalytics_Audit::hash($title) === SEOProAnalytics_Audit::hash($site)) || !self::title_matches($title, $site, $stored)) {
                $page['flags'] |= SEOProAnalytics_Crawl::FLAGS['title_differs'];
            }
        }
        if (isset($page['_desc']) && $page['desc_hash'] !== $stored['dh']) {
            $page['flags'] |= $page['_desc'] === '' ? SEOProAnalytics_Crawl::FLAGS['desc_missing'] : SEOProAnalytics_Crawl::FLAGS['desc_differs'];
        }
    }

    /**
     * Match the snapshot hash, with common site-name affixes removed.
     * When current WordPress text still matches that snapshot, containment
     * accepts other SEO title templates too, without trusting a stale title.
     *
     * @param string $title Served title.
     * @param string $site Site name.
     * @param array<string,mixed> $stored WordPress text and hashes.
     * @return bool
     */
    private static function title_matches($title, $site, array $stored) {
        $candidates = array($title);
        if ($site !== '') {
            $name = preg_quote($site, '/');
            $candidates[] = (string) preg_replace('/(?:^' . $name . '\s*[|–—:\-]\s*|\s*[|–—:\-]\s*' . $name . '$)/iu', '', $title);
        }
        foreach ($candidates as $candidate) {
            if (SEOProAnalytics_Audit::hash($candidate) === $stored['th']) {
                return true;
            }
        }
        if ($stored['title'] === '' || SEOProAnalytics_Audit::hash((string) $stored['title']) !== $stored['th']) {
            return false;
        }
        $lower = static function ($text) {
            return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
        };
        return strpos($lower($title), $lower((string) $stored['title'])) !== false;
    }

    /**
     * Record changed title/description hashes, sharing the status-change cap.
     * Unknown observations do not become changes. The new text is available
     * only for this batch; older text is available only if previously kept.
     *
     * @param string $path Path.
     * @param array<string,mixed> $before Previous crawl row.
     * @param array<string,mixed> $page New crawl row.
     * @param int $import Import id.
     * @param string $format Format.
     * @param int $room Remaining changes.
     * @return int
     */
    private static function text_changes($path, array $before, array $page, $import, $format, $room) {
        if ($room < 1 || SEOProAnalytics_Schema::set() !== 'live') {
            return 0;
        }
        require_once __DIR__ . '/class-seoproanalytics-changes.php';
        $old = SEOProAnalytics_Dict::values(array((int) $before['title_id'], (int) $before['desc_id']));
        $n = 0;
        foreach (array('title' => '_title', 'desc' => '_desc') as $field => $text) {
            if ($n >= $room || !isset($before[$field . '_hash'], $page[$field . '_hash'], $page[$text]) || $before[$field . '_hash'] === $page[$field . '_hash']) {
                continue;
            }
            $id = (int) $before[$field . '_id'];
            $empty = $before[$field . '_hash'] === SEOProAnalytics_Audit::NONE;
            $retained = $empty || isset($old[$id]);
            $name = $field === 'title' ? __('Served title', 'seoproanalytics') : __('Served description', 'seoproanalytics');
            $ok = SEOProAnalytics_Changes::record(SEOProAnalytics_Changes::SERVED, array(
                'ts' => (int) $page['crawled'], 'path' => $path, 'object_type' => 'crawl_' . $field, 'object_id' => (int) $import,
                'old' => $old[$id] ?? '', 'new' => (string) $page[$text],
                'meta' => array('name' => $retained ? $name : $name . ': ' . __('earlier text was not kept', 'seoproanalytics'), 'field' => $field, 'old_kept' => $retained, 'import' => (int) $import, 'format' => $format),
                'source' => defined('WP_CLI') && WP_CLI ? 2 : 4, 'user_id' => 0,
            ));
            $n += $ok ? 1 : 0;
        }
        return $n;
    }

    /**
     * Whether a page's answer changed from the import before: between 2xx,
     * a redirect and an error, or its served noindex or canonical.
     *
     * @param array<string,mixed> $page Row.
     * @return bool
     */
    private static function served_change(array $page) {
        if ($page['prev_status'] === null) {
            return false;
        }
        $watch = SEOProAnalytics_Crawl::FLAGS['noindex'] | SEOProAnalytics_Crawl::FLAGS['canonical'] | SEOProAnalytics_Crawl::SLOW_CONFIRMED;
        return SEOProAnalytics_Crawl::answer((int) $page['prev_status']) !== SEOProAnalytics_Crawl::answer((int) $page['status'])
            || ((int) $page['prev_flags'] & $watch) !== ((int) $page['flags'] & $watch);
    }

    /**
     * Put a served change on the timeline (live data).
     *
     * @param string              $path   The page's path.
     * @param array<string,mixed> $page   Row.
     * @param int                 $import imports.id.
     * @param string              $format Format.
     * @return bool
     */
    private static function record_change($path, array $page, $import, $format) {
        if ($path === '' || SEOProAnalytics_Schema::set() !== 'live') {
            return false;
        }
        require_once __DIR__ . '/class-seoproanalytics-changes.php';
        return SEOProAnalytics_Changes::record(SEOProAnalytics_Changes::SERVED, array(
            'ts'          => (int) $page['crawled'],
            'path'        => (string) $path,
            'object_type' => 'crawl',
            'object_id'   => (int) $import,
            'old'         => SEOProAnalytics_Crawl::served((int) $page['prev_status'], (int) $page['prev_flags']) . (((int) $page['prev_flags'] & SEOProAnalytics_Crawl::SLOW_CONFIRMED) ? ', slow as the crawler measured' : ''),
            'new'         => SEOProAnalytics_Crawl::served((int) $page['status'], (int) $page['flags']) . (((int) $page['flags'] & SEOProAnalytics_Crawl::SLOW_CONFIRMED) ? ', slow as the crawler measured' : ''),
            'meta'        => array('name' => (string) $path, 'import' => (int) $import, 'format' => (string) $format, 'status_before' => (int) $page['prev_status'], 'status' => (int) $page['status'], 'response_ms' => (int) $page['response_ms'], 'prev_response_ms' => $page['prev_response_ms']),
            // WP-CLI (2), else the import's cron or the progress it shows (4).
            'source'      => defined('WP_CLI') && WP_CLI ? 2 : 4,
            'user_id'     => 0,
        ));
    }

    /**
     * Insert pages or replace their rows.
     *
     * @param array<int,array<string,mixed>> $pages Rows.
     */
    private static function upsert_pages(array $pages) {
        global $wpdb;
        $cols = array('path_id', 'import_id', 'crawled', 'status', 'prev_status', 'flags', 'prev_flags', 'depth', 'response_ms', 'prev_response_ms', 'bytes', 'hops', 'redirect_id', 'title_hash', 'desc_hash', 'title_id', 'desc_id', 'h1', 'links_in', 'links_in_body');
        foreach (array_chunk($pages, self::BATCH) as $chunk) {
            $groups = array();
            $args   = array(SEOProAnalytics_Schema::table('crawl_pages'));
            foreach ($chunk as $page) {
                $group = array();
                foreach ($cols as $col) {
                    if ($page[$col] === null) {
                        // A fixed NULL: no previous import.
                        $group[] = 'NULL';
                        continue;
                    }
                    $hash = in_array($col, array('title_hash', 'desc_hash'), true);
                    $group[] = $hash ? 'UNHEX(%s)' : '%d';
                    $args[]  = $hash ? (string) $page[$col] : (int) $page[$col];
                }
                $groups[] = '(' . implode(', ', $group) . ')';
            }
            $update = implode(', ', array_map(static function ($col) {
                return "$col = VALUES($col)";
            }, array_slice($cols, 1)));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table by primary key; $cols and $update are fixed column names, $groups holds only placeholders and NULL.
            $wpdb->query($wpdb->prepare('INSERT INTO %i (' . implode(', ', $cols) . ') VALUES ' . implode(', ', $groups) . " ON DUPLICATE KEY UPDATE $update", $args));
        }
    }

    /**
     * Write links whose target did not answer 2xx straight away. A link
     * to a page on the site with no status in its file takes the status
     * the crawl's pages gave that page; links to pages it did not crawl,
     * or that answered 2xx, are left out.
     *
     * @param array<int,array<int,mixed>> $rows   Staged link rows (SEOProAnalytics_Crawl_CSV::link()).
     * @param int                         $import imports.id.
     * @return int Links kept.
     */
    public static function write_links(array $rows, $import) {
        global $wpdb;
        self::load();
        $rows = self::resolve_links($rows);
        if (!$rows) {
            return 0;
        }
        $from    = SEOProAnalytics_Dict::ids(SEOProAnalytics_Schema::DICT_PATH, array_column($rows, 1));
        $targets = SEOProAnalytics_Dict::ids(SEOProAnalytics_Schema::DICT_TARGET, array_column($rows, 2));
        $finals = SEOProAnalytics_Dict::ids(SEOProAnalytics_Schema::DICT_TARGET, array_filter(array_column($rows, 8), array(__CLASS__, 'filled')));
        $anchors = SEOProAnalytics_Dict::ids(SEOProAnalytics_Schema::DICT_LABEL, array_filter(array_column($rows, 7), array(__CLASS__, 'filled')));
        $keep    = array();
        foreach ($rows as $row) {
            $f = isset($from[(string) $row[1]]) ? (int) $from[(string) $row[1]] : 0;
            $t = isset($targets[(string) $row[2]]) ? (int) $targets[(string) $row[2]] : 0;
            if ($f < 1 || $t < 1) {
                continue;
            }
            $keep[$f . ':' . $t] = array($f, $t, (int) $import, max(0, (int) $row[4]), min(255, (int) $row[5]), (int) $row[3], (int) $row[6], $row[7] !== '' && isset($anchors[(string) $row[7]]) ? (int) $anchors[(string) $row[7]] : 0);
            $keep[$f . ':' . $t][] = (int) ($finals[(string) ($row[8] ?? '')] ?? 0);
        }
        foreach (array_chunk(array_values($keep), self::BATCH) as $chunk) {
            $groups = implode(', ', array_fill(0, count($chunk), '(%d, %d, %d, %d, %d, %d, %d, %d, %d)'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table by primary key; $groups holds only placeholder groups.
            $wpdb->query($wpdb->prepare("INSERT INTO %i (from_path_id, target_id, import_id, status, hops, internal, placement, anchor_id, redirect_id) VALUES $groups ON DUPLICATE KEY UPDATE import_id = VALUES(import_id), status = VALUES(status), hops = VALUES(hops), internal = VALUES(internal), placement = VALUES(placement), anchor_id = VALUES(anchor_id), redirect_id = VALUES(redirect_id)", array_merge(array(SEOProAnalytics_Schema::table('crawl_links')), array_merge(...$chunk))));
        }
        return count($keep);
    }

    /**
     * Links to the site's own pages with no status of their own take it
     * from crawl_pages (a links file imported alone); those it does not
     * know, or that answered 2xx straight away, are left out.
     *
     * @param array<int,array<int,mixed>> $rows Staged link rows.
     * @return array<int,array<int,mixed>>
     */
    private static function resolve_links(array $rows) {
        $ask = array();
        foreach ($rows as $row) {
            if ((int) $row[4] === SEOProAnalytics_Crawl_CSV::UNKNOWN && (int) $row[3] === 1) {
                // An own target is the path percent-encoded; pages are stored decoded.
                $ask[] = rawurldecode((string) $row[2]);
            }
        }
        $known = array();
        if ($ask) {
            // Text => id; a path the site never had gets an id here, which is harmless.
            $ids = array_filter(SEOProAnalytics_Dict::ids(SEOProAnalytics_Schema::DICT_PATH, $ask));
            $by  = self::existing_pages(array_values($ids));
            foreach ($ids as $path => $id) {
                if (isset($by[(int) $id])) {
                    $known[(string) $path] = array((int) $by[(int) $id]['status'], (int) $by[(int) $id]['hops']);
                }
            }
        }
        $out = array();
        foreach ($rows as $row) {
            if ((int) $row[4] === SEOProAnalytics_Crawl_CSV::UNKNOWN) {
                $path = rawurldecode((string) $row[2]);
                if (!isset($known[$path])) {
                    continue;
                }
                list($row[4], $row[5]) = $known[$path];
            }
            if ((int) $row[4] >= 200 && (int) $row[4] < 300 && (int) $row[5] === 0) {
                continue;
            }
            $out[] = $row;
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // History.

    /**
     * Crawl imports, newest first.
     *
     * @param int $limit Most.
     * @return array<int,array<string,mixed>>
     */
    public static function imports($limit = 20) {
        global $wpdb;
        $statuses = array(self::RUNNING => 'running', self::DONE => 'done', self::FAILED => 'failed', self::UNDONE => 'undone');
        $limit = max(1, min(5000, (int) $limit));
        $rows = array();
        foreach (array_keys($statuses) as $status) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- fixed source/status uses source_status plus implicit id, avoiding a sort of all crawl history.
            $rows = array_merge($rows, (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE source = %s AND status = %d ORDER BY id DESC LIMIT %d', SEOProAnalytics_Schema::table('imports'), self::SOURCE, $status, $limit), ARRAY_A));
        }
        usort($rows, static function ($a, $b) {
            return (int) $b['id'] <=> (int) $a['id'];
        });
        $out      = array();
        foreach (array_slice($rows, 0, $limit) as $row) {
            $meta  = json_decode((string) $row['meta'], true);
            $meta  = is_array($meta) ? $meta : array();
            $out[] = array(
                'id'       => (int) $row['id'],
                'status'   => isset($statuses[(int) $row['status']]) ? $statuses[(int) $row['status']] : 'unknown',
                'format'   => isset($meta['format']) ? (string) $meta['format'] : '',
                'origin'   => (string) ($meta['origin'] ?? 'upload'),
                'partial'  => !empty($meta['partial']),
                'files'    => isset($meta['files']) && is_array($meta['files']) ? array_values($meta['files']) : array(),
                'started'  => (int) $row['started'] ? gmdate('c', (int) $row['started']) : null,
                'finished' => (int) $row['finished'] ? gmdate('c', (int) $row['finished']) : null,
                'pages'    => isset($meta['pages']) ? (int) $meta['pages'] : 0,
                'links'    => isset($meta['links']) ? (int) $meta['links'] : 0,
                'skipped'  => isset($meta['skipped']) ? (int) $meta['skipped'] : 0,
                'changes'  => isset($meta['changes']) ? (int) $meta['changes'] : 0,
                'totals'   => isset($meta['totals']) && is_array($meta['totals']) ? $meta['totals'] : null,
                'error'    => isset($meta['error']) ? (string) $meta['error'] : '',
            );
        }
        return $out;
    }

    /**
     * Undo a crawl import: delete the rows it wrote, by its import_id key.
     * The earlier crawl it replaced is not brought back; import it again
     * for that.
     *
     * @param int $id imports.id.
     * @return int|WP_Error Rows deleted.
     */
    public static function undo($id) {
        if (!self::lock()) {
            return new WP_Error('seoproanalytics_crawl_busy', __('A crawl import is busy.', 'seoproanalytics'), array('status' => 409));
        }
        try {
            return self::undo_locked($id);
        } finally {
            self::unlock();
        }
    }

    /** @param int $id Import ID. @return int|WP_Error Rows removed, under the import lock. */
    private static function undo_locked($id) {
        global $wpdb;
        self::load();
        $id = (int) $id;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, by primary key.
        $row = $wpdb->get_row($wpdb->prepare('SELECT id, source, status, meta FROM %i WHERE id = %d', SEOProAnalytics_Schema::table('imports'), $id), ARRAY_A);
        if (!$row || (string) $row['source'] !== self::SOURCE) {
            return new WP_Error('seoproanalytics_import_unknown', __('There is no crawl import with that ID.', 'seoproanalytics'), array('status' => 404));
        }
        $job = self::status();
        if ($job['status'] === 'running') {
            return new WP_Error('seoproanalytics_crawl_busy', __('That import is still running.', 'seoproanalytics'), array('status' => 409));
        }
        if ((int) $row['status'] === self::UNDONE) {
            if ((int) $job['id'] === $id && $job['status'] === 'restoring') {
                $job['status'] = 'done';
                update_option(SEOProAnalytics_Schema::option(self::OPTION), $job, false);
                wp_clear_scheduled_hook(self::HOOK);
                self::clear_undo();
            }
            return 0;
        }
        $meta = json_decode((string) $row['meta'], true);
        if (!empty($meta['partial']) && (int) $job['id'] !== $id) {
            return new WP_Error('seoproanalytics_crawl_undo', __('Undo a partial crawl before starting another import; its one-step undo data has expired.', 'seoproanalytics'), array('status' => 409));
        }
        if (!empty($meta['partial'])) {
            $job['status'] = 'restoring';
            update_option(SEOProAnalytics_Schema::option(self::OPTION), $job, false);
            if (!wp_next_scheduled(self::HOOK)) {
                wp_schedule_single_event(time() + MINUTE_IN_SECONDS, self::HOOK);
            }
        }
        $deleted = self::forget($id);
        if (!empty($meta['partial'])) {
            $start = microtime(true);
            while (!self::restore_partial($job)) {
                update_option(SEOProAnalytics_Schema::option(self::OPTION), $job, false);
                if (!SEOProAnalytics_Feature::more_time($start, self::BUDGET) || !empty($job['error']) || $wpdb->last_error !== '') {
                    return new WP_Error('seoproanalytics_crawl_undo', __('Undo is not finished. Its staging is kept; retry undo to continue.', 'seoproanalytics'), array('status' => 503));
                }
            }
            update_option(SEOProAnalytics_Schema::option(self::OPTION), $job, false);
        }
        if ((int) $job['id'] === $id) {
            self::clear_chunks((int) $job['chunks']);
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, by primary key.
        $wpdb->update(SEOProAnalytics_Schema::table('imports'), array('status' => self::UNDONE, 'finished' => time()), array('id' => $id), array('%d', '%d'), array('%d'));
        $state = SEOProAnalytics_Crawl::state();
        SEOProAnalytics_Crawl::recount(!empty($meta['partial']) ? (array) $job['before_state'] : ((int) $state['import'] === $id ? array('import' => 0, 'format' => '', 'files' => array(), 'read' => 0, 'complete' => false, 'link_counts' => false) : array()));
        if (!empty($meta['partial'])) {
            $job['status'] = 'done';
            update_option(SEOProAnalytics_Schema::option(self::OPTION), $job, false);
            wp_clear_scheduled_hook(self::HOOK);
            self::clear_undo();
        }
        return $deleted;
    }

    /**
     * Delete the crawl rows one import wrote, by its import_id key, in
     * batches.
     *
     * @param int $id imports.id.
     * @return int Rows deleted.
     */
    private static function forget($id) {
        global $wpdb;
        $deleted = 0;
        foreach (array('crawl_pages', 'crawl_links') as $table) {
            do {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, by its import_id key, in batches.
                $gone     = (int) $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE import_id = %d LIMIT %d', SEOProAnalytics_Schema::table($table), (int) $id, self::DELETE));
                $deleted += $gone;
            } while ($gone === self::DELETE);
        }
        return $deleted;
    }

    /**
     * Forget the job, its chunks and the crawl tables' rows (demo removal,
     * uninstall); the cron hook with live data only.
     */
    public static function reset() {
        global $wpdb;
        require_once __DIR__ . '/class-seoproanalytics-site-crawler.php';
        SEOProAnalytics_Site_Crawler::reset();
        self::clear_undo();
        self::clear_chunks((int) self::status()['chunks']);
        $cleared = self::clear_counts();
        while (!$cleared) {
            $cleared = self::clear_counts();
        }
        delete_option(SEOProAnalytics_Schema::option(self::OPTION));
        self::unlock();
        if (SEOProAnalytics_Schema::set() === 'live') {
            wp_clear_scheduled_hook(self::HOOK);
        }
        if (SEOProAnalytics_Schema::is_current()) {
            foreach (array('crawl_pages', 'crawl_links') as $table) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, emptied on purpose.
                $wpdb->query($wpdb->prepare('TRUNCATE TABLE %i', SEOProAnalytics_Schema::table($table)));
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, by its source_status key.
            $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE source = %s', SEOProAnalytics_Schema::table('imports'), self::SOURCE));
        }
        SEOProAnalytics_Crawl::reset();
    }

    // ------------------------------------------------------------------
    // Helpers.

    /**
     * Take the five-minute lease (a run that dies gives it up).
     *
     * @return bool
     */
    private static function lock() {
        $lock = SEOProAnalytics_Schema::option(self::OPTION . '_lock');
        $held = (int) get_option($lock, 0);
        if ($held && $held < time() - 5 * MINUTE_IN_SECONDS) {
            delete_option($lock);
        }
        return add_option($lock, time(), '', false);
    }

    /**
     * Give the lease back.
     */
    private static function unlock() {
        delete_option(SEOProAnalytics_Schema::option(self::OPTION . '_lock'));
    }

    /**
     * Load the classes the import uses.
     */
    private static function load() {
        require_once __DIR__ . '/class-seoproanalytics-dict.php';
        require_once __DIR__ . '/class-seoproanalytics-crawl.php';
        require_once __DIR__ . '/class-seoproanalytics-crawl-csv.php';
        require_once __DIR__ . '/class-seoproanalytics-audit.php';
    }
}
