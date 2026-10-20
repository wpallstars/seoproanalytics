/**
 * Search → Audit, Bing's crawl: the addresses Bing Webmaster Tools reports
 * crawl issues on (errors first), its crawl by day, the sitemaps Bing has
 * and the URL submission quota. Read once a day in the hourly import while
 * Bing Webmaster Tools is connected. Bing only.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { useState } from 'react';
import { Card, CardBody, CardHeader, ExternalLink, Notice, SelectControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { CRAWL_ISSUES, formatNumber, type BingSitemap, type CrawlIssue, type CrawlIssueRow, type CrawlIssuesAnswer } from '@seoproanalytics/core';
import { useCrawlIssues } from './api';
import { locale } from './boot';
import { crawlIssueName } from './Audit';
import { longLabel } from './dates';
import { LoadState, Pager } from './components/Pager';
import { TableScroll } from './components/TableScroll';

const PER_PAGE = 25;

const number = (value: number) => formatNumber(value, locale, false);
const day = (iso: string | null) => (iso ? longLabel(iso.slice(0, 10), 'day') : '–');

export function CrawlIssues() {
	const [issue, setIssue] = useState<CrawlIssue | 'errors' | ''>('');
	const [at, setAt] = useState({ issue, offset: 0 });
	const offset = at.issue === issue ? at.offset : 0;
	const setOffset = (next: number) => setAt({ issue, offset: next });
	const query = useCrawlIssues(issue, PER_PAGE, offset);
	const answer = query.data;
	const rows = answer?.rows ?? [];
	const listed = answer ? CRAWL_ISSUES.reduce((sum, name) => sum + (answer.counts[name] > 0 ? 1 : 0), 0) : 0;

	return (
		<Card className="seoproan-app-card is-wide seoproan-app-section" size="small">
			<CardHeader className="seoproan-app-card__header">
				<h2 className="seoproan-app-card__title">{__('Bing’s crawl', 'seoproanalytics')}</h2>
				{answer && listed > 0 && (
					<div className="seoproan-changes__filters seoproan-plan__filters">
						<SelectControl
							__nextHasNoMarginBottom
							label={__('Show', 'seoproanalytics')}
							value={issue}
							options={[
								{ value: '', label: __('Every issue', 'seoproanalytics') },
								{ value: 'errors', label: `${__('Errors', 'seoproanalytics')} (${number(answer.counts.errors)})` },
								...CRAWL_ISSUES.filter((name) => answer.counts[name] > 0).map((name) => ({ value: name, label: `${crawlIssueName(name)} (${number(answer.counts[name])})` })),
							]}
							onChange={(next: string) => setIssue(next as CrawlIssue | 'errors' | '')}
						/>
					</div>
				)}
			</CardHeader>
			<CardBody className="seoproan-app-card__body">
				<LoadState
					query={query}
					failed={__('Bing’s crawl could not be loaded. Reload the page to try again.', 'seoproanalytics')}
					unconnected={answer && !answer.connected ? __('Connect Bing Webmaster Tools on the Connections tab to see how Bing crawls the site.', 'seoproanalytics') : undefined}
				/>
				{answer?.connected && (
					<>
						{answer.error && (
							<Notice status="warning" isDismissible={false} className="seoproan-notice">
								{sprintf(/* translators: %s: Bing's message. */ __('Bing’s site data could not all be read: %s', 'seoproanalytics'), answer.error)}
							</Notice>
						)}
						<p className="seoproan-note seoproan-opportunities__intro">
							{__(
								'Addresses Bing reports a crawl issue on, errors first, then those with most links to them. Errors on published pages are also Audit findings and Plan items. Redirects are listed but are not errors.',
								'seoproanalytics'
							)}
						</p>
						{!rows.length && (
							<div className="seoproan-app-empty">
								<p>{emptyText(answer)}</p>
							</div>
						)}
						{rows.length > 0 && <IssuesTable rows={rows} refreshing={query.isFetching} />}
						<Pager offset={offset} perPage={PER_PAGE} total={answer.total} more={answer.more} label={__('Pages of Bing’s crawl issues', 'seoproanalytics')} onChange={setOffset} />
						<CrawlStats answer={answer} />
						{answer.sitemaps.length > 0 && <SitemapsTable rows={answer.sitemaps} />}
						<Notes answer={answer} />
					</>
				)}
			</CardBody>
		</Card>
	);
}

/** With no rows: not read yet, nothing with the issue chosen, or nothing found. */
function emptyText(answer: CrawlIssuesAnswer): string {
	if (!answer.read) {
		return __('Bing’s site data has not been read yet; it is read once a day in the hourly import.', 'seoproanalytics');
	}
	return answer.issue ? __('No address has this issue.', 'seoproanalytics') : __('Bing reports no crawl issue on the site.', 'seoproanalytics');
}

