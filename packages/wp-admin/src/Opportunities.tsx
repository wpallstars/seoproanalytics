/**
 * Search → Opportunities: where search effort pays, from the chosen
 * engine's imported days (Search Console or Bing, or both added up:
 * Combined), in five cards.
 *
 * - Striking distance: a page's query at position 4–20; the clicks it
 *   could gain in the top three.
 * - Low CTR: a page's query in the top 10 chosen less often than the
 *   site's own CTR at that position; the clicks it misses.
 * - Losing clicks: pages with fewer clicks than the earlier period, with
 *   the likely cause, the queries that lost most and what changed on
 *   the page.
 * - Missing from the page: a page's query in the top 20 whose words the
 *   page does not have, or has only some of; questions are marked.
 * - Overlapping pages: a query two or more pages each get a tenth or more
 *   of the impressions for, with each page's share; a candidate to
 *   review, marked when the leading page changed between the halves.
 *
 * Choosing a row opens it in Rankings. The period is cut at the newest
 * search day and to its newest 366 days.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { useState, type ReactNode } from 'react';
import { Button, Card, CardBody, CardHeader, Notice } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	apiArgs,
	formatDecimal,
	formatNumber,
	formatPercent,
	SEARCH_METRICS,
	type DecayCause,
	type Marker,
	type OpportunitiesAnswer,
	type OpportunityDecay,
	type OpportunityKind,
	type OpportunityMissing,
	type OpportunityOverlap,
	type OpportunityPage,
	type OpportunityPair,
} from '@seoproanalytics/core';
import { CoverageBadges } from './components/CoverageBadges';
import { ResearchMenu } from './components/ResearchMenu';
import { AddTarget } from './components/AddTarget';
import { errorMessage, scopeKey, useOpportunities } from './api';
import { locale } from './boot';
import { longLabel, rangeText } from './dates';
import type { ViewProps } from './App';
import { PeriodLine } from './Overview';
import { Change } from './components/Change';
import { EditLink } from './components/EditLink';
import { SearchSetup, useReportEngines, type SearchPick, type SearchReportProps } from './components/SearchSetup';
import { TableScroll } from './components/TableScroll';

const PER_PAGE = 10;

const number = (value: number) => formatNumber(value, locale, false);
const percent = (value: number) => formatPercent(value, locale);
const place = (value: number | null) => (value === null ? '–' : formatDecimal(value, locale));

function causeName(cause: DecayCause): string {
	const names: Record<DecayCause, string> = {
		position: __('Position', 'seoproanalytics'),
		demand: __('Demand', 'seoproanalytics'),
		ctr: __('CTR', 'seoproanalytics'),
		gone: __('Not shown', 'seoproanalytics'),
	};
	return names[cause];
}

/** A day of a change: Tue 16 Sep 2026. */
function day(marker: Marker): string {
	return longLabel(marker.t, 'day');
}

type OpportunitiesProps = SearchReportProps & {
	open: (pick: SearchPick) => void;
};

export function Opportunities({ state, open, onEngines }: Readonly<OpportunitiesProps>) {
	// The striking card's own request (the same arguments, so fetched once).
	const striking = useOpportunities(state, 'striking', PER_PAGE, 0);
	const answer = striking.data;
	useReportEngines(answer, onEngines);
	return (
		<>
			{answer && <SearchSetup answer={answer} />}
			{answer && answer.ignored.length > 0 && (
				<Notice status="warning" isDismissible={false} className="seoproan-notice">
					{__('Search data has no visits, so only page filters apply here; the other filters are left out.', 'seoproanalytics')}
				</Notice>
			)}
			<div className="seoproan-opportunities">
				<KindCard state={state} kind="striking" open={open} />
				<KindCard state={state} kind="ctr" open={open} />
				<KindCard state={state} kind="decay" open={open} />
				<KindCard state={state} kind="missing" open={open} />
				<KindCard state={state} kind="overlap" open={open} />
			</div>
		</>
	);
}

