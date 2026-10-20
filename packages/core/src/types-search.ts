/**
 * Answers of the SEO Pro Analytics API, as docs/api/openapi.yaml describes
 * them. Keep the two in step.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 * Provenance: wpas-c8662019b99fb07b. Copies, even of part, keep these lines.
 */

import type { Answer, Range, Marker, ClickPageInfo, VitalSample } from './types-traffic';

/** Rows of the search report. */
export const SEARCH_KINDS = ['queries', 'pages', 'countries', 'devices', 'appearance', 'days'] as const;
export type SearchKind = (typeof SEARCH_KINDS)[number];

/** The kinds every engine, page and query has; the others are Google's whole site only. */
export const SEARCH_ANY_KINDS: readonly SearchKind[] = ['queries', 'pages', 'days'];

/** Directions of a sorted table: desc most (or newest) first, asc least first. */
export const SORT_ORDERS = ['desc', 'asc'] as const;
export type SortOrder = (typeof SORT_ORDERS)[number];

/** Columns whose first click sorts lowest first (a lower position is better); the others sort most first. */
const ASCENDING_SORTS: ReadonlySet<string> = new Set(['position']);

/** A column's natural order: its first click; a second click reverses it. */
export function naturalOrder(sort: string): SortOrder {
	return ASCENDING_SORTS.has(sort) ? 'asc' : 'desc';
}

/**
 * A table's sort from the view: the column asked for if the table has it
 * (else the table's default, the first) and the direction that goes with
 * it (the column's natural one unless reversed).
 */
export function tableSort<K extends string>(sorts: readonly K[], sort: string | undefined, order: SortOrder | undefined): { sort: K; order: SortOrder } {
	const has = sort === undefined || (sorts as readonly string[]).includes(sort);
	const column = has && sort !== undefined ? (sort as K) : sorts[0]!;
	return { sort: column, order: (has ? order : undefined) ?? naturalOrder(column) };
}

/** Orders of the search rows (Rankings) and of Audit's pages; the first is the default. */
export const SEARCH_SORTS = ['impressions', 'clicks', 'ctr', 'position'] as const;
export type SearchSort = (typeof SEARCH_SORTS)[number];

/** Orders of the days rows: by day (the default, newest first) or a figure. */
export const SEARCH_DAY_SORTS = ['day', ...SEARCH_SORTS] as const;
export type SearchDaySort = (typeof SEARCH_DAY_SORTS)[number];

/** The orders of a kind of search rows; the first is the default. */
export function searchSorts(kind: SearchKind): readonly SearchDaySort[] {
	return kind === 'days' ? SEARCH_DAY_SORTS : SEARCH_SORTS;
}

/** The Search section's reports; the first is the default. */
export const SEARCH_REPORTS = ['rankings', 'opportunities', 'audit', 'content', 'backlinks', 'targets', 'plan', 'experiments'] as const;
export type SearchReport = (typeof SEARCH_REPORTS)[number];

/**
 * Search engines with imported data; the first is the default. Bing gives
 * pages and queries by week (stored on each week's last day) and no
 * countries or devices.
 */
export const SEARCH_ENGINES = ['google', 'bing'] as const;
export type SearchEngine = (typeof SEARCH_ENGINES)[number];

/**
 * The engine choices: an engine, or `all` (Combined), every engine with
 * data added up. Combined is for Rankings, Opportunities and Content
 * (COMBINED_REPORTS); it has no countries or devices, and with an engine
 * by week its period is whole weeks.
 */
export const SEARCH_ENGINE_CHOICES = [...SEARCH_ENGINES, 'all'] as const;
export type SearchEngineChoice = (typeof SEARCH_ENGINE_CHOICES)[number];

/** The Search reports that can show Combined; the others show one engine (Google for Combined). */
export const COMBINED_REPORTS = ['rankings', 'opportunities', 'content'] as const;

/** The one engine a report of one engine reads: Google for Combined. */
export function singleEngine(engine: SearchEngineChoice | undefined): SearchEngine {
	return engine && engine !== 'all' ? engine : 'google';
}

/** What every search answer says about its engine. */
export interface SearchEngineAnswer<E extends SearchEngineChoice = SearchEngine> {
	engine: E;
	/** Engines with search data or connected; Google always. */
	engines: SearchEngine[];
	/** Rankings, Opportunities and Content: whether Combined adds anything up (two or more engines with data). */
	combined?: boolean;
}

