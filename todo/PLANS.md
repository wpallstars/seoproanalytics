# SEO Pro Analytics: plan

The design is in `docs/architecture.md`. Each phase is one or more pull
requests; each ends with lint, the smoke test and a check on the preview
site. A phase may start once the phases it depends on are merged.

## Phase 0: groundwork

- [x] Starter: JavaScript build support for every plugin (sources and
      `package*.json` left out of zips, built files committed), then sync
      core files here.
- [x] `docs/architecture.md` and this plan.

## Phase 1: collect, store, show (depends on 0)

- [x] npm workspaces: `packages/core`, `packages/tracker`,
      `packages/charts`, `packages/wp-admin`; build into `assets/build/`
      (wp-scripts for the app, esbuild for the tracker).
- [x] Schema and migrations (`includes/stats/class-seoproanalytics-schema.php`),
      dictionary, uninstall.
- [x] Tracker: pageviews, SPA, engagement, events API, outbound and
      downloads, sessions; inline print; exclusions (editors, feeds,
      previews, customizer, automation; paths, roles and DNT/GPC in the
      settings below). Hash routes wait for a processor change: it
      drops the fragment from paths.
- [x] Collector (fast path and REST), salts, buffer files, loopback test.
- [x] Processor: user agents, channels, location (CDN header, time zone),
      sessions, pageviews, events, properties, engagement; minute cron.
      Bot hits are counted and dropped until the `bots` table (Phase 5).
Order from here: something to see first, then real data, then the
long-term record.

- [x] Report engine: ranges, comparison, filters, breakdowns, cache.
- [x] REST API (`stats`, `timeseries`, `breakdown`, `realtime`,
      `markers`), OpenAPI file, WP-CLI (`stats`, `timeseries`,
      `breakdown`, `realtime`, `process`, `doctor`).
- [x] Top-level menu at position 3 with the plugin icon; Overview:
      headline metrics, chart with comparison, Sources, Pages, Locations,
      Devices cards, filters, realtime; Dashboard widget.
- [x] Tracker (above).
- [x] Nightly summaries into `daily`; retention. Long ranges (year,
      12mo, all time) read `daily`: from the fact tables they read every
      visit in the range, which for all time is the whole `sessions`
      table (58 ms over 42,000 visits on the preview). Retention months
      are in the settings below.
- [x] Settings: tracking, privacy (DNT/GPC, excluded IPs and paths, roles),
      data retention, roles that may see the statistics.
- [x] Purge known page caches (WP-Optimize, LiteSpeed, WP Rocket, W3 Total
      Cache…) when the plugin version or tracker settings change: cached
      pages keep the inline tracker they were cached with (seen on the
      preview).

## Phase 2: behaviour and conversions (depends on 1)

- [x] Goals (page and event, revenue per currency), funnels (2–12 steps,
      in order within one visit), custom properties; per data set, with
      demo examples; REST, WP-CLI and dashboard sections with editors.
      Properties read `props` by period (schema v3 keys).
- [x] Click and form autocapture (never field values; masked labels,
      `data-sps-mask`); dead clicks; outbound, affiliate (paths and
      `rel="sponsored"`, with an Affiliate link event) and file flags;
      `clicks` table (schema v4) kept 3 months; REST, WP-CLI, demo data
      and a Clicks section.
- [x] Enhanced: pages not found, site search (words optional, masked;
      no results), views by author, category and post type (`pages`
      table filled by the minute job), logged-in visits; schema v5;
      REST, WP-CLI, demo data and Overview cards.
- [x] Purchases: WooCommerce, Easy Digital Downloads, FluentCart and
      ThriveCart orders as revenue events, once per order (server side, on
      payment), in each order's currency, on the visit that checked out.
      Refunds and renewals later.
- [x] A/B testing of blocks: variants in the editor, results by test and
      variant: blocks, editor, `ab_tests` registry and the site's variant
      swap; collection; reports; results and picking the winner in the
      editor.
