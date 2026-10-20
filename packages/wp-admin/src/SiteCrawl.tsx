/**
 * Search → Audit, Site crawl: a crawl of the site made elsewhere
 * (LibreCrawl, the aidevops site crawler or any crawler's CSV or JSON)
 * and imported in Settings → Import. What each page served once the
 * theme, plugins, caching and the host had their say; links that fail or
 * redirect; and outbound or affiliate destinations people clicked whose
 * crawl answer failed. The plugin never crawls. Not in shared reports.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { useState, type ReactNode } from 'react';
import { Card, CardBody, CardHeader, ExternalLink, SelectControl } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';
import {
	apiArgs,
	CRAWL_FINDINGS,
	formatNumber,
	type CrawlAnswer,
	type CrawlClickedRow,
	type CrawlFinding,
	type CrawlFlag,
	type CrawlKind,
	type CrawlLinkRow,
	type CrawlPageRow,
	type CrawlStatus,
	type ViewState,
} from '@seoproanalytics/core';
import { scopeKey, shareAccess, useCrawl } from './api';
import { boot, locale } from './boot';
import { crawlStatusText, findingName } from './Audit';
import { longLabel } from './dates';
import { LoadState, Pager } from './components/Pager';
import { TableScroll } from './components/TableScroll';
import { Sparkline } from './components/Sparkline';

const PER_PAGE = 25;

const number = (value: number) => formatNumber(value, locale, false);

/** A list's name. */
function kindName(kind: CrawlKind): string {
	const names: Record<CrawlKind, string> = {
		pages: __('Pages with a problem', 'seoproanalytics'),
		links: __('Links that fail, redirect or are blocked', 'seoproanalytics'),
		clicked: __('Clicked links that fail or redirect', 'seoproanalytics'),
	};
	return names[kind];
}

/** What a page served, in a few words. */
function flagName(flag: CrawlFlag): string {
	const names: Record<CrawlFlag, string> = {
		noindex: __('noindex', 'seoproanalytics'),
		nofollow: __('nofollow', 'seoproanalytics'),
		canonical: __('canonical elsewhere', 'seoproanalytics'),
		title_missing: __('no title', 'seoproanalytics'),
		title_differs: __('title differs', 'seoproanalytics'),
		desc_differs: __('description differs', 'seoproanalytics'),
		desc_missing: __('description missing', 'seoproanalytics'),
	};
	return names[flag];
}

/** An HTTP status, or no answer. */
function statusText(status: number | null): string {
	if (status === null) {
		return '–';
	}
	return status > 0 ? String(status) : __('none', 'seoproanalytics');
}

type Filter = `finding:${CrawlFinding}` | `status:${CrawlStatus}` | '';

