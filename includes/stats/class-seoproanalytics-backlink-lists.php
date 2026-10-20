<?php
/**
 * Backlink class lists (Settings → Backlinks): one list per class of
 * sites, pages and words. A link whose linking page matches a list has
 * that class, now and when it is found later, with no write to its row;
 * the report reads the lists (SEOProAnalytics_Backlinks::build()) and its
 * cache key holds their fingerprint, so a change shows at once.
 *
 * A line is a site (example.com, with its subdomains), a page or folder
 * (example.com/blog/), a part of the address (/profile/ or casino), or a
 * pattern where * matches anything (*.example.*). A line starting with !
 * is an exception: what it matches never has the class from this list.
 * Classes of a page, not a site (PAGE_ONLY), ignore whole-site lines and
 * never go to a site. Matching is in memory over the report's bounded,
 * cached read. Layout: docs/architecture.md → Backlinks.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 * Provenance: wpas-c8662019b99fb07b. Copies, even of part, keep these lines.
 *
 * @package SEOProAnalytics
 * @since 1.9.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-type Rule array{0:string,1:string,2:string}
 * @phpstan-type Rules array{domains:array<string,array<string,string>>,prefixes:array<int,Rule>,parts:array<int,Rule>,patterns:array<int,Rule>,except:array<string,array<int,array{0:string,1:string}>>}
 */
final class SEOProAnalytics_Backlink_Lists {

    /**
     * Classes of one page, not a whole site: a site line never gives them
     * (a site's home page or one list post says nothing of its others), and
     * a site is never put on their lists. Pages, folders, parts of the
     * address and patterns still do.
     */
    const PAGE_ONLY = array('needs_updating', 'home_page', 'listicle', 'statistics', 'quote', 'guest_post', 'niche_edit', 'url_linked', 'url_unlinked', 'logo_linked', 'brand_citation', 'guide');

    /** The settings switch: the lists apply while it is on. */
    const SWITCH_KEY = 'backlink_lists';

    /** Each class's list is the setting PREFIX . class name. */
    const PREFIX = 'backlink_class_';

    /** Lines in one list at most (the settings option is autoloaded). */
    const MAX_ENTRIES = 1000;

    /** Sites or pages changed in one request at most. */
    const MAX_SITES = 500;

    /**
     * Lists filled in at first: sites whose links are nearly always of
     * that class. The owner edits them in Settings → Backlinks.
     */
    const PRESETS = array(
        // Pages made by machine for every domain (site worth, traffic
        // guesses, similar sites): they link to every site and send no one.
        // And addresses with "backlink" in them: link sellers and checkers.
        'spam'          => array('hypestat.com', 'statscrop.com', 'siteprice.org', 'sitelike.org', 'websiteoutlook.com', 'website.informer.com', 'webstatsdomain.org', 'backlink'),
        'profile_page'  => array('linktr.ee', 'beacons.ai', 'bio.link', 'lnk.bio', 'about.me', 'carrd.co', 'taplink.cc', 'campsite.bio', 'solo.to', 'linkin.bio', 'gravatar.com', '/profile/', '/profiles/', '/members/', '/users/', '/user/', '/author/'),
        'social_media'  => array('facebook.com', 'fb.com', 'instagram.com', 'x.com', 'twitter.com', 't.co', 'linkedin.com', 'lnkd.in', 'youtube.com', 'youtu.be', 'tiktok.com', 'pinterest.com', 'pin.it', 'reddit.com', '!reddit.com/r/', 'threads.net', 'threads.com', 'bsky.app', 'mastodon.social', 'tumblr.com', 'quora.com', 'snapchat.com', 'discord.com', 't.me', 'vk.com', 'weibo.com'),
        'press_release' => array('prnewswire.com', 'businesswire.com', 'globenewswire.com', 'einpresswire.com', 'accesswire.com', 'prweb.com', 'newswire.com', 'openpr.com', 'prlog.org'),
        'blog'          => array('/blog/', 'blogspot.com', 'wordpress.com', 'medium.com', 'substack.com'),
        'listicle'      => array('/best-', '/top-'),
        'statistics'    => array('statistics', '/stats/'),
        'quote'         => array('/quotes/', 'brainyquote.com', 'goodreads.com/quotes/'),
        'wiki'          => array('wikipedia.org', 'wikimedia.org', 'wikidata.org', 'fandom.com', '/wiki/'),
        // Reddit's communities are forums; Social Media leaves them out (!reddit.com/r/).
        'forum'         => array('reddit.com/r/', '/forum/', '/forums/', '/viewtopic', '/showthread', '/discussion/'),
        'documentation' => array('/docs/', '/documentation/', 'readthedocs.io'),
        'video'         => array('vimeo.com', 'dailymotion.com', '/video/', '/videos/'),
        'guide'         => array('/howto', '/how-to', '/guide'),
    );