export interface SearchMetrics {
	clicks: number;
	impressions: number;
	/** clicks ÷ impressions. */
	ctr: number;
	/** Average position, weighted by impressions; 0 without impressions. Lower is better. */
	position: number;
}

export type SearchMetricKey = keyof SearchMetrics;

/** Relative change for clicks, impressions and CTR; position: now − then in places (lower is better). */
export type SearchChange = Record<SearchMetricKey, number | null>;

export interface SearchPoint extends SearchMetrics {
	t: string;
}

export interface SearchRow extends SearchMetrics {
	/** Query or page dictionary ID, country code (alpha-3), device code, appearance or a days row's first day. */
	id: string;
	/** The query, page path, country (alpha-2; '' unknown), device, appearance or a days row's first day. */
	value: string;
	label: string;
	/** clicks ÷ all clicks. */
	share: number;
	/** Present only for days rows: the days covered (YYYY-MM-DD, both included), as the chart's grain. */
	from?: string;
	to?: string;
	/** Present only for pages rows. */
	path?: string;
	url?: string;
	post_id?: number;
	/** Present only when the page is a term archive (and no post). */
	term_id?: number;
	taxonomy?: string;
	edit_url?: string | null;
	compare?: SearchMetrics & { change: SearchChange };
}

export interface SearchAnswer extends Answer, SearchEngineAnswer<SearchEngineChoice> {
	/** The range cut at the newest day with search data. */
	range: Range;
	/** Newest and first day with search data (YYYY-MM-DD); '' with none. */
	through: string;
	first: string;
	/** Whether the engine's source is connected (demo data: always). */
	connected: boolean;
	kind: SearchKind;
	/** The rows' order, as asked or the kind's default. */
	sort: SearchDaySort;
	order: SortOrder;
	page: string;
	query: string;
	page_info: ClickPageInfo | null;
	/** Filter dimensions left out: only page filters apply to search data. */
	ignored: string[];
	totals: SearchMetrics;
	/** week: a page or query of an engine that gives them by week. */
	grain: 'day' | 'week' | 'month';
	points: SearchPoint[];
	rows: SearchRow[];
	more: boolean;
	compare?: {
		range: Range;
		totals: SearchMetrics;
		change: SearchChange;
		points: SearchPoint[];
	};
}

/** Kinds of search opportunity. */
export const OPPORTUNITY_KINDS = ['striking', 'ctr', 'decay', 'missing', 'overlap'] as const;
export type OpportunityKind = (typeof OPPORTUNITY_KINDS)[number];

/** Likely cause of a page losing clicks. */
export type DecayCause = 'position' | 'demand' | 'ctr' | 'gone';

/** A page of an opportunity, with links. */
export interface OpportunityPage {
	path_id: number;
	path: string;
	url: string;
	post_id: number;
	/** Present only when the page is a term archive (and no post). */
	term_id?: number;
	taxonomy?: string;
	edit_url: string | null;
}

/** A page's query: striking distance or low CTR. */
export interface OpportunityPair extends OpportunityPage, SearchMetrics {
	query: string;
	/** CTR aimed for: the site's at position 3 (striking) or at its position (ctr). */
	expected_ctr: number;
	/** Clicks it could gain (striking) or misses (ctr) in the period. */
	potential: number;
}

/** Another page of the site that overtook a losing page for a query: its figures now, its position before (null: not shown then) and its share of the query's impressions. */
export interface DecayRival extends OpportunityPage {
	clicks: number;
	impressions: number;
	position: number;
	then_position: number | null;
	share: number;
}

/** A page losing clicks. */
export interface OpportunityDecay extends OpportunityPage, SearchMetrics {
	compare: SearchMetrics & { change: SearchChange };
	lost: number;
	cause: DecayCause;
	/** One sentence saying why, in the site's language. */
	why: string;
	/** The queries that lost most, each with the other page that overtook this one there (ranks better now, did not before), if one did. */
	queries: {
		query: string;
		lost: number;
		clicks: number;
		then_clicks: number;
		position: number | null;
		then_position: number | null;
		rival: DecayRival | null;
	}[];
	/** What changed on the page in the two periods, newest first. */
	changes: Marker[];
}

/** How far a page's words cover a query, best first (coverage.ts). */
export const COVERAGE_MATCHES = ['title', 'heading', 'text', 'partial', 'none'] as const;
export type CoverageMatch = (typeof COVERAGE_MATCHES)[number];

