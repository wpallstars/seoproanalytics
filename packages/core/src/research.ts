/**
 * Portable, browser-only research links. No results are fetched or counted.
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 */
export const RESEARCH_ENGINES = ['google', 'bing', 'brave', 'duckduckgo'] as const;
export type ResearchEngine = (typeof RESEARCH_ENGINES)[number];
export interface ResearchLink { kind: string; description: string; query: string; url: string; supported: boolean }

/**
 * Where results are asked for: a country (ISO 3166-1 alpha-2, lower case)
 * and a language (ISO 639-1); '' for either leaves it to the engine, which
 * then uses the browser's own location and settings.
 */
export interface ResearchLocation { country: string; language: string }
export const NO_LOCATION: ResearchLocation = { country: '', language: '' };

/**
 * Countries offered, each with its usual language and DuckDuckGo's region
 * (kl); DuckDuckGo has no region for every country, so some share one
 * (Jersey and Guernsey use the United Kingdom's). Names come from the
 * browser (Intl.DisplayNames), in the reader's language.
 */
export const RESEARCH_COUNTRIES: readonly { code: string; language: string; ddg: string }[] = [
	{ code: 'us', language: 'en', ddg: 'us-en' },
	{ code: 'gb', language: 'en', ddg: 'uk-en' },
	{ code: 'je', language: 'en', ddg: 'uk-en' },
	{ code: 'gg', language: 'en', ddg: 'uk-en' },
	{ code: 'ie', language: 'en', ddg: 'ie-en' },
	{ code: 'ca', language: 'en', ddg: 'ca-en' },
	{ code: 'au', language: 'en', ddg: 'au-en' },
	{ code: 'nz', language: 'en', ddg: 'nz-en' },
	{ code: 'za', language: 'en', ddg: 'za-en' },
	{ code: 'in', language: 'en', ddg: 'in-en' },
	{ code: 'sg', language: 'en', ddg: 'sg-en' },
	{ code: 'de', language: 'de', ddg: 'de-de' },
	{ code: 'at', language: 'de', ddg: 'at-de' },
	{ code: 'ch', language: 'de', ddg: 'ch-de' },
	{ code: 'fr', language: 'fr', ddg: 'fr-fr' },
	{ code: 'be', language: 'nl', ddg: 'be-nl' },
	{ code: 'nl', language: 'nl', ddg: 'nl-nl' },
	{ code: 'es', language: 'es', ddg: 'es-es' },
	{ code: 'mx', language: 'es', ddg: 'mx-es' },
	{ code: 'it', language: 'it', ddg: 'it-it' },
	{ code: 'pt', language: 'pt', ddg: 'pt-pt' },
	{ code: 'br', language: 'pt', ddg: 'br-pt' },
	{ code: 'se', language: 'sv', ddg: 'se-sv' },
	{ code: 'no', language: 'no', ddg: 'no-no' },
	{ code: 'dk', language: 'da', ddg: 'dk-da' },
	{ code: 'fi', language: 'fi', ddg: 'fi-fi' },
	{ code: 'pl', language: 'pl', ddg: 'pl-pl' },
	{ code: 'jp', language: 'ja', ddg: 'jp-jp' },
];

/** Languages offered for the results' language, ISO 639-1. */
export const RESEARCH_LANGUAGES: readonly string[] = ['en', 'de', 'fr', 'nl', 'es', 'it', 'pt', 'sv', 'no', 'da', 'fi', 'pl', 'ja'];

const SEARCH_URLS: Record<ResearchEngine, string> = {
	google: 'https://www.google.com/search',
	bing: 'https://www.bing.com/search',
	brave: 'https://search.brave.com/search',
	duckduckgo: 'https://duckduckgo.com/',
};

/** Whether an engine takes a country or language in its address (Brave uses its own settings). */
export function researchHasLocation(engine: ResearchEngine): boolean {
	return engine !== 'brave';
}

/** A location from a WordPress locale (en_GB: United Kingdom, English), when its country is offered. */
export function researchLocationFromLocale(locale: string): ResearchLocation {
	const [language = '', region = ''] = locale.toLowerCase().split(/[-_]/);
	const country = RESEARCH_COUNTRIES.find((c) => c.code === (region === 'uk' ? 'gb' : region));
	return country ? { country: country.code, language: RESEARCH_LANGUAGES.includes(language) ? language : country.language } : NO_LOCATION;
}

