/**
 * Answers of the SEO Pro Analytics API, as docs/api/openapi.yaml describes
 * them. Keep the two in step.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 * Provenance: wpas-c8662019b99fb07b. Copies, even of part, keep these lines.
 */

import type { Answer, Range, Marker, Revenue, GoalKind, VitalSample } from './types-traffic';
import type { SearchEngine, SearchEngineAnswer, OpportunityPage, OpportunityKind, DecayCause, CoverageMatch, AuditFinding, AuditFacts, LinksKind, LinksMissingQuery, SitemapSource, DecayRival, IndexationKind, Owner } from './types-search';
import type { BacklinkNeedRow } from './types-backlinks';

/** What an experiment should change. */
export const EXPERIMENT_METRICS = ['clicks', 'impressions', 'ctr', 'position', 'visits', 'conversions'] as const;
export type ExperimentMetric = (typeof EXPERIMENT_METRICS)[number];

/** Days in each window, before and after: whole weeks. */
export const EXPERIMENT_WINDOWS = [7, 14, 28, 56, 84] as const;

/** A person's or agent's decision; the plugin suggests one too. */
export const EXPERIMENT_RESULTS = ['keep', 'revise', 'undo', 'inconclusive'] as const;
export type ExperimentResult = (typeof EXPERIMENT_RESULTS)[number];

export type ExperimentStatus = 'running' | 'decided' | 'cancelled';

/** Days, both included (site dates). */
export interface DayWindow {
	from: string;
	to: string;
}

/** Some pages' figures in one window: search figures, or visits (and conversions of a goal). */
export interface ExperimentFigures {
	clicks?: number;
	impressions?: number;
	ctr?: number | null;
	position?: number | null;
	visits?: number;
	conversions?: number;
	rate?: number | null;
}

/** Why the plugin suggests its result. */
export type ExperimentReason =
	| 'no_group'
	| 'search_update'
	| 'too_little_data'
	| 'no_measure'
	| 'within_noise'
	| 'at_threshold'
	| 'under_threshold'
	| 'opposite'
	| 'no_change';

export interface ExperimentRunning {
	state: 'running';
	through: string | null;
	review: string;
	days: number;
	/** Days of data after the change so far. */
	so_far: number;
	windows: { before: DayWindow; after: DayWindow };
}

export interface ExperimentMeasured {
	state: 'ready';
	through: string;
	review: string;
	days: number;
	windows: { before: DayWindow; after: DayWindow };
	/** Days with search data in each window (search measures). */
	coverage: { before: number; after: number; days: number } | null;
	pages: { count: number; before: ExperimentFigures; after: ExperimentFigures };
	group: { count: number; before: ExperimentFigures; after: ExperimentFigures; change: number | null };
	/** Whether there were enough unchanged pages to compare with. */
	compared: boolean;
	value: { before: number | null; after: number | null };
	/** Raw change: a ratio (0.12 is +12%), or places for position. */
	change: number | null;
	/** Against the group (raw without one). */
	effect: number | null;
	/** The effect in the expected direction: positive when it went the way expected. */
	improvement: number | null;
	unit: 'ratio' | 'places';
	/** The group's usual spread (10th to 90th percentile of its pages' effects). */
	noise: { low: number; high: number; pages: number } | null;
	beyond_noise: boolean | null;
	enough: { ok: boolean; unit: string; needed: number; before: number; after: number };
	confounders: { updates: Marker[]; site: Marker[]; pages: Marker[]; total: number };
	suggested: ExperimentResult;
	reasons: ExperimentReason[];
	/** One sentence saying what was found. */
	summary: string;
}

export interface Experiment {
	id: number;
	name: string;
	hypothesis: string;
	note: string;
	created: string;
	user: string | null;
	/** Who owns it; null for people who cannot edit posts. */
	owner?: Owner | null;
	/** The change's time (ISO, site time zone). */
	start: string;
	days: number;
	windows: { before: DayWindow; after: DayWindow };
	/** The day the after window's data is complete. */
	review: string;
	engine: SearchEngine;
	metric: ExperimentMetric;
	direction: 'up' | 'down';
	/** Percent, or places for position. */
	threshold: number;
	change_id: number | null;
	pages: string[];
	goal: { id: string; name: string | null } | null;
	status: ExperimentStatus;
	result: ExperimentResult | null;
	decided: string | null;
	/** Running, with data through the review day. */
	due: boolean;
	through: string | null;
	/** While running, now; once decided, as it was at the decision; null when cancelled. */
	measurement: ExperimentRunning | ExperimentMeasured | null;
}

