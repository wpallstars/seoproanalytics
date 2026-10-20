# SEO loop recipes for agents

How an AI agent, or a script, works the SEO decision loop (`docs/seo-loop.md`)
on a site with SEO Pro Analytics: read one answer, choose work, make the
change, record it, measure it and decide. Every step uses public routes,
WP-CLI commands or abilities; nothing here needs access to the database.

The plugin proposes and measures. It changes pages only on an explicit reviewed
change-set apply or undo request; other edits are made in WordPress.

## Crawl reachability and incoming links

Import pages together with an **all-links** export. Confirm whole-site
coverage only when the crawl did not hit its page limit: REST
`POST /crawl/import` accepts `complete: true`, the `seoproanalytics/crawl-import`
ability accepts `complete`, and WP-CLI accepts `crawl import --complete`.
Alternatively, REST JSON and the ability accept `format: librecrawl` and
`record` from the source's completed crawl: `status: completed`, positive
`urls_crawled`, and `config_snapshot.max_urls` greater than that count.
A supplied record takes precedence over confirmation; a list record without
its settings, a running crawl or a reached limit remains incomplete.
Without established completeness, `coverage_note` explains why unreachable
findings are skipped. Links-only imports cannot establish whole-site coverage.

Read `/links` for `source` (`served` or `text`), `links_in_served` (nullable)
and `links_in_text`. Inspect `/audit?finding=unreachable` and
`/audit?finding=deep`, then link from relevant pages with traffic and many
incoming links, using the converting/missing internal-link suggestions.
Read `/crawl?kind=clicked` or `wp seoproanalytics crawl clicked --format=json`
for clicked failing **and redirecting** outbound/affiliate destinations,
including `hops` and `redirect` (null if not exported), most clicks first.
Do not treat a redirect as proof of lost revenue or recommend bypassing an
affiliate programme's required tracking URL without checking its rules.

## Access

- **REST API**: `https://example.com/wp-json/seoproanalytics/v1`, signed in with
  an Application Password (Users → Profile → Application Passwords).
  Reading needs the `view_seoproanalytics` capability; accepting, marking done
  and deciding need `manage_options`, so use an administrator's password
  for the whole loop, or a reader's to only report.
- **WP-CLI**: `wp seoproanalytics loop`, `queue`, `experiments` and `owners` on
  the server.
- **Abilities** (WordPress 6.9 and later, for MCP clients through the
  WordPress MCP adapter): `seoproanalytics/loop`, `seoproanalytics/queue-update`,
  `seoproanalytics/experiment-record`, `seoproanalytics/owners` and
  `seoproanalytics/owners-set`.

Add `data=demo` (`--data=demo`) to report and ordinary experiment steps to try
demo data. Change sets and experiment decisions with `restore` always use live
data and real posts: demo is not a sandbox for those operations.

```sh
SITE="https://example.com/wp-json/seoproanalytics/v1"
AUTH="admin:APPLICATION PASSWORD"
```

## Refresh served facts with an owner's crawler

Run the crawler wherever it can reach the public site, then push its
exports to `POST /crawl/import` (A crawl for the loop, below). Read `/crawl` and
`/crawl/imports` for the facts and totals. New crawl facts can add Plan
findings; they do not prove that a content change caused a ranking
change. Import history and Undo remain in Settings → Import. The
SEO Pro Analytics Connect add-on can instead connect the owner's crawler
server and import its scheduled crawls; its own documentation describes
that connection.

## The cycle

Run it once a week: search data arrives two or three days late, and
experiments are measured in whole windows of days.

### 1. Read the loop

```sh
curl -s -u "$AUTH" "$SITE/loop?range=30d"
wp seoproanalytics loop --format=json
```

One answer holds everything for the cycle:

| Field | What it is |
|---|---|
| `summary` | Counts: open items (`new`, `accepted`), `done`, `dismissed`, `left_out` (items held back by a running experiment), experiments `due`, `running` and `decided`, export `rows`. |
| `params` | The period, engine, goal (and page filters) the queue was made with. Pass the same to every queue action in this cycle. |
| `queue.items` | Open decision queue items, best first: `key`, `kind`, `page`, `query`, `why`, `todo`, `figures` and the score with its `parts` (potential clicks, value, confidence, effort). `queue.rules` has the numbers the scores use. |
| `experiments.due` | Running experiments with search data through their review day: decide them now (step 5). |
| `experiments.running` | Running experiments not yet due, with their measurement so far. |
| `experiments.decided` | Decided in the last `experiments.recent_days` days, with the result, note and the measurement the decision was made on, including null and negative results. |
| `export` | The period's search figures per query and page in the aidevops export layout: `domain`, `source`, `start_date`, `end_date`, `columns` and `rows` (`query`, `page`, `clicks`, `impressions`, `ctr`, `position`), most impressions first; `more` when `rows` cut it short. |