export interface CoverageResult {
	match: CoverageMatch;
	/** The query's words, in order, are on the page. */
	phrase: boolean;
	/** The query's words the page does not have. */
	missing: string[];
	question: boolean;
}

/** A page's query its words do not cover, or only partly. */
export interface OpportunityMissing extends OpportunityPage, SearchMetrics, CoverageResult {
	query: string;
}

/** A page sharing a query: its figures and share of the query's impressions. */
export interface OverlapPage extends OpportunityPage, SearchMetrics {
	share: number;
}

/**
 * A query shared by pages (a candidate to review): the leading page's
 * fields, with the query's figures over all its pages.
 */
export interface OpportunityOverlap extends OpportunityPage, SearchMetrics {
	query: string;
	/** Most impressions first; at most rules.pages. */
	pages: OverlapPage[];
	/** Pages with the share or more, including any not listed. */
	page_count: number;
	/** The page with most impressions in the first and second half of the period. */
	leaders: [string | null, string | null];
	/** Whether the page with most impressions changed between the halves. */
	switched: boolean;
	/** Clicks it would have if all its pages' impressions had the best of their CTRs. */
	potential: number;
}

export interface OpportunitiesAnswer extends Answer, SearchEngineAnswer<SearchEngineChoice> {
	kind: OpportunityKind;
	/** The period read: cut at the newest search day and to its newest 366 days. */
	range: Range;
	days: number;
	cut: boolean;
	through: string;
	first: string;
	connected: boolean;
	ignored: string[];
	rules: Record<string, number>;
	rows: (OpportunityPair | OpportunityDecay | OpportunityMissing | OpportunityOverlap)[];
	total: number;
	more: boolean;
	/** overlap: the two halves of the period; null when too short to halve. */
	halves?: [Range, Range] | null;
	/** striking and ctr: the site's CTR by position (1–20). */
	curve?: { source: 'site' | 'mixed' | 'default'; ctr: Record<string, number> } | null;
	/**
	 * decay: the earlier period, and search engine updates in either; cut
	 * when both periods were shortened so the earlier one holds only days
	 * with search data (range and days are then the shortened period's).
	 */
	compare?: { range: Range; cut: boolean } | null;
	updates?: Marker[];
}

/** A query of the coverage report. */
export interface CoverageRow extends SearchMetrics, CoverageResult {
	query: string;
}

/** A focus keyword set in an SEO plugin, with its search figures when it is a query of the page. */
export interface CoverageFocus extends CoverageResult {
	keyword: string;
	/** rank-math, yoast, seopress, aioseo, demo, or another plugin's name. */
	source: string;
	searched: boolean;
	clicks: number;
	impressions: number;
	position: number | null;
}

export interface CoverageAnswer extends Answer {
	page: string;
	post_id: number;
	page_info: ClickPageInfo | null;
	/** The period read: cut at the newest search day and to its newest 366 days. */
	range: Range;
	days: number;
	cut: boolean;
	through: string;
	first: string;
	connected: boolean;
	/** What the page's words were read from; null when it is not one post. */
	text: {
		source: 'post' | 'demo';
		words: number;
		/** The SEO plugin read; '' for none. */
		plugin: string;
		seo_title: string;
		description: string;
	} | null;
	focus: CoverageFocus[];
	totals: {
		queries: number;
		impressions: number;
		clicks: number;
		/** Share of impressions on queries the page covers. */
		covered: number;
		/** Queries covered partly or not at all. */
		missing: number;
		questions: number;
	};
	/** Most impressions first, up to 200. */
	rows: CoverageRow[];
	more: boolean;
}

/** Orders of the content report; the first is the default. Conversions need a goal. */
export const CONTENT_SORTS = ['impressions', 'clicks', 'ctr', 'position', 'visits', 'bounce_rate', 'visit_duration', 'conversions', 'conversion_rate'] as const;
export type ContentSort = (typeof CONTENT_SORTS)[number];

/** A page's search figures with its visits from search and their conversions of the goal. */
export interface ContentMetrics extends SearchMetrics {
	/** Visits from organic search (any engine) that started on the page. */
	visits: number;
	bounce_rate: number;
	views_per_visit: number;
	/** Seconds. */
	visit_duration: number;
	/** Visits that reached the goal; null without goals. */
	conversions: number | null;
	conversion_rate: number | null;
}

export type ContentChange = Partial<Record<keyof ContentMetrics, number | null>>;

