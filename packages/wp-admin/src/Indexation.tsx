/**
 * Search → Audit, Indexation: pages search engines do not seem to show,
 * from the site's own search data, in two lists: published pages with no
 * impressions in the engine's newest days (published before them), and
 * other addresses in the site's sitemaps (category, tag and author
 * archives) with none. Never shown first, then the newest; each row also
 * goes to Plan. With Google, each page's URL Inspection (./Inspections)
 * gives Google's reason and last crawl.
 *
 * Choosing a page opens it in Rankings.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { useState } from 'react';
import { Button, Card, CardBody, CardHeader, Notice, SelectControl } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import { formatNumber, INDEXATION_KINDS, serializeFilter, singleEngine, type IndexationAnswer, type IndexationKind, type IndexationRow, type SearchEngine, type SitemapSource } from '@seoproanalytics/core';
import { errorMessage, scopeKey, useIndexation } from './api';
import { locale } from './boot';
import { GoogleCell } from './Inspections';
import { longLabel } from './dates';
import { PageCell } from './Opportunities';
import type { SearchPick, SearchReportProps } from './components/SearchSetup';
import { TableScroll } from './components/TableScroll';

const PER_PAGE = 25;

const number = (value: number) => formatNumber(value, locale, false);

/** A list's name, short enough for a list. */
export function indexationName(kind: IndexationKind): string {
	const names: Record<IndexationKind, string> = {
		pages: __('Published pages not shown', 'seoproanalytics'),
		sitemap: __('Sitemap addresses not shown', 'seoproanalytics'),
	};
	return names[kind];
}

/** Where a sitemap address comes from. */
function sourceLabel(source: SitemapSource | undefined): string {
	if (source === 'taxonomies') {
		return __('Category or tag', 'seoproanalytics');
	}
	if (source === 'users') {
		return __('Author', 'seoproanalytics');
	}
	return __('Other', 'seoproanalytics');
}

type IndexationProps = SearchReportProps & {
	open: (pick: SearchPick) => void;
};

export function Indexation({ state, update, open }: Readonly<IndexationProps>) {
	const kind: IndexationKind = state.index ?? 'pages';
	const engine: SearchEngine = singleEngine(state.engine);
	// Back to the first rows when the filters, engine or list change.
	const scope = scopeKey(state.filters.map(serializeFilter), engine, kind);
	const [at, setAt] = useState({ scope, offset: 0 });
	const offset = at.scope === scope ? at.offset : 0;
	const setOffset = (next: number) => setAt({ scope, offset: next });
	const query = useIndexation(state, kind, PER_PAGE, offset);
	const answer = query.data;
	const rows = answer?.rows ?? [];

	return (
		<Card className="seoproan-app-card is-wide seoproan-app-section" size="small">
			<CardHeader className="seoproan-app-card__header">
				<h2 className="seoproan-app-card__title">{__('Indexation', 'seoproanalytics')}</h2>
				{answer && (
					<div className="seoproan-changes__filters seoproan-plan__filters">
						<SelectControl
							__nextHasNoMarginBottom
							label={__('Show', 'seoproanalytics')}
							value={kind}
							options={INDEXATION_KINDS.map((k) => ({ value: k, label: `${indexationName(k)} (${number(answer.counts[k] ?? 0)})` }))}
							onChange={(next: string) => update({ index: next === 'pages' ? undefined : (next as IndexationKind) })}
						/>
					</div>
				)}
			</CardHeader>
			<CardBody className="seoproan-app-card__body">
				{query.isError && (
					<Notice status="error" isDismissible={false} className="seoproan-notice">
						{errorMessage(query.error, __('The indexation list could not be loaded. Reload the page to try again.', 'seoproanalytics'))}
					</Notice>
				)}
				{!answer && !query.isError && <div className="seoproan-skeleton seoproan-skeleton--table" aria-busy="true" />}
				{answer && <p className="seoproan-note seoproan-opportunities__intro">{intro(kind, answer)}</p>}
				{answer && !rows.length && (
					<div className="seoproan-app-empty">
						<p>{empty(kind, answer)}</p>
					</div>
				)}
				{rows.length > 0 && <RowsTable rows={rows} kind={kind} open={open} refreshing={query.isFetching} google={answer?.inspections != null} />}
				{answer && <Notes answer={answer} />}
				{answer && answer.total > PER_PAGE && (
					<nav className="seoproan-changes__pager" aria-label={__('Pages of the indexation list', 'seoproanalytics')}>
						<span className="seoproan-muted">
							{sprintf(
								/* translators: 1: first row shown, 2: last row shown, 3: number of rows. */
								__('%1$s–%2$s of %3$s', 'seoproanalytics'),
								number(offset + 1),
								number(Math.min(offset + PER_PAGE, answer.total)),
								number(answer.total)
							)}
						</span>
						<Button variant="secondary" disabled={offset === 0} onClick={() => setOffset(Math.max(0, offset - PER_PAGE))}>
							{__('Previous', 'seoproanalytics')}
						</Button>
						<Button variant="secondary" disabled={!answer.more} onClick={() => setOffset(offset + PER_PAGE)}>
							{__('Next', 'seoproanalytics')}
						</Button>
					</nav>
				)}
			</CardBody>
		</Card>
	);
}