`connected` false means no search engine is connected yet: there is no
queue or export to work from. `through` is the newest day with search data.

### 2. Decide what to do first

Work the experiments `due` before new items: the result of a change
decides whether to repeat it elsewhere.

Then choose from `queue.items`. The score is potential clicks per 28 days
× value × confidence ÷ effort, and every part is given, so an agent can
rank by its own rule (for example, only items of one `kind`, or value
first for a shop). Read `why` and `figures` before acting: an overlap is a
candidate to review, not a fault, and a refresh item is a proposal
(`update`, `leave`, `protect` or `merge`) with its reason.

A `backlinks` item is a page whose searches sit in striking distance
with fewer useful referring domains than the median of the site's pages
in the top three (`figures.need`, `useful`, `top_median`, `gap`,
`link_value` and its best `searches`). Earning links happens off the
site, so plan the outreach rather than edit the page: list prospects
with `GET /backlinks?kind=needs&engine=google` (or `wp seoproanalytics
backlinks needs`), then mark it done once links are placed. Read
`GET /backlinks?kind=impact` before repeating a kind of outreach: it
shows each page that gained useful referring domains, with its search
clicks in the 28 days before and after the first new one, against the
whole site. That is an association, not proof the links caused the
change; say so when reporting it. To test it, start an experiment from
the row's `change_id` (`POST /experiments` with `change`), which
measures the page against pages left alone.

Before proposing a change, look at `experiments.decided`: a kind of change
that was undone or inconclusive on similar pages is weaker evidence than
one that was kept.

On a team, work only what is yours: each item, target, page and
experiment has an `owner` (`id`, `name`, `source`: assigned, default or
none). Read who owns what, then your own list:

```sh
curl -s -u "$AUTH" "$SITE/owners"
curl -s -u "$AUTH" "$SITE/queue?owner=me&range=30d"
wp seoproanalytics owners
wp seoproanalytics queue --owner=12 --range=30d
```

An administrator gives work to someone who can own (an administrator,
editor, shop manager or author); anyone who can own takes work nobody
else was given (`user` `me`) and gives back their own (`default`):

```sh
curl -s -u "$AUTH" -X POST "$SITE/owners" -H "Content-Type: application/json" \
  -d '{"kind":"item","ids":["3f9c0a1b2d4e5f60"],"user":"12"}'
wp seoproanalytics owners set item 12 3f9c0a1b2d4e5f60
```

`kind` is `page` (paths), `target` (searches), `item` (keys) or
`experiment` (ids). Ids not found, or given to another person, come back
in `skipped` with the reason. With the abilities: `seoproanalytics/owners`
and `seoproanalytics/owners-set`, and `owner` on the list abilities. Owners
need reading the statistics and `edit_posts`; they are never in shared
reports.

### 3. Accept, or dismiss

```sh
curl -s -u "$AUTH" -X POST "$SITE/queue/3f9c0a1b2d4e5f60?range=30d&engine=google" \
  -H "Content-Type: application/json" -d '{"action":"accept","note":"New title and description"}'
wp seoproanalytics queue accept 3f9c0a1b2d4e5f60 --range=30d
```

Use the period, engine and goal from `params`: an item no one has acted
on yet must still be in the list they make. `dismiss` hides an item for
`queue.rules.hide_days` days (say why in `note`); `effort` (1–5) sets
your own estimate of the work, which changes its score; `restore` forgets
what was done with it.

With the ability: `seoproanalytics/queue-update` with `key`, `action`, `note`
and the `range`, `engine` and `goal` from `params`.

### 4. Make the change, then mark it done

Make the change in WordPress: edit the title or description, add the
missing words or links, update the page. The change log records it as it
is saved. Once it is live, mark the item done:

```sh
curl -s -u "$AUTH" -X POST "$SITE/queue/3f9c0a1b2d4e5f60?range=30d&engine=google" \
  -H "Content-Type: application/json" \
  -d '{"action":"done","note":"Title now leads with the query","days":28,"threshold":10}'
wp seoproanalytics queue done 3f9c0a1b2d4e5f60 --range=30d --note="Title now leads with the query"
```

Done opens an experiment on the item's page, starting now, with the
kind's measure (CTR for low CTR, clicks for missing words, losing clicks
and refreshes, position for striking distance and pages that need
links) expected to improve. That
is the pre-registration: what was changed, on which pages, what should
happen and by how much, written before the result is known. `days` is
the length of each window (7, 14, 28, 56 or 84; 28 by default) and
`threshold` the smallest change that counts (percent, or places for
position). The item then shows the experiment's id and result.