/** A location from untrusted input (stored in the browser): only offered codes survive. */
export function cleanResearchLocation(value: unknown): ResearchLocation {
	const raw = value && typeof value === 'object' ? (value as Record<string, unknown>) : {};
	const country = typeof raw.country === 'string' && RESEARCH_COUNTRIES.some((c) => c.code === raw.country) ? raw.country : '';
	const language = typeof raw.language === 'string' && RESEARCH_LANGUAGES.includes(raw.language) ? raw.language : '';
	return { country, language };
}

/**
 * The engine's documented country and language parameters: Google gl and
 * hl, Bing cc and setlang, DuckDuckGo kl (its region, which holds both).
 */
function locationParams(engine: ResearchEngine, location: ResearchLocation): string {
	const { country, language } = location;
	const params: [string, string][] = [];
	if (engine === 'google') {
		if (country) params.push(['gl', country]);
		if (language) params.push(['hl', language]);
	} else if (engine === 'bing') {
		if (country) params.push(['cc', country]);
		if (language) params.push(['setlang', language]);
	} else if (engine === 'duckduckgo' && country) {
		const region = RESEARCH_COUNTRIES.find((c) => c.code === country);
		if (region) params.push(['kl', region.ddg]);
	}
	return params.map(([key, value]) => `&${key}=${encodeURIComponent(value)}`).join('');
}

export function researchUrl(engine: ResearchEngine, query: string, location: ResearchLocation = NO_LOCATION): string {
	return `${SEARCH_URLS[engine]}?q=${encodeURIComponent(query)}${locationParams(engine, location)}`;
}