/**
 * Who is responsible for the SEO of a page, target, Plan item or
 * experiment. assigned: set by a person or agent (id null: nobody, on
 * purpose); default: worked out (a page's post author, or its page's
 * owner); none: no owner.
 */
export interface Owner {
	id: number | null;
	name: string | null;
	source: 'assigned' | 'default' | 'none';
}

export type OwnerKind = 'page' | 'target' | 'item' | 'experiment';

/** One person's (or nobody's) open work. */
export interface OwnerCounts {
	/** Pages owned (not given for nobody). */
	pages?: number;
	/** Open Plan items (new and accepted). */
	items: number;
	/** Those items' potential clicks per 28 days. */
	clicks: number;
	/** Open search targets. */
	targets: number;
	/** Running experiments, and those due for review. */
	running: number;
	due: number;
}

export interface OwnerPerson extends OwnerCounts {
	id: number;
	name: string;
	me: boolean;
	pages: number;
}

export interface OwnersAnswer {
	roles: string[];
	people: OwnerPerson[];
	nobody: OwnerCounts;
	plan: Record<string, unknown>;
	/** Administrators give work to anyone. */
	can_assign: boolean;
	/** People who can own take available work and give their own back. */
	can_take: boolean;
	me: number;
}

export interface OwnersSetAnswer {
	kind: OwnerKind;
	owner: Owner | null;
	default: boolean;
	set: string[];
	skipped: { id: string; reason: string }[];
}

export interface ContentRow extends ContentMetrics, OpportunityPage {
	id: string;
	value: string;
	label: string;
	/** Null for people who cannot edit posts, and in shared reports. */
	owner?: Owner | null;
	compare?: ContentMetrics & { change: ContentChange };
}

export interface ContentAnswer extends Answer, SearchEngineAnswer<SearchEngineChoice> {
	/** The range cut at the newest day with search data. */
	range: Range;
	through: string;
	first: string;
	connected: boolean;
	ignored: string[];
	sort: ContentSort;
	order: SortOrder;
	/** The goal counted (the first unless one is chosen); null without goals. */
	goal: { id: string; name: string; kind: string; match: string } | null;
	goals: { id: string; name: string }[];
	/** Oldest day whose visits from search are summarised; '' before the first. */
	landings_from: string;
	/** Whether some days of the period (or comparison) are not summarised yet. */
	partial: boolean;
	totals: ContentMetrics;
	rows: ContentRow[];
	total: number;
	more: boolean;
	compare?: { range: Range; totals: ContentMetrics; change: ContentChange };
}

/** Content audit findings, most serious first. */
export const AUDIT_FINDINGS = [
	'noindex',
	'canonical',
	'crawl_error',
	'served_noindex',
	'served_canonical',
	'served_title',
	'served_description',
	'unreachable',
	'deep',
	'slow',
	'robots_blocked',
	'not_indexed',
	'google_canonical',
	'bing_crawl',
	'broken_links',
	'thin',
	'title_missing',
	'title_duplicate',
	'title_long',
	'description_missing',
	'description_duplicate',
	'description_long',
	'h1_none',
	'h1_several',
	'rich_errors',
	'redirect_links',
	'images_alt',
] as const;
export type AuditFinding = (typeof AUDIT_FINDINGS)[number];

/** Findings from an imported crawl (also content audit findings), whatever a page's impressions. */
export const CRAWL_FINDINGS = ['crawl_error', 'served_noindex', 'served_canonical', 'served_title', 'served_description', 'broken_links', 'redirect_links', 'unreachable', 'deep', 'slow'] as const;
export type CrawlFinding = (typeof CRAWL_FINDINGS)[number];

/** The imported crawl's lists: pages with a problem, links that fail or redirect, clicked destinations that fail. */
export const CRAWL_KINDS = ['pages', 'links', 'clicked'] as const;
export type CrawlKind = (typeof CRAWL_KINDS)[number];

/** Status filters: blocked is links only; noindex, canonical and title_missing are pages only. */
export type CrawlStatus = 'errors' | 'redirects' | 'blocked' | 'noindex' | 'canonical' | 'title_missing';

/** An HTTP status's class: ok (2xx), redirect (3xx), error (4xx, 5xx) or none (no answer). */
export type CrawlAnswerClass = 'ok' | 'redirect' | 'error' | 'none';