Change one thing per page at a time: pages with an experiment running get
no new items until it is decided, so its measurement stays clean.

#### Apply a reviewed title/description plan instead

Save this JSON as `titles.json`, replacing the post ids and exact current values:

```json
{"name":"Shorter guide titles","pages":[{"post":123,"title":["Old guide","New guide"],"seo_description":["Old description","New description"]}]}
```

```sh
wp seoproanalytics change-sets apply titles.json --user=admin --dry-run
wp seoproanalytics change-sets apply titles.json --user=admin --yes --experiment="Shorter titles" --metric=ctr
wp seoproanalytics change-sets list
wp seoproanalytics change-sets show 1
```

Apply always prints the preview, then confirms unless `--yes`. It rechecks the
site before writing. A page with any stale current value, unsupported SEO field,
or missing `edit_post` permission is skipped entirely. The active Rank Math,
Yoast SEO or SEOPress fields are supported; without one, use only post titles.
Template variables are kept literally. Experiment options: `metric`, `days`,
`engine`, `direction`, `threshold`, `hypothesis` and `goal` (for conversions).

REST: `POST /change-sets` with `{"plan":{...},"dry_run":true}` to preview;
remove `dry_run` and optionally add `"experiment":"Shorter titles","metric":"ctr"`
to apply. `GET /change-sets` lists 50 newest sets (`before` for older ones),
`GET /change-sets/1` reads one. Abilities: `seoproanalytics/change-sets`
(`id` or `before`), `seoproanalytics/change-set-apply` (`plan`, `dry_run`,
`experiment` and experiment options), `seoproanalytics/change-set-undo` (`id`).
All writes require administrator permission and `edit_post` on each page.

Inspect `status`, per-field `result`/`reason`, and `experiment_error` if present.
A stopped run is partly applied, not rolled back automatically. Undo with
`wp seoproanalytics change-sets undo 1 --user=admin` or
`POST /change-sets/1/undo`; later edits are kept and reported. Inspect the saved
set before retrying a failed operation. Do not also mark the Plan item done
with its usual experiment creation: that would create a second experiment.

### 5. Decide on the review day

An experiment is due when search data covers its review day. Read its
`measurement`:

- `suggested` (`keep`, `revise`, `undo` or `inconclusive`) with its
  `reasons`, and a one-line `summary`;
- `effect` and `improvement`: the change on the pages against the
  unchanged pages in the same days, so a season or a site-wide rise does
  not count;
- `noise`, `beyond_noise`: how far unchanged pages usually move;
- `enough`: whether there was enough data;
- `confounders`: search engine updates and other changes in the same
  days.

Then decide, with what was learnt in the note:

```sh
curl -s -u "$AUTH" -X POST "$SITE/experiments/12" \
  -H "Content-Type: application/json" \
  -d '{"action":"decide","result":"keep","note":"CTR +18% against 120 unchanged pages"}'
wp seoproanalytics experiments decide 12 keep --note="CTR +18% against 120 unchanged pages"
```

With the ability: `seoproanalytics/experiment-record` with `action` `decide`,
`id`, `result` and `note`.

For an experiment created by applying a set, decide and restore in one step:

```sh
wp seoproanalytics experiments decide 12 undo --restore --user=admin --format=json
curl -s -u "$AUTH" -X POST "$SITE/experiments/12" \
  -H "Content-Type: application/json" -d '{"action":"decide","result":"undo","restore":true}'
```

The `seoproanalytics/experiment-record` ability also accepts `restore: true`.
Without a linked set, restore is refused. The response's `restoration` gives
conflicts and outcomes; changed-since values are left, producing partly undone.
All restoration uses live data, regardless of the selected report data set.

The decision keeps the measurement it was made on. Without `restore`, `undo`
only records the decision; put the pages back separately. A result is evidence about the
site, not proof of cause: record null and negative results too, as they
stop the same change being tried again.

### 6. Carry the results into the next plan

The next cycle's `experiments.decided` lists this cycle's results. Repeat
what was kept on similar items, avoid what was undone, and write down the
lesson where the team keeps its notes.

## Search data for other tools

`wp seoproanalytics loop --format=toon` writes only the export rows as an
aidevops export file (header lines, `---`, then tab-separated columns), so
tools that read that layout take the plugin's search data as a tracking
run without connecting to the search engines themselves:

```sh
wp seoproanalytics loop --range=90d --rows=5000 --format=toon > gsc-2026-07-01-2026-09-28.toon
```

`--engine=bing` writes Bing's (`source` `bing`). Over REST, the same rows
are in `export` of `GET /loop`.

## A crawl for the loop

