/**
 * Search without search days on live data: how to connect the engine's
 * source (Search Console, Bing Webmaster Tools), or that its days are on
 * their way, or, while the SEO Pro Analytics Connect add-on does not register
 * Search Console, where to get it. Shared by Rankings, Opportunities and
 * Content, with the engine switch and the engine's names.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { useEffect } from 'react';
import { Button, Notice } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';
import type { SearchEngine, SearchEngineChoice } from '@seoproanalytics/core';
import type { ViewProps } from '../App';
import { boot } from '../boot';
import { useDataSet } from '../data';

/** A page and query to open Rankings with. */
export interface SearchPick {
	page: string;
	query: string;
}

/**
 * A search report's props: the view's, and where to say which engines
 * have data and (Rankings, Opportunities and Content) whether Combined
 * adds anything up.
 */
export type SearchReportProps = ViewProps & { onEngines?: (engines: SearchEngine[], combined?: boolean) => void };

/** Pass on the engines an answer lists, and whether it can combine them, for the engine switch. */
export function useReportEngines(
	answer: { engines?: SearchEngine[]; combined?: boolean } | undefined,
	onEngines?: (engines: SearchEngine[], combined?: boolean) => void
): void {
	const list = answer?.engines;
	const combined = answer?.combined;
	useEffect(() => {
		if (list && onEngines) {
			onEngines(list, combined);
		}
	}, [list, combined, onEngines]);
}

/** An engine's name: Google, Bing; Combined for all. */
export function engineName(engine: SearchEngineChoice): string {
	if (engine === 'all') {
		return __('Combined', 'seoproanalytics');
	}
	return engine === 'bing' ? __('Bing', 'seoproanalytics') : __('Google', 'seoproanalytics');
}

/** Names as a list: “A and B”, “A, B and C”. */
function listOf(names: string[]): string {
	if (names.length < 2) {
		return names[0] ?? '';
	}
	/* translators: 1: names joined by commas, 2: the last name, e.g. "Google Search Console and Bing Webmaster Tools". */
	return sprintf(__('%1$s and %2$s', 'seoproanalytics'), names.slice(0, -1).join(__(', ', 'seoproanalytics')), names[names.length - 1] ?? '');
}

/**
 * The source of an engine's figures: Google Search Console, Bing
 * Webmaster Tools; for Combined, each engine's that it adds up.
 */
export function sourceName(engine: SearchEngineChoice, engines: SearchEngine[] = []): string {
	if (engine === 'all') {
		return listOf((engines.length ? engines : (['google'] as SearchEngine[])).map((e) => sourceName(e)));
	}
	return engine === 'bing' ? __('Bing Webmaster Tools', 'seoproanalytics') : __('Google Search Console', 'seoproanalytics');
}

export function SearchSetup({ answer }: Readonly<{ answer: { through: string; connected: boolean; engine?: SearchEngineChoice } }>) {
	const demo = useDataSet() === 'demo';
	if (demo || answer.through) {
		return null;
	}
	const connections = boot.canManage && boot.settingsUrl ? addQueryArgs(boot.settingsUrl, { tab: 'connections' }) : '';
	const bing = answer.engine === 'bing';
	if (!bing && boot.addon.needed.includes('search-console')) {
		// Search Console comes with the add-on; Bing stays free.
		return (
			<Notice status="info" isDismissible={false} className="seoproan-notice">
				{__('Google Search Console (subscription needed) comes with the SEO Pro Analytics Connect add-on: clicks, impressions, CTR and average position, next to your visits and changes. Bing Webmaster Tools stays free.', 'seoproanalytics')}{' '}
				{boot.addon.url && (
					<a href={boot.addon.url} target="_blank" rel="noopener noreferrer">
						{__('Get SEO Pro Analytics Connect', 'seoproanalytics')}
						<span className="screen-reader-text"> {__('(opens in a new tab)', 'seoproanalytics')}</span>
					</a>
				)}
				{connections && (
					<>
						{' · '}
						<a href={connections}>{__('Settings → Connections', 'seoproanalytics')}</a>
					</>
				)}
			</Notice>
		);
	}
	let text: string;
	if (bing) {
		text = answer.connected
			? __('Bing Webmaster Tools is connected. Its days are imported in the background, each week once Bing has it, about a week later; they show here as they arrive.', 'seoproanalytics')
			: __('Connect Bing Webmaster Tools to see Bing’s searches next to Google’s: clicks and impressions by day, and the top pages and queries by week with their average position.', 'seoproanalytics');
	} else {
		text = answer.connected
			? __('Search Console is connected. Its days are imported in the background, the newest about three days old; they show here as they arrive.', 'seoproanalytics')
			: __('Connect Google Search Console to see the searches that show your pages: clicks, impressions, CTR and average position, next to your visits and changes.', 'seoproanalytics');
	}
	return (
		<Notice status="info" isDismissible={false} className="seoproan-notice">
			{text}{' '}
			{!answer.connected && connections && <a href={connections}>{__('Settings → Connections', 'seoproanalytics')}</a>}
		</Notice>
	);
}

/**
 * Google or Bing, when there is more than one engine (or Bing is chosen),
 * then Combined where the report can add them up (`combined`) and two
 * engines have data (`combinable`), or it is chosen; elsewhere Combined
 * shows as Google, the engine those reports read.
 */
export function EngineSwitch({
	engines,
	engine,
	choose,
	combined,
	combinable,
}: Readonly<{
	engines: SearchEngine[];
	engine: SearchEngineChoice;
	choose: (engine: SearchEngineChoice) => void;
	combined: boolean;
	combinable: boolean;
}>) {
	const chosen: SearchEngineChoice = combined || engine !== 'all' ? engine : 'google';
	const one: SearchEngine[] = chosen === 'all' || engines.includes(chosen) ? engines : [...engines, chosen];
	const shown: SearchEngineChoice[] = combined && (combinable || chosen === 'all') ? [...one, 'all'] : one;
	if (shown.length < 2) {
		return null;
	}
	return (
		<div className="seoproan-engines" role="group" aria-label={__('Search engine', 'seoproanalytics')}>{/* NOSONAR: a group of buttons; a fieldset would bring its own border, padding and min-width. */}
			{shown.map((e) => (
				<Button key={e} size="small" variant={e === chosen ? 'primary' : 'secondary'} aria-pressed={e === chosen} onClick={() => choose(e)}>
					{engineName(e)}
				</Button>
			))}
		</div>
	);
}