    /**
     * Each list's example lines, shown while it is empty: the kinds of
     * sites and addresses the class is for.
     */
    const PLACEHOLDERS = array(
        'brand_citation'  => "/reviews/\nexample.com/news/",
        'nap_citation'    => "yelp.com\n/business-directory/",
        'cross_site'      => "our-other-site.com\nshop.our-brand.com",
        'documentation'   => "docs.example.com\n/docs/",
        'forum'           => "reddit.com/r/\n/forum/",
        'general'         => "news.example.com\nexample-directory.com",
        'guest_post'      => "/guest-post/\n/contributors/",
        'guide'           => "/how-to\n/guide",
        'home_page'       => "*example.com/\n*.example.com/",
        'integration'     => "wordpress.org/plugins/\n/integrations/",
        'link_swap'       => "our-swap-partner.com\nexample.com/resources/",
        'listicle'        => "/best-\n/top-",
        'logo_linked'     => "/sponsors/\n/partners/",
        'monitor'         => "competitor.com\nexample.com/resources/",
        'needs_updating'  => "example.com/old-post/\n/2019/",
        'niche_edit'      => "example.com/2021/an-older-article/\n/2020/",
        'organic_mention' => "news.example.com\n/review/",
        'paid_link'       => "/sponsored/\n/advertorial/",
        'partner'         => "our-partner.com\nour-client.com",
        'press_organic'   => "bbc.co.uk\nnews.example.com",
        'press_release'   => "prnewswire.com\n/press-release/",
        'private_blog'    => "network-site.com\n*-reviews-*.com",
        'profile_page'    => "linktr.ee\n/profile/",
        'blog'            => "/blog/\nmedium.com",
        'quote'           => "/quotes/\ngoodreads.com/quotes/",
        'redirect'        => "our-old-domain.com\n/go/",
        'site_footer'     => "theme-maker.com\nour-web-agency.com",
        'social_media'    => "facebook.com\nx.com",
        'statistics'      => "/statistics/\n/stats/",
        'topical'         => "same-subject-site.com\n/category/our-subject/",
        'url_linked'      => "/links/\n/resources/",
        'url_unlinked'    => "/mentions/\nexample.com/article-naming-us/",
        'video'           => "youtube.com/watch\n/videos/",
        'wiki'            => "wikipedia.org\n/wiki/",
        'ignore'          => "staging.our-site.com\n*.local",
        'spam'            => "hypestat.com\nbacklink",
    );

    /** @var Rules|null Compiled lists of this request. */
    private static $rules = null;

    /** @var array<string,array<string,string>> Matches of this request by address. */
    private static $seen = array();