The plugin never crawls unless **Crawl this site** is on. Administrators can
run `wp seoproanalytics crawl enable`, then `crawl run` to advance one bounded
batch; `crawl status` reads progress and `crawl stop` stops collection. Cron
continues the work. `crawl enable --external` separately consents to checking
external link targets. `--force` on enable/run bypasses readiness only, never
robots.txt or HTTP safety. REST equivalents are POST `/crawl/readiness`
(external probe only with `external: true`), POST/GET/DELETE `/crawl/run`.
Only settings administrators can use these routes. GET status is read-only.

Alternatively, an agent that can run another crawler gives it what
the pages served. Crawl the public site (LibreCrawl, the aidevops site
crawler or another that exports CSV or JSON), then import every export
file of that one crawl together, so links to the crawl's own pages take
those pages' answers:

```sh
wp seoproanalytics crawl import crawl-data.csv broken-links.csv redirects.csv
wp seoproanalytics crawl --finding=crawl_error --format=json
wp seoproanalytics crawl --kind=clicked --range=30d --format=json
```

Over REST, `POST /crawl/import` (multipart `files[]`, or JSON `files`
with each file's `name` and `rows`) answers 202 with the job; read `GET
/crawl/import` until `status` is `done`, which also moves the import on.
The ability `seoproanalytics/crawl-import` takes `files` with rows or `text`
of one export. The next `GET /loop` then has the crawl's findings as
`audit` items (`crawl_error`, `served_noindex`, `served_canonical`,
`broken_links`, `redirect_links`, and `clicked_broken` for clicked
destinations that fail; `figures.target` names the destination). Fix
them in the site, crawl again and import: a page whose answer changed
gets a `served` change on the timeline. Mark the item done as for any
other, so its experiment measures the fix.

For crawl findings, the next fresh import covering the page also confirms or
reopens the item. Read `GET /queue?status=done` (or `wp seoproanalytics queue
--status=done --format=json`): `verified` is `confirmed` or `reopened`, with
`verified_at`; reopened items are also open. The queue ability returns the same
fields. Do not infer confirmation from a finding absent from a ranked report.

For a small follow-up crawl, send `partial: true` to `POST /crawl/import` or
`seoproanalytics/crawl-import`, or run `wp seoproanalytics crawl import pages.csv links.csv
--partial`. Include pages (at most 100) and their complete links export. Unknown
destinations are not proof a broken link is fixed. Other pages remain untouched,
and partial counts never enter the whole-site trend. Undo the partial import
before starting the next import to restore its replaced rows and verdicts.

With the add-on's crawler connection, marking done can queue this check
automatically (the `seoproanalytics_crawl_fix_done` action). Use a normal
whole-site crawl to verify depth or discoverability; a crawl seeded at a
page cannot establish either.

## Search targets

Search targets (Search → Targets) are the searches the site chose to win
and the page meant for each. They come from three places.

The SEO plugin's focus keywords (Rank Math, Yoast SEO, SEOPress or All in
One SEO), each with the page it is set on. List them, then import the new
ones (all, or those named) as targeted:

```sh
curl -s -u "$AUTH" "$SITE/targets/suggestions"
curl -s -u "$AUTH" -X POST "$SITE/targets/suggestions" \
  -H "Content-Type: application/json" --data '{"queries":["core web vitals"]}'
wp seoproanalytics targets suggest
wp seoproanalytics targets import --from=seo-plugin
```

A keyword set on more than one page (`clash`) is never imported: choose
its page and import it as a list. Targets already set stay as they are.

Searches the reports already show, as candidates that leave listed
targets alone (`only_new`), with the page that ranks when there is one:

```sh
curl -s -u "$AUTH" -X POST "$SITE/targets" -H "Content-Type: application/json" \
  --data '{"targets":[{"query":"speed up wordpress","page":"/blog/speed-up-wordpress/","status":"candidate"}],"only_new":true,"source":"search"}'
```

Keyword research: the aidevops search targets table (`phrase`,
`target_url`, `priority`, `status`), or any list as CSV, tab-separated
text or JSON:

```sh
jq -Rs '{text: .}' targets.toon | curl -s -u "$AUTH" -X POST "$SITE/targets" \
  -H "Content-Type: application/json" --data-binary @-
wp seoproanalytics targets import targets.toon
```

Rows that cannot be read are skipped and listed with their reason. The
queue then adds `target` items where another page ranks for a target, and
high-priority targets close to the top three. Import again when the
targets change: a search already kept is updated, and `"replace": true`
deletes the targets not in the import.

## Cost

`GET /loop` reads what the queue and the experiments list already read
(their answers are cached), and one grouped read of the search data by
query and page for the period, by its primary key, cached until the next
search import. Nothing is stored and no visitor page is touched.
