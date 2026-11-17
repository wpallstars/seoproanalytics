/**
 * Speed: how fast pages are for real visitors (Settings → Tracking →
 * Measure page speed for visitors). Each metric's 75th percentile as a
 * tile with its share of good, needs-improvement and poor page loads, then
 * by device and by page. Optionally on one page. Never shared.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import type { ReactNode } from 'react';
import { Button, Card, CardBody, Notice } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import { formatNumber, formatPercent } from '@seoproanalytics/core';
import { errorMessage, useBreakdown, useSpeed, type SpeedFigures, type SpeedMetric, type SpeedRating } from './api';
import { locale } from './boot';
import type { ViewProps } from './App';
import { Change } from './components/Change';
import { PageCardHeader, PageFilter } from './components/PageFilter';
import { TableScroll } from './components/TableScroll';

const METRIC_ORDER: readonly SpeedMetric[] = ['lcp', 'inp', 'cls', 'fcp', 'ttfb'];

function metricName(metric: SpeedMetric): string {
	switch (metric) {
		case 'lcp':
			return __('Largest contentful paint', 'seoproanalytics');
		case 'inp':
			return __('Interaction to next paint', 'seoproanalytics');
		case 'cls':
			return __('Layout shift', 'seoproanalytics');
		case 'fcp':
			return __('First contentful paint', 'seoproanalytics');
		default:
			return __('Time to first byte', 'seoproanalytics');
	}
}

function ratingName(rating: SpeedRating): string {
	if (rating === 'good') {
		return __('Good', 'seoproanalytics');
	}
	if (rating === 'poor') {
		return __('Poor', 'seoproanalytics');
	}
	return rating === 'ni' ? __('Needs improvement', 'seoproanalytics') : '';
}

/** A metric's value as people read it: 350 ms, 2.4 s, or 0.12 for layout shift. */
export function speedValue(metric: SpeedMetric, value: number | null | undefined): string {
	if (value === null || value === undefined) {
		return '–';
	}
	if (metric === 'cls') {
		return (value / 1000).toLocaleString(locale, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
	}
	if (value >= 1000) {
		/* translators: %s: seconds. */
		return sprintf(__('%s s', 'seoproanalytics'), (value / 1000).toLocaleString(locale, { maximumFractionDigits: 1 }));
	}
	/* translators: %s: milliseconds. */
	return sprintf(__('%s ms', 'seoproanalytics'), formatNumber(value, locale));
}

/** (now - then) / then, or null. */
function changeOf(now: number | null, then: number | null | undefined): number | null {
	return now === null || !then ? null : (now - then) / then;
}

/** The good, needs-improvement and poor shares as one bar. */
function Shares({ figures }: Readonly<{ figures: SpeedFigures }>) {
	if (!figures.n) {
		return null;
	}
	const title = sprintf(
		/* translators: 1: share of good page loads, 2: needing improvement, 3: poor. */
		__('Good %1$s · needs improvement %2$s · poor %3$s', 'seoproanalytics'),
		formatPercent(figures.good, locale),
		formatPercent(figures.ni, locale),
		formatPercent(figures.poor, locale),
	);
	return (
		<span className="seoproan-speed-bar" title={title} role="img" aria-label={title}>
			<span className="is-good" style={{ width: `${figures.good * 100}%` }} />
			<span className="is-ni" style={{ width: `${figures.ni * 100}%` }} />
			<span className="is-poor" style={{ width: `${figures.poor * 100}%` }} />
		</span>
	);
}

/** A value with its rating's colour. */
function Rated({ metric, value, rating }: Readonly<{ metric: SpeedMetric; value: number | null | undefined; rating: SpeedRating | null | undefined }>) {
	return (
		<span className={`seoproan-speed-value${rating ? ` is-${rating}` : ''}`} title={ratingName(rating ?? '')}>
			{speedValue(metric, value)}
		</span>
	);
}

export function Speed({ state, update }: Readonly<ViewProps>) {
	const page = state.page ?? '';
	const query = useSpeed(state, page, 25);
	const pages = useBreakdown(state, 'page', 100);
	const answer = query.data;
	const totals = answer?.totals;
	const before = answer?.compare?.totals;

	return (
		<>
			{query.isError && (
				<Notice status="error" isDismissible={false}>
					{errorMessage(query.error, __('Page speed could not be loaded. Reload the page to try again.', 'seoproanalytics'))}
				</Notice>
			)}
			{answer && !answer.enabled && (
				<Notice status="info" isDismissible={false}>
					{__('Page speed is measured for visitors when "Measure page speed for visitors" is on (Settings → Tracking). It adds no request: the figures go with the visit\'s engagement.', 'seoproanalytics')}
				</Notice>
			)}

			<Card className="seoproan-app-card is-wide seoproan-app-section" size="small">
				<PageCardHeader
					title={page ? sprintf(/* translators: %s: a page path. */ __('Page speed on %s', 'seoproanalytics'), page) : __('Page speed for real visitors', 'seoproanalytics')}
					range={answer?.range}
					compare={answer?.compare?.range}
					filter={
						<PageFilter
							page={page}
							suggestions={(pages.data?.rows ?? []).map((r) => r.value).filter((value) => value !== '')}
							onApply={(value) => update({ page: value })}
						/>
					}
				/>

				<div className="seoproan-tiles">
					{METRIC_ORDER.map((metric) => {
						const figures = totals?.[metric];
						return (
							<div key={metric} className="seoproan-tile">
								<span className="seoproan-tile__label">{metricName(metric)}</span>
								<span className="seoproan-tile__value">
									<Rated metric={metric} value={figures?.p75} rating={figures?.rating} />
								</span>
								<span className="seoproan-tile__foot">
									<span className="seoproan-muted">
										{figures
											? sprintf(
													/* translators: %s: number of page loads. */
													_n('%s page load', '%s page loads', figures.n, 'seoproanalytics'),
													formatNumber(figures.n, locale),
												)
											: ''}
									</span>
									{figures && before && <Change change={changeOf(figures.p75, before[metric]?.p75)} better="down" previous={speedValue(metric, before[metric]?.p75)} />}
								</span>
								{figures && <Shares figures={figures} />}
							</div>
						);
					})}
				</div>

				<CardBody className="seoproan-app-card__body">
					{!answer && !query.isError && <div className="seoproan-skeleton seoproan-skeleton--table" aria-busy="true" />}
					{answer && totals && !totals.loads && (
						<div className="seoproan-app-empty">
							<p>{__('No page loads measured in this period.', 'seoproanalytics')}</p>
						</div>
					)}
					{answer && totals && totals.loads > 0 && (
						<>
							<SpeedTable
								caption={__('By device', 'seoproanalytics')}
								first={__('Device', 'seoproanalytics')}
								rows={(['desktop', 'mobile', 'tablet'] as const)
									.filter((device) => answer.devices[device])
									.map((device) => {
										const summary = answer.devices[device]!;
										return {
											key: device,
											name: <th scope="row">{deviceName(device)}</th>,
											loads: summary.loads,
											values: METRIC_ORDER.map((metric) => [summary[metric].p75, summary[metric].rating] as const),
										};
									})}
							/>
							{answer.pages.length > 0 && (
								<SpeedTable
									caption={__('Pages with the most page loads', 'seoproanalytics')}
									first={__('Page', 'seoproanalytics')}
									fetching={query.isFetching}
									rows={answer.pages.map((row) => ({
										key: row.path,
										selected: page === row.path,
										name: (
											<td>
												<Button variant="link" aria-pressed={page === row.path} onClick={() => update({ page: page === row.path ? '' : row.path })}>
													{row.path}
												</Button>
											</td>
										),
										loads: row.loads,
										values: METRIC_ORDER.map((metric) => [row[metric], row[`${metric}_rating`]] as const),
									}))}
								/>
							)}
						</>
					)}
					{answer && (
						<p className="seoproan-note">
							{sprintf(
								/* translators: %s: share of page loads measured, such as 100%. */
								__('Measured in %s of page loads, by browsers that report it (largest contentful paint, interaction to next paint and layout shift are not reported by every browser). Figures are the 75th percentile: three in four page loads were at least this fast. Good and poor follow Google\'s Core Web Vitals thresholds.', 'seoproanalytics'),
								formatPercent((answer.sample || 0) / 100, locale),
							)}
						</p>
					)}
				</CardBody>
			</Card>
		</>
	);
}

function deviceName(device: 'desktop' | 'mobile' | 'tablet'): string {
	if (device === 'mobile') {
		return __('Mobile', 'seoproanalytics');
	}
	return device === 'tablet' ? __('Tablet', 'seoproanalytics') : __('Desktop', 'seoproanalytics');
}

function MetricHeads() {
	return (
		<>
			<th scope="col" className="num">
				{__('Page loads', 'seoproanalytics')}
			</th>
			{METRIC_ORDER.map((metric) => (
				<th key={metric} scope="col" className="num">
					<abbr title={metricName(metric)}>{metric.toUpperCase()}</abbr>
				</th>
			))}
		</>
	);
}

interface SpeedRow {
	key: string;
	/** The first cell: a th for a device, a td with a link for a page. */
	name: ReactNode;
	loads: number;
	/** p75 and rating of each of METRIC_ORDER, in that order. */
	values: readonly (readonly [number | null | undefined, SpeedRating | null | undefined])[];
	selected?: boolean;
}

/** Rows of page speed: by device, or the pages with the most page loads. */
function SpeedTable({ caption, first, rows, fetching = false }: Readonly<{ caption: string; first: string; rows: SpeedRow[]; fetching?: boolean }>) {
	return (
		<>
		<h3 className="seoproan-subtitle">{caption}</h3>
		<TableScroll label={caption}>
			<table className={`widefat striped seoproan-table${fetching ? ' is-refreshing' : ''}`}>
				<thead>
					<tr>
						<th scope="col">{first}</th>
						<MetricHeads />
					</tr>
				</thead>
				<tbody>
					{rows.map((row) => (
						<tr key={row.key} className={row.selected ? 'is-selected' : undefined}>
							{row.name}
							<td className="num">{formatNumber(row.loads, locale)}</td>
							{METRIC_ORDER.map((metric, i) => (
								<td key={metric} className="num">
									<Rated metric={metric} value={row.values[i]?.[0]} rating={row.values[i]?.[1]} />
								</td>
							))}
						</tr>
					))}
				</tbody>
			</table>
		</TableScroll>
		</>
	);
}