/** What a page served to the crawl. */
export type CrawlFlag = 'noindex' | 'nofollow' | 'canonical' | 'title_missing' | 'title_differs' | 'desc_differs' | 'desc_missing';

/** The crawl's facts of a page on its audit row. */
export interface AuditCrawl {
	response_ms: number | null;
	prev_response_ms: number | null;
	bytes: number | null;
	field: VitalSample[];
	field_poor: boolean;
	slow: boolean;
	links_in: number | null;
	links_in_body: number | null;
	depth: number | null;
	/** Served count; null when the export did not observe H1s. */
	h1: number | null;
	/** HTTP status; 0 for no answer; null when only its links were crawled. */
	status: number | null;
	hops: number;
	redirect: string | null;
	flags: CrawlFlag[];
	/** Its links that fail, and internal links through redirects. */
	broken: number;
	redirects: number;
	crawled: string | null;
}

export interface CrawlPageRow extends AuditCrawl {
	visits?: number;
	clicks?: number;
	conversions?: number;
	goal?: { id: string; name: string } | null;
	/** null means not observed or not retained; empty means observed empty. */
	title: string | null;
	description: string | null;
	/** Current WordPress text, not a second stored crawl snapshot. */
	stored_title: string | null;
	stored_description: string | null;
	path_id: number;
	path: string;
	url: string;
	answer: CrawlAnswerClass | null;
	/** The status in the import before; null when not crawled then. */
	prev_status: number | null;
	findings: CrawlFinding[];
	depth: number | null;
	response_ms: number | null;
	bytes: number | null;
}

export interface CrawlLinkRow {
	from: string;
	from_url: string;
	target: string;
	internal: boolean;
	status: number;
	/** External 401/403/429/999 is blocked; stored status is unchanged. */
	answer: CrawlAnswerClass | 'blocked';
	failed: boolean;
	blocked: boolean;
	hops: number;
	placement: 'navigation' | 'footer' | 'body' | 'image' | 'sidebar' | 'other';
	anchor: string;
}

/** An outbound or affiliate destination clicked in the range whose crawl answer failed. */
export interface CrawlClickedRow {
	hops: number;
	redirect: string | null;
	target: string;
	status: number;
	answer: CrawlAnswerClass;
	clicks: number;
	outbound: boolean;
	affiliate: boolean;
	path_id: number;
	/** Pages it was clicked on, most clicks first (up to five). */
	pages: string[];
	/** Pages the crawl saw link to it (up to five). */
	linked_on: string[];
}

export interface CrawlCounts {
	pages: number;
	ok: number;
	redirect: number;
	error: number;
	none: number;
	redirected: number;
	noindex: number;
	canonical: number;
	title_missing: number;
	title_differs: number;
	desc_differs: number;
	desc_missing: number;
	links: number;
	broken_links: number;
	blocked_links: number;
	redirect_links: number;
}

/** Once per completed import, not per-page history. Counts before audit exclusions. */
export interface CrawlTotals {
	/** Nearest-rank milliseconds, excluding unknown (zero) timings; absent on legacy imports. */
	response_ms?: { median: number | null; p90: number | null };
	complete?: boolean;
	link_counts?: boolean;
	pages: number;
	links: number;
	statuses: Record<'2xx' | '3xx' | '4xx' | '5xx' | 'none' | 'other', number>;
	findings: Partial<Record<CrawlFinding, number>>;
	/** Seconds, including staging and time between batches. */
	duration: number;
}

export interface CrawlTrendPoint {
	id: number;
	started: string;
	totals: CrawlTotals;
}

/** A crawl made elsewhere and imported: what each page served, and links that fail or redirect. */
export interface CrawlAnswer {
	complete: boolean;
	coverage_note: string;
	kind: CrawlKind;
	status: CrawlStatus | '';
	finding: CrawlFinding | '';
	import: number | null;
	/** librecrawl, aidevops or csv. */
	format: string | null;
	files: { name: string; kind: 'pages' | 'links'; format: string }[];
	read: string | null;
	counts: CrawlCounts;
	findings: Record<CrawlFinding, number>;
	/** Completed imports with totals, oldest first (up to the newest 5,000). */
	trend: CrawlTrendPoint[];
	/** clicked only. */
	range: Range | null;
	rows: (CrawlPageRow | CrawlLinkRow | CrawlClickedRow)[];
	total: number;
	more: boolean;
	rules: { max_pages: number; max_links: number; max_rows: number; formats: string[] };
}