export interface ExperimentsAnswer {
	experiments: Experiment[];
	total: number;
}

/** An A/B test's state (set in the block editor). */
export const AB_TEST_STATUSES = ['draft', 'running', 'paused', 'ended'] as const;
export type AbTestStatus = (typeof AB_TEST_STATUSES)[number];

/**
 * A comparison's verdict: for a variant (control, too_early, better,
 * worse, unclear) and for a test (no_data, too_early, winner, control,
 * unclear).
 */
export type AbVariantVerdict = 'control' | 'too_early' | 'better' | 'worse' | 'unclear';
export type AbTestVerdictCode = 'no_data' | 'too_early' | 'winner' | 'control' | 'unclear';

/** The minimum sample before a call, and the probability that calls it. */
export interface AbThresholds {
	/** Visits each side of a comparison needs. */
	visits: number;
	/** Conversions the two sides need together. */
	conversions: number;
	/** Days the test has run. */
	days: number;
	/** Probability to beat the control that calls a variant better (1 − this: worse). */
	confidence: number;
}

/** The post a test is in. */
export interface AbTestPost {
	id: number;
	title: string;
	path: string | null;
	/** For people who may edit it. */
	edit_url: string | null;
}

/** What the variants are compared on: the test's first goal, or clicks inside the variant. */
export interface AbPrimary {
	kind: 'goal' | 'clicks';
	/** The goal's id ('' for clicks). */
	id: string;
	name: string;
}

/** A variant against the control: uplift (relative change in rate) with its 95% interval, and the probability to beat the control. All null for the control. */
export interface AbComparison {
	uplift: number | null;
	interval: [number, number] | null;
	probability: number | null;
}

export interface AbTestVerdict {
	code: AbTestVerdictCode;
	/** One sentence, in the site's language. */
	text: string;
}

/** Visits that saw two or more of a test's variants: counted apart, in no variant. */
export interface AbMixed {
	visits: number;
	/** Of every visit that saw the test. */
	share: number;
}

/** A test in the list. */
export interface AbTestRow {
	id: string;
	name: string;
	status: AbTestStatus;
	post: AbTestPost;
	/** ISO, site time zone; null before it first ran. */
	started: string | null;
	ended: string | null;
	/** When its block was taken out of the post. */
	removed: string | null;
	/** The winner chosen in the editor (a variant slug), or ''. */
	winner: string;
	primary: AbPrimary;
	visits: number;
	mixed: AbMixed;
	variants: {
		slug: string;
		label: string;
		weight: number;
		control: boolean;
		visits: number;
		conversions: number;
		rate: number;
		probability: number | null;
		verdict: AbVariantVerdict;
	}[];
	leader: { slug: string; label: string } | null;
	verdict: AbTestVerdict;
}

export interface AbTestsAnswer extends Answer {
	thresholds: AbThresholds;
	tests: AbTestRow[];
}

/** A goal's numbers for one variant. */
export interface AbGoalResult extends AbComparison {
	id: string;
	name: string;
	/** Visits that reached the goal after seeing the test. */
	conversions: number;
	completions: number;
	/** conversions ÷ the variant's visits. */
	rate: number;
	/** Per currency, never added across currencies. */
	revenue: Revenue[];
}

/** One variant of a test, in full. */
export interface AbVariantReport {
	slug: string;
	label: string;
	weight: number;
	control: boolean;
	/** The winner chosen in the editor. */
	winner: boolean;
	/** Visits that saw this variant only. */
	visits: number;
	/** Of every visit that saw the test. */
	share: number;
	visitors: number;
	/** Page loads that showed it. */
	pageviews: number;
	/** Clicks inside the variant. */
	clicks: number;
	/** Visits that clicked inside it. */
	clicked: number;
	click_rate: number;
	bounce_rate: number;
	/** Seconds per visit. */
	engaged_time: number;
	goals: AbGoalResult[];
	primary: AbComparison & { conversions: number; rate: number; verdict: AbVariantVerdict };
}