- [x] Clicks by page with View and Edit links.
- [ ] Journeys (visitor per day timeline), Flow (Sankey), segments.

## Phase 3: changes and causes (depends on 1)

- [x] Change log from WordPress hooks: posts (status, address, title,
      words, links), SEO plugin fields, products (prices, sales, stock,
      coupons), plugin, theme and core updates, key settings; `markers`,
      `changes`, `wp seoproanalytics changes`.
- [ ] Page snapshots and word diffs; page detail.
- [x] Annotations (UI, API, CLI, abilities); markers lane under the
      Overview's chart; Changes section.
- [x] Search engine update markers: Google's Search Status Dashboard and
      the owner's other feeds, opt-in, daily; rollout spans.
- [ ] Movers and anomalies with possible causes; page detail.

## Phase 4: search (depends on 1, 3)

- [x] Connections (keys stored encrypted) and the search import: final
      days and the 16-month history, undo, retention; Settings →
      Connections, REST and WP-CLI. The Search Console connection is the
      SEO Pro Analytics Connect add-on's.
- [x] Connection registry: the add-on's connections register through
      filters; without it they show as "(subscription needed)".
- [x] Rankings: a Search section with clicks, impressions, CTR and
      position against the previous period, queries, pages, countries
      and devices, page ↔ query drill-down; REST `search`, WP-CLI, the
      `seoproanalytics/search` ability and demo search data.
- [x] Opportunities: striking distance, low CTR against the site's own
      CTR curve, and pages losing clicks with the likely cause (position,
      demand, CTR, gone), the queries that lost most and the page's
      changes; REST `opportunities`, WP-CLI, the
      `seoproanalytics/opportunities` ability and demo data.
- [x] Content performance: search joined with each page's visits from
      search and conversions of a goal, by a daily summary of search
      landings (refilled backwards) and the goal's own hits; REST
      `content`, WP-CLI, the `seoproanalytics/content` ability and demo
      data.
- [x] Query coverage: each page's search queries checked against its own
      words (title, headings, text), its questions, and its SEO plugin's
      focus keywords (Rank Math, Yoast SEO, SEOPress, All in One SEO, or
      none); Opportunities → Missing from the page, a block editor panel
      and classic meta box that re-check as people write; REST
      `coverage`, WP-CLI, the `seoproanalytics/coverage` ability and demo
      page text.