/** Control characters become spaces and quotes are dropped, so the text cannot break out of a phrase. */
function cleanQuery(text: string): string {
	return Array.from(text, (character) => {
		const code = character.codePointAt(0) ?? 0;
		return code < 32 || code === 127 ? ' ' : character;
	}).join('').replace(/["“”]/g, ' ').replace(/\s+/g, ' ').trim();
}

/** A host as search operators take it: letters, digits, dots and hyphens, lower case, no www. */
function cleanHost(host: string): string {
	const clean = host.trim().toLowerCase().replace(/^www\./, '');
	return /^[a-z0-9.-]+$/.test(clean) && clean.includes('.') ? clean : '';
}

/** An address as search operators take it: host and path, no scheme, query or fragment, no trailing slash. */
function cleanAddress(address: string): { host: string; path: string } | null {
	const text = address.trim().replace(/^[a-z][a-z0-9+.-]*:\/\//i, '').split(/[?#]/)[0] ?? '';
	const slash = text.indexOf('/');
	const host = cleanHost(slash < 0 ? text : text.slice(0, slash));
	if (!host) return null;
	const path = slash < 0 ? '' : trimSlashes(text.slice(slash));
	return /^[\w\-./~%]*$/.test(path) ? { host, path } : null;
}

/** Text without its trailing slashes, in one pass (a /\/+$/ regex is quadratic on many inner slashes). */
function trimSlashes(text: string): string {
	let end = text.length;
	while (end > 0 && text.charCodeAt(end - 1) === 47) {
		end--;
	}
	return text.slice(0, end);
}

/**
 * Google's allintitle search for every word of the query, in any order, as the
 * Keyword Golden Ratio counts it. Unquoted: quotes would make it an exact-phrase
 * title search and undercount the competition. No space after the colon.
 */
export function allintitleQuery(text: string): string {
	return `allintitle:${cleanQuery(text)}`;
}

type Template = [kind: string, description: string, query: string, supported: boolean];

function links(engine: ResearchEngine, templates: Template[], location: ResearchLocation): ResearchLink[] {
	return templates.map(([kind, description, search, supported]) => ({ kind, description, query: search, url: researchUrl(engine, search, location), supported }));
}

/** The longest lexical word (the first on a tie) is a predictable URL-word seed that skips short filler words such as "how" or "the". */
export function researchLinks(engine: ResearchEngine, text: string, site: string, location: ResearchLocation = NO_LOCATION): ResearchLink[] {
	const query = cleanQuery(text);
	if (!query) return [];
	const phrase = `"${query}"`;
	const word = (query.match(/[\p{L}\p{N}]+/gu) ?? []).reduce((longest, next) => (next.length > longest.length ? next : longest), '');
	const host = cleanHost(site);
	const templates: Template[] = [
		['query', 'Search the query', query, true],
		['allintitle', 'All words in page titles, in any order (Google only)', allintitleQuery(query), engine === 'google'],
		['intitle', 'Find the phrase in page titles', `intitle:${phrase}`, true],
		['inurl', 'Find the longest word in page addresses', `inurl:${word}`, !!word && (engine === 'google' || engine === 'duckduckgo')],
	];
	if (host) templates.push(
		['competitors', 'Find pages outside this site', `${phrase} -site:${host}`, true],
		['site', 'Find this site’s pages and internal link sources', `site:${host} ${phrase}`, true],
	);
	// Google's OR binds only its neighbours: group it, or every result needs "forum" in its address.
	if (engine === 'google') templates.push(['forum', 'Find forum questions and discussions', `${phrase} (intitle:forum OR inurl:forum)`, true]);
	return links(engine, templates, location);
}

/**
 * Places that may link to a page about the query: resource and links pages,
 * pages that take guest posts, list posts and statistics roundups, all
 * outside this site. Bing has no inurl.
 */
export function prospectLinks(engine: ResearchEngine, text: string, site: string, location: ResearchLocation = NO_LOCATION): ResearchLink[] {
	const query = cleanQuery(text);
	if (!query) return [];
	const phrase = `"${query}"`;
	const host = cleanHost(site);
	const outside = host ? ` -site:${host}` : '';
	const urlWords = engine === 'google' || engine === 'duckduckgo';
	const templates: Template[] = [
		['resources', 'Resource and links pages on the topic', engine === 'google' ? `${phrase} (intitle:resources OR intitle:links)${outside}` : `${phrase} intitle:resources${outside}`, true],
		['linkpages', 'Pages with links or resources in the address', `${phrase} inurl:links${outside}`, urlWords],
		['write', 'Sites that take articles (“write for us”)', `${phrase} "write for us"${outside}`, true],
		['guest', 'Guest posts on the topic', `${phrase} "guest post"${outside}`, true],
		['lists', 'List posts that could include this page', `${phrase} intitle:best${outside}`, true],
		['statistics', 'Statistics roundups that cite sources', `${phrase} intitle:statistics${outside}`, true],
	];
	if (host) templates.push(['mentions_topic', 'Pages that mention the topic, not on this site', `${phrase}${outside}`, true]);
	return links(engine, templates, location);
}

/**
 * A page's topic in words, from its address's last part: "/blog/best-coffee-grinders/"
 * gives "best coffee grinders". Numbers alone and file endings are left out; the
 * home page has none (""). For link prospects when a page has no query.
 */
export function pageTopic(path: string): string {
	const parts = trimSlashes(path.split(/[?#]/)[0] ?? '').split('/').filter((part) => part !== '' && !/^\d+$/.test(part));
	const last = parts[parts.length - 1] ?? '';
	let words = last.replace(/\.[a-z0-9]{1,5}$/i, '');
	try {
		words = decodeURIComponent(words);
	} catch {
		// Not percent-encoded text: keep it as it is.
	}
	return cleanQuery(words.replace(/[-_+]+/g, ' '));
}

/**
 * Research about another site: what of it is in the index, where it
 * mentions this site, and who mentions it.
 */
export function siteResearchLinks(engine: ResearchEngine, other: string, site: string, location: ResearchLocation = NO_LOCATION): ResearchLink[] {
	const host = cleanHost(other);
	if (!host) return [];
	const own = cleanHost(site);
	const templates: Template[] = [['pages', 'Its pages in the index', `site:${host}`, true]];
	if (own && own !== host) templates.push(['mentions', 'Where it mentions this site', `site:${host} "${own}"`, true]);
	templates.push(['about', 'Who mentions it', `"${host}" -site:${host}`, true]);
	return links(engine, templates, location);
}

/**
 * Research about one page: whether it is in the index, and who mentions
 * its address outside its own site.
 */
export function pageResearchLinks(engine: ResearchEngine, address: string, location: ResearchLocation = NO_LOCATION): ResearchLink[] {
	const page = cleanAddress(address);
	if (!page) return [];
	const target = `${page.host}${page.path}`;
	return links(engine, [
		['indexed', 'Whether it is in the index', `site:${target}`, true],
		['mentioned', 'Who mentions its address', `"${target}" -site:${page.host}`, true],
	], location);
}