/** What a list shows and what to do about it. */
function intro(kind: IndexationKind, answer: IndexationAnswer): string {
	if (kind === 'sitemap') {
		return sprintf(
			/* translators: %s: number of days. */
			__(
				'Addresses in the site’s own sitemaps besides its posts, such as category, tag and author archives, listed for %s days or more with no search impressions in that time. Make each worth showing, or leave it out of the sitemap. Never shown first.',
				'seoproanalytics'
			),
			number(answer.days)
		);
	}
	return sprintf(
		/* translators: %s: number of days. */
		__(
			'Published pages with no search impressions in the newest %s days of search data, though they were published before them. Check that search engines may index each, that related pages link to it and that it is in the sitemap, then ask them to crawl it. Never shown first, then the newest.',
			'seoproanalytics'
		),
		number(answer.days)
	);
}

function empty(kind: IndexationKind, answer: IndexationAnswer): string {
	if (!answer.through) {
		return __('There is no search data yet. Connect a search engine on the Connections tab.', 'seoproanalytics');
	}
	if (kind === 'sitemap') {
		if (answer.read.sitemap.read && !answer.read.sitemap.enabled) {
			return __('WordPress’s sitemaps are off, perhaps because an SEO plugin makes its own, so there are no other addresses to check.', 'seoproanalytics');
		}
		if (!answer.read.sitemap.read) {
			return __('The site’s sitemaps have not been read yet; they are read daily.', 'seoproanalytics');
		}
		return __('Search has shown every sitemap address listed long enough to tell.', 'seoproanalytics');
	}
	if (!answer.read.published) {
		return __('No page’s published time has been read yet. Pages are read when they are saved, and in a daily batch.', 'seoproanalytics');
	}
	return __('Search has shown every published page old enough to tell.', 'seoproanalytics');
}