/** Findings of Google's URL Inspection (also content audit findings). */
export const INSPECTION_FINDINGS = ['robots_blocked', 'not_indexed', 'google_canonical', 'rich_errors'] as const;
export type InspectionFinding = (typeof INSPECTION_FINDINGS)[number];

/** Google's verdicts of a page in its index. */
export const INSPECTION_VERDICTS = ['PASS', 'PARTIAL', 'FAIL', 'NEUTRAL'] as const;
export type InspectionVerdict = (typeof INSPECTION_VERDICTS)[number];

/** A rich result type Google detected on a page, with its issues. */
export interface InspectionRichType {
	type: string;
	items: number;
	errors: number;
	warnings: number;
	issues: { message: string; severity: 'error' | 'warning' }[];
}

/** Google's view of a page from URL Inspection (the version in its index); Google's values as it gives them, null when not given. */
export interface InspectionGoogle {
	/** When it was inspected (ISO 8601). */
	checked: string;
	verdict: InspectionVerdict | null;
	/** Google's reason, in its words, e.g. "Crawled - currently not indexed". */
	coverage: string | null;
	indexing: string | null;
	robots: string | null;
	page_fetch: string | null;
	crawled_as: string | null;
	last_crawl: string | null;
	google_canonical: string | null;
	user_canonical: string | null;
	rich_verdict: InspectionVerdict | null;
	rich: InspectionRichType[];
	/** Sitemaps and pages Google knows it from (up to five each). */
	sitemaps: string[];
	referring: string[];
	/** The inspection in Search Console. */
	link: string | null;
	/** Google's message when it would not inspect the address. */
	error: string | null;
	findings: InspectionFinding[];
}

/** A sitemap problem: errors, not downloaded lately, warnings. */
export type SitemapProblem = 'errors' | 'stale' | 'warnings';

/** A sitemap submitted in Search Console, as Google read it. */
export interface SearchSitemap {
	path: string;
	type: string;
	index: boolean;
	pending: boolean;
	submitted: string | null;
	downloaded: string | null;
	errors: number;
	warnings: number;
	/** Addresses submitted, by type (Google's indexed count is deprecated and left out). */
	contents: { type: string; submitted: number }[];
	problems: SitemapProblem[];
}

/** The property's sitemaps, read daily while Search Console is connected. */
export interface SearchSitemaps {
	read: string | null;
	error: string | null;
	/** The site's own sitemap index (its SEO plugin's, or WordPress's); null without one. */
	own: string | null;
	/** False when the site's own sitemap index is not submitted. */
	submitted: boolean;
	stale_days: number;
	rows: SearchSitemap[];
}

/** URL Inspection's daily cap, today's use, the last run and its error. */
export interface InspectionProgress {
	daily: number;
	used: number;
	/** Google's day (Pacific time), YYYY-MM-DD. */
	day: string;
	last: string | null;
	error: string | null;
	error_at: string | null;
}

export interface InspectionRow extends OpportunityPage, InspectionGoogle {}

/** Bing's crawl issue flags, in its order: redirects, client and server errors, robots.txt, malware, DNS, timeouts. */
export const CRAWL_ISSUES = ['redirect_301', 'redirect_302', 'client_error', 'server_error', 'robots_blocked', 'malware', 'important_blocked', 'dns', 'timeout'] as const;
export type CrawlIssue = (typeof CRAWL_ISSUES)[number];

/** Bing's crawl issue of an address: every issue but redirects is an error. */
export interface CrawlIssueRow {
	url: string;
	/** Its path on the site; '' when not on it. */
	path: string;
	/** HTTP status Bing got; 0 when none (robots.txt, DNS). */
	http: number;
	issues: CrawlIssue[];
	error: boolean;
	/** Links to it Bing knows. */
	inlinks: number;
}

/** One day of Bing's crawl. */
export interface CrawlStatsDay {
	day: string;
	crawled: number;
	errors: number;
	in_index: number;
	inlinks: number;
	code_2xx: number;
	code_301: number;
	code_302: number;
	code_4xx: number;
	code_5xx: number;
	robots_blocked: number;
	dns: number;
	timeout: number;
	malware: number;
	other: number;
}

/** A sitemap (or feed) Bing has for the site. */
export interface BingSitemap {
	url: string;
	type: string;
	/** Bing's word, e.g. "Success". */
	status: string;
	submitted: string | null;
	crawled: string | null;
	urls: number;
}

