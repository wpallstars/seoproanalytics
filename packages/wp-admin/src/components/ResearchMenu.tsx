/**
 * WordPress's keyboard-accessible research menu; navigation only, no scraping.
 * Researches a query (and where links for it could come from), another site
 * or a page, in a country and language kept in this browser.
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 */
import { Button, DropdownMenu, MenuGroup, SelectControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { useSyncExternalStore } from 'react';
import {
	RESEARCH_COUNTRIES, RESEARCH_ENGINES, RESEARCH_LANGUAGES, cleanResearchLocation, pageResearchLinks, prospectLinks,
	researchHasLocation, researchLinks, researchLocationFromLocale, siteResearchLinks,
	type ResearchEngine, type ResearchLink, type ResearchLocation,
} from '@seoproanalytics/core';
import { boot, locale } from '../boot';

const STORE_KEY = 'seoproanalytics-research-location';
const ENGINE_NAMES: Record<ResearchEngine, string> = { google: 'Google', bing: 'Bing', brave: 'Brave', duckduckgo: 'DuckDuckGo' };

/** The location every research menu uses: the browser's choice, else the site's locale. */
let current: ResearchLocation | null = null;
const listeners = new Set<() => void>();

function read(): ResearchLocation {
	if (current) return current;
	try {
		const saved = window.localStorage.getItem(STORE_KEY);
		current = saved === null ? researchLocationFromLocale(boot.locale) : cleanResearchLocation(JSON.parse(saved));
	} catch {
		current = researchLocationFromLocale(boot.locale);
	}
	return current;
}

function write(next: ResearchLocation) {
	current = next;
	try {
		window.localStorage.setItem(STORE_KEY, JSON.stringify(next));
	} catch {
		// Private windows can refuse storage: the choice lasts until the page is left.
	}
	listeners.forEach((listener) => listener());
}

function subscribe(listener: () => void) {
	listeners.add(listener);
	return () => listeners.delete(listener);
}

/** A region's or language's name in the reader's language, or the code when the browser has none. */
function displayName(code: string, type: 'region' | 'language'): string {
	try {
		return new Intl.DisplayNames([locale], { type }).of(type === 'region' ? code.toUpperCase() : code) ?? code;
	} catch {
		return code;
	}
}

type Props = Readonly<{
	/** A search query: the query's research, and link prospects when prospects is set. */
	query?: string;
	/** Another site's host. */
	site?: string;
	/** A page's address (host and path). */
	page?: string;
	/** Add where links for the query could come from. */
	prospects?: boolean;
	/** A page's topic in words (pageTopic()): where links for it could come from, without a query. */
	topic?: string;
	/** The toggle's accessible name. */
	label?: string;
}>;

export function ResearchMenu({ query = '', site = '', page = '', prospects = false, topic = '', label }: Props) {
	const location = useSyncExternalStore(subscribe, read, read);
	const descriptions: Record<string, string> = {
		query: __('Search the query', 'seoproanalytics'),
		allintitle: __('All words in titles', 'seoproanalytics'),
		intitle: __('Phrase in titles', 'seoproanalytics'),
		inurl: __('Longest word in addresses', 'seoproanalytics'),
		competitors: __('Pages outside this site', 'seoproanalytics'),
		site: __('This site’s pages', 'seoproanalytics'),
		forum: __('Forum questions', 'seoproanalytics'),
		resources: __('Resource and links pages', 'seoproanalytics'),
		linkpages: __('“Links” in the address', 'seoproanalytics'),
		write: __('Sites that take articles', 'seoproanalytics'),
		guest: __('Guest posts on the topic', 'seoproanalytics'),
		lists: __('“Best” list posts', 'seoproanalytics'),
		statistics: __('Statistics roundups', 'seoproanalytics'),
		mentions_topic: __('Pages on the topic, not on this site', 'seoproanalytics'),
		pages: __('Its pages in the index', 'seoproanalytics'),
		mentions: __('Where it mentions this site', 'seoproanalytics'),
		about: __('Who mentions it', 'seoproanalytics'),
		indexed: __('Whether it is in the index', 'seoproanalytics'),
		mentioned: __('Who mentions its address', 'seoproanalytics'),
	};
	// Link prospects for the query when asked, else for the page's topic.
	const prospectText = query && prospects ? query : topic;
	const groups = (engine: ResearchEngine): [string, ResearchLink[]][] => [
		[__('Query', 'seoproanalytics'), query ? researchLinks(engine, query, boot.siteHost, location) : []],
		[__('Link prospects', 'seoproanalytics'), prospectText ? prospectLinks(engine, prospectText, boot.siteHost, location) : []],
		[__('Site', 'seoproanalytics'), site ? siteResearchLinks(engine, site, boot.siteHost, location) : []],
		[__('Page', 'seoproanalytics'), page ? pageResearchLinks(engine, page, location) : []],
	];
	const subject = query || site || page || topic;
	if (!subject) return null;
	const countries = RESEARCH_COUNTRIES.map((c) => ({ value: c.code, label: displayName(c.code, 'region') })).sort((a, b) => a.label.localeCompare(b.label, locale));
	/* translators: a button that opens a menu of searches; the ellipsis shows it opens a menu. */
	const more = __('Research…', 'seoproanalytics');
	// A space, not a margin: a line break drops it, so a menu that wraps starts its line flush (seoproan-research__gap widens it).
	return (
		<>
			<span className="seoproan-research__gap">{' '}</span>
			<DropdownMenu
				className="seoproan-research"
				label={label ?? sprintf(/* translators: %s: a query, site or page. */ __('Research “%s”', 'seoproanalytics'), subject)}
				text={more}
				icon={null}
				toggleProps={{ variant: 'link', className: 'seoproan-research__toggle' }}
				popoverProps={{ flip: true, shift: true, resize: false }}
			>
				{() => (
					<div style={{ width: 'min(340px, calc(100vw - 48px))', maxHeight: 'min(70vh, 560px)', overflowY: 'auto' }}>
						<div className="seoproan-research__location">
							<SelectControl __next40pxDefaultSize __nextHasNoMarginBottom label={__('Country', 'seoproanalytics')} value={location.country}
								options={[{ value: '', label: __('Any (the engine decides)', 'seoproanalytics') }, ...countries]}
								onChange={(country) => {
									const found = RESEARCH_COUNTRIES.find((c) => c.code === country);
									write({ country, language: found && !location.language ? found.language : location.language });
								}} />
							<SelectControl __next40pxDefaultSize __nextHasNoMarginBottom label={__('Language', 'seoproanalytics')} value={location.language}
								options={[{ value: '', label: __('Any', 'seoproanalytics') }, ...RESEARCH_LANGUAGES.map((code) => ({ value: code, label: displayName(code, 'language') }))]}
								onChange={(language) => write({ ...location, language })} />
							<p className="seoproan-research__note">
								{__('Searches ask the engine for results in this country and language; Brave uses its own settings. An engine can still use your device’s location and history: open the links in a private window, and for one town use a connection there. Tracked positions use their provider’s location (Settings → Connections).', 'seoproanalytics')}
							</p>
						</div>
						{RESEARCH_ENGINES.map((engine) => {
							const shown = groups(engine).filter(([, list]) => list.length);
							const where = location.country && !researchHasLocation(engine) ? ` (${__('any location', 'seoproanalytics')})` : '';
							return shown.map(([title, list]) => (
								<MenuGroup key={`${engine}-${title}`} label={ENGINE_NAMES[engine] + (shown.length > 1 ? ` · ${title}` : '') + where}>
									{list.map((link) => (
										<Button role="menuitem" className="components-menu-item__button" key={link.kind} href={link.supported ? link.url : undefined} disabled={!link.supported} target="_blank" rel="noopener noreferrer" title={link.query}
											style={{ width: '100%', height: 'auto', minHeight: 36, whiteSpace: 'normal', overflowWrap: 'anywhere' }}
											aria-label={`${descriptions[link.kind] ?? link.description} — ${link.supported ? __('Opens in a new tab', 'seoproanalytics') : __('Not supported', 'seoproanalytics')}`}>
											{descriptions[link.kind] ?? link.description}{!link.supported && ` — ${__('Not supported', 'seoproanalytics')}`}
										</Button>
									))}
								</MenuGroup>
							));
						})}
					</div>
				)}
			</DropdownMenu>
		</>
	);
}
