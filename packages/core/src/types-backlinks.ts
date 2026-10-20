/**
 * Answers of the SEO Pro Analytics API, as docs/api/openapi.yaml describes
 * them. Keep the two in step.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 * Provenance: wpas-c8662019b99fb07b. Copies, even of part, keep these lines.
 */

import type { Answer, Range } from './types-traffic';
import type { SortOrder, OpportunityPage, SearchEngineChoice, SearchEngine } from './types-search';

/**
 * Backlinks lists: live links, the sites linking, the site's pages linked
 * to, links lost in the period, the referring pages link exports named,
 * pages that need links (search data) and pages' search before and after a
 * site first linked to them.
 */
export const BACKLINK_KINDS = ['links', 'domains', 'pages', 'lost', 'reported', 'needs', 'impact'] as const;
export type BacklinkKind = (typeof BACKLINK_KINDS)[number];

export const BACKLINK_SOURCES = ['referrer', 'dataforseo', 'gsc', 'ahrefs', 'semrush', 'majestic', 'moz', 'bing', 'generic', 'verified'] as const;
export type BacklinkSource = (typeof BACKLINK_SOURCES)[number];

/** A reported page's check: not opened yet, links to the site, none seen, could not be opened, or gone. */
export type BacklinkReportedState = 'unchecked' | 'links' | 'none' | 'error' | 'gone';

/** One live link on a reported page: the page of this site it links to, its text ('' when none) and rel. */
export interface BacklinkReportedTarget {
	page: string;
	anchor: string;
	rel: BacklinkRel[];
	first_seen: string | null;
}

/** A referring page a link export named. */
export interface BacklinkReportedRow {
	/** The page's id, for POST /backlinks/classify (pages). */
	id: number;
	/** The page linking (as the export gave it). */
	source: string;
	/** The page's classes (every link on it has its own ones) and the class lists'. */
	classes: BacklinkClass[];
	inherited: BacklinkInherited;
	host: string;
	found: string[];
	state: BacklinkReportedState;
	/** Live links to the site found on it. */
	links: number;
	/** Those live links, first found first: the page of this site each links to, its text and rel. */
	targets: BacklinkReportedTarget[];
	/** ISO times: the export's newest date for it, and its last check. */
	reported: string | null;
	checked: string | null;
	providers: Record<string, { authority: number | null; last_seen: string | null }>;
}

/** A link's rel words kept. */
export type BacklinkRel = 'nofollow' | 'sponsored' | 'ugc';

/**
 * The built-in classes of a link, referring page or site (POST
 * /backlinks/classify): A to Z by English name, then Ignore and Spam
 * (BACKLINK_HIDDEN_CLASSES). A site can turn some off and add its own
 * (BacklinkClassInfo), so a class is any key the site has in use.
 */
export const BACKLINK_CLASSES = [
	'brand_citation',
	'nap_citation',
	'cross_site',
	'documentation',
	'forum',
	'general',
	'guest_post',
	'guide',
	'home_page',
	'integration',
	'link_swap',
	'listicle',
	'logo_linked',
	'monitor',
	'needs_updating',
	'niche_edit',
	'organic_mention',
	'paid_link',
	'partner',
	'press_organic',
	'press_release',
	'private_blog',
	'profile_page',
	'blog',
	'quote',
	'redirect',
	'site_footer',
	'social_media',
	'statistics',
	'topical',
	'url_linked',
	'url_unlinked',
	'video',
	'wiki',
	'ignore',
	'spam',
] as const;
export type BacklinkBuiltInClass = (typeof BACKLINK_CLASSES)[number];
/** A class key: a built-in one, or one of the site's own (a–z, 0–9 and _). */
export type BacklinkClass = BacklinkBuiltInClass | (string & {});
/** A class in use on a site, as the dashboard lists it (SEOProAnalytics_Backlinks_Triage::boot()). */
export interface BacklinkClassInfo {
	name: BacklinkClass;
	/** Its name, in the site's language for a built-in class. */
	label: string;
	/** Hidden until ticked in the Classes filter, after the line. */
	hidden: boolean;
	/** A page's class, not a site's (BACKLINK_PAGE_ONLY_CLASSES). */
	pageOnly: boolean;
	/** One of the site's own classes. */
	own: boolean;
}
/**
 * Classes of one page, not a whole site: a site line on their lists does
 * nothing and a site is never classed with them (SEOProAnalytics_Backlink_Lists::PAGE_ONLY).
 */
export const BACKLINK_PAGE_ONLY_CLASSES: readonly BacklinkClass[] = ['needs_updating', 'home_page', 'listicle', 'statistics', 'quote', 'guest_post', 'niche_edit', 'url_linked', 'url_unlinked', 'logo_linked', 'brand_citation', 'guide'];
/**
 * Where a row's class comes from when it is not the row's own: its
 * referring page (Reported pages), or a line of a class list (Settings →
 * Backlinks). Taking such a class off the row keeps it off that row.
 */
