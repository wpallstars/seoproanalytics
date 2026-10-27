=== SEO Pro Analytics ===
Contributors: wpallstars
Donate link: https://buymeacoffee.com/marcusquinn
Tags: analytics, statistics, privacy, search console, seo
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.20.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Actionable analytics: connect your content to the data that shows you how to grow. Private stats, search rankings and sales, no cookies.

== Description ==

**Actionable analytics for WordPress.** Content, traffic, search rankings, sales and site changes on one timeline. Prioritise search work and measure each change.

AI agents can read reports through REST (Application Password), WP-CLI (`wp seoproanalytics`; `loop` gives one search cycle) and WordPress 6.9+ abilities. The plugin never changes pages by itself.

= Statistics =

* **Visitors**: visitors, visits, pageviews, bounce rate and time, with period comparisons and traffic filters.
* **Goals, Funnels and Properties**: conversions, rates, revenue per currency, visit steps and event properties.
* **Business profiles**: tagged listing links, visits, conversions and revenue in their own channel.
* **Purchases**: paid WooCommerce, Easy Digital Downloads, FluentCart and ThriveCart orders, refunds and renewals. No order numbers or customer details.
* **Form submissions**: accepted Fluent Forms, WPForms and Contact Form 7 submissions for goals, with form titles, never field values.
* **Bookings**: completed Calendly bookings for goals, even with autocapture off. No invitee details.
* **Clicks**: clicks, dead clicks, outbound/affiliate links, files and form attempts. Never typed values.
* **Changes**: posts, SEO titles, prices, plugins, settings, search updates and notes under charts.

= Search =

Connect Bing Webmaster Tools (Settings → Connections), or Google Search Console with the SEO Pro Analytics Connect add-on, to import clicks, impressions and position by page and query.