/** The weekly read of Bing's inbound links into Backlinks (found: bing). */
export interface BingLinksRead {
	read: string | null;
	started: string | null;
	running: boolean;
	targets: number;
	links: number;
	error: string | null;
}

/** Bing's site data on the Connections tab: crawl facts' last read, URL submission quota (for IndexNow), the links read. */
export interface BingSiteStatus {
	read: string | null;
	error: string | null;
	issues: number;
	quota: { daily: number; monthly: number } | null;
	links: BingLinksRead;
}

/** Bing's crawl issues, crawl stats, sitemaps and quota, read daily while Bing Webmaster Tools is connected. */
export interface CrawlIssuesAnswer {
	engine: 'bing';
	connected: boolean;
	site: string | null;
	issue: CrawlIssue | 'errors' | '';
	read: string | null;
	error: string | null;
	counts: Record<CrawlIssue | 'errors', number>;
	/** Errors first, then most inbound links. */
	rows: CrawlIssueRow[];
	total: number;
	more: boolean;
	/** Oldest day first. */
	stats: CrawlStatsDay[];
	sitemaps: BingSitemap[];
	quota: { daily: number; monthly: number } | null;
	links: BingLinksRead;
	rules: { requests: number; budget: number; max_issues: number; max_links: number };
}

export interface InspectionsAnswer {
	connected: boolean;
	ignored: string[];
	verdict: InspectionVerdict | '';
	coverage: string;
	finding: InspectionFinding | '';
	progress: InspectionProgress;
	rules: { daily: number; max_daily: number; recheck_days: number; budget: number; stale_days: number };
	sitemaps: SearchSitemaps;
	/** Pages inspected, and per verdict. */
	inspected: number;
	counts: Record<InspectionVerdict, number>;
	/** Newest first. */
	rows: InspectionRow[];
	total: number;
	more: boolean;
}

/** What the content audit read from a page's post and SEO plugin fields. */
export interface AuditFacts {
	/** Characters of the title shown: the SEO title, else the post title. */
	title_length: number;
	/** Characters of the SEO title as written (0 when it is the plugin's variables). */
	seo_title_length: number;
	/** Characters of the description: the SEO plugin's, else the excerpt. */
	description_length: number;
	/** H1s in the text (themes show the title as one more). */
	h1: number;
	words: number;
	images: number;
	images_no_alt: number;
	noindex: boolean;
	canonical_away: boolean;
	/** When the post was last changed and its facts read (ISO 8601). */
	modified: string | null;
	checked: string;
}

/** A page with audit findings and its search figures in the period. */
export interface AuditRow extends OpportunityPage, SearchMetrics {
	findings: AuditFinding[];
	facts: AuditFacts;
	/** Other pages with the same title or description (up to five). */
	same_title: string[];
	same_description: string[];
	/** Google's URL Inspection of the page; null until inspected. */
	google: InspectionGoogle | null;
	/** Bing's crawl issue of the page; null without one. */
	bing: { http: number; issues: CrawlIssue[]; url: string } | null;
	/** The imported crawl's facts of the page; null when it was not crawled. */
	crawl: AuditCrawl | null;
}

export interface AuditAnswer extends Answer, SearchEngineAnswer {
	/** The period of the search figures: cut at the newest search day and to its newest 366 days. */
	range: Range;
	days: number;
	cut: boolean;
	through: string;
	first: string;
	connected: boolean;
	ignored: string[];
	/** The finding asked for; '' for all. */
	finding: AuditFinding | '';
	/** The pages' order, as asked or most impressions first. */
	sort: SearchSort;
	order: SortOrder;
	rules: {
		title_max: number;
		description_max: number;
		thin_words: number;
		thin_impressions: number;
		batch: number;
		stale_days: number;
	};
	/** Pages with facts, and when the oldest and newest were read. */
	checked: { pages: number; oldest: string | null; newest: string | null };
	/** The SEO plugin read: rank-math, yoast, seopress, aioseo, demo or ''. */
	plugin: string;
	/** Pages per finding (of every page, whatever finding is asked for). */
	counts: Record<AuditFinding, number>;
	/** Pages with any finding. */
	pages: number;
	/** Most impressions first. */
	rows: AuditRow[];
	total: number;
	more: boolean;
}

/** Internal links lists. */
export const LINKS_KINDS = ['orphans', 'converting', 'missing'] as const;
export type LinksKind = (typeof LINKS_KINDS)[number];

