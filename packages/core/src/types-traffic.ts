/**
 * Answers of the SEO Pro Analytics API, as docs/api/openapi.yaml describes
 * them. Keep the two in step.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 * Provenance: wpas-c8662019b99fb07b. Copies, even of part, keep these lines.
 */

export type VitalMetric = 'lcp' | 'inp' | 'cls' | 'fcp' | 'ttfb';
export type VitalSample = {
	day: string;
	form_factor: 'PHONE' | 'DESKTOP';
	metric: VitalMetric;
	p75: number;
	good: number;
	ni: number;
	poor: number;
	status: 'good' | 'needs_improvement' | 'poor';
	stale: boolean;
};
export type VitalsAnswer = {
	connected: boolean;
	source: 'crux';
	window_days: number;
	traffic_days: number;
	origin: VitalSample[];
	series: VitalSample[];
	rows: { path_id: number; path: string; clicks: number; visits: number; samples: VitalSample[]; available: boolean; failing: boolean }[];
};
export type LighthouseAnswer = { source: 'lighthouse'; url: string; score: number | null; opportunities: { id: string; title: string; display: string; saved_ms: number }[] };

/**
 * Ranges the API takes. 7d, 28d, 91d, 182d and 364d are whole weeks (1, 4,
 * 13, 26 and 52), so the previous period starts on the same weekday and
 * each day meets the same weekday. The dashboard's period menu groups and
 * orders them itself (rangeMenu() in packages/wp-admin/src/labels.ts).
 */
export const RANGE_KEYS = ['realtime', 'today', 'yesterday', '24h', '7d', '28d', '30d', '90d', '91d', '182d', '364d', 'week', 'month', 'year', '12mo', 'lastyear', 'all', 'custom'] as const;
export type RangeKey = (typeof RANGE_KEYS)[number];

/**
 * Comparisons the API takes, in menu order: the period before, then a week,
 * a month and a year earlier, by weekday before by date (compare.ts).
 */
export const COMPARE_KEYS = ['none', 'prev', 'typical', 'week', 'month_weekday', 'month', 'year_weekday', 'year'] as const;
export type CompareKey = (typeof COMPARE_KEYS)[number];

export const DIMENSIONS = [
	'channel',
	'source',
	'utm_source',
	'utm_medium',
	'utm_campaign',
	'utm_term',
	'utm_content',
	'country',
	'device',
	'browser',
	'os',
	'language',
	'login',
	'entry',
	'exit',
	'page',
	'not_found',
	'search',
	'no_results',
	'author',
	'category',
	'post_type',
	'event',
	/** A/B test variants seen: value "test-id:variant-slug" ("test-id:*" filters every variant of a test). */
	'variant',
] as const;
export type Dimension = (typeof DIMENSIONS)[number];

export const OPERATORS = ['is', 'is_not', 'contains', 'matches'] as const;
export type Operator = (typeof OPERATORS)[number];

/** A chart point's span; week only for search data that comes by week (Bing). */
export type Grain = 'hour' | 'day' | 'week' | 'month';

export interface Range {
	key: string;
	from: string;
	to: string;
	timezone: string;
}

export interface Metrics {
	visitors: number;
	visits: number;
	pageviews: number;
	views_per_visit: number;
	bounce_rate: number;
	visit_duration: number;
	events: number;
}

export type MetricKey = keyof Metrics;

/** (now − then) ÷ then per metric; null when then is 0. */
export type Change = Partial<Record<MetricKey, number | null>>;

export interface Answer {
	generated?: string;
	cached?: boolean;
}

export interface StatsAnswer extends Answer {
	range: Range;
	metrics: Metrics;
	compare?: { range: Range; periods?: Range[]; metrics: Metrics; change: Change };
}

export interface Point extends Metrics {
	t: string;
}

export interface TimeseriesAnswer extends Answer {
	range: Range;
	grain: Grain;
	points: Point[];
	compare?: { range: Range; periods?: Range[]; points: Point[]; low?: Point[]; high?: Point[] };
}

export interface BreakdownRow extends Partial<Metrics> {
	value: string;
	label: string;
	visitors: number;
	visits: number;
	share: number;
	time_on_page?: number;
	scroll?: number;
	conversion_rate?: number;
	/** Variants: clicks inside the variant. */
	clicks?: number;
	/** Pages: the page has a running A/B test. */
	ab_test?: boolean;
}

