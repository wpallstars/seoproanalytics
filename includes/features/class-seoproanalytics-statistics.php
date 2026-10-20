<?php
/**
 * The statistics' settings: what is collected (Tracking), who and what is
 * left out (Privacy), and how long visits are kept and who reads them
 * (Data). The engine in includes/stats/ reads them through the helpers
 * below; its filters (README.md → Developers) still apply on top.
 *
 * Saving rewrites the collector's config file, which collect.php reads
 * without WordPress. Visitor pages read the settings from the autoloaded
 * option, with no query. Design: docs/architecture.md.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 * Provenance: wpas-c8662019b99fb07b. Copies, even of part, keep these lines.
 *
 * @package SEOProAnalytics
 * @since 0.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProAnalytics_Statistics extends SEOProAnalytics_Feature {

    /** Collect statistics: the tracker is printed and hits are stored. */
    const KEY = 'tracking';

    /** Headers that may hold the visitor's address behind a proxy ($_SERVER keys). */
    const IP_HEADERS = array(
        'HTTP_CF_CONNECTING_IP' => 'CF-Connecting-IP (Cloudflare)',
        'HTTP_TRUE_CLIENT_IP'   => 'True-Client-IP (Akamai, Cloudflare Enterprise)',
        'HTTP_X_REAL_IP'        => 'X-Real-IP (nginx)',
        'HTTP_X_FORWARDED_FOR'  => 'X-Forwarded-For',
    );

    /** Headers that may hold the visitor's country ($_SERVER keys). */
    const COUNTRY_HEADERS = array(
        'HTTP_CLOUDFRONT_VIEWER_COUNTRY' => 'CloudFront-Viewer-Country (Amazon CloudFront)',
        'HTTP_X_COUNTRY_CODE'            => 'X-Country-Code',
        'GEOIP_COUNTRY_CODE'             => 'GEOIP_COUNTRY_CODE (the web server\'s GeoIP module)',
    );

    /**
     * Settings schema entries.
     *
     * @return array<string,array>
     */
    public static function settings() {
        return array(
            'alerts' => array(
                'type' => 'bool', 'default' => true, 'tab' => 'alerts',
                'label' => __('Email when statistics stop being collected or processed', 'seoproanalytics'),
                'description' => __('One combined email per hour at most, weekly reminders and a recovery email. Notices stay visible when email is off.', 'seoproanalytics'),
            ),
            'alerts_to' => array(
                'type' => 'lines', 'default' => '', 'parent' => 'alerts', 'network' => false,
                'label' => __('Email addresses', 'seoproanalytics'),
                'description' => __('One address per line (up to twenty). Empty uses the site administration email. Invalid addresses are ignored.', 'seoproanalytics'),
            ),
            'alerts_kinds' => array(
                'type' => 'multi', 'parent' => 'alerts',
                'default' => array('hits_stopped', 'jobs_late', 'summaries_behind', 'collector_broken', 'source_failing', 'email_failing'),
                'label' => __('Which alerts email', 'seoproanalytics'),
                'options' => array(
                    'hits_stopped' => __('Visits stopped', 'seoproanalytics'),
                    'jobs_late' => __('Scheduled jobs late', 'seoproanalytics'),
                    'summaries_behind' => __('Daily summaries behind', 'seoproanalytics'),
                    'collector_broken' => __('Collector unavailable', 'seoproanalytics'),
                    'source_failing' => __('Connected source failing', 'seoproanalytics'),
                    'email_failing' => __('Report email failing', 'seoproanalytics'),
                ),
            ),
            'crawl_site' => array(
                'type' => 'bool', 'default' => false, 'tab' => 'tracking', 'network' => false, 'panel' => true,
                'label' => __('Crawl this site', 'seoproanalytics'),
                'description' => __('Opt in to a built-in HTML crawl in cron: weekly or manually, up to 5,000 pages and depth 10, with a half-second delay. Respects robots.txt and noindex/nofollow. Check server readiness in Options first.', 'seoproanalytics'),
            ),
            'crawl_external' => array(
                'type' => 'bool', 'default' => false, 'parent' => 'crawl_site', 'network' => false,
                'label' => __('Also check links to other websites', 'seoproanalytics'),
                'description' => __('Separate consent to contact link targets: HEAD, GET only for 405/501, no redirects or further crawling. At most 1,000 targets per UTC day, with a per-host delay. Check external readiness first.', 'seoproanalytics'),
            ),
            'crawl_schedule' => array(
                'type' => 'select', 'default' => 'weekly', 'parent' => 'crawl_site', 'network' => false,
                'label' => __('Crawl schedule', 'seoproanalytics'),
                'options' => array('weekly' => __('Weekly', 'seoproanalytics'), 'manual' => __('Manual only', 'seoproanalytics')),
            ),
            // Shared reports: defaults for new reports (SEOProAnalytics_Shares::defaults()).
            // Only switches and panels have controls on a card, so the fields
            // sit in the panels of the Agency branding and Report colours cards.
            'share_brand'             => array(
                'type'        => 'bool',
                'default'     => false,
                'tab'         => 'shared-reports',
                'label'       => __('Agency branding', 'seoproanalytics'),
                'description' => __('Put your agency\'s logo, name, website and a byline at the foot of new shared reports. Off: reports carry only the site\'s own name and logo.', 'seoproanalytics'),
            ),
            'share_agency'            => array('type' => 'text', 'default' => '', 'parent' => 'share_brand', 'label' => __('Agency name', 'seoproanalytics')),
            'share_website'           => array('type' => 'url', 'default' => '', 'parent' => 'share_brand', 'label' => __('Agency website', 'seoproanalytics'), 'placeholder' => 'https://'),
            'share_byline'            => array('type' => 'text', 'default' => '', 'parent' => 'share_brand', 'label' => __('Byline', 'seoproanalytics'), 'description' => __('Words before the agency\'s name, such as “Prepared by”.', 'seoproanalytics')),
            'share_agency_logo'       => array('type' => 'media', 'default' => 0, 'parent' => 'share_brand', 'label' => __('Agency logo', 'seoproanalytics'), 'description' => __('A PNG, JPEG, WebP or GIF picture from this site\'s Media Library.', 'seoproanalytics')),
            'share_accent'            => array(
                'type'        => 'text',
                'network'     => false,
                'default'     => '#2271b1',
                'tab'         => 'shared-reports',
                // The colour picker is drawn in the panel (SEOProAnalytics_Share_Settings).
                'panel'       => true,
                'label'       => __('Report colours', 'seoproanalytics'),
                'description' => __('The colour of the report\'s chart, links and buttons, and whether it opens light or dark.', 'seoproanalytics'),
            ),
            'share_mode'              => array(
                'type'        => 'select',
                'default'     => 'system',
                'parent'      => 'share_accent',
                'label'       => __('Light or dark', 'seoproanalytics'),
                'description' => __('How the report opens. Readers can switch between light and dark on the report.', 'seoproanalytics'),
                'options'     => array(
                    'system' => __('Follow the reader\'s device', 'seoproanalytics'),
                    'light'  => __('Light', 'seoproanalytics'),
                    'dark'   => __('Dark', 'seoproanalytics'),
                ),
            ),
            'share_credit'            => array(
                'type'        => 'bool',
                'default'     => true,
                'tab'         => 'shared-reports',
                'label'       => __('Show “Statistics logged by SEO Pro Analytics for WordPress”', 'seoproanalytics'),
                'description' => __('A short line at the foot of new shared reports.', 'seoproanalytics'),
            ),
            self::KEY                 => array(
                'type'        => 'bool',
                'default'     => true,
                'tab'         => 'tracking',
                'label'       => __('Collect statistics', 'seoproanalytics'),
                'description' => __('Count visits, pages and events with a small script printed in the site\'s pages. It sets no cookies and stores no IP addresses. Off: no script is printed and new hits are not stored; the statistics collected so far stay.', 'seoproanalytics'),
            ),
            'tracking_skip_roles'     => array(
                'type'        => 'multi',
                'network'     => false,
                'default'     => self::editor_roles(),
                'parent'      => self::KEY,
                'options'     => array('SEOProAnalytics_Feature', 'role_options'),
                'label'       => __('Not counted when logged in', 'seoproanalytics'),
                'description' => __('People with these roles see the site without being counted. Visitors who are not logged in are always counted.', 'seoproanalytics'),
            ),
            'tracking_params'         => array(
                'type'        => 'lines',
                'default'     => '',
                'parent'      => self::KEY,
                'rows'        => 3,
                'label'       => __('Query parameters to keep', 'seoproanalytics'),
                'description' => __('Page addresses keep UTM tags and WordPress\'s own page parameters, and drop the rest. Add others that pick out a page, one per line (for example lang). Letters, digits, dots, dashes and underscores only.', 'seoproanalytics'),
                'placeholder' => 'lang',
            ),
            'tracking_hosts'          => array(
                'type'        => 'domains',
                'network'     => false,
                'default'     => '',
                'parent'      => self::KEY,
                'rows'        => 3,
                'label'       => __('Other domains of this site', 'seoproanalytics'),
                'description' => __('Domains that show this same site, one per line. Their pages are counted, and links to them are not outbound. With and without www. are both included.', 'seoproanalytics'),
                'placeholder' => 'example.org',
            ),
            'tracking_clicks'         => array(
                'type'        => 'bool',
                'default'     => true,
                'parent'      => self::KEY,
                'label'       => __('Count clicks and form submits', 'seoproanalytics'),
                'description' => __('What people click (links, buttons, images), clicks that do nothing, and forms sent. Never what is typed or chosen in a form; emails and long numbers in labels are hidden. Add data-sps-mask to an element to leave out its text.', 'seoproanalytics'),
            ),
            'tracking_search'         => array(
                'type'        => 'bool',
                'default'     => true,
                'parent'      => self::KEY,
                'label'       => __('Record site search words', 'seoproanalytics'),
                'description' => __('What people search for on the site, to show what they look for and what it does not have. Emails and long numbers are hidden. Off: searches and searches with no results are still counted, without their words.', 'seoproanalytics'),
            ),
            'tracking_ab_visit'       => array(
                'type'        => 'bool',
                'default'     => false,
                'parent'      => self::KEY,
                'label'       => __('Show each visit one variant of an A/B test', 'seoproanalytics'),
                'description' => __('Off: each page load picks a variant again and nothing is stored in the browser. On: the variant a visitor saw first is kept in the browser tab\'s session storage (seoproan-ab- and the test) until the tab is closed, so pages seen again show the same one. Privacy law treats browser storage like cookies, so this may need the visitor\'s consent.', 'seoproanalytics'),
            ),
            'tracking_affiliate'      => array(
                'type'        => 'lines',
                'default'     => "/go/*\n/recommends/*",
                'parent'      => self::KEY,
                'rows'        => 3,
                'label'       => __('Affiliate link paths', 'seoproanalytics'),
                'description' => __('Paths of this site that forward to affiliate offers, one per line; * matches any characters. Links to them, and links marked rel="sponsored", send an Affiliate link event, which a goal can count.', 'seoproanalytics'),
                'placeholder' => '/go/*',
            ),
            'purchases'               => array(
                'type'        => 'bool',
                'default'     => true,
                'parent'      => self::KEY,
                'label'       => __('Record purchases', 'seoproanalytics'),
                'description' => __('Paid orders from WooCommerce, Easy Digital Downloads, FluentCart and ThriveCart send a Purchase event with the order\'s total in its currency, on the visit that placed it, once per order, which goals and funnels can count. Only orders placed on the site by a counted visitor; no order numbers or customer details are kept.', 'seoproanalytics'),
            ),
            'forms'                   => array(
                'type'        => 'bool',
                'default'     => true,
                'parent'      => self::KEY,
                'label'       => __('Form submissions', 'seoproanalytics'),
                'description' => __('Accepted Fluent Forms and WPForms entries and successfully mailed Contact Form 7 submissions send a Form sent event on the counted visitor\'s visit. Goals can count it and Properties shows the form title. No field values are kept. This is separate from submit attempts in Clicks.', 'seoproanalytics'),
            ),
            'purchases_thrivecart'    => array(
                'type'        => 'text',
                'network'     => false,
                'default'     => '',
                'parent'      => self::KEY,
                'label'       => __('ThriveCart secret word', 'seoproanalytics'),
                'description' => sprintf(
                    /* translators: %s: webhook address. */
                    __('To record ThriveCart orders: paste the secret word from ThriveCart\'s Settings → API & Webhooks here, and add a webhook there with this address: %s. Links to ThriveCart checkouts then carry the page\'s random ID, so each order joins its visit.', 'seoproanalytics'),
                    SEOProAnalytics_Purchases::thrivecart_url()
                ),
                'placeholder' => '',
            ),
            'tracking_file'           => array(
                'type'        => 'bool',
                'default'     => false,
                'parent'      => self::KEY,
                'group'       => 'troubleshooting',
                'label'       => __('Load the script as a file', 'seoproanalytics'),
                'description' => __('For a Content Security Policy that blocks inline scripts. Costs one more request per page view.', 'seoproanalytics'),
            ),
            'tracking_ip_header'      => array(
                'type'        => 'select',
                'default'     => '',
                'parent'      => self::KEY,
                'group'       => 'troubleshooting',
                'options'     => array(__CLASS__, 'ip_header_options'),
                'label'       => __('Visitor address from', 'seoproanalytics'),
                'description' => __('Behind a proxy or CDN every visit comes from its address. Choose the header it puts the visitor\'s address in, and only one it always sets: visitors can send any header themselves. The address is used for the daily visitor count and excluded addresses, then dropped.', 'seoproanalytics'),
            ),
            'tracking_country_header' => array(
                'type'        => 'select',
                'default'     => '',
                'parent'      => self::KEY,
                'group'       => 'troubleshooting',
                'options'     => array(__CLASS__, 'country_header_options'),
                'label'       => __('Visitor country from', 'seoproanalytics'),
                'description' => __('Cloudflare\'s country header is read when present. Choose another header that a CDN or the web server sets; without one, the country comes from the browser\'s time zone.', 'seoproanalytics'),
            ),
            'privacy_signals'         => array(
                'type'        => 'bool',
                'default'     => false,
                'tab'         => 'privacy',
                'label'       => __('Respect Do Not Track and Global Privacy Control', 'seoproanalytics'),
                'description' => __('Leave out browsers that send either signal. The statistics already use no cookies and keep no IP addresses or cross-day identity, so most sites do not need this; it lowers the counts. Checked in the browser, so cached pages work.', 'seoproanalytics'),
            ),
            'exclusions'              => array(
                'type'        => 'bool',
                'default'     => false,
                'tab'         => 'privacy',
                'label'       => __('Leave out addresses and pages', 'seoproanalytics'),
                'description' => __('Do not count visits from some IP addresses (an office, a monitoring service) or views of some pages.', 'seoproanalytics'),
            ),
            'exclude_ips'             => array(
                'type'        => 'lines',
                'default'     => '',
                'parent'      => 'exclusions',
                'rows'        => 4,
                'label'       => __('IP addresses', 'seoproanalytics'),
                'description' => __('One address or range per line, IPv4 or IPv6, such as 203.0.113.7 or 203.0.113.0/24. Lines that are not an address are ignored.', 'seoproanalytics'),
                'placeholder' => '203.0.113.0/24',
            ),
            'exclude_paths'           => array(
                'type'        => 'lines',
                'default'     => '',
                'parent'      => 'exclusions',
                'rows'        => 4,
                'label'       => __('Pages', 'seoproanalytics'),
                'description' => __('One path per line; * matches any characters, so /checkout/* leaves out every page under /checkout/.', 'seoproanalytics'),
                'placeholder' => '/checkout/*',
            ),
            'retention'               => array(
                'type'        => 'bool',
                'default'     => true,
                'tab'         => 'data',
                'label'       => __('Delete old visits', 'seoproanalytics'),
                'description' => __('Keep the database small: once a day, visits, events and clicks older than the months below are deleted. Daily totals are kept, so charts and totals still reach back; filters and visit details reach back as far as visits are kept. Off: nothing is deleted.', 'seoproanalytics'),
            ),
            'retention_visits'        => array(
                'type'        => 'int',
                'default'     => 75,
                'min'         => 1,
                'max'         => 120,
                'unit'        => __('months', 'seoproanalytics'),
                'parent'      => 'retention',
                'label'       => __('Visits and page views', 'seoproanalytics'),
                'description' => __('75 months allows quarter-by-quarter comparisons, with filters, going back six years. 13 months is enough for a comparison with the same month last year.', 'seoproanalytics'),
            ),
            'retention_events'        => array(
                'type'        => 'int',
                'default'     => 120,
                'min'         => 1,
                'max'         => 120,
                'unit'        => __('months', 'seoproanalytics'),
                'parent'      => 'retention',
                'label'       => __('Events and revenue', 'seoproanalytics'),
                'description' => __('Fewer rows and more value, so kept longer: 120 months is ten years.', 'seoproanalytics'),
            ),
            'retention_clicks'        => array(
                'type'        => 'int',
                'default'     => 3,
                'min'         => 1,
                'max'         => 120,
                'unit'        => __('months', 'seoproanalytics'),
                'parent'      => 'retention',
                'label'       => __('Clicks and form submits', 'seoproanalytics'),
                'description' => __('Many rows, most useful while a page is new or changing. Never kept longer than their visits.', 'seoproanalytics'),
            ),
            'retention_search'        => array(
                'type'        => 'int',
                'default'     => 25,
                'min'         => 1,
                'max'         => 120,
                'unit'        => __('months', 'seoproanalytics'),
                'parent'      => 'retention',
                'label'       => __('Search data by page and query', 'seoproanalytics'),
                'description' => __('Clicks, impressions and positions imported from a connected search engine (Settings → Connections). Search Console itself keeps 16 months; 25 allows a comparison with two years before. Daily search totals are kept.', 'seoproanalytics'),
            ),
            'retention_crawl'         => array(
                'type'        => 'int',
                'default'     => 13,
                'min'         => 1,
                'max'         => 120,
                'unit'        => __('months', 'seoproanalytics'),
                'parent'      => 'retention',
                'label'       => __('Crawl import history', 'seoproanalytics'),
                'description' => __('Import records and their trend totals are kept for 13 months. The newest import and imports still used by the latest crawl are always kept. Served changes on the timeline stay.', 'seoproanalytics'),
            ),
            'search_updates'          => array(
                'type'        => 'bool',
                'default'     => false,
                'tab'         => 'data',
                'label'       => __('Show search engine updates', 'seoproanalytics'),
                'description' => __('Mark Google\'s ranking updates (core, spam, reviews and others) and its crawling, indexing and serving incidents on the charts and in Changes, with how long each rolled out, so a change in traffic can be weighed against them. Once a day the site asks Google\'s Search Status Dashboard (status.search.google.com) for them: one request, sending nothing about the site or its visitors. The last two years it lists are added the first time.', 'seoproanalytics'),
            ),
            'search_updates_feeds'    => array(
                'type'        => 'lines',
                'default'     => '',
                'parent'      => 'search_updates',
                'rows'        => 3,
                'label'       => __('Other feeds', 'seoproanalytics'),
                'description' => __('RSS, Atom or JSON Feed addresses of other sources you trust, such as a search engine\'s blog, one per line; after a space, the name to show (without one, the domain). Their posts whose title names an update are added once a day, one request to each. For Bing, add its Search blog: https://blogs.bing.com/search/feed/ Bing (Bing announces few updates there; its product news is left out).', 'seoproanalytics'),
                'placeholder' => 'https://blogs.bing.com/search/feed/ Bing',
            ),
            'business_profiles'       => array(
                'type'        => 'bool',
                'default'     => false,
                'tab'         => 'data',
                // Each profile's tagged link is drawn in the panel (SEOProAnalytics_Business_Profiles).
                'label'       => __('Business profiles', 'seoproanalytics'),
                'description' => __('Count visits from the site\'s listings on Google Business Profile, Bing Places, Apple Business Connect and other directories. Each profile gets a tagged website link; visits from it show in the Business profiles channel and on the profile\'s own row with their conversions and revenue, and adding, changing or removing a profile shows on the timeline. Map apps that send visitors without the link still count in the channel. Nothing is fetched from the platforms.', 'seoproanalytics'),
            ),
            'business_profiles_list'  => array(
                'type'        => 'lines',
                'default'     => '',
                'parent'      => 'business_profiles',
                'rows'        => 4,
                'label'       => __('Profiles', 'seoproanalytics'),
                // Drawn as rows of four fields with Paste a list (admin/js/seoproanalytics-business-profiles.js); stored as these lines.
                'description' => __('Each profile: its platform, a name, the profile\'s public address and the page it stands for, such as /contact/ for a branch (without one, the home page). The address and page may be left out. The name gives the link\'s campaign, so renaming a profile starts a new row. For a long list, Paste a list takes one profile per line: platform | name | profile address | page, with google, bing, apple or other as the platform.', 'seoproanalytics'),
                'placeholder' => 'google | Head office | https://… | /',
            ),
            'backlinks'               => array(
                'type'        => 'bool',
                'default'     => true,
                'tab'         => 'data',
                'label'       => __('Check pages that send visitors for links', 'seoproanalytics'),
                'description' => __('Once a day the site opens the pages of other sites that sent visitors (the address the browser gave, often only the other site\'s home page), at most for 20 seconds, and keeps the links on them to this site, with their text, so Search → Backlinks lists them and new and lost links show on the timeline. After a link export import (Settings → Import → Links), the pages it named are opened a batch a minute until each was opened once. Each page is checked again weekly. The request names SEO Pro Analytics and this site, and sends nothing about visitors. Off: no page is opened.', 'seoproanalytics'),
            ),
            'inspections'             => array(
                'type'        => 'int',
                'default'     => 200,
                'min'         => 0,
                'max'         => 2000,
                'unit'        => __('a day', 'seoproanalytics'),
                'tab'         => 'data',
                'label'       => __('Google URL inspections', 'seoproanalytics'),
                'description' => __('While Search Console is connected, the hourly import asks Google how it indexed this many pages a day at most (Google allows 2,000 a day per property): first the pages Search → Audit, Indexation lists, then pages with search traffic, each again after 14 days. Each answer gives Google\'s reason a page is or is not indexed, its last crawl, the canonical Google chose and rich result issues. Only from cron, never on a visitor page. 0: none.', 'seoproanalytics'),
            ),
            'indexnow'                => array(
                'type'        => 'bool',
                'default'     => false,
                'tab'         => 'data',
                'label'       => __('Notify search engines of changed pages (IndexNow)', 'seoproanalytics'),
                'description' => __('Send only changed page addresses and a public verification key to api.indexnow.org from cron. Participating search engines, including Bing, share notifications. Google does not participate. Received does not mean crawled or indexed. Submission status is shown under Connections.', 'seoproanalytics'),
            ),
            'viewers'                 => array(
                'type'        => 'bool',
                'network'     => false,
                'default'     => false,
                'tab'         => 'data',
                'label'       => __('Let other roles see the statistics', 'seoproanalytics'),
                'description' => __('Administrators always see them. People with the roles below see the SEO Pro Analytics menu, the Dashboard widget and the read API, but not these settings.', 'seoproanalytics'),
            ),
            'viewer_roles'            => array(
                'type'        => 'multi',
                'default'     => array(),
                'parent'      => 'viewers',
                'options'     => array('SEOProAnalytics_Feature', 'restrictable_role_options'),
                'label'       => __('Roles', 'seoproanalytics'),
            ),
        ) + self::owners() + self::backlink_lists();
    }

    /**
     * Settings → Data → Owners: who can own and each place's default
     * owner (SEOProAnalytics_Owners).
     *
     * @return array<string,array>
     */
    private static function owners() {
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-owners.php';
        return SEOProAnalytics_Owners::settings();
    }

    /**
     * Settings → Backlinks: the class lists (SEOProAnalytics_Backlink_Lists).
     *
     * @return array<string,array>
     */
    private static function backlink_lists() {
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-backlinks-triage.php';
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-backlink-lists.php';
        return SEOProAnalytics_Backlink_Lists::settings();
    }

    /**
     * Saving any setting rewrites the collector's config file; a change
     * to Owners refreshes reports cached by owner.
     */
    public static function boot() {
        add_action('add_option_' . SEOProAnalytics_Settings::OPTION, array(__CLASS__, 'write_config'));
        add_action('seoproanalytics_settings_changed', array(__CLASS__, 'write_config'));
        add_action('seoproanalytics_settings_changed', array(__CLASS__, 'owners_changed'), 10, 2);
        add_action('seoproanalytics_settings_changed', array(__CLASS__, 'classes_changed'), 10, 2);
        add_action('seoproanalytics_settings_tab_after', array(__CLASS__, 'crawl_sizes'));
    }

    /**
     * After settings change: Backlinks → Classes
     * (SEOProAnalytics_Backlinks_Triage::settings_changed()).
     *
     * @param array $old Settings before.
     * @param array $new Settings after.
     */
    public static function classes_changed($old, $new) {
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-backlinks-triage.php';
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-backlink-lists.php';
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-backlinks.php';
        SEOProAnalytics_Backlinks_Triage::settings_changed(is_array($old) ? $old : array(), is_array($new) ? $new : array());
        SEOProAnalytics_Backlink_Lists::flush();
    }

    /**
     * After settings change (SEOProAnalytics_Owners::settings_changed()).
     *
     * @param array $old Settings before.
     * @param array $new Settings after.
     */
    public static function owners_changed($old, $new) {
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-owners.php';
        SEOProAnalytics_Owners::settings_changed((array) $old, (array) $new);
    }

    /** Settings → Data: allocated crawl storage, never queried on a visitor page. @param string $tab Tab. */
    public static function crawl_sizes($tab) {
        if ($tab !== 'data' || !current_user_can('manage_options')) {
            return;
        }
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-crawl.php';
        echo '<h2>' . esc_html__('Crawl storage', 'seoproanalytics') . '</h2><p>' . esc_html__('Estimated rows and allocated data and index bytes, refreshed once a day.', 'seoproanalytics') . '</p><ul>';
        foreach (SEOProAnalytics_Crawl::sizes() as $name => $size) {
            echo '<li>' . esc_html($name . ': ' . number_format_i18n($size['rows']) . ' ' . __('rows', 'seoproanalytics') . '; ' . __('data', 'seoproanalytics') . ' ' . size_format($size['data']) . '; ' . __('indexes', 'seoproanalytics') . ' ' . size_format($size['index'])) . '</li>';
        }
        echo '</ul>';
    }

    /**
     * Rewrite the collector's config file after a save. Not on visitor
     * pages (the first request may store the settings' defaults); the
     * hourly job rewrites it anyway.
     */
    public static function write_config() {
        if (is_admin() || wp_doing_cron() || (defined('WP_CLI') && WP_CLI)) {
            SEOProAnalytics_Collection::write_config();
            require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-indexnow.php';
            SEOProAnalytics_IndexNow::settings_saved();
        }
    }

    /**
     * Roles that can edit posts, the default for people not counted.
     *
     * @return string[]
     */
    private static function editor_roles() {
        $roles = array();
        foreach (wp_roles()->roles as $role => $data) {
            if (!empty($data['capabilities']['edit_posts'])) {
                $roles[] = (string) $role;
            }
        }
        return $roles;
    }

    /**
     * Options for the address header.
     *
     * @return array<string,string>
     */
    public static function ip_header_options() {
        return array('' => __('The connection (no proxy)', 'seoproanalytics')) + self::IP_HEADERS;
    }

    /**
     * Options for the country header.
     *
     * @return array<string,string>
     */
    public static function country_header_options() {
        return array('' => __('Cloudflare\'s, when present', 'seoproanalytics')) + self::COUNTRY_HEADERS;
    }

    /**
     * Whether statistics are collected.
     *
     * @return bool
     */
    public static function collecting() {
        return (bool) SEOProAnalytics_Settings::get(self::KEY);
    }

    /**
     * Roles not counted when logged in.
     *
     * @return string[]
     */
    public static function skip_roles() {
        return array_map('strval', (array) SEOProAnalytics_Settings::get('tracking_skip_roles'));
    }

    /**
     * Query parameters kept in page addresses, besides UTM tags and
     * WordPress's own: lower case, valid names only.
     *
     * @return string[]
     */
    public static function params() {
        $out = array();
        foreach (self::lines('tracking_params') as $line) {
            $line = strtolower($line);
            if (preg_match('/^[a-z0-9_.-]{1,40}$/', $line)) {
                $out[] = $line;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * The site's other hosts, with and without www.
     *
     * @return string[]
     */
    public static function hosts() {
        $out = array();
        foreach (SEOProAnalytics_Settings::parse_domains(SEOProAnalytics_Settings::get('tracking_hosts')) as $host) {
            $out[] = $host;
            $out[] = 'www.' . $host;
        }
        return $out;
    }

    /**
     * Whether the tracker is loaded as a file.
     *
     * @return bool
     */
    public static function as_file() {
        return (bool) SEOProAnalytics_Settings::get('tracking_file');
    }

    /**
     * The trusted address header, or '' for the connection's address.
     *
     * @return string
     */
    public static function ip_header() {
        $header = (string) SEOProAnalytics_Settings::get('tracking_ip_header');
        return isset(self::IP_HEADERS[$header]) ? $header : '';
    }

    /**
     * The country header besides Cloudflare's, or ''.
     *
     * @return string
     */
    public static function country_header() {
        $header = (string) SEOProAnalytics_Settings::get('tracking_country_header');
        return isset(self::COUNTRY_HEADERS[$header]) ? $header : '';
    }

    /**
     * Whether Do Not Track and Global Privacy Control are respected.
     *
     * @return bool
     */
    public static function respect_signals() {
        return (bool) SEOProAnalytics_Settings::get('privacy_signals');
    }

    /**
     * Excluded addresses and ranges: valid ones only.
     *
     * @return string[]
     */
    public static function excluded_ips() {
        if (!SEOProAnalytics_Settings::get('exclusions')) {
            return array();
        }
        $out = array();
        foreach (self::lines('exclude_ips') as $line) {
            $parts = explode('/', $line, 2);
            if (!filter_var($parts[0], FILTER_VALIDATE_IP)) {
                continue;
            }
            $max = strpos($parts[0], ':') === false ? 32 : 128;
            if (isset($parts[1]) && (!ctype_digit($parts[1]) || (int) $parts[1] > $max)) {
                continue;
            }
            $out[] = $line;
        }
        return array_values(array_unique($out));
    }

    /**
     * Excluded path patterns, each starting with / or *.
     *
     * @return string[]
     */
    public static function excluded_paths() {
        if (!SEOProAnalytics_Settings::get('exclusions')) {
            return array();
        }
        $out = array();
        foreach (self::lines('exclude_paths') as $line) {
            $line = (string) preg_replace('/\s+/', '', $line);
            if ($line === '') {
                continue;
            }
            $out[] = $line[0] === '/' || $line[0] === '*' ? $line : '/' . $line;
        }
        return array_values(array_unique($out));
    }

    /**
     * Months visits, events, clicks and search data by page and query are
     * kept; 0 keeps them forever.
     *
     * @return array{visits:int,events:int,clicks:int,search:int,crawl:int}
     */
    public static function retention() {
        if (!SEOProAnalytics_Settings::get('retention')) {
            return array('visits' => 0, 'events' => 0, 'clicks' => 0, 'search' => 0, 'crawl' => 0);
        }
        return array(
            'visits' => max(1, (int) SEOProAnalytics_Settings::get('retention_visits')),
            'events' => max(1, (int) SEOProAnalytics_Settings::get('retention_events')),
            'clicks' => max(1, (int) SEOProAnalytics_Settings::get('retention_clicks')),
            'search' => max(1, (int) SEOProAnalytics_Settings::get('retention_search')),
            'crawl'  => max(1, (int) SEOProAnalytics_Settings::get('retention_crawl')),
        );
    }

    /**
     * Whether clicks and form submits are captured.
     *
     * @return bool
     */
    public static function autocapture() {
        return (bool) SEOProAnalytics_Settings::get('tracking_clicks');
    }

    /**
     * Whether the words of site searches are recorded.
     *
     * @return bool
     */
    public static function search_terms() {
        return (bool) SEOProAnalytics_Settings::get('tracking_search');
    }

    /**
     * Whether a running A/B test keeps showing a visit the variant it
     * showed first, by keeping it in the tab's sessionStorage (opt-in).
     *
     * @return bool
     */
    public static function ab_one_variant() {
        return (bool) SEOProAnalytics_Settings::get('tracking_ab_visit');
    }

    /**
     * The site's affiliate link paths, each starting with / or *.
     *
     * @return string[]
     */
    public static function affiliate_paths() {
        $out = array();
        foreach (self::lines('tracking_affiliate') as $line) {
            $line = (string) preg_replace('/\s+/', '', $line);
            if ($line !== '') {
                $out[] = $line[0] === '/' || $line[0] === '*' ? $line : '/' . $line;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Whether search engine updates are fetched.
     *
     * @return bool
     */
    public static function search_updates() {
        return (bool) SEOProAnalytics_Settings::get('search_updates');
    }

    /**
     * Whether pages that send visitors are checked for links (backlinks).
     *
     * @return bool
     */
    public static function backlinks() {
        return (bool) SEOProAnalytics_Settings::get('backlinks');
    }

    /**
     * Google URL inspections a day at most (0: none).
     *
     * @return int
     */
    public static function inspections() {
        return max(0, (int) SEOProAnalytics_Settings::get('inspections'));
    }

    /**
     * The other search update feeds: address, engine key and name, each
     * address once, at most SEOProAnalytics_Search_Updates::MAX_FEEDS.
     *
     * @return array<int,array{url:string,engine:string,name:string}>
     */
    public static function search_feeds() {
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-search-updates.php';
        $out = array();
        foreach (self::lines('search_updates_feeds') as $line) {
            $feed = SEOProAnalytics_Search_Updates::parse_feed_line($line);
            if ($feed && !isset($out[$feed['url']])) {
                $out[$feed['url']] = $feed;
            }
        }
        return array_slice(array_values($out), 0, SEOProAnalytics_Search_Updates::MAX_FEEDS);
    }

    /**
     * Roles that may read the statistics, besides people who can manage
     * options.
     *
     * @return string[]
     */
    public static function viewer_roles() {
        if (!SEOProAnalytics_Settings::get('viewers')) {
            return array();
        }
        return array_map('strval', (array) SEOProAnalytics_Settings::get('viewer_roles'));
    }

    /**
     * A lines setting's non-empty lines.
     *
     * @param string $key Setting key.
     * @return string[]
     */
    private static function lines($key) {
        $lines = preg_split('/[\r\n]+/', (string) SEOProAnalytics_Settings::get($key)) ?: array();
        return array_values(array_filter(array_map('trim', $lines), static function ($line) {
            return $line !== '';
        }));
    }
}
