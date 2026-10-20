/**
 * Search → Content: which pages earn their search traffic. Per page, the
 * chosen engine's (or every engine's, Combined) clicks, position and CTR, with the visits from
 * search that started on the page (bounce rate, time) and how many of them
 * reached a goal. A page that ranks but whose visits leave needs better
 * content or a clearer next step; one that converts but gets few clicks is
 * worth ranking higher.
 *
 * Totals as tiles, then the pages, most impressions first; any figure's
 * header sorts by it, a second click reverses. Choosing a page opens it in Rankings.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { useState, type ReactNode } from 'react';
import { Button, Card, CardBody, CardHeader, Notice, SelectControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import {
	apiArgs,
	formatDecimal,
	formatDuration,
	formatNumber,
	formatPercent,
	CONTENT_SORTS,
	SEARCH_METRICS,
	tableSort,
	type ContentAnswer,
	type ContentMetrics,
	type ContentRow,
	type ContentSort,
	type OwnersAnswer,
	type SearchEngineChoice,
} from '@seoproanalytics/core';
import { errorMessage, scopeKey, useContent } from './api';
import { OwnerCell, OwnerFilter, useOwnerPeople } from './components/Owner';
import { locale } from './boot';
import { longLabel } from './dates';
import { PageCell } from './Opportunities';
import { PeriodLine } from './Overview';
import { Change } from './components/Change';
import { SearchSetup, sourceName, useReportEngines, type SearchPick, type SearchReportProps } from './components/SearchSetup';
import { SortHeader, type TableSortProps } from './components/SortHeader';
import { TableScroll } from './components/TableScroll';

const PER_PAGE = 25;

const number = (value: number) => formatNumber(value, locale, false);
const percent = (value: number) => formatPercent(value, locale);
const place = (row: { impressions: number; position: number }) => (row.impressions ? formatDecimal(row.position, locale) : '–');

type ContentProps = SearchReportProps & {
	open: (pick: SearchPick) => void;
};

export function Content({ state, update, open, onEngines }: Readonly<ContentProps>) {
	// Most impressions first unless a header was chosen.
	const by = tableSort(CONTENT_SORTS, state.sort, state.order);
	const goal = state.goal ?? '';
	const engine: SearchEngineChoice = state.engine ?? 'google';
	const owner = state.owner ?? '';
	// Back to the first rows when the period, filters, engine, order, goal or owner change.
	const scope = scopeKey(apiArgs(state), engine, by, goal, owner);
	const [at, setAt] = useState({ scope, offset: 0 });
	const offset = at.scope === scope ? at.offset : 0;
	const setOffset = (next: number) => setAt({ scope, offset: next });
	const query = useContent(state, by, goal, PER_PAGE, offset);
	// Owners: for people who can edit posts, never in shared reports (the hook is off there).
	const owners = useOwnerPeople(state);
	const [error, setError] = useState('');
	const answer = query.data;
	useReportEngines(answer, onEngines);
	// The engine answered for: Combined with fewer than two engines with data answers as the one with data.
	const answered: SearchEngineChoice = answer?.engine ?? engine;
	const rows = answer?.rows ?? [];
	const counted = answer?.goal ?? null;

	return (
		<>
			{(query.isError || error) && (
				<Notice status="error" isDismissible={!!error} onRemove={() => setError('')} className="seoproan-notice">
					{error || errorMessage(query.error, __('The content report could not be loaded. Reload the page to try again.', 'seoproanalytics'))}
				</Notice>
			)}
			{answer && <SearchSetup answer={answer} />}
			{answer && answer.ignored.length > 0 && (
				<Notice status="warning" isDismissible={false} className="seoproan-notice">
					{__('Search data has no visits, so only page filters apply here; the other filters are left out.', 'seoproanalytics')}
				</Notice>
			)}
			{answer?.through && answer.partial && (
				<Notice status="info" isDismissible={false} className="seoproan-notice">
					{answer.landings_from
						? sprintf(
								/* translators: %s: a day, e.g. "Sun 4 Oct 2026". */
								__('Visits from search are counted from %s. Older days are still being added; they appear within a few minutes.', 'seoproanalytics'),
								longLabel(answer.landings_from, 'day')
							)
						: __('Visits from search are still being added; they appear within a few minutes.', 'seoproanalytics')}
				</Notice>
			)}

			<Card className="seoproan-summary">
				<div className="seoproan-search__head">
					<div>
						<h2 className="seoproan-app-card__title">{__('Content performance', 'seoproanalytics')}</h2>
						{answer && <PeriodLine range={answer.range} compare={answer.compare?.range} />}
						{answer?.through && (
							<p className="seoproan-meta">
								{sprintf(
									/* translators: 1: a source, e.g. "Google Search Console", 2: a day, e.g. "Sun 4 Oct 2026". */
									__('%1$s, final days through %2$s, with the visits from search of the same days', 'seoproanalytics'),
									sourceName(answer.engine, answer.engines),
									longLabel(answer.through, 'day')
								)}
							</p>
						)}
					</div>
					{answer && answer.goals.length > 0 && (
						<div className="seoproan-properties__event">
							<SelectControl
								__nextHasNoMarginBottom
								label={__('Conversions of', 'seoproanalytics')}
								value={counted?.id ?? ''}
								options={answer.goals.map((g) => ({ value: g.id, label: g.name }))}
								onChange={(next) => update({ goal: next === answer.goals[0]?.id ? undefined : next })}
							/>
						</div>
					)}
				</div>
				<Tiles answer={answer} engine={answered} />
			</Card>

			<Card className="seoproan-app-card is-wide seoproan-app-section" size="small">
				<CardHeader className="seoproan-app-card__header">
					<h2 className="seoproan-app-card__title">{__('Pages', 'seoproanalytics')}</h2>
					{owners && (
						<div className="seoproan-changes__filters seoproan-plan__filters">
							<OwnerFilter owners={owners} value={owner} onChange={(next) => update({ owner: next })} />
						</div>
					)}
				</CardHeader>
				<CardBody className="seoproan-app-card__body">
					{!answer && !query.isError && <div className="seoproan-skeleton seoproan-skeleton--table" aria-busy="true" />}
					{answer && !rows.length && (
						<div className="seoproan-app-empty">
							<p>{emptyText(!!answer.through, owner !== '')}</p>
						</div>
					)}
					{answer && rows.length > 0 && (
						// The order the answer has (a goal's column falls back to the default without goals).
						<PageTable answer={answer} {...tableSort(CONTENT_SORTS, answer.sort, answer.order)} onSort={(sort, order) => update({ sort, order })} open={open} refreshing={query.isFetching} owners={owners} onError={setError} />
					)}
					{answer?.through && (
						<div className="seoproan-note">
							<p>{figuresNote(answered)}</p>
							{answer.goals.length === 0 && (
								<p>
									{__('Add a goal to count conversions:', 'seoproanalytics')} <a href="#/goals">{__('Goals', 'seoproanalytics')}</a>
								</p>
							)}
						</div>
					)}
					{answer && answer.total > PER_PAGE && (
						<nav className="seoproan-changes__pager" aria-label={__('Pages of the content report', 'seoproanalytics')}>
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
		</>
	);
}