export interface BreakdownAnswer extends Answer {
	range: Range;
	dimension: Dimension;
	total: Metrics;
	rows: BreakdownRow[];
}

export interface Count {
	value: string;
	label: string;
	count: number;
}

export interface RealtimeAnswer {
	visitors: number;
	pageviews: number;
	per_minute: number[];
	pages: Count[];
	sources: Count[];
	processed: string | null;
	generated: string;
}

/**
 * Groups of changes in the change log; search: search engine updates
 * (from feeds); note: added by a person or an agent.
 */
export const CHANGE_GROUPS = ['content', 'seo', 'product', 'site', 'search', 'note'] as const;
export type ChangeGroup = (typeof CHANGE_GROUPS)[number];

/** Where a change was made. */
export type ChangeSource = 'wordpress' | 'cli' | 'api' | 'cron' | 'feed' | 'note';

/** One change to the site (an entry of the change log), as a chart marker. */
export interface Marker {
	id: number;
	/** When it changed (ISO, site time zone). */
	t: string;
	/** What changed: published, price_down, plugin_updated, search_update… */
	kind: string;
	group: ChangeGroup;
	/** One line saying what changed, in the site's language. */
	label: string;
	/** The post, product, plugin, theme or setting, by name. */
	title: string;
	/** The page it affects; null for site-wide changes. */
	path: string | null;
	/** Before; for search_update, its type (core, spam, reviews…). */
	old: string;
	/** After; for search_update, its id at the source. */
	new: string;
	/** For search_update, type is the engine (google, or a feed's name). */
	object: { type: string; id: number };
	/**
	 * Details; for search_update: name, engine, url, and ended (ISO; ''
	 * while rolling out) when it rolls out over a span.
	 */
	meta: Record<string, unknown>;
	source: ChangeSource;
	/** Who made it, for people who may list users. */
	user: string | null;
}

export interface MarkersAnswer {
	range: Range;
	markers: Marker[];
	total: number;
}

export interface ChangesAnswer {
	range: Range;
	changes: Marker[];
	total: number;
	limit: number;
	offset: number;
}

/** What a goal or funnel step matches: a page viewed or an event sent. */
export type GoalKind = 'page' | 'event';

/** A goal or funnel step: a path (`*` any text) or an event name. */
export interface GoalStep {
	name: string;
	kind: GoalKind;
	match: string;
	/** Optional event-only condition; both empty strings remove it on update. */
	prop?: string;
	value?: string;
}

export interface Goal extends GoalStep {
	id: string;
}

export interface Funnel {
	id: string;
	name: string;
	steps: GoalStep[];
}

/** Funnels have 2 to 12 steps. */
export const FUNNEL_STEPS = { min: 2, max: 12 } as const;

/** Revenue in one currency (never added across currencies). */
export interface Revenue {
	currency: string;
	/** In the currency's main unit (dollars, not cents). */
	amount: number;
	/** Events that carried it. */
	count: number;
}

export interface GoalRow extends Goal {
	visitors: number;
	/** Visits that reached the goal. */
	visits: number;
	/** Times it was reached (pageviews or events). */
	completions: number;
	/** visits ÷ all visits. */
	conversion_rate: number;
	revenue: Revenue[];
	change?: {
		visits: number | null;
		completions: number | null;
		conversion_rate: number | null;
	};
}

export interface GoalsAnswer extends Answer {
	range: Range;
	visits: number;
	goals: GoalRow[];
	/** Site-wide daily counters; unavailable for visit-filtered, demo or sub-day requests. */
	renewals?: {
		scope: 'site' | 'unavailable';
		days: (Revenue & { day: string })[];
		totals: Revenue[];
	};
	compare?: { range: Range; visits: number; goals: GoalRow[] };
}

/** Visits from a business profile's tagged link, or the whole channel. */
export interface BusinessProfileFigures {
	visitors: number;
	visits: number;
	/** Goal completions, all goals together. */
	conversions: number;
	goals: { id: string; name: string; visits: number; completions: number }[];
	/** Every event's amount in these visits, refunds taken off. */
	revenue: Revenue[];
}

export type BusinessProfilePlatform = 'google' | 'bing' | 'apple' | 'other';