    /**
     * The settings: the switch, then one list per class (the classes'
     * order), on the Backlinks tab.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function settings() {
        $out = array(
            // The classes themselves: drawn as one list by
            // admin/js/seoproanalytics-backlink-classes.js, the three
            // textareas without it. Each site's own (bits are per site).
            SEOProAnalytics_Backlinks_Triage::OWN_KEY    => array(
                'type'        => 'lines',
                'default'     => '',
                'tab'         => 'backlinks',
                'network'     => false,
                'panel'       => true,
                'reload'      => true,
                'rows'        => 3,
                'label'       => __('Classes', 'seoproanalytics'),
                'description' => __('The classes offered in Search → Backlinks to class links, pages and sites. Untick one to stop offering, showing and counting it: links keep it, and it comes back when ticked again. Add the site\'s own. Classes hidden until ticked are left out of the links lists until chosen in Classes, after the line.', 'seoproanalytics'),
            ),
            SEOProAnalytics_Backlinks_Triage::OFF_KEY    => array(
                'type'        => 'lines',
                'default'     => '',
                'network'     => false,
                'parent'      => SEOProAnalytics_Backlinks_Triage::OWN_KEY,
                'reload'      => true,
                'rows'        => 3,
                'label'       => __('Classes turned off', 'seoproanalytics'),
                'description' => __('One class key a line, such as guest_post.', 'seoproanalytics'),
            ),
            SEOProAnalytics_Backlinks_Triage::HIDDEN_KEY => array(
                'type'        => 'lines',
                'default'     => implode("\n", SEOProAnalytics_Backlinks_Triage::HIDDEN_DEFAULT),
                'network'     => false,
                'parent'      => SEOProAnalytics_Backlinks_Triage::OWN_KEY,
                'rows'        => 3,
                'label'       => __('Classes hidden until ticked', 'seoproanalytics'),
                'description' => __('One class key a line, such as spam.', 'seoproanalytics'),
            ),
            self::SWITCH_KEY => array(
                'type'        => 'bool',
                'default'     => true,
                'tab'         => 'backlinks',
                'label'       => __('Class links by the site linking', 'seoproanalytics'),
                'description' => __('Give every link from a site or page on a list below that list\'s class, in Search → Backlinks, the REST API and WP-CLI: now, and when links from it are found later. Classing a link in Search → Backlinks can add its site here. One per line: a site (example.com, also its subdomains), a page or folder (example.com/blog/), part of the address (/profile/ or casino), or a pattern where * matches anything (*.example.*). Start a line with ! for an exception (!news.example.com): what it matches never has the class from this list. Taking a class off a link, page or site in Search → Backlinks keeps it off. Only the classes in use (Classes, above) have a list.', 'seoproanalytics'),
            ),
        );
        // A to Z by the names in the site's language, hidden ones after, as in Search → Backlinks.
        $labels = SEOProAnalytics_Backlinks_Triage::labels();
        $last   = array_intersect_key($labels, array_flip(SEOProAnalytics_Backlinks_Triage::hidden()));
        $labels = array_diff_key($labels, $last);
        uasort($labels, 'strnatcasecmp');
        uasort($last, 'strnatcasecmp');
        foreach ($labels + $last as $name => $label) {
            $page_only = in_array($name, self::PAGE_ONLY, true);
            $out[self::key($name)] = array(
                'type'        => 'lines',
                'default'     => isset(self::PRESETS[$name]) ? implode("\n", self::PRESETS[$name]) : '',
                'parent'      => self::SWITCH_KEY,
                'rows'        => 3,
                'label'       => $label,
                'description' => SEOProAnalytics_Backlinks_Triage::hint($name) . ($page_only ? ' ' . __('A page\'s class, not a site\'s: pages, folders, parts of addresses and patterns only; a whole site (example.com) on this list does nothing.', 'seoproanalytics') : ''),
                'placeholder' => isset(self::PLACEHOLDERS[$name]) ? self::PLACEHOLDERS[$name] : "example.com\n/folder/",
            );
        }
        return $out;
    }

    /**
     * A class's setting key.
     *
     * @param string $name Class name.
     * @return string
     */
    public static function key($name) {
        return self::PREFIX . $name;
    }

    /**
     * Whether the lists apply.
     *
     * @return bool
     */
    public static function on() {
        return (bool) SEOProAnalytics_Settings::get(self::SWITCH_KEY);
    }

    /**
     * A class's lines as stored, at most MAX_ENTRIES.
     *
     * @param string $name Class name.
     * @return string[]
     */
    public static function lines($name) {
        $lines = preg_split('/[\r\n]+/', (string) SEOProAnalytics_Settings::get(self::key($name))) ?: array();
        $lines = array_values(array_filter(array_map('trim', $lines), static function ($line) {
            return $line !== '';
        }));
        return array_slice($lines, 0, self::MAX_ENTRIES);
    }

    /**
     * Every class's lines, for GET /backlinks/lists and WP-CLI.
     *
     * @return array{enabled:bool,lists:array<string,string[]>,page_only:string[]}
     */
    public static function all() {
        $lists = array();
        foreach (array_keys(SEOProAnalytics_Backlinks_Triage::classes()) as $name) {
            $lists[$name] = self::lines($name);
        }
        return array(
            'enabled'   => self::on(),
            'lists'     => $lists,
            'page_only' => array_values(array_intersect(self::PAGE_ONLY, array_keys($lists))),
            'classes'   => SEOProAnalytics_Backlinks_Triage::boot(),
            'off'       => SEOProAnalytics_Backlinks_Triage::registry()['off'],
        );
    }