function kindTitle(kind: OpportunityKind): string {
	const titles: Record<OpportunityKind, string> = {
		striking: __('Striking distance', 'seoproanalytics'),
		ctr: __('Low CTR', 'seoproanalytics'),
		decay: __('Losing clicks', 'seoproanalytics'),
		missing: __('Missing from the page', 'seoproanalytics'),
		overlap: __('Overlapping pages', 'seoproanalytics'),
	};
	return titles[kind];
}

/** What a card lists, with its thresholds and the CTR aimed for. */
function kindIntro(answer: OpportunitiesAnswer): string {
	const rules = answer.rules;
	if (answer.kind === 'striking') {
		return sprintf(
			/* translators: 1: lowest position, 2: highest position, 3: minimum impressions, 4: target position, 5: the site's CTR at that position. */
			__(
				'Queries a page ranks for at positions %1$s–%2$s with at least %3$s impressions. Reaching position %4$s, where this site’s CTR is %5$s, would bring about the clicks shown.',
				'seoproanalytics'
			),
			number(rules.position_from ?? 4),
			number(rules.position_to ?? 20),
			number(rules.min_impressions ?? 0),
			number(rules.target ?? 3),
			percent(answer.curve?.ctr[String(rules.target ?? 3)] ?? 0)
		);
	}
	if (answer.kind === 'ctr') {
		return sprintf(
			/* translators: 1: highest position, 2: minimum impressions, 3: share, e.g. 60%. */
			__(
				'Queries where a page is in the top %1$s (at least %2$s impressions) but its CTR is under %3$s of this site’s own CTR at that position. A clearer title and description may win the missed clicks.',
				'seoproanalytics'
			),
			number(rules.position_to ?? 10),
			number(rules.min_impressions ?? 0),
			percent(rules.under ?? 0.6)
		);
	}
	if (answer.kind === 'missing') {
		return sprintf(
			/* translators: 1: highest position, 2: minimum impressions, 3: number of pages read. */
			__(
				'Queries a page shows for in the top %1$s (at least %2$s impressions) whose words the page does not have, or has only some of. Use the words in the text or a heading, or answer the question, so the page matches the search. The %3$s pages with most impressions are read.',
				'seoproanalytics'
			),
			number(rules.position_to ?? 20),
			number(rules.min_impressions ?? 0),
			number(rules.pages ?? 50)
		);
	}
	if (answer.kind === 'overlap') {
		return sprintf(
			/* translators: 1: share, e.g. 10%, 2: minimum impressions. */
			__(
				'Queries for which two or more pages each get at least %1$s of the impressions (%2$s or more in all). Candidates to review, not faults: a guide and a product page can both be right. If the pages answer the same need, make one the clear answer and link to it from the others. Switched means the page with most impressions changed between the halves of the period.',
				'seoproanalytics'
			),
			percent(rules.min_share ?? 0.1),
			number(rules.min_impressions ?? 0)
		);
	}
	return sprintf(
		/* translators: 1: share of clicks lost, e.g. 20%, 2: minimum clicks lost. */
		__(
			'Pages with at least %1$s fewer clicks (and %2$s or more) than in the earlier period, with the likely cause, the queries that lost most and what changed on the page.',
			'seoproanalytics'
		),
		percent(rules.min_share ?? 0.2),
		number(rules.min_lost ?? 0)
	);
}

/** What an empty card says, once there is search data. */
function emptyText(kind: OpportunityKind): string {
	switch (kind) {
		case 'decay':
			return __('No page lost clicks this way in this period.', 'seoproanalytics');
		case 'missing':
			return __('The pages read have the words of every query they show for.', 'seoproanalytics');
		case 'overlap':
			return __('No query is shared by pages this way in this period.', 'seoproanalytics');
		default:
			return __('Nothing of this kind in this period.', 'seoproanalytics');
	}
}