* **Rankings**: queries, pages, countries, devices and search appearance, for Google, Bing or both.
* **Opportunities**: striking-distance queries, low CTR, losing clicks with causes, missing queries and overlapping pages.
* **Audit**: titles, descriptions, headings, thin content, internal links, indexation, sitemaps and URL Inspection (Google's, with the add-on).
* **Site crawl**: imports, LibreCrawl or an opt-in built-in crawl; findings verify Plan fixes.
* **Page experience**: Core Web Vitals (Chrome UX Report, with the add-on) by page, against search clicks and visits.
* **Backlinks**: visits, CSV imports, classes, DR/LD, link value, needs, impact, spam review and local disavow. Nothing submitted.
* **Targets**: focus keywords, reports or a list, with research links, Keyword Golden Ratio, tracked places and AI answer sources (with the add-on).
* **IndexNow**: tell search engines when pages change (opt-in).
* **Plan**: prioritised actions with seasonal checks. Done starts an experiment, except Leave.
* **Experiments**: before and after a change against unchanged pages, with a suggested keep, revise or undo.
* **Change sets**: reviewed title/description edits, full backups, optional experiments and conflict-aware undo via CLI, REST and abilities. Live posts only.
* **Owners**: who is responsible for each page, target, Plan item and experiment.
* **A/B tests**: test blocks in the block editor, with results and a winner, without cookies.
* **Search queries panel**: queries a page shows for and does not cover, in the editor.

= And =

* **Alerts** (Settings → Alerts): emails, on by default, and notices when collection, jobs, summaries or sources fail, with reminders and recovery emails.
* **Import**: Burst Statistics, Koko Analytics, Statify, WP Statistics, Independent Analytics, Slimstat, Matomo for WordPress and Jetpack Stats (Settings → Import).
* **Shared reports**: private links with passwords, expiry and branding, and scheduled report emails.
* **Demo data**: a year of made-up visits to try the reports.
* **Stats test mode**: inspect tracker hits without recording them (admin bar, or 15-minute tokens for automation). Forms and server-side purchases still run: use staging.
* **Dark mode**: Light, Dark or System, for each person.
* **Multisite**: network defaults, locked settings and one-time copying to sites.

Full guide: SEO Pro Analytics → Settings → Read Me.

= Privacy =

No visitor cookies/storage, stored IPs or cross-day identity: daily-salted visit hashes. Admin research menus store country/language locally. Do Not Track and Global Privacy Control respected. Clicks/searches mask emails and long numbers; form values never stored. Visitor pages query no database; reports use daily summaries.

= External services =

Off by default, except the first. Google's [terms](https://policies.google.com/terms) and [privacy policy](https://policies.google.com/privacy) cover its services.

* **Pages that sent visitors** (Settings → Data, on): a daily job opens public referring pages (20 seconds at most) to find links here; the user agent names the plugin and site. No visitor data.
* **Google Search Status Dashboard** (status.search.google.com), opt-in: public ranking updates daily; added feeds likewise. No site data sent.
* **Built-in external checks** (Tracking, opt-in): HEAD/GET targets, no redirects, 1,000/day. Readiness contacts Google Search Status only on request. User agent includes site URL; no visitor data.
* **IndexNow** (api.indexnow.org), when on (Settings → Data): changed page addresses and the site's public key, from cron. No visitor data. [Terms](https://indexnow.org/terms).
* **Bing Webmaster API** (ssl.bing.com), after you connect with your own API key: the site's search data by day and week; crawl issues and stats, sitemaps and URL quota daily; links to the site weekly. Microsoft [terms](https://www.microsoft.com/servicesagreement) and [privacy statement](https://privacy.microsoft.com/privacystatement).
* **WordPress.com** (public-api.wordpress.com): daily statistics only during Jetpack Stats imports, through Jetpack's connection.

Search requests run in WP-Cron, WP-CLI or Import now, never on visitor pages, and send nothing about visitors. The SEO Pro Analytics Connect add-on, a separate plugin, lists the services its connections use.

= Built with AI =

Built with [aidevops](https://aidevops.sh), the developer's free, open-source AI harness. Ask it questions about SEO Pro Analytics; it reads the plugin's docs and code.

Made from WP Plugin Starter (https://github.com/wpallstars/wp-plugin-starter-template-for-ai-coding), the wpallstars starter plugin.

== Installation ==

1. Upload and activate it (Plugins → Add New → Upload Plugin).
2. Open SEO Pro Analytics under Dashboard; visit the site logged out, or use Demo data.
3. Add goals, connect search engines, import history in Settings, then work through Search → Plan.

== Frequently Asked Questions ==

= Does it need a cookie banner? =

No visitor cookies or storage by default. Opt-in A/B variant persistence uses session storage and may need consent.

= Can I bring my old statistics across? =

Yes. Settings → Import previews history, skips duplicate days and offers Undo. Removing leftover data needs the old plugin inactive; back up first.

= Where do updates come from? =

From where you installed it. GitHub gets new versions first; WordPress.org 30 days later, security fixes at once.

= Where do I get help? =

Use **Support** on the settings screen, or ask aidevops.

== Screenshots ==

1. Visitors: visits against the previous period, visitors now and site changes.
2. Search → Rankings: Google and Bing clicks, impressions, CTR and position by query and page.
3. Search → Plan: search work from every report, best first.
4. Changes: what changed on the site, when and where.
5. Goals: conversions, conversion rate and revenue per currency.
6. A shared report, read-only without wp-admin, ready to print.

== Changelog ==

= Unreleased =
* Changed: the banner shows the SEO Pro Analytics name and its new tagline.
* Developers: synced the shared settings, admin styles and maintenance scripts with the starter. Settings and defaults are unchanged.

= 1.20.0 =
* New: reviewed title and SEO description change sets with backups, experiments and safe undo through CLI, REST and abilities.
* Changed: Google Search Console, Chrome UX Report, the paid providers and a crawler server come with the SEO Pro Analytics Connect add-on. Bing, IndexNow, imports, the built-in crawl and every report stay. Saved connections and imported data are kept.

== Upgrade Notice ==

= 1.20.0 =
Google Search Console, Chrome UX Report, the paid providers and the crawler server now need the SEO Pro Analytics Connect add-on. Their saved settings and imported data stay.

= 1.19.1 =
* Fixed: moving from SEO Pro Stats (1.19.0) on an admin page or scheduled job no longer stops with an error on sites with connections.

Every change: changelog.txt.