    /**
     * A line in its compared form: lower case, without the scheme, www.,
     * query or fragment; '' when it cannot match anything.
     *
     * @param string $line A line, a host or an address.
     * @return string
     */
    public static function normal($line) {
        $entry = strtolower(trim((string) $line));
        $entry = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $entry);
        $entry = (string) preg_replace('/[?#].*$/', '', $entry);
        $entry = (string) preg_replace('/^www\./', '', $entry);
        return $entry === '' || preg_match('/\s/', $entry) ? '' : $entry;
    }

    /**
     * The lists compiled once per request: sites by domain, address
     * prefixes, parts of the address and patterns, each with its class
     * and line, and each class's exceptions.
     *
     * @return Rules
     */
    public static function rules() {
        if (self::$rules !== null) {
            return self::$rules;
        }
        $rules = self::empty_rules();
        if (self::on()) {
            foreach (array_keys(SEOProAnalytics_Backlinks_Triage::classes()) as $name) {
                foreach (self::lines($name) as $line) {
                    self::compile($rules, $name, $line);
                }
            }
        }
        self::$rules = $rules;
        self::$seen  = array();
        return $rules;
    }

    /**
     * Compiled lists with nothing on them.
     *
     * @return Rules
     */
    private static function empty_rules() {
        return array('domains' => array(), 'prefixes' => array(), 'parts' => array(), 'patterns' => array(), 'except' => array());
    }

    /**
     * What kind of line an entry is, and its compared form: pattern (a
     * regular expression), part, prefix or domain; null when it matches
     * nothing.
     *
     * @param string $entry A line in its compared form (normal()).
     * @return array{0:string,1:string}|null
     */
    private static function kind($entry) {
        if ($entry === '') {
            return null;
        }
        if (strpos($entry, '*') !== false) {
            return array('pattern', '/^' . str_replace('\*', '.*', preg_quote($entry, '/')) . '$/');
        }
        if ($entry[0] === '/' || (strpos($entry, '.') === false && strpos($entry, '/') === false)) {
            return array('part', $entry);
        }
        if (strpos($entry, '/') !== false) {
            return array('prefix', $entry);
        }
        return preg_match('/^[a-z0-9.-]+$/', $entry) ? array('domain', trim($entry, '.')) : null;
    }

    /**
     * Add one line to the compiled lists. A whole-site line on a page-only
     * class's list does nothing; a line starting with ! is an exception.
     *
     * @param Rules  $rules Compiled lists; added to.
     * @param string $name  Class name.
     * @param string $line  The line as stored.
     * @param-out Rules $rules
     */
    private static function compile(array &$rules, $name, $line) {
        $line   = trim((string) $line);
        $except = $line !== '' && $line[0] === '!';
        $kind   = self::kind(self::normal($except ? substr($line, 1) : $line));
        if ($kind === null) {
            return;
        }
        if ($except) {
            $rules['except'][$name][] = $kind;
            return;
        }
        switch ($kind[0]) {
            case 'pattern':
                $rules['patterns'][] = array($kind[1], $name, $line);
                break;
            case 'part':
                $rules['parts'][] = array($kind[1], $name, $line);
                break;
            case 'prefix':
                $rules['prefixes'][] = array($kind[1], $name, $line);
                break;
            default:
                if (!in_array($name, self::PAGE_ONLY, true) && !isset($rules['domains'][$kind[1]][$name])) {
                    $rules['domains'][$kind[1]][$name] = $line;
                }
        }
    }

    /**
     * Whether one compiled line matches a host and address.
     *
     * @param array{0:string,1:string} $kind    From kind().
     * @param string                   $host    Host, compared form.
     * @param string                   $address Host and path.
     * @return bool
     */
    private static function hits(array $kind, $host, $address) {
        switch ($kind[0]) {
            case 'pattern':
                return (bool) (preg_match($kind[1], $host) || preg_match($kind[1], $address));
            case 'part':
                return strpos($address, $kind[1]) !== false;
            case 'prefix':
                return strpos($address, $kind[1]) === 0;
            default:
                return $host === $kind[1] || substr($host, -strlen('.' . $kind[1])) === '.' . $kind[1];
        }
    }

    /**
     * A fingerprint of the lists, for the report's cache key.
     *
     * @return string
     */
    public static function fingerprint() {
        // The classes in use too: turning one off or on changes every row's classes.
        return md5((string) wp_json_encode(array(self::rules(), SEOProAnalytics_Backlinks_Triage::classes()))); // NOSONAR: a cache key, not security.
    }

    /**
     * The classes a linking page or site has from the lists, each with
     * the line that gave it, in the classes' order.
     *
     * @param string $host Its host.
     * @param string $path Its path ('' for the site itself: no page-only class).
     * @return array<string,string> Class name => line.
     */
    public static function match($host, $path = '') {
        $rules = self::rules();
        $key   = self::normal($host) . ' ' . strtolower((string) $path);
        if (isset(self::$seen[$key])) {
            return self::$seen[$key];
        }
        $sorted = self::test($rules, $host, $path);
        if (count(self::$seen) < 20000) {
            self::$seen[$key] = $sorted;
        }
        return $sorted;
    }

    /**
     * The classes compiled lists give a site or page, with the lines.
     *
     * @param Rules  $rules From rules() or compile().
     * @param string $host  Its host.
     * @param string $path  Its path ('' for the site itself).
     * @return array<string,string> Class name => line, in the classes' order.
     */
    private static function test(array $rules, $host, $path) {
        $host = self::normal($host);
        if ($host === '' || (!$rules['domains'] && !$rules['prefixes'] && !$rules['parts'] && !$rules['patterns'])) {
            return array();
        }
        $site    = (string) $path === '';
        $path    = strtolower((string) $path);
        $address = $host . ($path === '' || $path[0] !== '/' ? '/' . $path : $path);
        $out     = array();
        $labels = explode('.', $host);
        // The host and each domain it is under: a.b.example.com, b.example.com, example.com, com.
        for ($i = 0, $n = count($labels); $i < $n; $i++) {
            $domain = implode('.', array_slice($labels, $i));
            if (isset($rules['domains'][$domain])) {
                $out += $rules['domains'][$domain];
            }
        }
        foreach ($rules['prefixes'] as $rule) {
            if (!isset($out[$rule[1]]) && strpos($address, $rule[0]) === 0) {
                $out[$rule[1]] = $rule[2];
            }
        }
        foreach ($rules['parts'] as $rule) {
            if (!isset($out[$rule[1]]) && strpos($address, $rule[0]) !== false) {
                $out[$rule[1]] = $rule[2];
            }
        }
        foreach ($rules['patterns'] as $rule) {
            if (!isset($out[$rule[1]]) && (preg_match($rule[0], $host) || preg_match($rule[0], $address))) {
                $out[$rule[1]] = $rule[2];
            }
        }
        $sorted = array();
        foreach (array_keys(SEOProAnalytics_Backlinks_Triage::classes()) as $name) {
            if (!isset($out[$name]) || ($site && in_array($name, self::PAGE_ONLY, true))) {
                continue;
            }
            foreach (isset($rules['except'][$name]) ? $rules['except'][$name] : array() as $kind) {
                if (self::hits($kind, $host, $address)) {
                    continue 2;
                }
            }
            $sorted[$name] = $out[$name];
        }
        return $sorted;
    }

    /**
     * The classes a linking page has from the lists, by its address.
     *
     * @param string $url The linking page (https://host/path).
     * @return array<string,string> Class name => line.
     */
    public static function match_url($url) {
        $parts = wp_parse_url((string) $url);
        if (!is_array($parts) || empty($parts['host'])) {
            return array();
        }
        return self::match((string) $parts['host'], isset($parts['path']) ? (string) $parts['path'] : '/');
    }

    /**
     * Add sites or pages to classes' lists, take them off, or put them on
     * exactly these classes' lists (set: off every other list). Taking one
     * off removes its own line and, when another line (its domain, a part
     * or a pattern) still gives it the class, adds an exception (!line);
     * adding one removes its exception. A site never goes on a page-only
     * class's list (skipped). A list that does not change is not saved.
     * Adding turns the lists on.
     *
     * @param string[] $sites Sites (hosts) or pages (addresses).
     * @param string[] $names Class names.
     * @param string   $mode  set, add or remove.
     * @return array{updated:int,sites:string[],classes:string[],mode:string,skipped:string[]}|WP_Error
     */
    public static function change(array $sites, array $names, $mode) {
        $entries = array();
        foreach ($sites as $site) {
            $entry = self::normal($site);
            $entry = strpos($entry, '/') === strlen($entry) - 1 && substr_count($entry, '/') === 1 ? rtrim($entry, '/') : $entry;
            if ($entry !== '' && strpos($entry, '.') !== false && preg_match('#^[a-z0-9.-]+(/\S*)?$#', $entry)) {
                $entries[$entry] = $entry;
            }
        }
        if (!$entries || count($entries) > self::MAX_SITES) {
            /* translators: %d: most sites per request */
            return new WP_Error('seoproanalytics_backlinks_filter', sprintf(__('Give from 1 to %d sites (example.com) or pages (example.com/page/).', 'seoproanalytics'), self::MAX_SITES), array('status' => 400));
        }
        $pages   = array_filter($entries, static function ($entry) {
            return strpos($entry, '/') !== false;
        });
        $updated = 0;
        $skipped = array();
        foreach (array_keys(SEOProAnalytics_Backlinks_Triage::classes()) as $name) {
            $chosen = in_array($name, $names, true);
            if (($mode === 'add' || $mode === 'remove') && !$chosen) {
                continue;
            }
            // A page-only class takes pages, never whole sites.
            $these = in_array($name, self::PAGE_ONLY, true) ? $pages : $entries;
            if (!$these) {
                $skipped[] = $chosen && $mode !== 'remove' ? $name : '';
                continue;
            }
            $lines = self::lines_all($name);
            $next  = ($mode === 'add' || ($mode === 'set' && $chosen)) ? self::with(self::without($lines, self::excepted($these)), $these) : self::off($name, self::without($lines, $these), $these);
            if ($next === $lines) {
                continue;
            }
            if ($mode !== 'remove' && count($next) > self::MAX_ENTRIES) {
                /* translators: 1: class name, 2: most lines in a list */
                return new WP_Error('seoproanalytics_backlinks_filter', sprintf(__('The %1$s list is full (%2$d lines).', 'seoproanalytics'), $name, self::MAX_ENTRIES), array('status' => 400));
            }
            $saved = SEOProAnalytics_Settings::set(self::key($name), implode("\n", $next));
            if (is_wp_error($saved)) {
                return $saved;
            }
            ++$updated;
        }
        if ($mode !== 'remove' && $names && !self::on()) {
            $saved = SEOProAnalytics_Settings::set(self::SWITCH_KEY, true);
            if (is_wp_error($saved)) {
                return $saved;
            }
        }
        self::flush();
        return array('updated' => $updated, 'sites' => array_values($entries), 'classes' => array_values($names), 'mode' => $mode, 'skipped' => array_values(array_filter($skipped)));
    }

    /**
     * Entries as exception lines (!entry), keyed by their compared form.
     *
     * @param array<string,string> $entries Compared forms.
     * @return array<string,string>
     */
    private static function excepted(array $entries) {
        $out = array();
        foreach ($entries as $entry) {
            $out['!' . $entry] = '!' . $entry;
        }
        return $out;
    }

    /**
     * A class's lines with exceptions added for the entries the lines
     * still give the class (by their domain, a part or a pattern).
     *
     * @param string               $name    Class name.
     * @param string[]             $lines   The lines without the entries' own.
     * @param array<string,string> $entries Compared forms.
     * @return string[]
     */
    private static function off($name, array $lines, array $entries) {
        $rules = self::empty_rules();
        foreach ($lines as $line) {
            self::compile($rules, $name, $line);
        }
        $still = array();
        foreach ($entries as $entry) {
            $slash = strpos($entry, '/');
            $host  = $slash === false ? $entry : substr($entry, 0, $slash);
            $path  = $slash === false ? '' : substr($entry, $slash);
            if (isset(self::test($rules, $host, $path)[$name])) {
                $still['!' . $entry] = '!' . $entry;
            }
        }
        return $still ? self::with($lines, $still) : $lines;
    }

    /**
     * A class's stored lines, every one.
     *
     * @param string $name Class name.
     * @return string[]
     */
    private static function lines_all($name) {
        $lines = preg_split('/[\r\n]+/', (string) SEOProAnalytics_Settings::get(self::key($name))) ?: array();
        return array_values(array_filter(array_map('trim', $lines), static function ($line) {
            return $line !== '';
        }));
    }

    /**
     * Lines with the entries added (those not there already).
     *
     * @param string[]              $lines   Lines.
     * @param array<string,string> $entries Compared forms.
     * @return string[]
     */
    private static function with(array $lines, array $entries) {
        $have = array();
        foreach ($lines as $line) {
            $have[self::normal($line)] = true;
        }
        foreach ($entries as $entry) {
            if (!isset($have[$entry])) {
                $lines[] = $entry;
            }
        }
        return $lines;
    }

    /**
     * Lines without those equal to an entry.
     *
     * @param string[]              $lines   Lines.
     * @param array<string,string> $entries Compared forms.
     * @return string[]
     */
    private static function without(array $lines, array $entries) {
        return array_values(array_filter($lines, static function ($line) use ($entries) {
            $entry = self::normal($line);
            return !isset($entries[$entry]) && !isset($entries[rtrim($entry, '/')]);
        }));
    }

    /**
     * Forget the compiled lists (after a settings change in this request).
     */
    public static function flush() {
        self::$rules = null;
        self::$seen  = array();
    }
}