export type BacklinkInherited = Partial<Record<BacklinkClass, { from: 'page' } | { from: 'list'; line: string }>>;
/** What POST /backlinks/classify changes: links by id, referring pages by id, or sites and pages on the class lists. */
export type BacklinkClassifyScope = 'links' | 'pages' | 'sites';
/** In the classes filter: links without a class. */
export type BacklinkClassFilter = BacklinkClass | 'none';
/** Classes hidden until asked for, until the site changes it. */
export const BACKLINK_HIDDEN_CLASSES: readonly BacklinkClass[] = ['ignore', 'spam'];

/** rel filter values: followed is a link without nofollow, sponsored or ugc. */
export const BACKLINK_RELS = ['followed', 'nofollow', 'sponsored', 'ugc'] as const;
export type BacklinkRelFilter = (typeof BACKLINK_RELS)[number];

/** The linking site's and page's metrics: rank (DR, 0–100) and referring domains (LD). */
export const BACKLINK_METRICS = ['site_dr', 'site_ld', 'page_dr', 'page_ld'] as const;
export type BacklinkMetric = (typeof BACKLINK_METRICS)[number];

/** Sorts of the links and lost lists. */
export const BACKLINK_SORTS = ['source', 'page', 'anchor', 'first_seen', 'last_seen', 'lost', 'site_dr', 'site_ld', 'page_dr', 'page_ld', 'value'] as const;
export type BacklinkSort = (typeof BACKLINK_SORTS)[number];

/** Where a links search looks: the linking page, the page linked to, the link text. */
export type BacklinkSearchIn = 'source' | 'page' | 'anchor';

/** Filters, search and sort of the links and lost lists (GET /backlinks); left out: no filter. */
export interface BacklinkTriage {
	classes?: BacklinkClassFilter[];
	rel?: BacklinkRelFilter[];
	search?: string;
	search_in?: BacklinkSearchIn[];
	date?: 'first_seen' | 'last_seen' | 'lost';
	date_from?: string;
	date_to?: string;
	/** From and to of each metric; null for an open end. */
	ranges?: Partial<Record<BacklinkMetric, [number | null, number | null]>>;
	sort?: BacklinkSort;
	order?: SortOrder;
}

/** A link from a page of another site to one of the site's pages. */
export interface BacklinkRow {
	/** The link's id, for POST /backlinks/classify. */
	id: number;
	/** The page linking (https://host/path). */
	source: string;
	host: string;
	/** The site's page it links to. */
	page: string;
	/** Its text ([alt] for an image, [image] without one). */
	anchor: string;
	rel: BacklinkRel[];
	/** How it was found: referrer (a page that sent visits), or a provider. */
	found: string[];
	/** ISO times: first and last check that found it. */
	first_seen: string | null;
	last_seen: string | null;
	/** First found in the period. */
	new: boolean;
	authority: number;
	/** Export scores are not comparable between providers; missing scores are null. */
	providers: Record<string, { authority: number | null; last_seen: string | null; spam_score?: number }>;
	facts?: { outbound?: number; language?: string; script?: string; redirects?: number; link_list?: boolean };
	/** The owner's classes: the link's own, its referring page's and the class lists'. */
	classes: BacklinkClass[];
	/** The classes from its referring page or a class list. */
	inherited: BacklinkInherited;
	/** The linking site's and page's rank (DR, 0–100) and referring domains (LD); null when not read. */
	site_dr: number | null;
	site_ld: number | null;
	page_dr: number | null;
	page_ld: number | null;
	/** The site's page's dictionary id. */
	path_id: number;
	/**
	 * What the link is likely worth, 0–100: an estimate to rank links by,
	 * not a search engine's figure (SEOProAnalytics_Backlinks::value()).
	 */
	value: number;
	/** Where its strength came from: the linking site's and page's rank, the export's authority, or neither. */
	value_from: 'rank' | 'authority' | 'unknown';
	/** lost list: when it was lost. */
	lost?: string | null;
}

/** A page with queries in striking distance, and the links to it now (Needs links). */
export interface BacklinkNeedRow extends OpportunityPage {
	/** The page's path, as path. */
	page: string;
	/** Clicks its striking-distance queries could gain in the top three, in the period. */
	potential: number;
	impressions: number;
	clicks: number;
	/** Their average position, weighted by impressions. */
	position: number;
	/** Its best striking-distance queries. */
	queries: { query_id: number; query: string; impressions: number; position: number; potential: number }[];
	/** Sites linking to it now (ignore and spam left out), and those with a followed link. */
	domains: number;
	followed: number;
	/** Useful referring domains: a followed link, not classed ignore, spam, paid_link, private_blog, profile_page, social_media or url_unlinked. */
	useful: number;
	/** The median useful referring domains of the site's pages in the top three. */
	top_median: number;
	/** top_median − useful (more than 0 on every row). */
	gap: number;
	links: number;
	/** The sum of each linking site's best link value. */
	value: number;
	/** potential × gap, rounded. */
	need: number;
}

