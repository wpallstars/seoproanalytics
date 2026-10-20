<?php
/**
 * The content audit (Search → Audit): facts about each published post,
 * read from WordPress itself (its title, words, headings and images, and
 * its SEO plugin's title, description, robots rule and canonical
 * address), and findings from them weighed by the page's search figures,
 * so the ones that matter come first.
 *
 * Facts are read when a post is saved (at the end of that request, so the
 * SEO plugin's fields are saved too) and by the daily cron, at most BATCH
 * posts within BUDGET seconds, never on visitor pages: one page_facts row
 * per page. The cron reads posts with no facts, changed since, or read
 * more than STALE_DAYS ago, by the posts table's primary key from where
 * the last run stopped; every page is read again after the SEO plugin is
 * switched. A post's row goes when it is deleted or no longer published.
 * The links in the same text are written with the facts
 * (SEOProAnalytics_Links): page_links, and page_facts.links_in. Each row
 * keeps when its post was published (page_facts.published), for
 * indexation (SEOProAnalytics_Indexation).
 *
 * Findings: title or description missing or long, the same title or
 * description as another page (by the hash keys), no H1 or several, a
 * thin page (few words, with impressions but no clicks), images without
 * alt text, and noindex or a canonical address elsewhere on a page with
 * search impressions; and from Google's URL Inspection of the page
 * (SEOProAnalytics_Inspections, by its flags key): blocked by robots.txt,
 * crawled but not indexed, Google chose another canonical, rich result
 * errors; from Bing's crawl issues (SEOProAnalytics_Bing_Site_Data): a
 * crawl error on the page; and from an imported crawl
 * (SEOProAnalytics_Crawl): the page answered an error, is served with noindex
 * or a canonical elsewhere that WordPress does not set, or links to
 * addresses that fail or (internal ones) redirect. Lengths are of what was written: an SEO title or
 * description made of its plugin's variables counts as the post's title
 * or excerpt. Themes show the post title as the page's H1, so an H1 in the
 * text makes several.
 *
 * Reads: page_facts by its flags key (pages with a finding of their own),
 * duplicates by title_hash and desc_hash, and each page's search figures
 * from gsc_pages by the primary key. Design: docs/seo-loop.md → Content
 * audit from WordPress.
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

final class SEOProAnalytics_Audit {

    /** Progress, per data set (autoload off): cursor (post ID), plugin, since (read all again from), version (facts written), last (cron run), links (links read from). */
    const OPTION = 'seoproanalytics_audit';

    /** SEOProAnalytics_Links's file, loaded when facts are read or written. */
    const LINKS_FILE = '/class-seoproanalytics-links.php';

    /** Posts read per cron run at most, and its seconds. */
    const BATCH  = 200;
    const BUDGET = 20;

    /** Posts looked at per read of the posts table, and read together. */
    const SCAN  = 1000;
    const CHUNK = 50;

    /** Facts older than this many days are read again. */
    const STALE_DAYS = 30;

    /** Longest title and description (characters), and words below which a page is short. */
    const TITLE_MAX       = 60;
    const DESCRIPTION_MAX = 160;
    const THIN_WORDS      = 300;

    /** Facts rows with a finding of their own read at most; duplicate groups read at most; pages named per duplicate. */
    const MAX_ROWS   = 5000;
    const MAX_GROUPS = 500;
    const SAME       = 5;

    /** Pages listed: default and most. */
    const LIMIT     = 50;
    const MAX_LIMIT = 500;

    /** Findings, most serious first. */
    const FINDINGS = array('noindex', 'canonical', 'crawl_error', 'served_noindex', 'served_canonical', 'served_title', 'served_description', 'unreachable', 'deep', 'slow', 'robots_blocked', 'not_indexed', 'google_canonical', 'bing_crawl', 'broken_links', 'thin', 'title_missing', 'title_duplicate', 'title_long', 'description_missing', 'description_duplicate', 'description_long', 'h1_none', 'h1_several', 'rich_errors', 'redirect_links', 'images_alt');

    /** Findings from an imported crawl (SEOProAnalytics_Crawl::FINDINGS), whatever the page's impressions. */
    const CRAWL = array('crawl_error', 'served_noindex', 'served_canonical', 'served_title', 'served_description', 'broken_links', 'redirect_links', 'unreachable', 'deep', 'slow');

    /** Findings from Google's URL Inspection (SEOProAnalytics_Inspections::FLAGS), whatever the page's impressions. */
    const GOOGLE = array('robots_blocked', 'not_indexed', 'google_canonical', 'rich_errors');

    /** The flags column: findings of the page alone, and short (thin when it has impressions and no clicks). */
    const FLAGS = array(
        'title_missing'       => 1,
        'title_long'          => 2,
        'description_missing' => 4,
        'description_long'    => 8,
        'h1_none'             => 16,
        'h1_several'          => 32,
        'images_alt'          => 64,
        'noindex'             => 128,
        'canonical'           => 256,
        'short'               => 512,
    );

    /**
     * Share of a page's expected clicks a finding puts at stake (the
     * decision queue's potential clicks); a page's are added, at most 1.
     */
    const SHARE = array(
        'noindex'               => 1.0,
        'canonical'             => 1.0,
        'crawl_error'           => 1.0,
        'served_noindex'        => 1.0,
        'served_canonical'      => 0.8,
        'served_title'          => 0.3,
        'served_description'    => 0.1,
        'robots_blocked'        => 1.0,
        'not_indexed'           => 1.0,
        'google_canonical'      => 0.8,
        'bing_crawl'            => 0.5,
        'broken_links'          => 0.1,
        'thin'                  => 0.3,
        'title_missing'         => 0.2,
        'title_duplicate'       => 0.15,
        'title_long'            => 0.05,
        'description_missing'   => 0.1,
        'description_duplicate' => 0.1,
        'description_long'      => 0.05,
        'h1_none'               => 0.05,
        'h1_several'            => 0.02,
        'rich_errors'           => 0.1,
        'redirect_links'        => 0.02,
        'images_alt'            => 0.02,
        'unreachable'           => 0.5,
        'deep'                  => 0.1,
        'slow'                  => 0.2,
    );

    /** A hash key for no text. */
    const NONE = '0000000000000000';

    /** The facts columns a report reads. */
    const FACTS_COLS = 'path_id, post_id, checked, modified, title_len, seo_title_len, desc_len, LOWER(HEX(title_hash)) AS th, LOWER(HEX(desc_hash)) AS dh, h1, words, images, images_no_alt, noindex, canonical_away, flags';

    /** @var array<int,bool> Posts saved or deleted in this request, read at its end. */
    private static $saved = array();

    // ------------------------------------------------------------------
    // Reading posts.

    /**
     * A post was saved or deleted, or its SEO fields changed: read it at
     * the end of the request (once), when every field is saved.
     *
     * @param int $post_id Post.
     */
    public static function saved($post_id) {
        $post_id = (int) $post_id;
        if ($post_id <= 0) {
            return;
        }
        if (!self::$saved) {
            add_action('shutdown', array(__CLASS__, 'save_now'));
        }
        self::$saved[$post_id] = true;
    }

    /**
     * End of a request that saved posts: read the published ones, forget
     * the rest (deleted, or no longer published). Live data only.
     */
    public static function save_now() {
        $ids         = array_keys(self::$saved);
        self::$saved = array();
        $before      = SEOProAnalytics_Schema::use_set('live');
        try {
            if (!SEOProAnalytics_Schema::is_current()) {
                return;
            }
            self::load();
            $read = array();
            $gone = array();
            foreach ($ids as $post_id) {
                $post = get_post($post_id);
                if (!$post instanceof WP_Post) {
                    $gone[] = $post_id;
                } elseif (SEOProAnalytics_Changes::is_public($post)) {
                    if ($post->post_status === 'publish') {
                        $read[] = $post_id;
                    } else {
                        $gone[] = $post_id;
                    }
                }
            }
            // A request saving many posts (a bulk edit) leaves the rest to the daily cron.
            $wrote = $read ? self::read_posts(array_slice($read, 0, self::CHUNK)) : 0;
            $wrote += $gone ? self::forget($gone) : 0;
            if ($wrote) {
                self::touch();
            }
        } finally {
            SEOProAnalytics_Schema::use_set($before);
        }
    }

    /**
     * Daily cron (and WP-CLI): read up to $limit posts whose facts are
     * missing, changed or old, within the time budget, from where the
     * last run stopped; back to the first post at the end. Live data only.
     *
     * @param int $limit  Posts read at most.
     * @param int $budget Seconds.
     * @return array{read:int,looked:int,done:bool} Posts read and looked at; done: the end of the posts was reached.
     */
    public static function batch($limit = self::BATCH, $budget = self::BUDGET) {
        $out = array('read' => 0, 'looked' => 0, 'done' => false);
        if (SEOProAnalytics_Schema::set() !== 'live' || !SEOProAnalytics_Schema::is_current()) {
            return $out;
        }
        self::load();
        $until = array('start' => microtime(true), 'limit' => max(1, (int) $limit), 'budget' => $budget);
        $state = self::restart(self::state());
        $types = self::post_types();
        $old   = max((int) $state['since'], time() - self::STALE_DAYS * DAY_IN_SECONDS);
        $stop  = !$types;
        while (!$stop) {
            $rows = self::scan($types, (int) $state['cursor']);
            $scan = self::read_rows($rows, $old, $until, $state, $out);
            if ($scan['stop']) {
                break;
            }
            if (count($rows) < self::SCAN) {
                // The end of the posts: from the first again next time.
                $state['cursor'] = 0;
                $out['done']     = true;
                break;
            }
            $state['cursor'] = $scan['last'];
            $stop            = !SEOProAnalytics_Feature::more_time($until['start'], $until['budget']);
        }
        if ($out['read']) {
            $state['version'] = time();
        }
        $state['last'] = time();
        update_option(SEOProAnalytics_Schema::option(self::OPTION), $state, false);
        return $out;
    }

    /**
     * Progress with every page to be read again, from the first, when the
     * SEO plugin changed or links or published times came with an update.
     *
     * @param array<string,mixed> $state From state().
     * @return array<string,mixed>
     */
    private static function restart(array $state) {
        $seo = SEOProAnalytics_Coverage::seo_plugin();
        if ($state['plugin'] !== $seo) {
            // Another SEO plugin's fields: every page is read again.
            $state['plugin'] = $seo;
            $state['since']  = time();
            $state['cursor'] = 0;
        }
        if (!$state['links']) {
            // Links came with an update: every page is read again for them.
            $state['links']  = time();
            $state['since']  = $state['links'];
            $state['cursor'] = 0;
        }
        if (!$state['published']) {
            // Published times came with an update (indexation): every page is read again for them.
            $state['published'] = time();
            $state['since']     = $state['published'];
            $state['cursor']    = 0;
        }
        return $state;
    }

    /**
     * The next SCAN published posts of the types after the cursor, by the
     * posts table's primary key: ID and m (modified, GMT).
     *
     * @param string[] $types  Post types.
     * @param int      $cursor Last post ID looked at.
     * @return array<int,array<string,string>>
     */
    private static function scan(array $types, $cursor) {
        global $wpdb;
        $holders = implode(', ', array_fill(0, count($types), '%s'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- WordPress's posts by their primary key, in its order, from the cursor; $holders holds only placeholders.
        return (array) $wpdb->get_results($wpdb->prepare("SELECT ID, post_modified_gmt AS m FROM %i FORCE INDEX (`PRIMARY`) WHERE ID > %d AND post_status = 'publish' AND post_type IN ($holders) ORDER BY ID LIMIT %d", array_merge(array($wpdb->posts, (int) $cursor), $types, array(self::SCAN))), ARRAY_A);
    }

    /**
     * Read the scanned posts whose facts are missing, changed or old, in
     * chunks of CHUNK, moving the cursor after each chunk; stop at the
     * limit or the end of the time budget.
     *
     * @param array<int,array<string,string>>         $rows  From scan().
     * @param int                                     $old   Facts read before this are old.
     * @param array{start:float,limit:int,budget:int} $until Start, posts read at most and seconds.
     * @param array<string,mixed>                     $state Progress (cursor).
     * @param array{read:int,looked:int,done:bool}    $out   Posts read and looked at.
     * @return array{stop:bool,last:int} Whether to stop, and the last post looked at.
     */
    private static function read_rows(array $rows, $old, array $until, array &$state, array &$out) {
        $known   = self::known(array_map('intval', array_column($rows, 'ID')));
        $pending = array();
        $last    = (int) $state['cursor'];
        foreach ($rows as $row) {
            $post_id = (int) $row['ID'];
            ++$out['looked'];
            if (self::stale($known, $post_id, $row['m'], $old)) {
                $pending[] = $post_id;
            }
            $last = $post_id;
            if (self::chunk_full($pending, $out['read'], $until['limit'])) {
                self::read_posts($pending);
                $out['read']    += count($pending);
                $pending         = array();
                $state['cursor'] = $last;
                if ($out['read'] >= $until['limit'] || !SEOProAnalytics_Feature::more_time($until['start'], $until['budget'])) {
                    return array('stop' => true, 'last' => $last);
                }
            }
        }
        if ($pending) {
            self::read_posts($pending);
            $out['read'] += count($pending);
        }
        return array('stop' => false, 'last' => $last);
    }

    /**
     * Whether a post's facts are missing, older than its last change, or
     * read before $old.
     *
     * @param array<int,array{modified:int,checked:int}> $known    From known().
     * @param int                                        $post_id  Post.
     * @param string                                     $modified Its post_modified_gmt.
     * @param int                                        $old      Facts read before this are old.
     * @return bool
     */
    private static function stale(array $known, $post_id, $modified, $old) {
        $was = isset($known[$post_id]) ? $known[$post_id] : null;
        return !$was || $was['modified'] !== self::time($modified) || $was['checked'] < $old;
    }

    /**
     * Whether the pending posts are to be read now: a full chunk, or
     * enough to reach the limit.
     *
     * @param int[] $pending Posts to read.
     * @param int   $read    Posts read so far.
     * @param int   $limit   Posts read at most.
     * @return bool
     */
    private static function chunk_full(array $pending, $read, $limit) {
        return $pending && (count($pending) >= self::CHUNK || $read + count($pending) >= $limit);
    }

    /**
     * Read posts' facts and write them, each on its page (its address
     * now); rows of the posts at other addresses go.
     *
     * @param int[] $post_ids Published posts.
     * @return int Posts written.
     */
    private static function read_posts(array $post_ids) {
        $post_ids = array_values(array_unique(array_filter(array_map('intval', $post_ids))));
        if (!$post_ids) {
            return 0;
        }
        _prime_post_caches($post_ids, false, true);
        $seo   = SEOProAnalytics_Coverage::seo_fields($post_ids);
        $facts = array();
        foreach ($post_ids as $post_id) {
            $post = get_post($post_id);
            $link = $post instanceof WP_Post ? get_permalink($post) : false;
            if (!is_string($link) || $link === '') {
                continue;
            }
            $path = SEOProAnalytics_Changes::path($link);
            $text = SEOProAnalytics_Coverage::text_of_post($post_id, isset($seo[$post_id]) ? $seo[$post_id] : null);
            if ($text) {
                $facts[$path] = self::facts($text, $path) + array(
                    'post_id'   => $post_id,
                    'modified'  => self::time($post->post_modified_gmt),
                    'published' => (int) get_post_time('U', true, $post),
                );
            }
        }
        return self::write($facts);
    }

    /**
     * Write facts by page path (one row per page), replacing the posts'
     * rows at other addresses.
     *
     * @param array<string,array<string,mixed>> $facts From facts(), with post_id, modified and published, by path.
     * @return int Rows written.
     */
    public static function write(array $facts) {
        if (!$facts) {
            return 0;
        }
        require_once __DIR__ . self::LINKS_FILE;
        $ids   = SEOProAnalytics_Dict::ids(SEOProAnalytics_Schema::DICT_PATH, array_keys($facts));
        $table = SEOProAnalytics_Schema::table('page_facts');
        $now   = time();
        $args  = array();
        $keep  = array();
        $links = array();
        $n     = 0;
        foreach ($facts as $path => $f) {
            $path_id = isset($ids[SEOProAnalytics_Dict::clean((string) $path)]) ? (int) $ids[SEOProAnalytics_Dict::clean((string) $path)] : 0;
            if (!$path_id) {
                continue;
            }
            $keep[(int) $f['post_id']][] = $path_id;
            $links[$path_id]             = isset($f['links']) ? (array) $f['links'] : array();
            array_push($args, $path_id, (int) $f['post_id'], $now, (int) $f['modified'], (int) $f['title_len'], (int) $f['seo_title_len'], (int) $f['desc_len'], (string) $f['title_hash'], (string) $f['desc_hash'], (int) $f['h1'], (int) $f['words'], (int) $f['images'], (int) $f['images_no_alt'], (int) $f['noindex'], (int) $f['canonical_away'], (int) $f['flags'], isset($f['published']) ? max(0, (int) $f['published']) : 0);
            ++$n;
        }
        if (!$n) {
            return 0;
        }
        self::insert_facts($table, $args, $n);
        // A post's rows at its old addresses go (a changed slug or parent), with their links.
        $gone = self::old_addresses($table, $keep);

        // The pages' links, and the pages linking to the pages they link to (and to them: new rows).
        $changed = SEOProAnalytics_Links::replace($links, $gone);
        SEOProAnalytics_Links::recount(array_merge($changed, array_keys($links)));
        return $n;
    }

    /**
     * Insert or update facts rows, by the primary key.
     *
     * @param string                $table page_facts.
     * @param array<int,int|string> $args  Each row's 17 values, in the columns' order.
     * @param positive-int          $n     Rows.
     */
    private static function insert_facts($table, array $args, $n) {
        global $wpdb;
        $groups = implode(', ', array_fill(0, $n, '(%d, %d, %d, %d, %d, %d, %d, UNHEX(%s), UNHEX(%s), %d, %d, %d, %d, %d, %d, %d, %d)'));
        $update = array();
        foreach (array('post_id', 'checked', 'modified', 'title_len', 'seo_title_len', 'desc_len', 'title_hash', 'desc_hash', 'h1', 'words', 'images', 'images_no_alt', 'noindex', 'canonical_away', 'flags', 'published') as $col) {
            $update[] = "$col = VALUES($col)";
        }
        $update = implode(', ', $update);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table by its primary key; $groups holds only placeholder groups and $update fixed column names.
        $wpdb->query($wpdb->prepare("INSERT INTO %i (path_id, post_id, checked, modified, title_len, seo_title_len, desc_len, title_hash, desc_hash, h1, words, images, images_no_alt, noindex, canonical_away, flags, published) VALUES $groups ON DUPLICATE KEY UPDATE $update", array_merge(array($table), $args)));
    }

    /**
     * Delete posts' rows at addresses other than those just written.
     *
     * @param string            $table page_facts.
     * @param array<int,int[]>  $keep  Post id => its path ids written now.
     * @return int[] Path ids deleted.
     */
    private static function old_addresses($table, array $keep) {
        global $wpdb;
        $gone = array();
        foreach ($keep as $post_id => $paths) {
            if (!$post_id) {
                continue;
            }
            $holders = implode(', ', array_fill(0, count($paths), '%d'));
            $args    = array_merge(array($table, (int) $post_id), $paths);
            // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table by its post_id key; $holders holds only placeholders.
            $old = array_map('intval', (array) $wpdb->get_col($wpdb->prepare("SELECT path_id FROM %i WHERE post_id = %d AND path_id NOT IN ($holders)", $args)));
            if ($old) {
                $gone = array_merge($gone, $old);
                $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE post_id = %d AND path_id NOT IN ($holders)", $args));
            }
            // phpcs:enable
        }
        return $gone;
    }

    /**
     * Forget posts' facts and their links.
     *
     * @param int[] $post_ids Posts.
     * @return int Rows deleted.
     */
    private static function forget(array $post_ids) {
        global $wpdb;
        $post_ids = array_values(array_filter(array_map('intval', $post_ids)));
        if (!$post_ids) {
            return 0;
        }
        require_once __DIR__ . self::LINKS_FILE;
        $holders = implode(', ', array_fill(0, count($post_ids), '%d'));
        $args    = array_merge(array(SEOProAnalytics_Schema::table('page_facts')), $post_ids);
        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table by its post_id key; $holders holds only placeholders.
        $paths = array_map('intval', (array) $wpdb->get_col($wpdb->prepare("SELECT path_id FROM %i WHERE post_id IN ($holders)", $args)));
        $gone  = (int) $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE post_id IN ($holders)", $args));
        // phpcs:enable
        if ($paths) {
            SEOProAnalytics_Links::recount(SEOProAnalytics_Links::replace(array(), $paths));
        }
        return $gone;
    }

    /**
     * When posts' facts were read and their posts changed, by post.
     *
     * @param int[] $post_ids Posts.
     * @return array<int,array{modified:int,checked:int}>
     */
    private static function known(array $post_ids) {
        global $wpdb;
        if (!$post_ids) {
            return array();
        }
        $holders = implode(', ', array_fill(0, count($post_ids), '%d'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table by its post_id key; $holders holds only placeholders.
        $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT post_id, modified, checked FROM %i WHERE post_id IN ($holders)", array_merge(array(SEOProAnalytics_Schema::table('page_facts')), $post_ids)), ARRAY_A);
        $out  = array();
        foreach ($rows as $row) {
            $id = (int) $row['post_id'];
            // A post at two addresses: the oldest read counts.
            if (!isset($out[$id]) || (int) $row['checked'] < $out[$id]['checked']) {
                $out[$id] = array('modified' => (int) $row['modified'], 'checked' => (int) $row['checked']);
            }
        }
        return $out;
    }

    /**
     * A page's facts from its text (SEOProAnalytics_Coverage::text_of_post(),
     * or SEOProAnalytics_Demo::text()), with the flags of its own findings and
     * its links to the site's pages (SEOProAnalytics_Links::parse()).
     *
     * @param array<string,mixed> $text Text.
     * @param string              $path The page's path.
     * @return array<string,mixed>
     */
    public static function facts(array $text, $path) {
        require_once __DIR__ . self::LINKS_FILE;
        $html      = self::html($text);
        $title     = self::line(isset($text['title']) ? (string) $text['title'] : '');
        $seo_title = self::seo_title($text);
        $shown     = $seo_title !== '' ? $seo_title : $title;
        $desc      = self::description($text);
        $h1        = (int) preg_match_all('/<h1[\s>]/i', $html);
        $images    = self::images($html);
        $words     = count(SEOProAnalytics_Coverage::words(SEOProAnalytics_Coverage::plain($html)));
        $noindex   = !empty($text['noindex']);
        $canonical = isset($text['canonical']) ? trim((string) $text['canonical']) : '';
        $away      = $canonical !== '' && self::away($canonical, (string) $path);

        $flags  = self::text_flags($shown, $desc) | self::h1_flags($h1, $title);
        $flags |= $images['no_alt'] ? self::FLAGS['images_alt'] : 0;
        $flags |= $noindex ? self::FLAGS['noindex'] : 0;
        $flags |= $away ? self::FLAGS['canonical'] : 0;
        $flags |= $words < self::THIN_WORDS ? self::FLAGS['short'] : 0;

        return array(
            'title_len'      => min(65535, self::length($shown)),
            'seo_title_len'  => min(65535, self::length($seo_title)),
            'desc_len'       => min(65535, self::length($desc)),
            'title_hash'     => self::hash($shown),
            'desc_hash'      => self::hash($desc),
            'h1'             => min(255, $h1),
            'words'          => $words,
            'images'         => min(65535, $images['images']),
            'images_no_alt'  => min(65535, $images['no_alt']),
            'noindex'        => $noindex ? 1 : 0,
            'canonical_away' => $away ? 1 : 0,
            'flags'          => $flags,
            'links'          => SEOProAnalytics_Links::parse($html),
        );
    }

    /**
     * A text's content without shortcodes, at most MAX_TEXT bytes.
     *
     * @param array<string,mixed> $text Text.
     * @return string
     */
    private static function html(array $text) {
        $html = (string) preg_replace('/\[\/?[a-zA-Z][^\[\]]*\]/', ' ', isset($text['content']) ? (string) $text['content'] : '');
        if (strlen($html) > SEOProAnalytics_Coverage::MAX_TEXT) {
            $html = function_exists('mb_strcut') ? mb_strcut($html, 0, SEOProAnalytics_Coverage::MAX_TEXT, 'UTF-8') : substr($html, 0, SEOProAnalytics_Coverage::MAX_TEXT);
        }
        return $html;
    }

    /**
     * The SEO title as written, or '' when there is none or it is made of
     * its plugin's variables (the post's title is shown then).
     *
     * @param array<string,mixed> $text Text.
     * @return string
     */
    private static function seo_title(array $text) {
        if (empty($text['seo_title_vars']) && isset($text['seo_title'])) {
            return self::line((string) $text['seo_title']);
        }
        return '';
    }

    /**
     * The description, or the excerpt when there is none.
     *
     * @param array<string,mixed> $text Text.
     * @return string
     */
    private static function description(array $text) {
        $desc = self::line(isset($text['description']) ? (string) $text['description'] : '');
        if ($desc === '') {
            // SEO plugins describe a page by its excerpt when it has no description of its own.
            $desc = self::line(SEOProAnalytics_Coverage::plain(isset($text['excerpt']) ? (string) $text['excerpt'] : ''));
        }
        return $desc;
    }

    /**
     * Images in the content, and those without alt text.
     *
     * @param string $html Content.
     * @return array{images:int,no_alt:int}
     */
    private static function images($html) {
        $out = array('images' => 0, 'no_alt' => 0);
        if (preg_match_all('/<img\b[^>]*>/i', $html, $found)) {
            foreach ($found[0] as $tag) {
                ++$out['images'];
                if (!preg_match('/\salt\s*=\s*(?:"\s*[^"\s][^"]*"|\'\s*[^\'\s][^\']*\')/i', $tag)) {
                    ++$out['no_alt'];
                }
            }
        }
        return $out;
    }

    /**
     * Flags of the title shown and the description: missing or long.
     *
     * @param string $shown The title shown.
     * @param string $desc  The description.
     * @return int
     */
    private static function text_flags($shown, $desc) {
        $flags = 0;
        if ($shown === '') {
            $flags |= self::FLAGS['title_missing'];
        } elseif (self::length($shown) > self::TITLE_MAX) {
            $flags |= self::FLAGS['title_long'];
        }
        if ($desc === '') {
            $flags |= self::FLAGS['description_missing'];
        } elseif (self::length($desc) > self::DESCRIPTION_MAX) {
            $flags |= self::FLAGS['description_long'];
        }
        return $flags;
    }

    /**
     * Flags of the H1s: none, or several (themes show the post title as one).
     *
     * @param int    $h1    H1s in the content.
     * @param string $title The post's title.
     * @return int
     */
    private static function h1_flags($h1, $title) {
        if ($h1 === 0 && $title === '') {
            return self::FLAGS['h1_none'];
        }
        if ($h1 > 1 || ($h1 === 1 && $title !== '')) {
            return self::FLAGS['h1_several'];
        }
        return 0;
    }

    /**
     * Whether a canonical address is another page than the path.
     *
     * @param string $canonical Canonical address or path.
     * @param string $path      The page's path.
     * @return bool
     */
    private static function away($canonical, $path) {
        $host = wp_parse_url($canonical, PHP_URL_HOST);
        if (is_string($host) && $host !== '' && strtolower($host) !== strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST))) {
            return true;
        }
        return untrailingslashit(SEOProAnalytics_Changes::path($canonical)) !== untrailingslashit((string) $path);
    }

    /**
     * The post types read: those with pages of their own, but not media.
     *
     * @return string[]
     */
    private static function post_types() {
        $types = array();
        // The same post types as SEOProAnalytics_Changes::is_public().
        foreach (get_post_types() as $type) {
            if ($type !== 'attachment' && $type !== 'revision' && is_post_type_viewable($type)) {
                $types[] = (string) $type;
            }
        }
        return $types;
    }

    // ------------------------------------------------------------------
    // The report.

    /**
     * The audit report: pages with findings, most impressions first
     * unless sorted otherwise.
     *
     * @param array<string,mixed> $req     From SEOProAnalytics_Query::request() (range, filters, limit, offset).
     * @param string              $engine  google or bing.
     * @param string              $finding Only pages with this finding; '' for all.
     * @param string              $sort    One of SEOProAnalytics_Search::SORTS; '' for impressions.
     * @param string              $order   asc or desc; '' for the sort's natural order.
     * @return array<string,mixed>|WP_Error
     */
    public static function report(array $req, $engine = 'google', $finding = '', $sort = '', $order = '') {
        self::load();
        self::first_read();
        $finding = (string) $finding;
        if ($finding !== '' && !in_array($finding, self::FINDINGS, true)) {
            /* translators: %s: list of findings */
            return new WP_Error('seoproanalytics_audit_finding', sprintf(__('The finding is one of: %s.', 'seoproanalytics'), implode(', ', self::FINDINGS)), array('status' => 400));
        }
        $sort   = in_array($sort, SEOProAnalytics_Search::SORTS, true) ? (string) $sort : SEOProAnalytics_Search::SORTS[0];
        $by     = array('sort' => $sort, 'order' => SEOProAnalytics_Search::sort_order($sort, $order));
        $engine = SEOProAnalytics_Search::engine_name($engine);
        $live   = SEOProAnalytics_Schema::set() === 'live';
        $answer = SEOProAnalytics_Query::cached('audit', $req + array('engine' => $engine, 'finding' => $finding) + $by + array('imports' => SEOProAnalytics_Search::version(), 'facts' => self::state()['version'], 'inspections' => SEOProAnalytics_Inspections::state()['version'], 'bing' => SEOProAnalytics_Bing_Site_Data::state()['version'], 'crawl' => SEOProAnalytics_Crawl::state()['version']), static function () use ($req, $engine, $finding, $by) {
            return self::build($req, $engine, $finding, $by);
        });
        $answer['connected'] = !$live || SEOProAnalytics_Search::connected($engine);
        // Editor links depend on the viewer, so they are added outside the shared cache.
        foreach ($answer['rows'] as &$row) {
            $row = SEOProAnalytics_Clicks::with_edit_url($row);
        }
        unset($row);
        return $answer;
    }

    /**
     * The shared part of the answer (cached).
     *
     * @param array<string,mixed>             $req     Request.
     * @param string                          $name    Engine name.
     * @param string                          $finding Finding asked for, or ''.
     * @param array{sort:string,order:string} $by      The sort (SEOProAnalytics_Search::SORTS) and its order (asc or desc).
     * @return array<string,mixed>
     */
    private static function build(array $req, $name, $finding, array $by) {
        $engine  = SEOProAnalytics_Search::ENGINES[$name];
        $bounds  = SEOProAnalytics_Search::bounds($engine);
        $range   = SEOProAnalytics_Query::range($req);
        $ignored = array();
        $pages   = SEOProAnalytics_Search::page_ids($req['filters'], '', $ignored);
        $weekly  = in_array($name, SEOProAnalytics_Search::WEEKLY, true);
        $full    = $bounds['to'] !== '' ? SEOProAnalytics_Search::days($range, $bounds, $weekly) : null;
        $now     = $full ? SEOProAnalytics_Opportunities::cut($full) : null;
        $days    = $now ? SEOProAnalytics_Search::length($now) : 0;
        $limit   = max(1, min(self::MAX_LIMIT, isset($req['limit']) ? (int) $req['limit'] : self::LIMIT));
        $offset  = max(0, isset($req['offset']) ? (int) $req['offset'] : 0);
        $rules   = array(
            'title_max'        => self::TITLE_MAX,
            'description_max'  => self::DESCRIPTION_MAX,
            'thin_words'       => self::THIN_WORDS,
            'thin_impressions' => max(10, $days),
            'batch'            => self::BATCH,
            'stale_days'       => self::STALE_DAYS,
        );

        $answer = array(
            'engine'  => $name,
            'engines' => SEOProAnalytics_Search::engines(),
            'range'   => SEOProAnalytics_Query::range_out($now ? $now : $range),
            'days'    => $days,
            'cut'     => $full && $now && SEOProAnalytics_Search::length($full) > $days,
            'through' => $bounds['to'],
            'first'   => $bounds['from'],
            'ignored' => array_values(array_unique($ignored)),
            'finding' => $finding,
            'sort'    => $by['sort'],
            'order'   => $by['order'],
            'rules'   => $rules,
            'checked' => self::checked(),
            'plugin'  => SEOProAnalytics_Schema::set() === 'demo' ? 'demo' : SEOProAnalytics_Coverage::seo_plugin(),
            'counts'  => array_fill_keys(self::FINDINGS, 0),
            'pages'   => 0,
            'rows'    => array(),
            'total'   => 0,
            'more'    => false,
            'coverage_note' => SEOProAnalytics_Crawl::state()['complete'] ? '' : __('Unreachable pages are not checked: this crawl is not confirmed complete.', 'seoproanalytics'),
        );
        if ($pages !== null && !$pages) {
            return $answer;
        }

        $read = self::read($pages);
        $sums = $now ? SEOProAnalytics_Opportunities::sums('gsc_pages', $engine, $now, $pages) : array();
        $value = SEOProAnalytics_Links::conversions($req, $name, '');
        $slow = array_fill_keys(array_column(SEOProAnalytics_Crawl::slow_rows($req, $name), 'path_id'), true);
        foreach ($read['crawl'] as $id => &$bits) {
            if (!isset($slow[$id])) {
                $bits &= ~SEOProAnalytics_Crawl::FINDINGS['slow'];
            }
            if (empty($sums[(string) $id]['i']) && empty($value['pages'][$id]['conversions'])) {
                $bits &= ~SEOProAnalytics_Crawl::FINDINGS['deep'];
            }
        }
        unset($bits);
        $list = self::sorted(self::judge($read, $sums, $rules, $finding, $answer), $by);
        $answer['total'] = count($list);
        $answer['more']  = $offset + $limit < count($list);
        $answer['rows']  = self::rows(array_slice($list, $offset, $limit), $read);
        return $answer;
    }

    /**
     * Judge each page read: count the pages with findings and each
     * finding in the answer, and list those with the finding asked for.
     *
     * @param array<string,mixed>                $read    From read().
     * @param array<string,array{c:int,i:int,p:int}> $sums Search sums by path id.
     * @param array<string,int>                  $rules   Rules.
     * @param string                             $finding Finding asked for, or ''.
     * @param array<string,mixed>                $answer  The answer (pages and counts).
     * @return array<int,array<string,mixed>> path_id, row, findings and sum of each page listed.
     */
    private static function judge(array $read, array $sums, array $rules, $finding, array &$answer) {
        $zero = array('c' => 0, 'i' => 0, 'p' => 0);
        $list = array();
        foreach ($read['rows'] as $path_id => $row) {
            $sum   = isset($sums[(string) $path_id]) ? $sums[(string) $path_id] : $zero;
            $found = self::findings($row, isset($read['same'][$path_id]) ? $read['same'][$path_id] : array(), $sum, $rules, isset($read['google'][$path_id]) ? $read['google'][$path_id] : 0, isset($read['bing'][$path_id]) ? $read['bing'][$path_id] : 0, isset($read['crawl'][$path_id]) ? $read['crawl'][$path_id] : 0);
            if (!$found) {
                continue;
            }
            ++$answer['pages'];
            foreach ($found as $one) {
                ++$answer['counts'][$one];
            }
            if ($finding === '' || in_array($finding, $found, true)) {
                $list[] = array('path_id' => (int) $path_id, 'row' => $row, 'findings' => $found, 'sum' => $sum);
            }
        }
        return $list;
    }

    /**
     * Pages in the sort, then most impressions, clicks and findings first.
     *
     * @param array<int,array<string,mixed>>  $list From judge().
     * @param array{sort:string,order:string} $by   The sort and its order.
     * @return array<int,array<string,mixed>>
     */
    private static function sorted(array $list, array $by) {
        usort($list, static function ($a, $b) use ($by) {
            return SEOProAnalytics_Search::compare(SEOProAnalytics_Search::sort_value($a['sum'], $by['sort']), SEOProAnalytics_Search::sort_value($b['sum'], $by['sort']), $by['order'])
                ?: array($b['sum']['i'], $b['sum']['c'], count($b['findings']), $a['path_id']) <=> array($a['sum']['i'], $a['sum']['c'], count($a['findings']), $b['path_id']);
        });
        return $list;
    }

    /**
     * Facts rows to judge: those with a finding of their own (by the flags
     * key), those sharing a title or description (by the hash keys) and
     * those with a finding of Google's URL Inspection (by the inspections
     * flags key), with the duplicate groups and Google's flags.
     *
     * @param int[]|null $pages Path ids, or null for every page.
     * @return array{rows:array<int,array<string,mixed>>,same:array<int,array<string,string>>,groups:array<string,int[]>,google:array<int,int>,bing:array<int,int>,crawl:array<int,int>}
     */
    private static function read($pages) {
        global $wpdb;
        $table = SEOProAnalytics_Schema::table('page_facts');
        $cols  = self::FACTS_COLS;
        $only  = $pages === null ? null : array_flip(array_map('intval', $pages));
        $rows  = array();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table, by its flags key; $cols is a fixed column list.
        foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT $cols FROM %i FORCE INDEX (`flags`) WHERE flags > 0 LIMIT %d", $table, self::MAX_ROWS), ARRAY_A) as $row) {
            $rows[(int) $row['path_id']] = $row;
        }

        // Pages sharing a title or description.
        $dupes = self::duplicates($table, $only);
        // Pages with a finding of Google's (only the pages with facts are judged: published posts).
        $google = SEOProAnalytics_Inspections::flagged();
        // Pages Bing's crawler had an error on.
        $bing = SEOProAnalytics_Bing_Site_Data::flagged();
        // Pages an imported crawl found a problem on.
        $crawl = SEOProAnalytics_Crawl::flagged();
        if ($only !== null) {
            $google = array_intersect_key($google, $only);
            $bing   = array_intersect_key($bing, $only);
            $crawl  = array_intersect_key($crawl, $only);
        }
        $need  = array_values(array_diff(array_unique(array_merge(array_keys($dupes['same']), array_keys($google), array_keys($bing), array_keys($crawl))), array_keys($rows)));
        $rows += self::rows_of($table, $need);
        if ($only !== null) {
            $rows = array_intersect_key($rows, $only);
        }
        // A measured H1 count replaces the theme assumption, including one
        // that clears an existing finding. Missing export columns stay unknown.
        foreach (SEOProAnalytics_Crawl::headings(array_keys($rows)) as $id => $h1) {
            $rows[$id]['h1'] = $h1;
            $rows[$id]['flags'] = ((int) $rows[$id]['flags'] & ~(self::FLAGS['h1_none'] | self::FLAGS['h1_several']))
                | ($h1 === 0 ? self::FLAGS['h1_none'] : 0) | ($h1 > 1 ? self::FLAGS['h1_several'] : 0);
        }
        return array('rows' => $rows, 'same' => self::shared($dupes['same'], $dupes['groups']), 'groups' => $dupes['groups'], 'google' => $google, 'bing' => $bing, 'crawl' => $crawl);
    }

    /**
     * Pages sharing a title or description (by the hash keys), within the
     * pages asked for: each page's shared hashes, and each group's pages.
     *
     * @param string                $table page_facts.
     * @param array<int,int>|null   $only  Path ids asked for (as keys), or null for every page.
     * @return array{same:array<int,array<string,string>>,groups:array<string,int[]>}
     */
    private static function duplicates($table, $only) {
        global $wpdb;
        $same   = array();
        $groups = array();
        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table, by its title_hash and desc_hash keys; $key is a fixed key name and $holders only placeholders.
        foreach (array('title' => 'title_hash', 'description' => 'desc_hash') as $what => $key) {
            $hashes = (array) $wpdb->get_col($wpdb->prepare("SELECT LOWER(HEX($key)) FROM %i FORCE INDEX (`$key`) WHERE $key > UNHEX(%s) GROUP BY $key HAVING COUNT(*) > 1 LIMIT %d", $table, self::NONE, self::MAX_GROUPS));
            if (!$hashes) {
                continue;
            }
            $holders = implode(', ', array_fill(0, count($hashes), 'UNHEX(%s)'));
            foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT path_id, LOWER(HEX($key)) AS h FROM %i FORCE INDEX (`$key`) WHERE $key IN ($holders)", array_merge(array($table), array_map('strval', $hashes))), ARRAY_A) as $row) {
                $path_id = (int) $row['path_id'];
                if ($only !== null && !isset($only[$path_id])) {
                    continue;
                }
                $same[$path_id][$what]            = (string) $row['h'];
                $groups[$what . ':' . $row['h']][] = $path_id;
            }
        }
        // phpcs:enable
        return array('same' => $same, 'groups' => $groups);
    }

    /**
     * Facts rows of pages, by the primary key.
     *
     * @param string $table page_facts.
     * @param int[]  $ids   Path ids.
     * @return array<int,array<string,mixed>> By path id.
     */
    private static function rows_of($table, array $ids) {
        global $wpdb;
        $cols = self::FACTS_COLS;
        $rows = array();
        foreach (array_chunk($ids, 500) as $chunk) {
            $holders = implode(', ', array_fill(0, count($chunk), '%d'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table by its primary key; $cols is a fixed column list and $holders only placeholders.
            foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT $cols FROM %i WHERE path_id IN ($holders)", array_merge(array($table), $chunk)), ARRAY_A) as $row) {
                $rows[(int) $row['path_id']] = $row;
            }
        }
        return $rows;
    }

    /**
     * Shared titles and descriptions whose group still has two pages
     * after the page filter.
     *
     * @param array<int,array<string,string>> $same   Each page's shared hashes.
     * @param array<string,int[]>             $groups Each group's pages.
     * @return array<int,array<string,string>>
     */
    private static function shared(array $same, array $groups) {
        foreach ($same as $path_id => $both) {
            foreach ($both as $what => $hash) {
                if (count($groups[$what . ':' . $hash]) < 2) {
                    unset($same[$path_id][$what]);
                }
            }
        }
        return $same;
    }

    /**
     * A page's findings, most serious first.
     *
     * @param array<string,mixed>     $row   Facts row.
     * @param array<string,string>    $same  title and description: the hash it shares.
     * @param array{c:int,i:int,p:int} $sum  Its search sums.
     * @param array<string,int>       $rules Rules.
     * @param int                     $google Its flags from Google's URL Inspection (SEOProAnalytics_Inspections::FLAGS).
     * @param int                     $bing   Its crawl error flags from Bing (SEOProAnalytics_Bing_Site_Data::ISSUES).
     * @param int                     $crawl  Its findings from an imported crawl (SEOProAnalytics_Crawl::FINDINGS bits).
     * @return string[]
     */
    private static function findings(array $row, array $same, array $sum, array $rules, $google = 0, $bing = 0, $crawl = 0) {
        $flags = (int) $row['flags'];
        $has   = static function ($name) use ($flags) {
            return (bool) ($flags & self::FLAGS[$name]);
        };
        $by = static function ($name) use ($google) {
            return (bool) ((int) $google & SEOProAnalytics_Inspections::FLAGS[$name]);
        };
        $crawled = static function ($name) use ($crawl) {
            return (bool) ((int) $crawl & SEOProAnalytics_Crawl::FINDINGS[$name]);
        };
        $on = array(
            'noindex'               => $has('noindex') && $sum['i'] > 0,
            'canonical'             => $has('canonical') && $sum['i'] > 0,
            'crawl_error'           => $crawled('crawl_error'),
            // Served while WordPress does not ask for it: another plugin, the theme, a cache or the server.
            'served_noindex'        => $crawled('served_noindex') && !(int) $row['noindex'],
            'served_canonical'      => $crawled('served_canonical') && !(int) $row['canonical_away'],
            'served_title'          => $crawled('served_title'),
            'served_description'    => $crawled('served_description'),
            'unreachable'           => $crawled('unreachable') && !(int) $row['noindex'] && !(int) $row['canonical_away'],
            'deep'                  => $crawled('deep'),
            'slow'                  => $crawled('slow'),
            // A page that asks not to be indexed or names another canonical is not a Google finding as well.
            'robots_blocked'        => $by('robots_blocked'),
            'not_indexed'           => $by('not_indexed') && !(int) $row['noindex'],
            'google_canonical'      => $by('google_canonical') && !(int) $row['canonical_away'],
            'bing_crawl'            => (int) $bing > 0,
            'broken_links'          => $crawled('broken_links'),
            'thin'                  => $has('short') && $sum['i'] >= (int) $rules['thin_impressions'] && $sum['c'] === 0,
            'title_missing'         => $has('title_missing'),
            'title_duplicate'       => isset($same['title']),
            'title_long'            => $has('title_long'),
            'description_missing'   => $has('description_missing'),
            'description_duplicate' => isset($same['description']),
            'description_long'      => $has('description_long'),
            'h1_none'               => $has('h1_none'),
            'h1_several'            => $has('h1_several'),
            'rich_errors'           => $by('rich_errors'),
            'redirect_links'        => $crawled('redirect_links'),
            'images_alt'            => $has('images_alt'),
        );
        return array_keys(array_filter($on));
    }

    /**
     * Pages as the answer gives them.
     *
     * @param array<int,array<string,mixed>> $list The pages shown.
     * @param array<string,mixed>            $read From read().
     * @return array<int,array<string,mixed>>
     */
    private static function rows(array $list, array $read) {
        $text   = SEOProAnalytics_Query::texts(self::shown_ids($list, $read));
        $live   = SEOProAnalytics_Schema::set() === 'live';
        $google = SEOProAnalytics_Inspections::of_pages(array_column($list, 'path_id'));
        $bing   = SEOProAnalytics_Bing_Site_Data::of_pages(array_column($list, 'path_id'));
        $crawl  = SEOProAnalytics_Crawl::of_pages(array_column($list, 'path_id'));
        $out    = array();
        foreach ($list as $item) {
            $row  = $item['row'];
            $id   = (int) $item['path_id'];
            $path = isset($text[$id]) ? (string) $text[$id] : '';
            $same = self::same_paths($id, $read, $text);
            $out[] = array(
                'path_id'  => $id,
                'path'     => $path,
                'url'      => self::url($path),
                // Demo posts are not the site's.
                'post_id'  => $live ? (int) $row['post_id'] : 0,
                'edit_url' => null,
            ) + SEOProAnalytics_Search::metrics($item['sum']['c'], $item['sum']['i'], $item['sum']['p']) + array(
                'findings'         => $item['findings'],
                'facts'            => self::facts_out($row),
                'same_title'       => $same['title'],
                'same_description' => $same['description'],
                // Google's URL Inspection of the page; null until inspected.
                'google'           => isset($google[$id]) ? $google[$id] : null,
                // Bing's crawl issue of the page; null without one.
                'bing'             => isset($bing[$id]) ? $bing[$id] : null,
                // What an imported crawl saw of the page; null when it did not name it.
                'crawl'            => isset($crawl[$id]) ? $crawl[$id] : null,
            );
        }
        return $out;
    }

    /**
     * Path ids whose addresses the answer shows: the pages shown and up to
     * SAME + 1 of each group they share a title or description with.
     *
     * @param array<int,array<string,mixed>> $list The pages shown.
     * @param array<string,mixed>            $read From read().
     * @return int[]
     */
    private static function shown_ids(array $list, array $read) {
        $ids = array_column($list, 'path_id');
        foreach ($list as $item) {
            foreach (array('title', 'description') as $what) {
                if (isset($read['same'][$item['path_id']][$what])) {
                    $ids = array_merge($ids, array_slice($read['groups'][$what . ':' . $read['same'][$item['path_id']][$what]], 0, self::SAME + 1));
                }
            }
        }
        return array_unique($ids);
    }

    /**
     * Up to SAME other pages' addresses sharing a page's title, and its
     * description.
     *
     * @param int                 $id   Path id.
     * @param array<string,mixed> $read From read().
     * @param array<int,string>   $text Addresses by path id.
     * @return array{title:string[],description:string[]}
     */
    private static function same_paths($id, array $read, array $text) {
        $same = array('title' => array(), 'description' => array());
        foreach (array_keys($same) as $what) {
            if (!isset($read['same'][$id][$what])) {
                continue;
            }
            foreach ($read['groups'][$what . ':' . $read['same'][$id][$what]] as $other) {
                if ($other !== $id && isset($text[$other]) && count($same[$what]) < self::SAME) {
                    $same[$what][] = (string) $text[$other];
                }
            }
        }
        return $same;
    }

    /**
     * A facts row as the answer gives it.
     *
     * @param array<string,mixed> $row Facts row.
     * @return array<string,mixed>
     */
    private static function facts_out(array $row) {
        return array(
            'title_length'       => (int) $row['title_len'],
            'seo_title_length'   => (int) $row['seo_title_len'],
            'description_length' => (int) $row['desc_len'],
            'h1'                 => (int) $row['h1'],
            'words'              => (int) $row['words'],
            'images'             => (int) $row['images'],
            'images_no_alt'      => (int) $row['images_no_alt'],
            'noindex'            => (bool) (int) $row['noindex'],
            'canonical_away'     => (bool) (int) $row['canonical_away'],
            'modified'           => (int) $row['modified'] ? gmdate('c', (int) $row['modified']) : null,
            'checked'            => gmdate('c', (int) $row['checked']),
        );
    }

    /**
     * How many pages have facts, and when the oldest and newest were read.
     *
     * @return array{pages:int,oldest:string|null,newest:string|null}
     */
    private static function checked() {
        global $wpdb;
        $table = SEOProAnalytics_Schema::table('page_facts');
        // phpcs:disable WordPress.DB.DirectDatabaseQuery -- our own table, by its post_id key and the ends of its checked key.
        $pages = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i FORCE INDEX (`post_id`) WHERE post_id > 0', $table));
        $ends  = $wpdb->get_row($wpdb->prepare('SELECT MIN(checked) AS o, MAX(checked) AS n FROM %i', $table), ARRAY_A);
        // phpcs:enable
        return array(
            'pages'  => $pages,
            'oldest' => is_array($ends) && (int) $ends['o'] ? gmdate('c', (int) $ends['o']) : null,
            'newest' => is_array($ends) && (int) $ends['n'] ? gmdate('c', (int) $ends['n']) : null,
        );
    }

    // ------------------------------------------------------------------
    // The decision queue's words.

    /**
     * Share of a page's expected clicks its findings put at stake.
     *
     * @param string[] $findings Findings.
     * @return float 0–1.
     */
    public static function share(array $findings) {
        $sum = 0.0;
        foreach ($findings as $finding) {
            $sum += isset(self::SHARE[$finding]) ? self::SHARE[$finding] : 0.0;
        }
        return min(1.0, $sum);
    }

    /**
     * A finding in a few words, for a sentence.
     *
     * @param string              $finding Finding.
     * @param array<string,mixed> $row     The report's row.
     * @return string
     */
    public static function phrase($finding, array $row) {
        $facts = isset($row['facts']) ? (array) $row['facts'] : array();
        $num   = static function ($key) use ($facts) {
            return number_format_i18n(isset($facts[$key]) ? (int) $facts[$key] : 0);
        };
        if (in_array($finding, self::GOOGLE, true)) {
            return SEOProAnalytics_Inspections::phrase($finding, isset($row['google']) && is_array($row['google']) ? $row['google'] : array());
        }
        if ($finding === 'bing_crawl') {
            return SEOProAnalytics_Bing_Site_Data::phrase(isset($row['bing']) && is_array($row['bing']) ? $row['bing'] : null);
        }
        if (in_array($finding, self::CRAWL, true)) {
            return SEOProAnalytics_Crawl::phrase($finding, isset($row['crawl']) && is_array($row['crawl']) ? $row['crawl'] : null);
        }
        switch ($finding) {
            case 'noindex':
                return __('it asks search engines not to index it', 'seoproanalytics');
            case 'canonical':
                return __('its canonical address is another page', 'seoproanalytics');
            case 'thin':
                $n = isset($facts['words']) ? (int) $facts['words'] : 0;
                /* translators: %s: number of words */
                return sprintf(_n('only %s word and no clicks', 'only %s words and no clicks', $n, 'seoproanalytics'), $num('words'));
            case 'title_missing':
                return __('no title', 'seoproanalytics');
            case 'title_duplicate':
                $n = max(1, count((array) $row['same_title']));
                /* translators: %s: number of pages */
                return sprintf(_n('the same title as %s other page', 'the same title as %s other pages', $n, 'seoproanalytics'), number_format_i18n($n));
            case 'title_long':
                /* translators: %s: number of characters */
                return sprintf(__('a %s-character title', 'seoproanalytics'), $num('title_length'));
            case 'description_missing':
                return __('no description', 'seoproanalytics');
            case 'description_duplicate':
                $n = max(1, count((array) $row['same_description']));
                /* translators: %s: number of pages */
                return sprintf(_n('the same description as %s other page', 'the same description as %s other pages', $n, 'seoproanalytics'), number_format_i18n($n));
            case 'description_long':
                /* translators: %s: number of characters */
                return sprintf(__('a %s-character description', 'seoproanalytics'), $num('description_length'));
            case 'h1_none':
                return isset($row['crawl']['h1']) ? __('no H1 as served', 'seoproanalytics') : __('no H1', 'seoproanalytics');
            case 'h1_several':
                return isset($row['crawl']['h1']) ? __('several H1s as served', 'seoproanalytics') : __('several H1s', 'seoproanalytics');
            case 'images_alt':
                $n = isset($facts['images_no_alt']) ? (int) $facts['images_no_alt'] : 0;
                /* translators: %s: number of images */
                return sprintf(_n('%s image without alt text', '%s images without alt text', $n, 'seoproanalytics'), $num('images_no_alt'));
            default:
                return (string) $finding;
        }
    }

    /**
     * What to do about a page's findings.
     *
     * @param string[] $findings Findings.
     * @return string
     */
    public static function todo(array $findings) {
        $do = array(
            'noindex'               => __('Let search engines index the page, or remove it from search on purpose.', 'seoproanalytics'),
            'canonical'             => __('Point its canonical address at itself, unless the other page is meant to rank.', 'seoproanalytics'),
            'crawl_error'           => SEOProAnalytics_Crawl::fix('crawl_error'),
            'served_noindex'        => SEOProAnalytics_Crawl::fix('served_noindex'),
            'served_canonical'      => SEOProAnalytics_Crawl::fix('served_canonical'),
            'served_title'          => SEOProAnalytics_Crawl::fix('served_title'),
            'served_description'    => SEOProAnalytics_Crawl::fix('served_description'),
            'unreachable'           => SEOProAnalytics_Crawl::fix('unreachable'),
            'deep'                  => SEOProAnalytics_Crawl::fix('deep'),
            'slow'                  => SEOProAnalytics_Crawl::fix('slow'),
            'robots_blocked'        => SEOProAnalytics_Inspections::fix('robots_blocked'),
            'not_indexed'           => SEOProAnalytics_Inspections::fix('not_indexed'),
            'google_canonical'      => SEOProAnalytics_Inspections::fix('google_canonical'),
            'bing_crawl'            => SEOProAnalytics_Bing_Site_Data::fix(),
            'broken_links'          => SEOProAnalytics_Crawl::fix('broken_links'),
            'thin'                  => __('Answer the searches it shows for in more depth.', 'seoproanalytics'),
            'title_missing'         => __('Write a title.', 'seoproanalytics'),
            'title_duplicate'       => __('Give it a title of its own.', 'seoproanalytics'),
            /* translators: %d: most characters */
            'title_long'            => sprintf(__('Shorten the title to %d characters.', 'seoproanalytics'), self::TITLE_MAX),
            /* translators: %d: most characters */
            'description_missing'   => sprintf(__('Write a description of up to %d characters.', 'seoproanalytics'), self::DESCRIPTION_MAX),
            'description_duplicate' => __('Give it a description of its own.', 'seoproanalytics'),
            /* translators: %d: most characters */
            'description_long'      => sprintf(__('Shorten the description to %d characters.', 'seoproanalytics'), self::DESCRIPTION_MAX),
            'h1_none'               => __('Give it one main heading.', 'seoproanalytics'),
            'h1_several'            => __('Keep one H1 (the title) and make the others H2.', 'seoproanalytics'),
            'rich_errors'           => SEOProAnalytics_Inspections::fix('rich_errors'),
            'redirect_links'        => SEOProAnalytics_Crawl::fix('redirect_links'),
            'images_alt'            => __('Describe its images in their alt text.', 'seoproanalytics'),
        );
        $out = array();
        foreach ($findings as $finding) {
            if (isset($do[$finding])) {
                $out[] = $do[$finding];
            }
        }
        return implode(' ', $out);
    }

    // ------------------------------------------------------------------
    // Helpers.

    /**
     * Load the classes the audit uses.
     */
    private static function load() {
        require_once __DIR__ . '/class-seoproanalytics-dict.php';
        require_once __DIR__ . '/class-seoproanalytics-query.php';
        require_once __DIR__ . '/class-seoproanalytics-search.php';
        require_once __DIR__ . '/class-seoproanalytics-clicks.php';
        require_once __DIR__ . '/class-seoproanalytics-changes.php';
        require_once __DIR__ . '/class-seoproanalytics-coverage.php';
        require_once __DIR__ . '/class-seoproanalytics-opportunities.php';
        require_once __DIR__ . '/class-seoproanalytics-demo.php';
        require_once __DIR__ . '/class-seoproanalytics-inspections.php';
        require_once __DIR__ . '/class-seoproanalytics-bing-site-data.php';
        require_once __DIR__ . '/class-seoproanalytics-crawl.php';
        require_once __DIR__ . '/class-seoproanalytics-crawl-import.php';
    }

    /**
     * Pages not read yet on live data (the daily cron has not run since
     * the update, or links or published times came since): a first few
     * pages now, for the audit, internal links and indexation reports.
     */
    public static function first_read() {
        if (SEOProAnalytics_Schema::set() !== 'live') {
            return;
        }
        $state = self::state();
        if (!$state['last'] || !$state['links'] || !$state['published']) {
            self::load();
            self::batch(self::CHUNK, 5);
        }
    }

    /**
     * Progress of the current data set.
     *
     * @return array{cursor:int,plugin:string,since:int,version:int,last:int,links:int,published:int}
     */
    public static function state() {
        $state = get_option(SEOProAnalytics_Schema::option(self::OPTION), array());
        $state = is_array($state) ? $state : array();
        return array(
            'cursor'    => isset($state['cursor']) ? (int) $state['cursor'] : 0,
            'plugin'    => isset($state['plugin']) ? (string) $state['plugin'] : '',
            'since'     => isset($state['since']) ? (int) $state['since'] : 0,
            'version'   => isset($state['version']) ? (int) $state['version'] : 0,
            'last'      => isset($state['last']) ? (int) $state['last'] : 0,
            'links'     => isset($state['links']) ? (int) $state['links'] : 0,
            'published' => isset($state['published']) ? (int) $state['published'] : 0,
        );
    }

    /**
     * Note that facts were written, so cached reports are made again.
     *
     * @param int $all_from When every page's links and published times
     *                      were read from (the demo, written at once); 0
     *                      to leave them.
     */
    public static function touch($all_from = 0) {
        $state            = self::state();
        $state['version'] = time();
        if ($all_from && !$state['links']) {
            $state['links'] = (int) $all_from;
        }
        if ($all_from && !$state['published']) {
            $state['published'] = (int) $all_from;
        }
        update_option(SEOProAnalytics_Schema::option(self::OPTION), $state, false);
    }

    /**
     * Delete the current data set's progress (demo removal, uninstall).
     */
    public static function reset() {
        delete_option(SEOProAnalytics_Schema::option(self::OPTION));
    }

    /**
     * Stored titles/descriptions for a bounded list of published pages.
     * Text is read from WordPress (or demo text), never copied to crawl rows.
     * Hashes remain the page_facts snapshot used by the import comparison.
     *
     * @param int[] $ids Path ids.
     * @return array<int,array<string,mixed>>
     */
    public static function stored_text(array $ids) {
        self::load();
        $facts = self::rows_of(SEOProAnalytics_Schema::table('page_facts'), $ids);
        $paths = SEOProAnalytics_Dict::values(array_keys($facts));
        $known = array();
        foreach ($facts as $id => $row) {
            $known[$id] = (int) $row['post_id'];
        }
        $texts = SEOProAnalytics_Coverage::texts($paths, $known);
        $out = array();
        foreach ($facts as $id => $row) {
            $text = isset($texts[$id]) ? $texts[$id] : array();
            $title = self::seo_title($text);
            $out[$id] = array(
                'title' => $title !== '' ? $title : self::line((string) ($text['title'] ?? '')),
                'description' => self::description($text),
                'th' => (string) $row['th'],
                'dh' => (string) $row['dh'],
            );
        }
        return $out;
    }

    /**
     * The 8-byte key of a title or description, as 16 hex digits: its
     * words in lower case. NONE for no text.
     *
     * @param string $text Text.
     * @return string
     */
    public static function hash($text) {
        $key = function_exists('mb_strtolower') ? mb_strtolower((string) $text, 'UTF-8') : strtolower((string) $text);
        $key = trim((string) preg_replace('/\s+/u', ' ', $key));
        return $key === '' ? self::NONE : SEOProAnalytics_Dict::hash($key);
    }

    /**
     * A text on one line: tags out, entities decoded, spaces joined.
     *
     * @param string $text Text.
     * @return string
     */
    public static function line($text) {
        $text = html_entity_decode(wp_strip_all_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Characters of a text.
     *
     * @param string $text Text.
     * @return int
     */
    private static function length($text) {
        return function_exists('mb_strlen') ? (int) mb_strlen((string) $text, 'UTF-8') : strlen((string) $text);
    }

    /**
     * A GMT date and time (Y-m-d H:i:s) as Unix seconds; 0 for none.
     *
     * @param string $gmt Date and time.
     * @return int
     */
    private static function time($gmt) {
        $gmt = (string) $gmt;
        if ($gmt === '' || strpos($gmt, '0000-00-00') === 0) {
            return 0;
        }
        $ts = strtotime($gmt . ' UTC');
        return $ts ? (int) $ts : 0;
    }

    /**
     * A page's address on this site.
     *
     * @param string $path Path.
     * @return string
     */
    private static function url($path) {
        $info = $path !== '' && $path[0] === '/' ? wp_parse_url(home_url('/')) : null;
        if (!is_array($info) || !isset($info['scheme'], $info['host'])) {
            return '';
        }
        return esc_url_raw($info['scheme'] . '://' . $info['host'] . (isset($info['port']) ? ':' . $info['port'] : '') . $path);
    }
}