export function SiteCrawl({ state }: Readonly<{ state: ViewState }>) {
	const [kind, setKind] = useState<CrawlKind>('pages');
	const [filter, setFilter] = useState<Filter>('');
	// Back to the first rows when the list, filter or (clicked) period change.
	const key = scopeKey(kind, filter, kind === 'clicked' || filter === 'finding:slow' ? apiArgs({ ...state, compare: 'none', filters: [] }) : null);
	const [at, setAt] = useState({ key, offset: 0 });
	const offset = at.key === key ? at.offset : 0;
	const setOffset = (next: number) => setAt({ key, offset: next });
	const status = filter.startsWith('status:') ? (filter.slice(7) as CrawlStatus) : '';
	const finding = filter.startsWith('finding:') ? (filter.slice(8) as CrawlFinding) : '';
	const query = useCrawl(state, kind, status, finding, PER_PAGE, offset);
	const answer = query.data;
	if (shareAccess.token) {
		return null;
	}
	const crawled = !!answer && (answer.import !== null || answer.counts.pages > 0 || answer.counts.links > 0);
	const importUrl = boot.canManage && boot.settingsUrl ? addQueryArgs(boot.settingsUrl, { tab: 'import' }) : '';

	return (
		<Card className="seoproan-app-card is-wide seoproan-app-section" size="small">
			<CardHeader className="seoproan-app-card__header">
				<div>
					<h2 className="seoproan-app-card__title">{__('Site crawl', 'seoproanalytics')}</h2>
					{answer?.read && (
						<p className="seoproan-meta">
							{sprintf(
								/* translators: 1: a day, 2: the crawler's name. */
								__('Imported %1$s from %2$s', 'seoproanalytics'),
								longLabel(answer.read.slice(0, 10), 'day'),
								formatName(answer.format)
							)}
						</p>
					)}
				</div>
				{crawled && answer && (
					<Lists
						kind={kind}
						filter={filter}
						answer={answer}
						onKind={(next) => {
							setKind(next);
							setFilter('');
						}}
						onFilter={setFilter}
					/>
				)}
			</CardHeader>
			<CardBody className="seoproan-app-card__body">
				<LoadState query={query} failed={__('The site crawl could not be loaded. Reload the page to try again.', 'seoproanalytics')} />
				{answer && !crawled && <NotImported importUrl={importUrl} />}
				{answer && crawled && (
					<>
						<Summary answer={answer} />
						{answer.coverage_note && <p className="seoproan-note">{answer.coverage_note}</p>}
						<Trend points={answer.trend} />
						{finding === 'slow' && <p className="seoproan-note"><strong>{__('Slowest that matter.', 'seoproanalytics')}</strong> {__('Pages with landing visits in the period, by conversions when a goal exists, otherwise visits. Timing is as the crawler measured and needs two imports unless field data agrees; large HTML also qualifies. Fresh field TTFB/LCP takes precedence. Crawler location and load affect timings.', 'seoproanalytics')}</p>}
						<Rows kind={kind} answer={answer} filtered={filter !== ''} refreshing={query.isFetching} />
						<Pager offset={offset} perPage={PER_PAGE} total={answer.total} more={answer.more} label={__('Pages of the site crawl', 'seoproanalytics')} onChange={setOffset} />
						<About importUrl={importUrl} />
					</>
				)}
			</CardBody>
		</Card>
	);
}

/** The header's lists: which list, and (pages, links) which findings or answers. */
function Lists({ kind, filter, answer, onKind, onFilter }: Readonly<{ kind: CrawlKind; filter: Filter; answer: CrawlAnswer; onKind: (kind: CrawlKind) => void; onFilter: (filter: Filter) => void }>) {
	return (
		<div className="seoproan-changes__filters seoproan-plan__filters">
			<SelectControl
				__nextHasNoMarginBottom
				label={__('List', 'seoproanalytics')}
				value={kind}
				options={(['pages', 'links', 'clicked'] as CrawlKind[]).map((k) => ({ value: k, label: kindName(k) }))}
				onChange={(next: string) => onKind(next as CrawlKind)}
			/>
			{kind !== 'clicked' && (
				<SelectControl __nextHasNoMarginBottom label={__('Show', 'seoproanalytics')} value={filter} options={filterOptions(kind, answer)} onChange={(next: string) => onFilter(next as Filter)} />
			)}
		</div>
	);
}

/** No crawl imported yet: how to get one. */
function NotImported({ importUrl }: Readonly<{ importUrl: string }>) {
	return (
		<div className="seoproan-app-empty">
			<p>
				{__(
					'No crawl is imported yet. Crawl the site with LibreCrawl, the aidevops site crawler or any crawler that exports CSV or JSON, then import its exports in Settings → Import to see what each page served, and the links that fail or redirect.',
					'seoproanalytics'
				)}
			</p>
			{importUrl && (
				<p>
					<a href={importUrl}>{__('Import a crawl', 'seoproanalytics')}</a>
				</p>
			)}
		</div>
	);
}

/** The chosen list's table, or why it is empty. */
function Rows({ kind, answer, filtered, refreshing }: Readonly<{ kind: CrawlKind; answer: CrawlAnswer; filtered: boolean; refreshing: boolean }>) {
	// The previous list's answer stays while the chosen one loads; its rows do not fit this table.
	if (answer.kind !== kind) {
		return null;
	}
	if (!answer.rows.length) {
		return (
			<div className="seoproan-app-empty">
				<p>{emptyText(kind, filtered)}</p>
			</div>
		);
	}
	if (kind === 'links') {
		return <LinksTable rows={answer.rows as CrawlLinkRow[]} refreshing={refreshing} />;
	}
	if (kind === 'clicked') {
		return <ClickedTable rows={answer.rows as CrawlClickedRow[]} refreshing={refreshing} />;
	}
	return <PagesTable rows={answer.rows as CrawlPageRow[]} refreshing={refreshing} />;
}