/** A page's search per day in a window (Link impact). */
export interface BacklinkImpactWindow {
	days: number;
	clicks: number;
	impressions: number;
	position: number;
}

/** A site new to a page in Link impact. */
export interface BacklinkImpactSite {
	host: string;
	/** Its first page found linking. */
	source: string;
	links: number;
	/** Its best link value. */
	value: number;
	first_seen: string;
}

/** A page that gained useful referring domains in the period, and its search before and after the first new link. */
export interface BacklinkImpactRow extends OpportunityPage {
	/** The page's path, as path. */
	page: string;
	/** Useful referring domains new in the period, and the first of them (first found first). */
	new_domains: number;
	hosts: BacklinkImpactSite[];
	/** Useful referring domains now. */
	useful: number;
	/** Links from the new sites, and their best value. */
	links: number;
	value: number;
	/** When the first new link was found. */
	first_seen: string;
	/** Its backlink_new change, to start an experiment from; null when none was written. */
	change_id: number | null;
	/** measured: a whole window after; measuring: fewer days so far; no_data: too little search data. */
	status: 'measured' | 'measuring' | 'no_data';
	before: BacklinkImpactWindow;
	after: BacklinkImpactWindow;
	/** Percent change in clicks (impressions) per day; null without any before. */
	change: number | null;
	impressions_change: number | null;
	/** Change in average position (negative is better); null without impressions either side. */
	position_change: number | null;
	/** The whole site's change in clicks per day in the same days. */
	site_change: number | null;
	/** change − site_change, in points. */
	relative: number | null;
}

/** A site linking to the site. */
export interface BacklinkDomainRow {
	host: string;
	/** The classes the class lists give the site (each in inherited, with its line). */
	classes: BacklinkClass[];
	inherited: BacklinkInherited;
	links: number;
	/** Links without nofollow, sponsored or ugc. */
	followed: number;
	pages: number;
	new: number;
	lost: number;
	/** Its visits in the period. */
	visits: number;
	first_seen: string | null;
	last_seen: string | null;
}

/** Classes counted in a linked page's class mix. */
export type BacklinkMixClass = 'topical' | 'general' | 'press_release' | 'profile_page' | 'social_media' | 'spam';

/** One of the site's pages that other sites link to. */
export interface BacklinkPageRow {
	page: string;
	links: number;
	domains: number;
	/** Sites with a followed link, and with a useful one (followed, not ignore, spam, paid_link, private_blog, profile_page, social_media or url_unlinked). */
	followed: number;
	useful: number;
	/** The linking sites' median DR; null when none was read. */
	median_dr: number | null;
	/** Sites with a link of each class. */
	mix: Record<BacklinkMixClass, number>;
	new: number;
	first_seen: string | null;
}

export interface BacklinksAnswer extends Answer {
	range: Range;
	kind: BacklinkKind;
	totals: { domains: number; links: number; pages: number; new: number; new_domains: number; lost: number; reported: number; reported_domains: number; reported_checked: number };
	/** Referring pages known and checked, the last run, failed requests then, whether the check is on, and the catch-up's next run. */
	read: { enabled: boolean; pages: number; checked: number; last: string | null; errors: number; next?: string | null };
	rules: { recheck_days: number; misses: number; budget: number; max_rows: number };
	/** links, lost, domains and reported: the whole list's rows per class (none: without one) and highest figure of each metric, before filters. */
	facets?: { classes: Record<BacklinkClassFilter, number>; max: Record<BacklinkMetric, number> };
	/** needs and impact: the engine read and whether it is connected. */
	engine?: SearchEngineChoice;
	engines?: SearchEngine[];
	combined?: boolean;
	connected?: boolean;
	/** needs and impact: the search period read and their rules. */
	search?: {
		range: Range | null;
		days: number;
		through: string;
		first?: string;
		rules: { min_impressions?: number; position_from?: number; position_to?: number; target?: number; queries?: number; window?: number; min_before?: number; max_pages?: number };
		/** needs: the site's pages in the top three and their median useful referring domains. */
		top?: { pages: number; median: number };
	};
	rows: (BacklinkRow | BacklinkDomainRow | BacklinkPageRow | BacklinkReportedRow | BacklinkNeedRow | BacklinkImpactRow)[];
	/** Rows of the list after filters and search. */
	total: number;
	more: boolean;
}

export interface BacklinkDecision {
	scope: 'domain' | 'url';
	target: string;
	decision: 'keep' | 'disavow' | 'undecided';
	imported: boolean;
	user_id: number;
	reviewed: number;
}

export interface BacklinkSignal {
	signal: string;
	weight: number;
	reason: string;
}

export interface BacklinkReviewAnswer {
	rows: { host: string; score: number; reasons: BacklinkSignal[]; decision: BacklinkDecision; url_decisions: BacklinkDecision[]; links: (BacklinkRow & { score: number; reasons: BacklinkSignal[]; decision: BacklinkDecision })[] }[];
	total: number;
	more: boolean;
	max_rows: number;
	signals_are_proof: false;
}