/** A test as the registry holds it. */
export interface AbTest {
	id: string;
	name: string;
	status: AbTestStatus;
	post: AbTestPost;
	variants: { slug: string; label: string; weight: number }[];
	/** Goal ids, in order; the first is the primary metric. */
	goals: string[];
	winner: string;
	created: string | null;
	/** Unix time of the last save. */
	updated: number;
	started: string | null;
	ended: string | null;
	removed: string | null;
}

export interface AbTestAnswer extends Answer {
	test: AbTest;
	thresholds: AbThresholds;
	/** The span measured: from its start (or creation) to its end, or now. */
	period: { from: string; to: string; days: number };
	primary: AbPrimary;
	goals: { id: string; name: string; kind: GoalKind; match: string }[];
	visits: number;
	mixed: AbMixed;
	variants: AbVariantReport[];
	leader: { slug: string; label: string } | null;
	verdict: AbTestVerdict;
}

/** A new experiment: from a change (its time and page), or a start and pages. */
export interface ExperimentInput {
	name: string;
	change?: number;
	start?: string;
	page?: string;
	days: number;
	engine: SearchEngine;
	metric: ExperimentMetric;
	direction: 'up' | 'down';
	threshold?: number;
	goal?: string;
	hypothesis?: string;
}

/** A search target's status, as the site set it. */
export const TARGET_STATUSES = ['candidate', 'targeted', 'live', 'won', 'retired'] as const;
export type TargetStatus = (typeof TARGET_STATUSES)[number];

/** Targets a list can ask for: every target, the open ones (candidate, targeted, live), or one status. */
export const TARGET_FILTERS = ['all', 'open', ...TARGET_STATUSES] as const;
export type TargetFilter = (typeof TARGET_FILTERS)[number];

/** How search treats a target: the page meant for it ranks, another page does, no page is chosen yet, or it is not shown. */
export const TARGET_STATES = ['ranking', 'wrong_page', 'no_page', 'not_shown'] as const;
export type TargetState = (typeof TARGET_STATES)[number];

/** Decision queue findings of a search target: shown with another page, or high priority in striking distance. */
export type TargetFinding = 'wrong_page' | 'striking';

/** A page of a target with its figures for the target's query. */
export interface TargetPage extends OpportunityPage {
	clicks: number;
	impressions: number;
	position: number | null;
	/** Its share of the query's impressions; null when search did not show it. */
	share: number | null;
}

export interface TargetRow {
	query: string;
	/** Who owns it; null for people who cannot edit posts. */
	owner?: Owner | null;
	allintitle: number | null;
	volume: number | null;
	measured: { allintitle: string | null; volume: string | null };
	kgr: number | null;
	kgr_band: 'good' | 'possible' | 'crowded' | 'volume_too_high' | 'unknown';
	/** 0–100. */
	priority: number;
	status: TargetStatus;
	/** list, aidevops, demo, seo-plugin (focus keywords) or search (added from a search report). */
	source: string;
	state: TargetState;
	/** Positions 1–3, 4–20 or beyond; null when not shown. */
	band: 'top' | 'striking' | 'beyond' | null;
	clicks: number;
	impressions: number;
	ctr: number;
	position: number | null;
	/** With a comparison period: the query's position and clicks then. */
	then_position: number | null;
	then_clicks: number | null;
	/** The page meant for it; null when none is chosen yet. */
	page: TargetPage | null;
	/** The page search shows most for it; null when not shown or search gave no page. */
	shown: TargetPage | null;
	/** Pages search showed for it. */
	pages: number;
	updated: string;
	/** Google only: its newest tracked positions (a paid provider's weekly check); null when not tracked. */
	tracked?: TrackedPosition | null;
	/** Any engine: the other engines' newest tracked places (desktop only: they have no devices); null when none. */
	tracked_engines?: Partial<Record<TrackedEngine, TrackedPosition>> | null;
}

export const SERP_FEATURES = ['ai_overview', 'featured_snippet', 'people_also_ask', 'local_pack', 'video', 'images', 'top_stories', 'shopping', 'knowledge_graph', 'cited'] as const;
export type SerpFeature = (typeof SERP_FEATURES)[number];

/** Engines tracked besides Google: Brave's and DeepSeek's web results, and the sources Brave (brave_ai) and Linkup (linkup_ai) give an AI to answer from. */
export const TRACKED_ENGINES = ['brave', 'deepseek', 'brave_ai', 'linkup_ai'] as const;
export type TrackedEngine = (typeof TRACKED_ENGINES)[number];