- [x] Bing Webmaster Tools as a second search engine: API-key
      connection, daily site totals and weekly pages, queries and page
      queries (stored on each week's last day), an engine switch on
      Rankings, Opportunities and Content with whole-week periods and
      weekly points; REST `engine`, WP-CLI `bing` and `--engine`, the
      abilities' `engine` and demo Bing data.
- [x] SEO decision loop: design (`docs/seo-loop.md`) and its features in
      build order (the items below).
- [x] Experiments: a change, its expected effect and review date,
      measured against unchanged pages with confounders named.
- [x] Decision queue: one ranked plan from the opportunities; done opens
      an experiment.
- [x] Overlapping pages: queries shared by several pages, as candidates to
      review.
- [x] Content audit from WordPress: page facts on save and in cron,
      findings weighed by search and conversions; Search → Audit and plan
      items.
- [x] Internal links: orphans, converting pages with few links in,
      missing links to a query's page; under Search → Audit and plan
      items.
- [x] Indexation: published pages and sitemap addresses without
      impressions; under Search → Audit and plan items.
- [x] Refresh planner: update, leave, protect or merge pages losing
      clicks; plan items in place of losing clicks.
- [x] Search targets: chosen queries and their pages, positions and wrong
      pages; imports, Search → Targets and plan items.
- [x] Loop export and agent recipes: `/loop`, `wp seoproanalytics loop`
      (`--format=toon` for the aidevops export layout), the ability
      `seoproanalytics/loop` and `docs/seo-loop-recipes.md`.
- [x] Backlinks from verified referrers: the daily check opens pages that
      sent visits and keeps their links to the site; Search → Backlinks,
      new and lost links on the timeline.
- [x] Search Console sitemaps and URL Inspection: Google's index under
      Search → Audit, four audit findings, sitemap plan items and verdict
      changes on the timeline (the add-on reads them).
- [x] Backlink exports imported (Search Console and backlink tools'
      CSVs), review and local disavow; a paid provider's backlinks come
      from the add-on.
- [ ] Referral spikes and mentions.

## Phase 5: health (depends on 1)

- [ ] Speed: real-user vitals, experience score, attribution, percentiles.
- [ ] JavaScript errors grouped by fingerprint.
- [ ] Crawlers and AI bots: server-side capture, verification (reverse DNS,
      published ranges), purposes, cache exclusions.
- [ ] Collection gaps and uptime incidents. Alerts when visits stop
      arriving, jobs stop running or summaries fall behind are built.

## Phase 6: reach (depends on 1–5)

- [ ] Alerts and email reports, webhooks; the weekly email carries the
      top decision queue items and experiments due for review
      (`docs/seo-loop.md`).
  - [x] Shared report links stored encrypted, so they can be copied
        again and emailed.
  - [x] Emails: branded layout, sending through WordPress, test send
        and send log.
  - [x] Scheduled email reports of a shared report: daily, weekdays,
        weekly, monthly or quarterly, with a message at the top.
  - [x] The site's changes in the period in report emails.
  - [x] Alerts by email and on our screens when statistics are at
        risk.
  - [ ] Still to do: the team's weekly decision-queue email, webhooks.
- [x] Each section's view in the address (filters, period, tabs), so
      any view can be bookmarked and shared.
- [ ] Shared client reports: saved interactive views (private link,
      password, expiry) that clients keep watching, with the site's and
      an agency's branding (after the view in the address).
- [ ] Import (generic events JSON/CSV, daily aggregates) and export;
      undo by import id.
- [ ] Abilities for MCP clients (the reports; `seoproanalytics/markers`,
      `seoproanalytics/annotate` and `seoproanalytics/search` so far);
      aidevops SEO loop recipes in `docs/`.
- [ ] Optional location database (DB-IP Lite) with our own reader.

## Phase 7: move from other statistics plugins

Bring a site's history and settings across when it switches, then help
remove the old plugin and its leftovers. Sources are found by their data
(tables, options), not only by the plugin being installed. Imported days
go in `daily` with an import id (undo). Each day is filled by one source
only: never over days we recorded ourselves, and where several sources
share days, the owner picks which one fills them. Other statistics
plugins may keep running beside SEO Pro Analytics (none goes in `REPLACES`).
It brings its own import engine, so it does not wait for Phase 6's
generic import.

- [x] Engine, detection, dry run, overlap choice, check after import,
      Remove leftover data (confirmed, only while the plugin is
      inactive), Settings → Import tab, REST, WP-CLI, abilities, and
      Burst Statistics as the first source.
- [x] Notices on the Plugins screen and SEO Pro Analytics's screens through
      each step: import, check, deactivate and delete, remove leftovers;
      shown until the plugin and its data are gone.
- [x] Jetpack Stats (from WordPress.com, by the site's Jetpack
      connection): daily views and visitors, top pages, referrers,
      countries. Built from Jetpack's open-source code without a test
      site.
- [ ] Jetpack Stats checked on connected sites by invited users with
      Jetpack (`wp seoproanalytics migrate run jetpack --dry-run --requests`):
      the visits window's end day, the site's days, and the answers'
      fields.
- [x] Koko Analytics and Statify, checked against real installs.
- [x] WP Statistics and Independent Analytics, checked against real
      installs.
- [x] Slimstat and Matomo for WordPress, each table layout checked
      against a real install first.

## Later

- Experiments (A/B) with sample size and significance.
- Click heatmaps and scroll maps.
- The standalone app, mobile app and browser extension (new
  repositories, reusing `packages/` and the OpenAPI contract).
