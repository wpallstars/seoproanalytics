/**
 * Search → Audit, Internal links: the links in the text of the site's
 * published pages to its own pages, in three lists weighed by search and
 * conversions: orphan pages (no other page links to them), converting
 * pages with few links in, and missing links (a page shows for a search
 * but does not link to the page that gets its clicks). Links are read
 * with the content audit's facts; each list's rows also go to Plan.
 *
 * Choosing a page opens it in Rankings.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { useState } from 'react';
import { Button, Card, CardBody, CardHeader, Notice, SelectControl } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	apiArgs,
	formatDecimal,
	formatNumber,
	LINKS_KINDS,
	type LinksAnswer,
	type LinksKind,
	type LinksMissingRow,
	type LinksPageRow,
	type LinksRow,
	singleEngine,
	type SearchEngine,
} from '@seoproanalytics/core';
import { errorMessage, scopeKey, useLinks } from './api';
import { locale } from './boot';
import { PageCell } from './Opportunities';
import type { SearchPick, SearchReportProps } from './components/SearchSetup';
import { TableScroll } from './components/TableScroll';

const PER_PAGE = 25;

const number = (value: number) => formatNumber(value, locale, false);
const place = (value: number) => formatDecimal(value, locale);

/** A list's name, short enough for a list. */
export function linksName(kind: LinksKind): string {
	const names: Record<LinksKind, string> = {
		orphans: __('Orphan pages', 'seoproanalytics'),
		converting: __('Converting pages with few links in', 'seoproanalytics'),
		missing: __('Missing links', 'seoproanalytics'),
	};
	return names[kind];
}

type LinksProps = SearchReportProps & {
	open: (pick: SearchPick) => void;
};

function isMissing(row: LinksRow): row is LinksMissingRow {
	return 'to' in row;
}