/** Notes under the list: what was read, the window and the rules. */
function Notes({ answer }: Readonly<{ answer: IndexationAnswer }>) {
	const notes: string[] = [];
	if (answer.through) {
		notes.push(
			sprintf(
				/* translators: 1: number of days, 2: the newest day with search data. */
				__('Impressions in the newest %1$s days of search data, through %2$s; the period chosen above does not apply.', 'seoproanalytics'),
				number(answer.days),
				longLabel(answer.through, 'day')
			)
		);
	}
	notes.push(
		answer.read.complete
			? sprintf(
					/* translators: %s: number of pages. */
					_n('Published time read on the %s published page.', 'Published times read on all %s published pages.', answer.read.pages, 'seoproanalytics'),
					number(answer.read.pages)
				)
			: sprintf(
					/* translators: 1: pages read, 2: published pages. */
					__('Published times read on %1$s of %2$s published pages so far; the rest are read in the daily batch.', 'seoproanalytics'),
					number(answer.read.published),
					number(answer.read.pages)
				)
	);
	const skipped = answer.skipped.noindex + answer.skipped.canonical;
	if (skipped) {
		notes.push(
			sprintf(
				/* translators: %s: number of pages. */
				_n(
					'%s page is left out: it asks search engines not to index it, or names another page as canonical.',
					'%s pages are left out: they ask search engines not to index them, or name another page as canonical.',
					skipped,
					'seoproanalytics'
				),
				number(skipped)
			)
		);
	}
	if (answer.inspections) {
		notes.push(
			sprintf(
				/* translators: %s: number of pages. */
				_n(
					'From the site’s own search data; Google’s column is its URL Inspection (%s page inspected so far, see Google’s index below).',
					'From the site’s own search data; Google’s column is its URL Inspection (%s pages inspected so far, see Google’s index below).',
					answer.inspections.inspected,
					'seoproanalytics'
				),
				number(answer.inspections.inspected)
			)
		);
	} else {
		notes.push(__('From the site’s own search data; search engines’ URL inspection is not used.', 'seoproanalytics'));
	}
	return (
		<div className="seoproan-note">
			{notes.map((note) => (
				<p key={note}>{note}</p>
			))}
		</div>
	);
}

/** When search last showed the page, or that it never did. */
function shownText(row: IndexationRow): string {
	if (row.state === 'never') {
		return __('Never shown', 'seoproanalytics');
	}
	const last = row.last_impression ? longLabel(row.last_impression, 'day') : '–';
	/* translators: %s: the last day search showed the page. */
	return sprintf(__('Last shown %s', 'seoproanalytics'), last);
}

function RowsTable({ rows, kind, open, refreshing, google }: Readonly<{ rows: IndexationRow[]; kind: IndexationKind; open: IndexationProps['open']; refreshing: boolean; google: boolean }>) {
	return (
		<TableScroll label={indexationName(kind)}>
			<table className={`widefat striped seoproan-table seoproan-decay${refreshing ? ' is-refreshing' : ''}`}>
				<thead>
					<tr>
						<th scope="col">{kind === 'sitemap' ? __('Address', 'seoproanalytics') : __('Page', 'seoproanalytics')}</th>
						<th scope="col">{__('Search', 'seoproanalytics')}</th>
						{google && <th scope="col">{__('Google', 'seoproanalytics')}</th>}
						<th scope="col">{kind === 'sitemap' ? __('First listed', 'seoproanalytics') : __('Published', 'seoproanalytics')}</th>
						{kind === 'sitemap' ? (
							<th scope="col">{__('Kind', 'seoproanalytics')}</th>
						) : (
							<>
								<th scope="col" className="num">
									{__('Words', 'seoproanalytics')}
								</th>
								<th scope="col" className="num">
									{__('Links in', 'seoproanalytics')}
								</th>
							</>
						)}
					</tr>
				</thead>
				<tbody>
					{rows.map((row) => {
						const since = kind === 'sitemap' ? row.first_seen : row.published;
						return (
							<tr key={row.path_id}>
								<td>
									<PageCell row={row} query="" open={open} />
								</td>
								<td>{shownText(row)}</td>
								{google && (
									<td>
										<GoogleCell google={row.google} />
									</td>
								)}
								<td>
									{since ? longLabel(since, 'day') : '–'}
									<span className="seoproan-meta">
										{sprintf(
											/* translators: %s: number of days. */
											_n('%s day', '%s days', row.age, 'seoproanalytics'),
											number(row.age)
										)}
									</span>
								</td>
								{kind === 'sitemap' ? (
									<td>{sourceLabel(row.source)}</td>
								) : (
									<>
										<td className="num">{number(row.words ?? 0)}</td>
										<td className="num">{number(row.links_in ?? 0)}</td>
									</>
								)}
							</tr>
						);
					})}
				</tbody>
			</table>
		</TableScroll>
	);
}
