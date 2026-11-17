/**
 * Crawlers: which search engines, AI crawlers, SEO tools and other bots
 * ask for the site's pages (Settings → Tracking → Count crawler visits),
 * by crawler, page, HTTP status or day; optionally one group of crawler
 * and one page. Bots are never visitors, so the visitor filters do not
 * apply. Never shared.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { Button, Card, CardBody, Notice, SelectControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { CRAWLER_GROUPS, formatNumber, type CrawlerGroup, type CrawlerKind } from '@seoproanalytics/core';
import { errorMessage, useCrawlers, type CrawlerRow, type CrawlerTotals, type CrawlersAnswer } from './api';
import { locale } from './boot';
import type { ViewProps } from './App';
import { KindTiles } from './components/KindTiles';
import { PageCardHeader, PageFilter } from './components/PageFilter';
import { TableScroll } from './components/TableScroll';

interface Tile {
	kind: CrawlerKind;
	label: string;
	value: (t: CrawlerTotals) => number;
	change: keyof CrawlerTotals;
	better?: 'down';
	foot: (t: CrawlerTotals) => string;
}

const tiles = (): Tile[] => [
	{
		kind: 'bots',
		label: __('Crawlers', 'seoproanalytics'),
		value: (t) => t.crawlers,
		change: 'crawlers',
		/* translators: %s: number of requests. */
		foot: (t) => sprintf(__('%s requests', 'seoproanalytics'), formatNumber(t.hits, locale)),
	},
	{
		kind: 'pages',
		label: __('Pages crawled', 'seoproanalytics'),
		value: (t) => t.pages,
		change: 'pages',
		foot: () => __('By most requests', 'seoproanalytics'),
	},
	{
		kind: 'statuses',
		label: __('Failed requests', 'seoproanalytics'),
		value: (t) => t.errors + t.not_found,
		change: 'not_found',
		better: 'down',
		foot: (t) =>
			sprintf(
				/* translators: 1: requests answered "not found" or another 4xx, 2: server errors, 3: redirects. */
				__('%1$s not found · %2$s errors · %3$s redirected', 'seoproanalytics'),
				formatNumber(t.not_found, locale),
				formatNumber(t.errors, locale),
				formatNumber(t.redirects, locale),
			),
	},
	{
		kind: 'days',
		label: __('Requests', 'seoproanalytics'),
		value: (t) => t.hits,
		change: 'hits',
		foot: () => __('By day', 'seoproanalytics'),
	},
];

export function groupName(group: CrawlerGroup): string {
	switch (group) {
		case 'search':
			return __('Search engines', 'seoproanalytics');
		case 'ai':
			return __('AI crawlers', 'seoproanalytics');
		case 'seo':
			return __('SEO tools', 'seoproanalytics');
		case 'social':
			return __('Social previews', 'seoproanalytics');
		case 'monitor':
			return __('Monitors and speed tests', 'seoproanalytics');
		case 'other':
			return __('Other bots', 'seoproanalytics');
		default:
			return __('All crawlers', 'seoproanalytics');
	}
}

function lastSeen(ts: number): string {
	return ts ? new Date(ts * 1000).toLocaleString(locale, { dateStyle: 'medium', timeStyle: 'short' }) : '–';
}

export function Crawlers({ state, update }: Readonly<ViewProps>) {
	const kind: CrawlerKind = state.crawl ?? 'bots';
	const group: CrawlerGroup = state.bots ?? '';
	const page = state.page ?? '';
	const query = useCrawlers(state, kind, group, page, 100);
	const answer = query.data;
	const totals = answer?.totals;
	const change = answer?.compare?.change;
	const all = tiles();
	const tile = all.find((t) => t.kind === kind) ?? all[0]!;

	return (
		<>
			{query.isError && (
				<Notice status="error" isDismissible={false}>
					{errorMessage(query.error, __('Crawler visits could not be loaded. Reload the page to try again.', 'seoproanalytics'))}
				</Notice>
			)}
			{answer && !answer.enabled && (
				<Notice status="info" isDismissible={false}>
					{__('Crawler visits are counted when "Count crawler visits" is on (Settings → Tracking). Only bots\' requests are checked further; people\'s requests are not slowed.', 'seoproanalytics')}
				</Notice>
			)}
			{state.filters.length > 0 && (
				<Notice status="info" isDismissible={false}>
					{__('Crawlers are not visitors: the filters above do not apply here. Only the period and the page below do.', 'seoproanalytics')}
				</Notice>
			)}

			<Card className="seoproan-app-card is-wide seoproan-app-section" size="small">
				<PageCardHeader
					title={page ? sprintf(/* translators: %s: a page path. */ __('Crawler visits to %s', 'seoproanalytics'), page) : __('Crawler visits', 'seoproanalytics')}
					range={answer?.range}
					compare={answer?.compare?.range}
					filter={
						<PageFilter
							page={page}
							suggestions={kind === 'pages' ? (answer?.rows ?? []).map((r) => r.path ?? '').filter((path) => path !== '') : []}
							onApply={(value) => update({ page: value })}
						>
							<SelectControl
								__nextHasNoMarginBottom
								__next40pxDefaultSize
								label={__('Crawlers', 'seoproanalytics')}
								value={group}
								options={(['', ...CRAWLER_GROUPS] as CrawlerGroup[]).map((value) => ({ value, label: groupName(value) }))}
								onChange={(value) => update({ bots: (value || undefined) as ViewProps['state']['bots'] })}
							/>
						</PageFilter>
					}
				/>

				<KindTiles
					selected={kind}
					onPick={(picked) => update({ crawl: picked === 'bots' ? undefined : picked })}
					tiles={all.map((t) => ({
						kind: t.kind,
						label: t.label,
						value: totals ? formatNumber(t.value(totals), locale) : '–',
						foot: totals ? t.foot(totals) : '',
						change: change ? change[t.change] : undefined,
						better: t.better,
					}))}
				/>

				<CardBody className="seoproan-app-card__body">
					{!answer && !query.isError && <div className="seoproan-skeleton seoproan-skeleton--table" aria-busy="true" />}
					{answer && answer.kind === kind && !answer.rows.length && (
						<div className="seoproan-app-empty">
							<p>{__('No crawler requests in this period.', 'seoproanalytics')}</p>
						</div>
					)}
					{answer && answer.kind === kind && answer.rows.length > 0 && (
						<CrawlerTable answer={answer} label={tile.label} page={page} update={update} fetching={query.isFetching} />
					)}
					{answer && (
						<p className="seoproan-note">
							{__('Counted on the server for requests whose browser names itself as a bot, by day; nothing is added to pages. Requests answered by a page cache without starting WordPress are not seen, so cached sites see fewer. "Not found" pages are kept by address only for search engines and AI crawlers.', 'seoproanalytics')}
						</p>
					)}
				</CardBody>
			</Card>
		</>
	);
}