/** A target's newest tracked positions in one engine. */
export interface TrackedPosition {
	/** The newest day checked. */
	day: string;
	/** Best organic place (brave_ai, linkup_ai: its place among the AI answer's sources); null when not within the results read. */
	desktop: number | null;
	mobile: number | null;
	/** The site's page at that place (a path). */
	page: string | null;
	/** cited: an AI overview names the site among its sources. */
	features: SerpFeature[];
}

export interface TargetsAnswer extends Answer, SearchEngineAnswer {
	/** The period read: cut at the newest search day and to its newest 366 days. */
	range: Range;
	days: number;
	cut: boolean;
	through: string;
	first: string;
	compare: { range: Range } | null;
	connected: boolean;
	rules: { priority: number; high_priority: number; striking_from: number; striking_to: number; max_targets: number };
	/** Targets per status (every target). */
	statuses: Record<TargetStatus, number>;
	status: TargetFilter;
	/** Targets of the list asked for, per state. */
	counts: Record<TargetState, number>;
	/** Highest priority first, then most impressions. */
	rows: TargetRow[];
	total: number;
	more: boolean;
}

/** A row an import skipped, and why. */
export interface TargetSkipped {
	/** 1 for the first data row. */
	row: number;
	query: string;
	/** exists: already a target (only_new); clash: the focus keyword of more than one page; unknown: no page's focus keyword. */
	reason: 'query' | 'address' | 'priority' | 'status' | 'duplicate' | 'limit' | 'measurements' | 'exists' | 'clash' | 'unknown';
	message: string;
}

export interface TargetsImportAnswer {
	/** list (objects), csv, json, toon or seo-plugin. */
	format: string;
	added: number;
	updated: number;
	removed: number;
	skipped: TargetSkipped[];
	total: number;
}

/** A target's search with its page, status and priority, without figures (GET /targets/queries). */
export interface TargetListed {
	query: string;
	/** Path of the page meant for it; '' when none is chosen. */
	page: string;
	status: TargetStatus;
	priority: number;
}

export interface TargetsQueriesAnswer {
	targets: TargetListed[];
}

/** A focus keyword suggestion's state: new, the focus keyword of more than one page, or a target already. */
export const TARGET_SUGGESTION_STATES = ['new', 'clash', 'targeted'] as const;
export type TargetSuggestionState = (typeof TARGET_SUGGESTION_STATES)[number];

/** A published page with a focus keyword. */
export interface TargetSuggestionPage {
	path: string;
	/** 0 in the demo data. */
	post_id: number;
	title: string;
	url: string;
	edit_url: string | null;
}

export interface TargetSuggestion {
	/** As targets store it (lower case). */
	query: string;
	/** As the SEO plugin keeps it. */
	keyword: string;
	state: TargetSuggestionState;
	pages: TargetSuggestionPage[];
	/** The target, when the search is one already. */
	target: Omit<TargetListed, 'query'> | null;
}

export interface TargetSuggestionsAnswer {
	/** rank-math, yoast, seopress, aioseo, demo, or '' when no SEO plugin is active. */
	plugin: string;
	all_keywords: boolean;
	/** Pages read with focus keywords. */
	pages: number;
	/** More posts have focus keywords than max_posts. */
	more: boolean;
	max_posts: number;
	counts: Record<TargetSuggestionState, number>;
	/** New first, then clashes, then those already targeted. */
	rows: TargetSuggestion[];
}

/** Refresh planner proposals for a page losing clicks, in the order they are checked. */
export const REFRESH_PROPOSALS = ['leave', 'protect', 'merge', 'update'] as const;
export type RefreshProposal = (typeof REFRESH_PROPOSALS)[number];

/** Kinds of decision queue item: each an opportunity kind, audit findings, internal links, indexation, refresh proposals, search targets, Search Console sitemap problems and pages that need links. */
export type QueueKind = OpportunityKind | 'audit' | 'links' | 'index' | 'refresh' | 'target' | 'sitemap' | 'backlinks';

/** A sitemap item's problem: a sitemap's own, or the site's sitemap index not submitted (missing). */
export const QUEUE_SITEMAP_PROBLEMS = ['missing', 'errors', 'stale', 'warnings'] as const;
export type QueueSitemapProblem = (typeof QUEUE_SITEMAP_PROBLEMS)[number];