export function Links({ state, update, open }: Readonly<LinksProps>) {
	const kind: LinksKind = state.links ?? 'orphans';
	const goal = state.goal ?? '';
	const engine: SearchEngine = singleEngine(state.engine);
	// Back to the first rows when the period, filters, engine, list or goal change.
	const scope = scopeKey(apiArgs({ ...state, compare: 'none' }), engine, kind, goal);
	const [at, setAt] = useState({ scope, offset: 0 });
	const offset = at.scope === scope ? at.offset : 0;
	const setOffset = (next: number) => setAt({ scope, offset: next });
	const query = useLinks(state, kind, goal, PER_PAGE, offset);
	const answer = query.data;
	const rows = answer?.rows ?? [];

	return (
		<Card className="seoproan-app-card is-wide seoproan-app-section" size="small">
			<CardHeader className="seoproan-app-card__header">
				<h2 className="seoproan-app-card__title">{__('Internal links', 'seoproanalytics')}</h2>
				{answer && (
					<div className="seoproan-changes__filters seoproan-plan__filters">
						<SelectControl
							__nextHasNoMarginBottom
							label={__('Show', 'seoproanalytics')}
							value={kind}
							options={LINKS_KINDS.map((k) => ({ value: k, label: `${linksName(k)} (${number(answer.counts[k] ?? 0)})` }))}
							onChange={(next: string) => update({ links: next === 'orphans' ? undefined : (next as LinksKind) })}
						/>
						{kind === 'converting' && answer.goals.length > 0 && (
							<SelectControl
								__nextHasNoMarginBottom
								label={__('Conversions of', 'seoproanalytics')}
								value={answer.goal?.id ?? ''}
								options={answer.goals.map((g) => ({ value: g.id, label: g.name }))}
								onChange={(next) => update({ goal: next === answer.goals[0]?.id ? undefined : next })}
							/>
						)}
					</div>
				)}
			</CardHeader>
			<CardBody className="seoproan-app-card__body">
				{query.isError && (
					<Notice status="error" isDismissible={false} className="seoproan-notice">
						{errorMessage(query.error, __('The internal links could not be loaded. Reload the page to try again.', 'seoproanalytics'))}
					</Notice>
				)}
				{!answer && !query.isError && <div className="seoproan-skeleton seoproan-skeleton--table" aria-busy="true" />}
				{answer && <p className="seoproan-note seoproan-opportunities__intro">{intro(kind)}</p>}
				{answer && !rows.length && (
					<div className="seoproan-app-empty">
						<p>{empty(kind, answer)}</p>
					</div>
				)}
				{rows.length > 0 &&
					(kind === 'missing' ? (
						<MissingTable rows={rows.filter(isMissing)} open={open} refreshing={query.isFetching} />
					) : (
						<PageTable rows={rows.filter((row): row is LinksPageRow => !isMissing(row))} kind={kind} goal={answer?.goal !== null} open={open} refreshing={query.isFetching} />
					))}
				{answer && <Notes answer={answer} />}
				{answer && answer.total > PER_PAGE && (
					<nav className="seoproan-changes__pager" aria-label={__('Pages of the internal links', 'seoproanalytics')}>
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
function intro(kind: LinksKind): string {
	if (kind === 'missing') {
		return __(
			'Pages that show for a search but do not link to the page that gets most of its clicks. A link, in the words of the search, tells visitors and search engines which page answers it. Most impressions first.',
			'seoproanalytics'
		);
	}
	if (kind === 'converting') {
		return __('Pages whose visits from search reach the goal, but that few other pages link to. Links from related pages bring them more visitors. Most conversions first.', 'seoproanalytics');
	}
	return __('Published pages that no other page’s text links to, so visitors and search engines find them only from menus, lists and the sitemap. Most search impressions first.', 'seoproanalytics');
}

function empty(kind: LinksKind, answer: LinksAnswer): string {
	if (!answer.read.read) {
		return __('No page’s links have been read yet. Pages are read when they are saved, and in a daily batch.', 'seoproanalytics');
	}
	if (kind === 'converting' && !answer.goal) {
		return __('Add a goal to find the pages that convert.', 'seoproanalytics');
	}
	if (kind === 'missing') {
		return __('No missing links: every page that shows for another page’s search links to it.', 'seoproanalytics');
	}
	return kind === 'converting' ? __('No converting page has few links in.', 'seoproanalytics') : __('No orphan pages.', 'seoproanalytics');
}

/** Notes under the list: what was read, and the rules. */
function Notes({ answer }: Readonly<{ answer: LinksAnswer }>) {
	const r = answer.rules;
	const notes: string[] = [
		answer.read.complete
			? sprintf(
					/* translators: %s: number of pages. */
					_n('Links read on the %s published page.', 'Links read on all %s published pages.', answer.read.pages, 'seoproanalytics'),
					number(answer.read.pages)
				)
			: sprintf(
					/* translators: 1: pages read, 2: published pages. */
					__('Links read on %1$s of %2$s published pages so far; the rest are read in the daily batch.', 'seoproanalytics'),
					number(answer.read.read),
					number(answer.read.pages)
				),
		sprintf(
			/* translators: 1: number of pages, 2: number of conversions, 3: impressions. */
			__('Only links in a page’s own text count, not menus or widgets, so the front page is never listed. Few links in is %1$s or fewer pages; converting is %2$s or more conversions. A missing link needs %3$s or more impressions on the search.', 'seoproanalytics'),
			number(r.few_links),
			number(r.min_conversions),
			number(r.min_impressions)
		),
	];
	if (answer.goal) {
		/* translators: %s: goal name. */
		notes.push(sprintf(__('Conversions of %s, from visits from search that started on the page.', 'seoproanalytics'), answer.goal.name));
	}
	return (
		<div className="seoproan-note">
			{notes.map((note) => (
				<p key={note}>{note}</p>
			))}
		</div>
	);
}

function PageTable({ rows, kind, goal, open, refreshing }: Readonly<{ rows: LinksPageRow[]; kind: LinksKind; goal: boolean; open: LinksProps['open']; refreshing: boolean }>) {
	return (
		<TableScroll label={linksName(kind)}>
			<table className={`widefat striped seoproan-table seoproan-decay${refreshing ? ' is-refreshing' : ''}`}>
				<thead>
					<tr>
						<th scope="col">{__('Page', 'seoproanalytics')}</th>
						{kind === 'converting' && (
							<th scope="col" className="num">
								{__('Links in', 'seoproanalytics')}
							</th>
						)}
						{goal && (
							<th scope="col" className="num">
								{__('Conversions', 'seoproanalytics')}
							</th>
						)}
						<th scope="col" className="num">
							{__('Visits from search', 'seoproanalytics')}
						</th>
						<th scope="col" className="num">
							{__('Impressions', 'seoproanalytics')}
						</th>
						<th scope="col" className="num">
							{__('Clicks', 'seoproanalytics')}
						</th>
						<th scope="col" className="num">
							{__('Position', 'seoproanalytics')}
						</th>
					</tr>
				</thead>
				<tbody>
					{rows.map((row) => (
						<tr key={row.path_id}>
							<td>
								<PageCell
									row={row}
									query=""
									open={open}
									extra={
										row.from.length > 0
											? sprintf(/* translators: %s: page paths. */ __('Linked from %s', 'seoproanalytics'), row.from.join(', '))
											: undefined
									}
								/>
								<span className="seoproan-meta">{sprintf(/* translators: 1: served incoming count or unknown, 2: incoming count in text. */ __('Links in: %1$s as served; %2$s in text', 'seoproanalytics'), row.links_in_served === null ? '–' : number(row.links_in_served), number(row.links_in_text))}</span>
							</td>
						{kind === 'converting' && <td className="num">{number(row.links_in)}<span className="seoproan-meta">{row.source === 'served' ? __('As served', 'seoproanalytics') : __('In text', 'seoproanalytics')}</span></td>}
							{goal && <td className="num">{row.conversions === null ? '–' : number(row.conversions)}</td>}
							<td className="num">{number(row.visits)}</td>
							<td className="num">{number(row.impressions)}</td>
							<td className="num">{number(row.clicks)}</td>
							<td className="num">{row.impressions ? place(row.position) : '–'}</td>
						</tr>
					))}
				</tbody>
			</table>
		</TableScroll>
	);
}

function MissingTable({ rows, open, refreshing }: Readonly<{ rows: LinksMissingRow[]; open: LinksProps['open']; refreshing: boolean }>) {
	return (
		<TableScroll label={linksName('missing')}>
			<table className={`widefat striped seoproan-table seoproan-decay${refreshing ? ' is-refreshing' : ''}`}>
				<thead>
					<tr>
						<th scope="col">{__('Page', 'seoproanalytics')}</th>
						<th scope="col">{__('Should link to', 'seoproanalytics')}</th>
						<th scope="col">{__('Searches', 'seoproanalytics')}</th>
						<th scope="col" className="num">
							{__('Impressions', 'seoproanalytics')}
						</th>
						<th scope="col" className="num">
							{__('Their clicks', 'seoproanalytics')}
						</th>
					</tr>
				</thead>
				<tbody>
					{rows.map((row) => (
						<tr key={`${row.path_id}:${row.to.path_id}`}>
							<td>
								<PageCell row={row} query="" open={open} />
							</td>
							<td>
								<PageCell row={row.to} query="" open={open} />
							</td>
							<td>
								<ul className="seoproan-decay__list">
									{row.queries.map((q) => (
										<li key={q.query}>
											<button type="button" className="seoproan-link" title={__('Open in Rankings', 'seoproanalytics')} onClick={() => open({ page: row.path, query: q.query })}>
												{q.query}
											</button>
											<span className="seoproan-meta">
												{sprintf(
													/* translators: 1: impressions, 2: average position, 3: the other page's average position. */
													__('%1$s impressions at %2$s; theirs at %3$s', 'seoproanalytics'),
													number(q.impressions),
													place(q.position),
													place(q.to_position)
												)}
											</span>
										</li>
									))}
									{row.query_count > row.queries.length && (
										<li className="seoproan-meta">
											{sprintf(
												/* translators: %s: number of searches. */
												_n('and %s more', 'and %s more', row.query_count - row.queries.length, 'seoproanalytics'),
												number(row.query_count - row.queries.length)
											)}
										</li>
									)}
								</ul>
							</td>
							<td className="num">{number(row.impressions)}</td>
							<td className="num">{number(row.to.clicks)}</td>
						</tr>
					))}
				</tbody>
			</table>
		</TableScroll>
	);
}