/** An orphan page, or a converting page with few links in. */
export interface LinksPageRow extends OpportunityPage, SearchMetrics {
	source: 'served' | 'text';
	links_in_text: number;
	links_in_served: number | null;
	/** Visits from search that started on the page, and their conversions of the goal (null without one). */
	visits: number;
	conversions: number | null;
	/** Other pages whose text links to it, and up to five of them. */
	links_in: number;
	from: string[];
}

/** A search a page shows for whose page with most clicks it does not link to. */
export interface LinksMissingQuery {
	query: string;
	clicks: number;
	impressions: number;
	position: number;
	/** The page it should link to, for the search. */
	to_clicks: number;
	to_position: number;
}

/** A missing link: the page (its figures for the searches) and the page it should link to (its figures for them). */
export interface LinksMissingRow extends OpportunityPage, SearchMetrics {
	to: OpportunityPage & SearchMetrics;
	/** Up to five searches, most impressions first, of query_count. */
	queries: LinksMissingQuery[];
	query_count: number;
}

export type LinksRow = LinksPageRow | LinksMissingRow;

export interface LinksAnswer extends Answer, SearchEngineAnswer {
	/** The period of the search figures: cut at the newest search day and to its newest 366 days. */
	range: Range;
	days: number;
	cut: boolean;
	through: string;
	first: string;
	connected: boolean;
	ignored: string[];
	kind: LinksKind;
	rules: {
		/** Pages linking in at most for "few". */
		few_links: number;
		min_conversions: number;
		/** Impressions a page needs on a search for a missing link. */
		min_impressions: number;
		queries: number;
		/** Pages a page's links are kept to, at most. */
		max_links: number;
	};
	/** Published pages read by the audit, and how many of them had their links read. */
	read: { pages: number; read: number; complete: boolean };
	/** The goal counted; null without goals. */
	goal: { id: string; name: string } | null;
	goals: { id: string; name: string }[];
	/** Rows per list (of every list, whichever is asked for). */
	counts: Record<LinksKind, number>;
	rows: LinksRow[];
	total: number;
	more: boolean;
}

/** Indexation lists: published pages, and other addresses in the site's sitemaps. */
export const INDEXATION_KINDS = ['pages', 'sitemap'] as const;
export type IndexationKind = (typeof INDEXATION_KINDS)[number];

/** Where a sitemap address comes from: category and tag archives, author archives, another plugin's. */
export type SitemapSource = 'taxonomies' | 'users' | 'other';

/** A page or sitemap address search has not shown in the window. */
export interface IndexationRow extends OpportunityPage {
	/** never: search never showed it; lost: it did until last_impression. */
	state: 'never' | 'lost';
	/** YYYY-MM-DD, or null when never shown. */
	last_impression: string | null;
	/** Days from publishing (pages) or first listing (sitemap) to the window's end. */
	age: number;
	/** pages: when it was published, its words and the pages linking to it. */
	published?: string;
	words?: number;
	links_in?: number;
	/** sitemap: when it was first listed, and from where. */
	first_seen?: string;
	source?: SitemapSource;
	/** Google's URL Inspection of the page (its reason and last crawl); null until inspected or for Bing. */
	google: InspectionGoogle | null;
}

export interface IndexationAnswer extends Answer, SearchEngineAnswer {
	/** The window: the engine's newest `days` days, through `through`. */
	range: Range;
	days: number;
	through: string;
	first: string;
	connected: boolean;
	ignored: string[];
	kind: IndexationKind;
	rules: { days: number; max_rows: number; max_urls: number };
	/** Published pages read by the audit, how many have their published time, and the sitemaps' last read. */
	read: {
		pages: number;
		published: number;
		complete: boolean;
		sitemap: { read: string | null; enabled: boolean; complete: boolean; addresses: number };
	};
	/** A page's clicks per 28 days here, on average, when search shows it. */
	typical: number;
	/** Pages left out: they ask not to be indexed, or name another page as canonical. */
	skipped: { noindex: number; canonical: number };
	/** Rows per list (of every list, whichever is asked for). */
	counts: Record<IndexationKind, number>;
	/** Search Console's sitemaps and the URL Inspection run (Google; null for Bing). */
	sitemaps: SearchSitemaps | null;
	inspections: (InspectionProgress & { inspected: number }) | null;
	/** Never shown first, then the newest. */
	rows: IndexationRow[];
	total: number;
	more: boolean;
}