/** An item's state: new (worked out now) or as someone left it. */
export const QUEUE_STATUSES = ['new', 'accepted', 'done', 'dismissed'] as const;
export type QueueStatus = (typeof QUEUE_STATUSES)[number];

/** States a list can ask for; open (the default) is new and accepted. */
export const QUEUE_FILTERS = ['open', 'new', 'accepted', 'done', 'dismissed', 'all'] as const;
export type QueueFilter = (typeof QUEUE_FILTERS)[number];

export type QueueAction = 'accept' | 'done' | 'dismiss' | 'restore' | 'effort' | 'note';

/** score = clicks × value × confidence ÷ effort. */
export interface QueueParts {
	/** Potential clicks per 28 days. */
	clicks: number;
	/** How well the page's visits from search convert against the site; 1 without goal data. */
	value: number;
	/** 0–1: the kind's own, weighed by impressions. */
	confidence: number;
	/** 1 (least) to 5. */
	effort: number;
}

/** The numbers behind an item; which are there depends on its kind. */
export interface QueueFigures {
	clicks: number;
	impressions: number;
	ctr?: number;
	position: number | null;
	expected_ctr?: number;
	potential?: number;
	then_clicks?: number;
	then_position?: number;
	lost?: number;
	cause?: DecayCause;
	match?: CoverageMatch;
	missing?: string[];
	question?: boolean;
	/** overlap: whether the leading page changed between the halves. */
	switched?: boolean;
	/** overlap: the pages sharing the query; refresh, merge: this page and the one that overtook it (with its share of the query). */
	pages?: { path_id: number; path: string; clicks: number; impressions: number; position: number | null; share: number | null }[];
	/** audit: the finding, its share of the page's expected clicks, the page's facts and the other pages with the same title or description. */
	finding?: AuditFinding | 'page_experience' | 'clicked_broken';
	samples?: VitalSample[];
	/** audit, clicked_broken: the destination, its crawl status (0: no answer), what kind of link, the pages it was clicked on and those linking to it. */
	target?: string;
	status?: number;
	affiliate?: boolean;
	outbound?: boolean;
	clicked_on?: string[];
	linked_on?: string[];
	share?: number;
	facts?: AuditFacts;
	same?: string[];
	/** links: the list; orphans and converting: links in, the pages linking (up to five), visits and conversions. */
	list?: LinksKind;
	links_in?: number;
	from?: string[];
	visits?: number | null;
	conversions?: number | null;
	/** links, missing: the page that should link, its figures for the searches, and the searches. */
	link_from?: { path_id: number; path: string; url: string };
	from_impressions?: number;
	from_position?: number;
	queries?: LinksMissingQuery[];
	query_count?: number;
	/** index: never or lost, the last day shown, the age in days, what a shown page earns here per 28 days, and the row's own facts. */
	state?: 'never' | 'lost';
	last_impression?: string | null;
	/** index: days since publishing or listing; refresh: days since the content changed (null: not known). */
	age?: number | null;
	typical?: number;
	published?: string | null;
	words?: number;
	first_seen?: string;
	source?: SitemapSource;
	/** refresh: the proposal, the impressions before, when the content changed, whether that is old or within the periods compared, the queries lost most (each with the page that overtook it) and, for merge, that page with the query. */
	proposal?: RefreshProposal;
	then_impressions?: number;
	modified?: string | null;
	old?: boolean;
	changed?: boolean;
	lost_queries?: { query: string; lost: number; position: number | null; then_position: number | null; rival: { path: string; clicks: number; position: number; then_position: number | null; share: number } | null }[];
	rival?: DecayRival & { query: string; position_here: number | null; then_position_here: number | null };
	/** target: the target's priority and status (wrong_page: pages are the page meant for it, then the page shown). */
	priority?: number;
	target_status?: TargetStatus;
	/** sitemap: the sitemap as Search Console read it (typical and share give the potential clicks). */
	sitemap?: { url: string; errors: number; warnings: number; downloaded: string | null; submitted: string | null };
	/** backlinks: the page's need (potential × gap), the links to it now against the median of the site's pages in the top three, and its best searches in striking distance. */
	need?: number;
	domains?: number;
	followed?: number;
	useful?: number;
	top_median?: number;
	gap?: number;
	links?: number;
	link_value?: number;
	searches?: BacklinkNeedRow['queries'];
}