/** What the crawl is, and where to import another. */
function About({ importUrl }: Readonly<{ importUrl: string }>) {
	return (
		<div className="seoproan-note">
			<p>
				{__(
					'The crawl is what a visitor or search engine was served, after the theme, plugins, caching and the host: noindex or a canonical address WordPress does not set, errors and links that fail are Audit findings and Plan items, weighed by search and conversions. A page whose answer changed between two imports is a served change on the timeline. Crawl again and import to refresh it; SEO Pro Analytics never crawls.',
					'seoproanalytics'
				)}
			</p>
			{importUrl && (
				<p>
					<a href={importUrl}>{__('Import or undo a crawl', 'seoproanalytics')}</a>
				</p>
			)}
		</div>
	);
}

/** The crawler's name. */
function formatName(format: string | null): string {
	const names: Record<string, string> = { librecrawl: 'LibreCrawl', aidevops: __('the aidevops site crawler', 'seoproanalytics'), csv: __('a CSV or JSON export', 'seoproanalytics') };
	return format ? (names[format] ?? format) : '–';
}

/** The second list: statuses and (pages) findings with their counts. */
function filterOptions(kind: CrawlKind, answer: CrawlAnswer): { value: string; label: string }[] {
	const c = answer.counts;
	const options: { value: string; label: string }[] = [{ value: '', label: __('Everything', 'seoproanalytics') }];
	if (kind === 'links') {
		options.push({ value: 'status:errors', label: `${__('Fail', 'seoproanalytics')} (${number(c.broken_links)})` }, { value: 'status:redirects', label: __('Redirect', 'seoproanalytics') }, { value: 'status:blocked', label: `${__('Blocked', 'seoproanalytics')} (${number(c.blocked_links)})` });
		return options;
	}
	CRAWL_FINDINGS.forEach((f) => options.push({ value: `finding:${f}`, label: f === 'slow' ? __('Slowest that matter', 'seoproanalytics') : `${findingName(f)} (${number(answer.findings[f] ?? 0)})` }));
	options.push(
		{ value: 'status:errors', label: `${__('Answered an error', 'seoproanalytics')} (${number(c.error + c.none)})` },
		{ value: 'status:redirects', label: __('Redirected', 'seoproanalytics') },
		{ value: 'status:noindex', label: `${__('Served noindex', 'seoproanalytics')} (${number(c.noindex)})` },
		{ value: 'status:canonical', label: `${__('Served a canonical elsewhere', 'seoproanalytics')} (${number(c.canonical)})` },
		{ value: 'status:title_missing', label: `${__('Served no title', 'seoproanalytics')} (${number(c.title_missing)})` }
	);
	return options;
}

function emptyText(kind: CrawlKind, filtered: boolean): string {
	if (filtered) {
		return __('Nothing in the crawl has this.', 'seoproanalytics');
	}
	if (kind === 'clicked') {
		return __('No outbound or affiliate link clicked in the period fails in the crawl.', 'seoproanalytics');
	}
	return kind === 'links' ? __('No link in the crawl fails, redirects or is blocked.', 'seoproanalytics') : __('No page in the crawl has a problem.', 'seoproanalytics');
}

/** The crawl in one line: pages, answers and links. */
function Summary({ answer }: Readonly<{ answer: CrawlAnswer }>) {
	const c = answer.counts;
	return (
		<p className="seoproan-note seoproan-opportunities__intro">
			{sprintf(
				/* translators: 1: pages crawled, 2: pages that answered an error or nothing, 3: pages that redirect, 4: links that fail, 5: internal links through redirects. */
				_n(
					'%1$s page crawled: %2$s failed, %3$s redirect. %4$s links fail and %5$s internal links go through redirects.',
					'%1$s pages crawled: %2$s failed, %3$s redirect. %4$s links fail and %5$s internal links go through redirects.',
					c.pages,
					'seoproanalytics'
				),
				number(c.pages),
				number(c.error + c.none),
				number(c.redirect),
				number(c.broken_links),
				number(c.redirect_links)
			)}
			{' '}{sprintf(/* translators: %s: external links refused by the linked site. */ __('%s external links blocked: the linked site refused the crawler; check by hand. These are not broken-link findings or Plan items.', 'seoproanalytics'), number(c.blocked_links))}
		</p>
	);
}