/** What an empty table says: no data yet, none in the period, or none of the owner chosen. */
function emptyText(hasData: boolean, owned: boolean): string {
	if (!hasData) {
		return __('No search data yet.', 'seoproanalytics');
	}
	return owned
		? __('No page of this owner had search clicks or visits from search in this period.', 'seoproanalytics')
		: __('No page had search clicks or visits from search in this period.', 'seoproanalytics');
}

/** Under the clicks tile: where the clicks came from. */
function clicksFoot(engine: SearchEngineChoice): string {
	if (engine === 'all') {
		return __('From search engines', 'seoproanalytics');
	}
	return engine === 'bing' ? __('From Bing', 'seoproanalytics') : __('From Google Search', 'seoproanalytics');
}

/** The note under the pages: what the figures are, by engine. */
function figuresNote(engine: SearchEngineChoice): string {
	if (engine === 'all') {
		return __(
			'Clicks and position are every search engine’s added up, Bing’s from its pages by week; visits from search are those from any search engine that started on the page, so the two differ. Conversions are those visits that reached the goal.',
			'seoproanalytics'
		);
	}
	return engine === 'bing'
		? __(
				'Clicks and position are Bing’s, from its pages by week; visits from search are those from any search engine that started on the page, so the two differ. Conversions are those visits that reached the goal.',
				'seoproanalytics'
			)
		: __(
				'Clicks and position are Google’s; visits from search are those from any search engine that started on the page, so the two differ. Conversions are those visits that reached the goal.',
				'seoproanalytics'
			);
}

/** The totals: search clicks, visits from search, their bounce rate, conversions. */
function Tiles({ answer, engine }: Readonly<{ answer: ContentAnswer | undefined; engine: SearchEngineChoice }>) {
	const totals = answer?.totals;
	const then = answer?.compare?.totals;
	const change = answer?.compare?.change;
	const tile = (label: string, foot: string, shown: string, delta: ReactNode) => (
		<div className="seoproan-tile is-static">
			<span className="seoproan-tile__label">{label}</span>
			<span className="seoproan-tile__value">{totals ? shown : '–'}</span>
			<span className="seoproan-tile__foot">
				<span className="seoproan-muted">{foot}</span>
				{delta}
			</span>
		</div>
	);
	return (
		<div className="seoproan-tiles" role="group" aria-label={__('Totals', 'seoproanalytics')}>{/* NOSONAR: a group of figures; a fieldset is for form controls and brings its own border, padding and min-width. */}
			{tile(
				__('Clicks', 'seoproanalytics'),
				clicksFoot(engine),
				totals ? number(totals.clicks) : '',
				change && <Change change={change.clicks} better={SEARCH_METRICS.clicks.better} previous={then ? number(then.clicks) : undefined} />
			)}
			{tile(
				__('Visits from search', 'seoproanalytics'),
				__('Started on these pages', 'seoproanalytics'),
				totals ? number(totals.visits) : '',
				change && <Change change={change.visits} metric="visits" previous={then ? number(then.visits) : undefined} />
			)}
			{tile(
				__('Bounce rate', 'seoproanalytics'),
				__('Of the visits from search', 'seoproanalytics'),
				totals ? percent(totals.bounce_rate) : '',
				change && <Change change={change.bounce_rate} metric="bounce_rate" previous={then ? percent(then.bounce_rate) : undefined} />
			)}
			{answer?.goal &&
				tile(
					__('Conversions', 'seoproanalytics'),
					sprintf(
						/* translators: 1: goal name, 2: conversion rate, e.g. 2.5%. */
						__('%1$s · %2$s', 'seoproanalytics'),
						answer.goal.name,
						percent(totals?.conversion_rate ?? 0)
					),
					totals ? number(totals.conversions ?? 0) : '',
					change && <Change change={change.conversions} previous={then ? number(then.conversions ?? 0) : undefined} />
				)}
		</div>
	);
}