function IssuesTable({ rows, refreshing }: Readonly<{ rows: CrawlIssueRow[]; refreshing: boolean }>) {
	return (
		<TableScroll label={__('Bing’s crawl issues', 'seoproanalytics')}>
			<table className={`widefat striped seoproan-table seoproan-decay${refreshing ? ' is-refreshing' : ''}`}>
				<thead>
					<tr>
						<th scope="col">{__('Address', 'seoproanalytics')}</th>
						<th scope="col" className="num">
							{__('HTTP', 'seoproanalytics')}
						</th>
						<th scope="col">{__('Issues', 'seoproanalytics')}</th>
						<th scope="col" className="num">
							{__('Links to it', 'seoproanalytics')}
						</th>
					</tr>
				</thead>
				<tbody>
					{rows.map((row) => (
						<tr key={row.url}>
							<td>{/^https?:\/\//.test(row.url) ? <ExternalLink href={row.url}>{row.path || row.url}</ExternalLink> : row.url}</td>
							<td className="num">{row.http > 0 ? row.http : '–'}</td>
							<td>
								<ul className="seoproan-decay__list">
									{row.issues.map((name) => (
										<li key={name}>
											<strong className={`seoproan-cause${row.error && !name.startsWith('redirect_') ? ' is-gone' : ''}`}>{crawlIssueName(name)}</strong>
										</li>
									))}
								</ul>
							</td>
							<td className="num">{number(row.inlinks)}</td>
						</tr>
					))}
				</tbody>
			</table>
		</TableScroll>
	);
}

/** Bing's crawl over its newest days: pages crawled, errors and pages in its index. */
function CrawlStats({ answer }: Readonly<{ answer: CrawlIssuesAnswer }>) {
	const days = answer.stats.slice(-14).reverse();
	if (!days.length) {
		return null;
	}
	return (
		<>
			<h3 className="seoproan-subtitle">{__('Crawl by day', 'seoproanalytics')}</h3>
			<TableScroll label={__('Bing’s crawl by day', 'seoproanalytics')}>
				<table className="widefat striped seoproan-table seoproan-decay">
					<thead>
						<tr>
							<th scope="col">{__('Day', 'seoproanalytics')}</th>
							{[__('Crawled', 'seoproanalytics'), __('Errors', 'seoproanalytics'), __('Server errors', 'seoproanalytics'), __('In Bing’s index', 'seoproanalytics')].map((label) => (
								<th key={label} scope="col" className="num">
									{label}
								</th>
							))}
						</tr>
					</thead>
					<tbody>
						{days.map((d) => (
							<tr key={d.day}>
								<td>{longLabel(d.day, 'day')}</td>
								<td className="num">{number(d.crawled)}</td>
								<td className="num">{d.errors ? <strong className="seoproan-cause is-gone">{number(d.errors)}</strong> : number(0)}</td>
								<td className="num">{number(d.code_5xx)}</td>
								<td className="num">{number(d.in_index)}</td>
							</tr>
						))}
					</tbody>
				</table>
			</TableScroll>
		</>
	);
}

function SitemapsTable({ rows }: Readonly<{ rows: BingSitemap[] }>) {
	return (
		<>
			<h3 className="seoproan-subtitle">{__('Sitemaps in Bing Webmaster Tools', 'seoproanalytics')}</h3>
			<TableScroll label={__('Sitemaps in Bing Webmaster Tools', 'seoproanalytics')}>
				<table className="widefat striped seoproan-table seoproan-decay">
					<thead>
						<tr>
							<th scope="col">{__('Sitemap', 'seoproanalytics')}</th>
							<th scope="col">{__('Status', 'seoproanalytics')}</th>
							<th scope="col">{__('Submitted', 'seoproanalytics')}</th>
							<th scope="col">{__('Last read by Bing', 'seoproanalytics')}</th>
							<th scope="col" className="num">
								{__('Addresses', 'seoproanalytics')}
							</th>
						</tr>
					</thead>
					<tbody>
						{rows.map((row) => (
							<tr key={row.url}>
								<td>
									{/^https?:\/\//.test(row.url) ? <ExternalLink href={row.url}>{row.url}</ExternalLink> : row.url}
									{row.type && <span className="seoproan-meta">{row.type}</span>}
								</td>
								<td>{row.status || '–'}</td>
								<td>{day(row.submitted)}</td>
								<td>{day(row.crawled)}</td>
								<td className="num">{number(row.urls)}</td>
							</tr>
						))}
					</tbody>
				</table>
			</TableScroll>
		</>
	);
}

/** Notes under the lists: when read, the quota and the weekly links read. */
function Notes({ answer }: Readonly<{ answer: CrawlIssuesAnswer }>) {
	const notes: string[] = [];
	if (answer.read) {
		/* translators: %s: a day. */
		notes.push(sprintf(__('Read from Bing on %s; read again once a day.', 'seoproanalytics'), day(answer.read)));
	}
	if (answer.quota) {
		notes.push(
			sprintf(
				/* translators: 1: addresses a day, 2: addresses a month. */
				__('URL submission quota: %1$s addresses a day, %2$s a month.', 'seoproanalytics'),
				number(answer.quota.daily),
				number(answer.quota.monthly)
			)
		);
	}
	const l = answer.links;
	if (l.running) {
		/* translators: %s: number of links. */
		notes.push(sprintf(__('Bing’s inbound links are being read into Backlinks: %s so far.', 'seoproanalytics'), number(l.links)));
	} else if (l.read) {
		notes.push(
			sprintf(
				/* translators: 1: number of links, 2: a day. */
				__('%1$s inbound links Bing knows were read into Backlinks on %2$s; read again once a week.', 'seoproanalytics'),
				number(l.links),
				day(l.read)
			)
		);
	}
	if (l.error) {
		/* translators: %s: Bing's message. */
		notes.push(sprintf(__('The links read stopped: %s It carries on in the next hourly import.', 'seoproanalytics'), l.error));
	}
	if (!notes.length) {
		return null;
	}
	return (
		<div className="seoproan-note">
			{notes.map((note) => (
				<p key={note}>{note}</p>
			))}
		</div>
	);
}