/** A column heading; num right-aligns a figure. */
type Column = Readonly<{ title: string; num?: boolean }>;

/** Small lines read only saved import totals; dates and counts explain each line. */
function Trend({ points }: Readonly<{ points: CrawlAnswer['trend'] }>) {
	const first = points[0];
	const last = points[points.length - 1];
	if (points.length < 2 || !first || !last) {
		return <p className="seoproan-note">{__('Import another crawl to compare its totals. Earlier imports without saved totals are not shown.', 'seoproanalytics')}</p>;
	}
	const series = [
		...(['median', 'p90'] as const).filter((key) => points.every((p) => p.totals.response_ms?.[key] != null)).map((key) => ({
			key, label: key === 'median' ? __('Median response (ms)', 'seoproanalytics') : __('90th percentile response (ms)', 'seoproanalytics'),
			values: points.map((p) => p.totals.response_ms?.[key] ?? 0),
		})),
		...(['2xx', '3xx', '4xx', '5xx', 'none', 'other'] as const).map((key) => ({
			key,
			label: key === 'none' ? __('No answer', 'seoproanalytics') : key === 'other' ? __('Other HTTP status', 'seoproanalytics') : key,
			values: points.map((p) => p.totals.statuses[key]),
		})),
		...CRAWL_FINDINGS.filter((key) => points.every((p) => p.totals.findings[key] !== undefined)).map((key) => ({ key, label: findingName(key), values: points.map((p) => p.totals.findings[key] ?? 0) })),
	];
	return (
		<details className="seoproan-note">
			<summary>{__('Crawl trends', 'seoproanalytics')}</summary>
			<p>{__('Saved totals per completed import, oldest to newest. Statuses count pages; link findings count links, before the content audit’s exclusions. Failed and undone imports are not samples.', 'seoproanalytics')}</p>
			<CrawlTable label={__('Crawl trends', 'seoproanalytics')} columns={[
				{ title: __('Finding or status', 'seoproanalytics') }, { title: __('Trend', 'seoproanalytics') },
				{ title: longLabel(first.started.slice(0, 10), 'day'), num: true }, { title: longLabel(last.started.slice(0, 10), 'day'), num: true },
			]} refreshing={false}>
				{series.map(({ key, label, values }) => <tr key={key}>
					<th scope="row">{label}</th>
					<td><Sparkline values={values} /></td>
					<td className="num">{number(values[0] ?? 0)}</td>
					<td className="num">{number(values[values.length - 1] ?? 0)}</td>
				</tr>)}
			</CrawlTable>
		</details>
	);
}

/** The crawl tables' shell: a scrolling table with its headings, rows given. */
function CrawlTable({ label, columns, refreshing, children }: Readonly<{ label: string; columns: Column[]; refreshing: boolean; children: ReactNode }>) {
	return (
		<TableScroll label={label}>
			<table className={`widefat striped seoproan-table seoproan-decay${refreshing ? ' is-refreshing' : ''}`}>
				<thead>
					<tr>
						{columns.map((column) => (
							<th key={column.title} scope="col" className={column.num ? 'num' : undefined}>
								{column.title}
							</th>
						))}
					</tr>
				</thead>
				<tbody>{children}</tbody>
			</table>
		</TableScroll>
	);
}