export interface BusinessProfileRow extends BusinessProfileFigures {
	platform: BusinessProfilePlatform;
	platform_label: string;
	name: string;
	/** The public profile's address, or ''. */
	url: string;
	/** The site's page it stands for ('/' for the home page). */
	page: string;
	/** utm_campaign of its link. */
	campaign: string;
	/** utm_source of its link. */
	source: string;
	/** The tagged website link to paste as the profile's website. */
	link: string;
}

export interface BusinessProfilesAnswer extends Answer {
	range: Range;
	channel: BusinessProfileFigures;
	profiles: BusinessProfileRow[];
}

export interface FunnelStepRow extends GoalStep {
	visits: number;
	/** Of the visits that started the funnel. */
	rate: number;
	/** Of the visits at the step before. */
	step_rate: number;
	/** Visits at the step before that did not reach this one. */
	dropped: number;
}

export interface FunnelRow {
	id: string;
	name: string;
	entered: number;
	completed: number;
	/** completed ÷ entered. */
	completion_rate: number;
	/** completed ÷ all visits. */
	conversion_rate: number;
	steps: FunnelStepRow[];
	change?: {
		entered: number | null;
		completed: number | null;
		conversion_rate: number | null;
	};
}

export interface FunnelsAnswer extends Answer {
	range: Range;
	visits: number;
	funnels: FunnelRow[];
	compare?: { range: Range; visits: number; funnels: FunnelRow[] };
}

export interface PropertyRow {
	value: string;
	label: string;
	/** Events and pageviews that carried it. */
	count: number;
	visits: number;
	share: number;
	revenue: Revenue[];
}

export interface PropertiesAnswer extends Answer {
	range: Range;
	/** The key whose values these are; '' when the rows are keys. */
	key: string;
	event: string;
	visits: number;
	rows: PropertyRow[];
}

/** Rows of the clicks report. */
export const CLICK_KINDS = ['elements', 'dead', 'links', 'downloads', 'forms', 'pages'] as const;
export type ClickKind = (typeof CLICK_KINDS)[number];

export interface ClickTotals {
	/** Clicks (form submits are counted apart). */
	clicks: number;
	/** Clicks the page did not react to within a second. */
	dead: number;
	/** dead ÷ clicks. */
	dead_rate: number;
	/** Clicks on links and buttons with a destination (here or elsewhere). */
	links: number;
	outbound: number;
	affiliate: number;
	downloads: number;
	/** Forms sent. */
	forms: number;
	/** Visits with a click or form submit. */
	visits: number;
}

export interface ClickPageInfo {
	path: string;
	url: string;
	/** Zero when the path is not a post on this site. */
	post_id: number;
	/** Present only when the path is a term archive (and no post). */
	term_id?: number;
	taxonomy?: string;
	/** Omitted by shared read-only interfaces; null without edit permission. */
	edit_url?: string | null;
}

export interface ClickRow {
	/** tag#id.class of the element or form. */
	selector: string;
	/** Its text (a form's name); '' when hidden or empty. */
	label: string;
	/** Link or form destination: a path here, origin and path elsewhere, mailto: or tel:. */
	target: string;
	count: number;
	visits: number;
	/** count ÷ all clicks (forms: all forms sent). */
	share: number;
	dead: number;
	dead_rate: number;
	outbound: boolean;
	affiliate: boolean;
	download: boolean;
	/** A form's fields (never their values). */
	fields: number;
	/** Present only for pages rows. */
	path?: string;
	url?: string;
	post_id?: number;
	/** Present only when the page is a term archive (and no post). */
	term_id?: number;
	taxonomy?: string;
	edit_url?: string | null;
	links?: number;
	forms?: number;
}

export interface ClicksAnswer extends Answer {
	range: Range;
	kind: ClickKind;
	page: string;
	page_info: ClickPageInfo | null;
	totals: ClickTotals;
	rows: ClickRow[];
	compare?: {
		range: Range;
		totals: ClickTotals;
		change: Record<keyof ClickTotals, number | null>;
	};
}

/** What a chart draws: the range, grain and points (each with its start, t). */
export interface ChartData<P extends { t: string } = { t: string }> {
	range: Range;
	grain: Grain;
	points: P[];
	compare?: { range: Range; periods?: Range[]; points: P[]; low?: P[]; high?: P[] };
	generated?: string;
}

export interface ApiError {
	code: string;
	message: string;
	data?: { status?: number };
}