export interface QueueItem extends OpportunityPage {
	/** 16 hex characters: kind, engine, page and query. */
	key: string;
	kind: QueueKind;
	engine: SearchEngine;
	status: QueueStatus;
	/** Who owns it; null for people who cannot edit posts, and in shared reports. */
	owner?: Owner | null;
	/** Crawl verdict: confirmed stays done; reopened is immediately new/open. */
	verified?: 'confirmed' | 'reopened';
	verified_at?: string;
	/** Whether the opportunity is still found in this period (else as it was when acted on). */
	found: boolean;
	query: string | null;
	/** audit: the finding (the item is one per page and finding); links and index: the list; refresh: the proposal; target: wrong_page or striking; sitemap: the problem; backlinks: needs; else null. */
	finding: AuditFinding | 'page_experience' | 'clicked_broken' | LinksKind | IndexationKind | RefreshProposal | TargetFinding | QueueSitemapProblem | 'needs' | null;
	/** Why it is listed, in the site's language. */
	why: string;
	/** What to do, in the site's language. */
	todo: string;
	figures: QueueFigures;
	/** The measure of the experiment done opens. */
	metric: ExperimentMetric;
	parts: QueueParts;
	score: number;
	note: string;
	updated: string | null;
	user: string | null;
	effort_set: boolean;
	experiment_id: number | null;
	/** Done: the experiment it opened and its result. */
	experiment: {
		id: number;
		name: string;
		status: ExperimentStatus;
		result: ExperimentResult | null;
		due: boolean;
		review: string;
		metric: ExperimentMetric;
		state: 'running' | 'ready' | null;
		effect: number | null;
		unit: 'ratio' | 'places' | null;
		suggested: ExperimentResult | null;
		summary: string | null;
	} | null;
}

export interface QueueAnswer extends Answer, SearchEngineAnswer {
	/** The period read: cut at the newest search day and to its newest 366 days. */
	range: Range;
	days: number;
	cut: boolean;
	/**
	 * The periods losing clicks were compared in, when shortened so the
	 * earlier one holds only days with search data; null when not.
	 */
	decay: { range: Range; days: number; compare: Range } | null;
	through: string;
	connected: boolean;
	ignored: string[];
	/** The goal giving value; null without goals. */
	goal: { id: string; name: string } | null;
	goals: { id: string; name: string }[];
	/** The site's conversion rate of visits from search; null without a goal or visits. */
	site_rate: number | null;
	rules: {
		scale_days: number;
		effort: Record<QueueKind, number>;
		/** Audit findings whose effort is not the audit kind's. */
		audit_effort: Partial<Record<AuditFinding | 'page_experience' | 'clicked_broken', number>>;
		/** Internal links lists whose effort is not the links kind's, and each list's share of the expected clicks. */
		links_effort: Partial<Record<LinksKind, number>>;
		links_share: Record<LinksKind, number>;
		/** Indexation lists whose effort is not the index kind's, and each list's share of a typical page's clicks. */
		index_effort: Partial<Record<IndexationKind, number>>;
		index_share: Record<IndexationKind, number>;
		/** Sitemap problems whose effort is not the sitemap kind's, and each problem's share of a typical page's clicks. */
		sitemap_effort: Partial<Record<QueueSitemapProblem, number>>;
		sitemap_share: Record<QueueSitemapProblem, number>;
		/** Refresh proposals' effort and share of the clicks lost, and the planner's thresholds. */
		refresh_effort: Record<RefreshProposal, number>;
		refresh_share: Record<RefreshProposal, number>;
		refresh: { old_days: number; protect_value: number; protect_conversions: number };
		/** Search targets: the share of a search's expected clicks at stake on the wrong page, the default and least striking priority, and the statuses listed. */
		target: { share: number; priority: number; high_priority: number; statuses: TargetStatus[] };
		confidence: Record<QueueKind, number>;
		full_impressions: number;
		missing_share: number;
		smooth_visits: number;
		max_value: number;
		hide_days: number;
		per_kind: number;
	};
	/** New items left out because their page has a running experiment. */
	left_out: number;
	status: QueueFilter;
	/** The kind asked for; null for every kind. */
	kind: QueueKind | null;
	counts: Record<QueueStatus, number>;
	/** Done view also includes reopened crawl items, without double-counting All. */
	done_count: number;
	items: QueueItem[];
	total: number;
	more: boolean;
}