function firstColumn(kind: CrawlerKind): string {
	switch (kind) {
		case 'pages':
			return __('Page', 'seoproanalytics');
		case 'statuses':
			return __('HTTP status', 'seoproanalytics');
		case 'days':
			return __('Day', 'seoproanalytics');
		default:
			return __('Crawler', 'seoproanalytics');
	}
}

function statusName(status: number): string {
	if (status >= 500) {
		return __('Server error', 'seoproanalytics');
	}
	if (status === 404 || status === 410) {
		return __('Not found', 'seoproanalytics');
	}
	if (status >= 400) {
		return __('Refused', 'seoproanalytics');
	}
	if (status >= 300) {
		return status === 304 ? __('Not modified', 'seoproanalytics') : __('Redirected', 'seoproanalytics');
	}
	return __('OK', 'seoproanalytics');
}

function RowName({ row, kind, page, update }: Readonly<{ row: CrawlerRow; kind: CrawlerKind; page: string; update: ViewProps['update'] }>) {
	if (kind === 'bots') {
		return (
			<>
				<span>{row.name}</span>
				{row.group && <span className="seoproan-app-badge seoproan-crawler-group">{groupName(row.group)}</span>}
			</>
		);
	}
	if (kind === 'pages') {
		if (row.not_found) {
			return <span className="seoproan-muted">{__('(pages not found, asked for by other bots)', 'seoproanalytics')}</span>;
		}
		return (
			<Button variant="link" aria-pressed={page === row.path} onClick={() => update({ page: page === row.path ? '' : (row.path ?? '') })}>
				{row.path}
			</Button>
		);
	}
	if (kind === 'statuses') {
		return (
			<>
				<code>{row.status}</code> {statusName(row.status ?? 0)}
			</>
		);
	}
	return <>{row.date}</>;
}

function CrawlerTable({ answer, label, page, update, fetching }: Readonly<{ answer: CrawlersAnswer; label: string; page: string; update: ViewProps['update']; fetching: boolean }>) {
	const kind = answer.kind;
	const rows = answer.rows;
	const top = Math.max(...rows.map((r) => r.hits), 1);
	return (
		<TableScroll label={label}>
			<table className={`widefat striped seoproan-table${fetching ? ' is-refreshing' : ''}`}>
				<thead>
					<tr>
						<th scope="col">{firstColumn(kind)}</th>
						<th scope="col" className="num">
							{__('Requests', 'seoproanalytics')}
						</th>
						{kind !== 'pages' && (
							<th scope="col" className="num">
								{__('Pages', 'seoproanalytics')}
							</th>
						)}
						{kind !== 'bots' && (
							<th scope="col" className="num">
								{__('Crawlers', 'seoproanalytics')}
							</th>
						)}
						{kind !== 'statuses' && (
							<th scope="col" className="num">
								{__('Failed', 'seoproanalytics')}
							</th>
						)}
						{kind !== 'days' && <th scope="col">{__('Last seen', 'seoproanalytics')}</th>}
					</tr>
				</thead>
				<tbody>
					{rows.map((row) => (
						<tr key={`${row.name ?? ''}|${row.path ?? ''}|${row.status ?? ''}|${row.date ?? ''}`} className={kind === 'pages' && page !== '' && page === row.path ? 'is-selected' : ''}>
							<td className="seoproan-table__bar-cell">
								<span className="seoproan-table__bar" style={{ width: `${(row.hits / top) * 100}%` }} aria-hidden="true" />
								<RowName row={row} kind={kind} page={page} update={update} />
							</td>
							<td className="num">{formatNumber(row.hits, locale)}</td>
							{kind !== 'pages' && <td className="num">{formatNumber(row.pages, locale)}</td>}
							{kind !== 'bots' && <td className="num">{formatNumber(row.crawlers, locale)}</td>}
							{kind !== 'statuses' && <td className="num">{row.failed ? formatNumber(row.failed, locale) : <span className="seoproan-muted">–</span>}</td>}
							{kind !== 'days' && <td>{lastSeen(row.last)}</td>}
						</tr>
					))}
				</tbody>
			</table>
		</TableScroll>
	);
}