function PagesTable({ rows, refreshing }: Readonly<{ rows: CrawlPageRow[]; refreshing: boolean }>) {
	const columns: Column[] = [
		{ title: __('Page', 'seoproanalytics') },
		{ title: __('HTTP', 'seoproanalytics'), num: true },
		{ title: __('Findings', 'seoproanalytics') },
		{ title: __('As served', 'seoproanalytics') },
		{ title: __('Stored in WordPress now', 'seoproanalytics') },
		{ title: __('Links failing', 'seoproanalytics'), num: true },
		{ title: __('Links redirecting', 'seoproanalytics'), num: true },
		{ title: __('Links in (served)', 'seoproanalytics'), num: true },
		{ title: __('Links in (body)', 'seoproanalytics'), num: true },
		{ title: __('Depth', 'seoproanalytics'), num: true },
		{ title: __('As the crawler measured', 'seoproanalytics') },
		{ title: __('Field TTFB / LCP', 'seoproanalytics') },
		{ title: __('Visits / conversions', 'seoproanalytics') },
		{ title: __('Search clicks', 'seoproanalytics'), num: true },
	];
	return (
		<CrawlTable label={__('Pages with a problem', 'seoproanalytics')} columns={columns} refreshing={refreshing}>
			{rows.map((row) => (
				<tr key={row.path_id}>
					<td>
						{row.url ? <ExternalLink href={row.url}>{row.path}</ExternalLink> : row.path}
						{row.flags.length > 0 && <span className="seoproan-meta">{sprintf(/* translators: %s: what the page served, comma-separated. */ __('Served: %s', 'seoproanalytics'), row.flags.map(flagName).join(', '))}</span>}
						{row.redirect && <span className="seoproan-meta">{sprintf(/* translators: %s: an address. */ __('Redirects to %s', 'seoproanalytics'), row.redirect)}</span>}
						{row.prev_status !== null && row.prev_status !== row.status && (
							<span className="seoproan-meta">{sprintf(/* translators: %s: an HTTP status. */ __('Was %s in the import before', 'seoproanalytics'), statusText(row.prev_status))}</span>
						)}
					</td>
					<td className="num">{row.answer === 'error' || row.answer === 'none' ? <strong className="seoproan-cause is-gone">{statusText(row.status)}</strong> : statusText(row.status)}</td>
					<td>
						{row.findings.length > 0 ? (
							<ul className="seoproan-decay__list">
								{row.findings.map((f) => (
									<li key={f}>
										<strong className={`seoproan-cause${f === 'crawl_error' || f.startsWith('served_') ? ' is-gone' : ''}`}>{findingName(f)}</strong>
									</li>
								))}
							</ul>
						) : (
							'–'
						)}
					</td>
					<td><Metadata title={row.title} description={row.description} />{row.h1 !== null && <span className="seoproan-meta">{sprintf(/* translators: %s: H1 count. */ __('H1s as served: %s', 'seoproanalytics'), number(row.h1))}</span>}</td>
					<td><Metadata title={row.stored_title} description={row.stored_description} /></td>
					<td className="num">{number(row.broken)}</td>
					<td className="num">{number(row.redirects)}</td>
					<td className="num">{row.links_in === null ? '–' : number(row.links_in)}</td>
					<td className="num">{row.links_in_body === null ? '–' : number(row.links_in_body)}</td>
					<td className="num">{row.depth === null ? '–' : number(row.depth)}</td>
					<td>{row.response_ms ? `${number(row.response_ms)} ms` : '–'}<span className="seoproan-meta">{row.bytes ? `${number(row.bytes)} HTML bytes` : '–'}</span></td>
					<td>{row.field.map((sample) => <span className="seoproan-meta" key={`${sample.form_factor}:${sample.metric}`}>{`${sample.form_factor} ${sample.metric.toUpperCase()}: ${number(sample.p75)} ms (${sample.status})`}</span>)}</td>
					<td className="num">{row.visits === undefined ? '–' : `${number(row.visits)} / ${number(row.conversions ?? 0)}`}</td>
					<td className="num">{row.clicks === undefined ? '–' : number(row.clicks)}</td>
				</tr>
			))}
		</CrawlTable>
	);
}