interface PageTableProps extends TableSortProps<ContentSort> {
	answer: ContentAnswer;
	open: ContentProps['open'];
	refreshing: boolean;
	/** Who can own, when owners are shown. */
	owners: OwnersAnswer | undefined;
	onError: (message: string) => void;
}

function PageTable({ answer, sort, order, onSort, open, refreshing, owners, onError }: Readonly<PageTableProps>) {
	const goal = answer.goal !== null;
	const header = (column: ContentSort, label: string) => <SortHeader column={column} label={label} sort={sort} order={order} onSort={onSort} />;
	return (
		<TableScroll label={__('Pages', 'seoproanalytics')}>
			<table className={`widefat striped seoproan-table seoproan-content${refreshing ? ' is-refreshing' : ''}`}>
				<thead>
					<tr>
						<th scope="col">{__('Page', 'seoproanalytics')}</th>
						{header('impressions', __('Impressions', 'seoproanalytics'))}
						{header('clicks', __('Clicks', 'seoproanalytics'))}
						{header('position', __('Position', 'seoproanalytics'))}
						{header('ctr', __('CTR', 'seoproanalytics'))}
						{header('visits', __('Visits from search', 'seoproanalytics'))}
						{header('bounce_rate', __('Bounce rate', 'seoproanalytics'))}
						{header('visit_duration', __('Visit duration', 'seoproanalytics'))}
						{goal && header('conversions', __('Conversions', 'seoproanalytics'))}
						{goal && header('conversion_rate', __('Conversion rate', 'seoproanalytics'))}
						{owners && <th scope="col">{__('Owner', 'seoproanalytics')}</th>}
					</tr>
				</thead>
				<tbody>
					{answer.rows.map((row) => (
						<PageRow key={row.path_id} row={row} goal={goal} open={open} owners={owners} onError={onError} />
					))}
				</tbody>
			</table>
		</TableScroll>
	);
}

interface PageRowProps {
	row: ContentRow;
	goal: boolean;
	open: ContentProps['open'];
	owners: OwnersAnswer | undefined;
	onError: (message: string) => void;
}

function PageRow({ row, goal, open, owners, onError }: Readonly<PageRowProps>) {
	const then: ContentMetrics | undefined = row.compare;
	const change = row.compare?.change;
	return (
		<tr>
			<td>
				<PageCell row={row} query="" open={open} />
			</td>
			<td className="num">
				{number(row.impressions)}
				{change && <Change change={change.impressions} better={SEARCH_METRICS.impressions.better} previous={then ? number(then.impressions) : undefined} />}
			</td>
			<td className="num">
				{number(row.clicks)}
				{change && <Change change={change.clicks} better={SEARCH_METRICS.clicks.better} previous={then ? number(then.clicks) : undefined} />}
			</td>
			<td className="num">
				{place(row)}
				{change && then && row.impressions > 0 && then.impressions > 0 && (
					<Change change={change.position} places better={SEARCH_METRICS.position.better} previous={place(then)} />
				)}
			</td>
			<td className="num">{row.impressions ? percent(row.ctr) : '–'}</td>
			<td className="num">
				{number(row.visits)}
				{change && <Change change={change.visits} metric="visits" previous={then ? number(then.visits) : undefined} />}
			</td>
			<td className="num">{row.visits ? percent(row.bounce_rate) : '–'}</td>
			<td className="num">{row.visits ? formatDuration(row.visit_duration) : '–'}</td>
			{goal && (
				<td className="num">
					{number(row.conversions ?? 0)}
					{change && then && (then.conversions ?? 0) + (row.conversions ?? 0) > 0 && (
						<Change change={change.conversions} previous={number(then.conversions ?? 0)} />
					)}
				</td>
			)}
			{goal && <td className="num">{row.visits ? percent(row.conversion_rate ?? 0) : '–'}</td>}
			{owners && (
				<td className="seoproan-owner-cell">
					<OwnerCell owners={owners} owner={row.owner} kind="page" id={row.path} onError={onError} />
				</td>
			)}
		</tr>
	);
}