function KindCard({ state, kind, open }: Readonly<{ state: ViewProps['state']; kind: OpportunityKind; open: OpportunitiesProps['open'] }>) {
	// Back to the first rows when the period, filters or engine change.
	const scope = scopeKey(apiArgs(state), state.engine ?? 'google');
	const [at, setAt] = useState({ scope, offset: 0 });
	const offset = at.scope === scope ? at.offset : 0;
	const setOffset = (next: number) => setAt({ scope, offset: next });
	const query = useOpportunities(state, kind, PER_PAGE, offset);
	const answer = query.data?.kind === kind ? query.data : undefined;
	const rows = answer?.rows ?? [];
	const titleId = `seoproan-opportunities-${kind}`;

	let table: ReactNode = null;
	if (answer && rows.length > 0) {
		const common = { open, refreshing: query.isFetching, label: kindTitle(kind) };
		if (kind === 'decay') {
			table = <DecayTable rows={rows as OpportunityDecay[]} {...common} />;
		} else if (kind === 'missing') {
			table = <MissingTable rows={rows as OpportunityMissing[]} {...common} />;
		} else if (kind === 'overlap') {
			table = <OverlapTable rows={rows as OpportunityOverlap[]} {...common} />;
		} else {
			table = <PairTable kind={kind} rows={rows as OpportunityPair[]} {...common} />;
		}
	}

	return (
		<Card className="seoproan-app-card is-wide seoproan-app-section" size="small">
			<CardHeader className="seoproan-app-card__header">
				<div>
					<h2 className="seoproan-app-card__title" id={titleId}>
						{kindTitle(kind)}
					</h2>
					{answer?.through && answer.days > 0 && <PeriodLine range={answer.range} compare={answer.compare?.range} />}
				</div>
			</CardHeader>
			<CardBody className="seoproan-app-card__body">
				{query.isError && (
					<Notice status="error" isDismissible={false}>
						{errorMessage(query.error, __('The opportunities could not be loaded. Reload the page to try again.', 'seoproanalytics'))}
					</Notice>
				)}
				{!answer && !query.isError && <div className="seoproan-skeleton seoproan-skeleton--table" aria-busy="true" />}
				{answer?.through && <p className="seoproan-note seoproan-opportunities__intro">{kindIntro(answer)}</p>}
				{answer && !rows.length && (
					<div className="seoproan-app-empty">
						<p>{answer.through ? emptyText(kind) : __('No search data yet.', 'seoproanalytics')}</p>
					</div>
				)}
				{table}
				{answer?.through && <Notes answer={answer} />}
				{answer && answer.total > PER_PAGE && (
					<nav className="seoproan-changes__pager" aria-label={sprintf(/* translators: %s: card title, e.g. "Low CTR". */ __('Pages of %s', 'seoproanalytics'), kindTitle(kind))}>
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

/** Why losing clicks compares shorter periods than the one chosen (the Plan says it too). */
export function decayCutNote(range: { from: string; to: string }, compare: { from: string; to: string }): string {
	return sprintf(
		/* translators: 1: the period's days, e.g. "1 Aug – 31 Aug 2026", 2: the earlier period's days. */
		__('Losing clicks compares %1$s with %2$s, the longest it can: there is no search data before that.', 'seoproanalytics'),
		rangeText(range.from, range.to),
		rangeText(compare.from, compare.to)
	);
}

/** Notes under a card: the period read, the CTR curve's source, search engine updates. */
function Notes({ answer }: Readonly<{ answer: OpportunitiesAnswer }>) {
	const notes: string[] = [];
	if (answer.cut) {
		notes.push(
			sprintf(
				/* translators: %s: number of days. */
				_n('Read from the newest %s day of the period.', 'Read from the newest %s days of the period.', answer.days, 'seoproanalytics'),
				number(answer.days)
			)
		);
	}
	if (answer.compare?.cut) {
		notes.push(decayCutNote(answer.range, answer.compare.range));
	}
	if (answer.curve?.source === 'default') {
		notes.push(__('This site has too few impressions to measure its own CTR by position yet, so a cautious typical CTR is used.', 'seoproanalytics'));
	} else if (answer.curve?.source === 'mixed') {
		notes.push(__('Expected CTR is this site’s own by position; positions with too few impressions use a cautious typical CTR.', 'seoproanalytics'));
	}
	const updates = answer.updates ?? [];
	if (!notes.length && !updates.length) {
		return null;
	}
	return (
		<div className="seoproan-note">
			{notes.map((note) => (
				<p key={note}>{note}</p>
			))}
			{updates.length > 0 && (
				<p>
					{__('Search engine updates in these periods:', 'seoproanalytics')}{' '}
					{updates.map((u, i) => (
						<span key={u.id}>
							{i > 0 && '; '}
							{u.label} ({day(u)})
						</span>
					))}
				</p>
			)}
		</div>
	);
}

/** A page: its path (opens Rankings) and View and Edit links, then any extra figures; also Content's. */
export function PageCell({ row, query, open, extra }: Readonly<{ row: OpportunityPage; query: string; open: OpportunitiesProps['open']; extra?: ReactNode }>) {
	return (
		<>
			<button type="button" className="seoproan-link" title={__('Open in Rankings', 'seoproanalytics')} onClick={() => open({ page: row.path, query })}>
				{query || row.path}
			</button>
			{query && <ResearchMenu query={query} />}
			{(query || row.url || row.edit_url || extra) && (
				<span className="seoproan-meta">
					{query && row.path}
					{query && (row.url || row.edit_url) && ' · '}
					{row.url && (
						<a
							href={row.url}
							target="_blank"
							rel="noopener noreferrer"
							aria-label={sprintf(/* translators: %s: page path. */ __('View %s (opens in a new tab)', 'seoproanalytics'), row.path)}
						>
							<span className="dashicons dashicons-external" aria-hidden="true" /> {__('View page', 'seoproanalytics')}
						</a>
					)}
					{row.edit_url && (
						<>
							{row.url && ' · '}
							<EditLink href={row.edit_url} path={row.path} />
						</>
					)}
					{extra && (
						<>
							{(query || row.url || row.edit_url) && ' · '}
							{extra}
						</>
					)}
				</span>
			)}
		</>
	);
}

interface PairTableProps {
	kind: OpportunityKind;
	rows: OpportunityPair[];
	open: OpportunitiesProps['open'];
	refreshing: boolean;
	label: string;
}

function PairTable({ kind, rows, open, refreshing, label }: Readonly<PairTableProps>) {
	return (
		<TableScroll label={label}>
			<table className={`widefat striped seoproan-table${refreshing ? ' is-refreshing' : ''}`}>
				<thead>
					<tr>
						<th scope="col">{__('Query and page', 'seoproanalytics')}</th>
						<th scope="col" className="num">
							{__('Position', 'seoproanalytics')}
						</th>
						<th scope="col" className="num">
							{__('Impressions', 'seoproanalytics')}
						</th>
						<th scope="col" className="num">
							{__('Clicks', 'seoproanalytics')}
						</th>
						<th scope="col" className="num">
							{__('CTR', 'seoproanalytics')}
						</th>
						<th scope="col" className="num">
							{kind === 'striking' ? __('CTR at top 3', 'seoproanalytics') : __('Expected CTR', 'seoproanalytics')}
						</th>
						<th scope="col" className="num">
							{kind === 'striking' ? __('Clicks to gain', 'seoproanalytics') : __('Clicks missed', 'seoproanalytics')}
						</th>
					</tr>
				</thead>
				<tbody>
					{rows.map((row) => (
						<tr key={`${row.path_id}:${row.query}`}>
							<td>
								<PageCell row={row} query={row.query} open={open} />
								{kind === 'striking' && <AddTarget query={row.query} page={row.path} />}
							</td>
							<td className="num">{place(row.position)}</td>
							<td className="num">{number(row.impressions)}</td>
							<td className="num">{number(row.clicks)}</td>
							<td className="num">{percent(row.ctr)}</td>
							<td className="num">{percent(row.expected_ctr)}</td>
							<td className="num">
								<strong>+{number(row.potential)}</strong>
							</td>
						</tr>
					))}
				</tbody>
			</table>
		</TableScroll>
	);
}

interface MissingTableProps {
	rows: OpportunityMissing[];
	open: OpportunitiesProps['open'];
	refreshing: boolean;
	label: string;
}

function MissingTable({ rows, open, refreshing, label }: Readonly<MissingTableProps>) {
	return (
		<TableScroll label={label}>
			<table className={`widefat striped seoproan-table${refreshing ? ' is-refreshing' : ''}`}>
				<thead>
					<tr>
						<th scope="col">{__('Query and page', 'seoproanalytics')}</th>
						<th scope="col">{__('On the page', 'seoproanalytics')}</th>
						<th scope="col" className="num">
							{__('Position', 'seoproanalytics')}
						</th>
						<th scope="col" className="num">
							{__('Impressions', 'seoproanalytics')}
						</th>
						<th scope="col" className="num">
							{__('Clicks', 'seoproanalytics')}
						</th>
					</tr>
				</thead>
				<tbody>
					{rows.map((row) => (
						<tr key={`${row.path_id}:${row.query}`}>
							<td>
								<PageCell row={row} query={row.query} open={open} />
							</td>
							<td>
								<CoverageBadges result={row} />
							</td>
							<td className="num">{place(row.position)}</td>
							<td className="num">{number(row.impressions)}</td>
							<td className="num">{number(row.clicks)}</td>
						</tr>
					))}
				</tbody>
			</table>
		</TableScroll>
	);
}

interface OverlapTableProps {
	rows: OpportunityOverlap[];
	open: OpportunitiesProps['open'];
	refreshing: boolean;
	label: string;
}

function OverlapTable({ rows, open, refreshing, label }: Readonly<OverlapTableProps>) {
	return (
		<TableScroll label={label}>
			<table className={`widefat striped seoproan-table seoproan-decay seoproan-overlap${refreshing ? ' is-refreshing' : ''}`}>
				<thead>
					<tr>
						<th scope="col">{__('Query', 'seoproanalytics')}</th>
						<th scope="col" className="seoproan-overlap__pages">
							{__('Pages, by share of impressions', 'seoproanalytics')}
						</th>
						<th scope="col" className="num">
							{__('Impressions', 'seoproanalytics')}
						</th>
						<th scope="col" className="num">
							{__('Clicks', 'seoproanalytics')}
						</th>
						<th scope="col">{__('Leading page', 'seoproanalytics')}</th>
					</tr>
				</thead>
				<tbody>
					{rows.map((row) => (
						<tr key={row.query}>
							<td>
								<button type="button" className="seoproan-link" title={__('Open in Rankings', 'seoproanalytics')} onClick={() => open({ page: '', query: row.query })}>
									{row.query}
								</button>
								<ResearchMenu query={row.query} />
								{/* Which page is meant is the choice overlap asks for: added with none chosen. */}
								<AddTarget query={row.query} />
								{row.potential > 0 && (
									<span className="seoproan-meta">
										{sprintf(
											/* translators: %s: number of clicks. */
											__('+%s clicks with the best of their CTRs', 'seoproanalytics'),
											number(row.potential)
										)}
									</span>
								)}
							</td>
							<td>
								<ul className="seoproan-decay__list">
									{row.pages.map((page) => (
										<li key={page.path_id}>
											<PageCell
												row={page}
												query=""
												open={(pick) => open({ ...pick, query: row.query })}
												extra={sprintf(
													/* translators: 1: share of the query's impressions, 2: average position, 3: clicks. */
													__('%1$s of impressions · position %2$s · %3$s clicks', 'seoproanalytics'),
													percent(page.share),
													place(page.position),
													number(page.clicks)
												)}
											/>
										</li>
									))}
									{row.page_count > row.pages.length && (
										<li className="seoproan-muted">
											{sprintf(
												/* translators: %s: number of pages. */
												_n('and %s more page', 'and %s more pages', row.page_count - row.pages.length, 'seoproanalytics'),
												number(row.page_count - row.pages.length)
											)}
										</li>
									)}
								</ul>
							</td>
							<td className="num">{number(row.impressions)}</td>
							<td className="num">{number(row.clicks)}</td>
							<td>
								<Leaders switched={row.switched} leaders={row.leaders} />
							</td>
						</tr>
					))}
				</tbody>
			</table>
		</TableScroll>
	);
}

/** The page search showed most in each half: switched, the same, or none. */
function Leaders({ switched, leaders }: Readonly<{ switched: boolean; leaders: [string | null, string | null] }>) {
	if (switched) {
		return (
			<>
				<strong className="seoproan-cause is-position">{__('Switched', 'seoproanalytics')}</strong>
				<span className="seoproan-meta">
					{sprintf(
						/* translators: 1: page path in the first half of the period, 2: page path in the second half. */
						__('%1$s, then %2$s', 'seoproanalytics'),
						leaders[0] ?? '–',
						leaders[1] ?? '–'
					)}
				</span>
			</>
		);
	}
	return <span className="seoproan-muted">{leaders[0] || leaders[1] ? __('The same in both halves', 'seoproanalytics') : '–'}</span>;
}

interface DecayTableProps {
	rows: OpportunityDecay[];
	open: OpportunitiesProps['open'];
	refreshing: boolean;
	label: string;
}

function DecayTable({ rows, open, refreshing, label }: Readonly<DecayTableProps>) {
	return (
		<TableScroll label={label}>
			<table className={`widefat striped seoproan-table seoproan-decay${refreshing ? ' is-refreshing' : ''}`}>
				<thead>
					<tr>
						<th scope="col">{__('Page', 'seoproanalytics')}</th>
						<th scope="col" className="num">
							{__('Clicks', 'seoproanalytics')}
						</th>
						<th scope="col" className="num">
							{__('Position', 'seoproanalytics')}
						</th>
						<th scope="col">{__('Likely cause', 'seoproanalytics')}</th>
						<th scope="col">{__('Queries that lost most', 'seoproanalytics')}</th>
						<th scope="col">{__('Changed on the page', 'seoproanalytics')}</th>
					</tr>
				</thead>
				<tbody>
					{rows.map((row) => (
						<tr key={row.path_id}>
							<td>
								<PageCell row={row} query="" open={open} />
							</td>
							<td className="num">
								{number(row.clicks)}
								<Change change={row.compare.change.clicks} better={SEARCH_METRICS.clicks.better} previous={number(row.compare.clicks)} />
								<span className="seoproan-meta">
									{sprintf(/* translators: %s: number of clicks. */ __('%s lost', 'seoproanalytics'), number(row.lost))}
								</span>
							</td>
							<td className="num">
								{row.impressions ? place(row.position) : '–'}
								{row.impressions > 0 && row.compare.impressions > 0 && (
									<Change change={row.compare.change.position} places better={SEARCH_METRICS.position.better} previous={place(row.compare.position)} />
								)}
							</td>
							<td>
								<strong className={`seoproan-cause is-${row.cause}`}>{causeName(row.cause)}</strong>
								<span className="seoproan-meta">{row.why}</span>
							</td>
							<td>
								{row.queries.length ? (
									<ul className="seoproan-decay__list">
										{row.queries.map((q) => (
											<li key={q.query}>
												<button type="button" className="seoproan-link" title={__('Open in Rankings', 'seoproanalytics')} onClick={() => open({ page: row.path, query: q.query })}>
													{q.query}
												</button>
												<span className="seoproan-meta">
													{sprintf(
														/* translators: 1: clicks before, 2: clicks now, 3: position before, 4: position now. */
														__('%1$s → %2$s clicks · position %3$s → %4$s', 'seoproanalytics'),
														number(q.then_clicks),
														number(q.clicks),
														place(q.then_position),
														place(q.position)
													)}
												</span>
												{q.rival && (
													<span className="seoproan-meta">
														{sprintf(
															/* translators: 1: another page's path, 2: its average position now. */
															__('Overtaken by %1$s (position %2$s)', 'seoproanalytics'),
															q.rival.path,
															place(q.rival.position)
														)}
													</span>
												)}
											</li>
										))}
									</ul>
								) : (
									<span className="seoproan-muted">–</span>
								)}
							</td>
							<td>
								{row.changes.length ? (
									<ul className="seoproan-decay__list">
										{row.changes.map((c) => (
											<li key={c.id}>
												<span className="seoproan-changes__group">
													<span className="seoproan-chart-lane__dot" style={{ background: `var(--seoproan-mark-${c.group})` }} aria-hidden="true" />
													<span>{c.label}</span>
												</span>
												<span className="seoproan-meta">{day(c)}</span>
											</li>
										))}
									</ul>
								) : (
									<span className="seoproan-muted">{__('No change recorded', 'seoproanalytics')}</span>
								)}
							</td>
						</tr>
					))}
				</tbody>
			</table>
		</TableScroll>
	);
}