/** Null is unknown/discarded, not an empty observed title or description. */
function Metadata({ title, description }: Readonly<{ title: string | null; description: string | null }>) {
	const text = (value: string | null) => value === null ? __('Not observed or not kept', 'seoproanalytics') : value === '' ? __('Empty', 'seoproanalytics') : value;
	return <><span className="seoproan-meta"><strong>{__('Title:', 'seoproanalytics')}</strong> {text(title)}</span><span className="seoproan-meta"><strong>{__('Description:', 'seoproanalytics')}</strong> {text(description)}</span></>;
}

/** Where a link sits on the page. */
function placementName(placement: CrawlLinkRow['placement']): string {
	const names: Record<CrawlLinkRow['placement'], string> = {
		navigation: __('Navigation', 'seoproanalytics'),
		footer: __('Footer', 'seoproanalytics'),
		body: __('Text', 'seoproanalytics'),
		image: __('Image', 'seoproanalytics'),
		sidebar: __('Sidebar', 'seoproanalytics'),
		other: '–',
	};
	return names[placement];
}

function LinksTable({ rows, refreshing }: Readonly<{ rows: CrawlLinkRow[]; refreshing: boolean }>) {
	const columns: Column[] = [{ title: __('On page', 'seoproanalytics') }, { title: __('Links to', 'seoproanalytics') }, { title: __('HTTP', 'seoproanalytics'), num: true }, { title: __('Where', 'seoproanalytics') }];
	return (
		<CrawlTable label={__('Links that fail, redirect or are blocked', 'seoproanalytics')} columns={columns} refreshing={refreshing}>
			{rows.map((row) => (
				<tr key={`${row.from}|${row.target}`}>
					<td>{row.from_url ? <ExternalLink href={row.from_url}>{row.from}</ExternalLink> : row.from}</td>
					<td>
						<span className="seoproan-crawl__address">{row.target}</span>
						{row.blocked && <span className="seoproan-meta">{__('Blocked: the linked site refused the crawler; check by hand.', 'seoproanalytics')}</span>}
						{row.anchor && <span className="seoproan-meta">{sprintf(/* translators: %s: a link's text. */ __('Text: %s', 'seoproanalytics'), row.anchor)}</span>}
						{row.hops > 0 && <span className="seoproan-meta">{sprintf(/* translators: %s: number of redirects. */ _n('%s redirect', '%s redirects', row.hops, 'seoproanalytics'), number(row.hops))}</span>}
					</td>
					<td className="num">{row.failed ? <strong className="seoproan-cause is-gone">{statusText(row.status)}</strong> : statusText(row.status)}</td>
					<td>{placementName(row.placement)}</td>
				</tr>
			))}
		</CrawlTable>
	);
}

function ClickedTable({ rows, refreshing }: Readonly<{ rows: CrawlClickedRow[]; refreshing: boolean }>) {
	const columns: Column[] = [{ title: __('Destination', 'seoproanalytics') }, { title: __('Crawl', 'seoproanalytics') }, { title: __('Clicks', 'seoproanalytics'), num: true }, { title: __('Clicked on', 'seoproanalytics') }];
	return (
		<CrawlTable label={__('Clicked links that fail', 'seoproanalytics')} columns={columns} refreshing={refreshing}>
			{rows.map((row) => (
				<tr key={row.target}>
					<td>
						<span className="seoproan-crawl__address">{row.target}</span>
						<span className="seoproan-meta">{row.affiliate ? __('Affiliate link', 'seoproanalytics') : __('Outbound link', 'seoproanalytics')}</span>
					</td>
					<td>
						<strong className="seoproan-cause is-gone">{crawlStatusText(row.status)}</strong>
						{row.hops > 0 && <span className="seoproan-meta">{sprintf(/* translators: %s: redirect count. */ __('Redirects: %s', 'seoproanalytics'), number(row.hops))}</span>}
						{row.redirect && <span className="seoproan-meta">{row.redirect}</span>}
					</td>
					<td className="num">{number(row.clicks)}</td>
					<td>
						{row.pages.join(', ') || '–'}
						{row.linked_on.length > 0 && <span className="seoproan-meta">{sprintf(/* translators: %s: pages, comma-separated. */ __('Linked on: %s', 'seoproanalytics'), row.linked_on.join(', '))}</span>}
					</td>
				</tr>
			))}
		</CrawlTable>
	);
}
