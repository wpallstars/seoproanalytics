/**
 * Overview: the period and the live count, headline metrics, the chart,
 * and where visits came from, what
 * they viewed (pages, pages not found, content by author, category and
 * type), what they searched for, where (with a world map) and on what,
 * and what they did (events): two cards a row on wide screens.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { Card, Notice } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { errorMessage, shareAccess, useMarkers, useStats, useTimeseries, useTimeseriesHistory } from './api';
import { filteredPage } from './changelog';
import { rangeText } from './dates';
import type { ViewProps } from './App';
import { BreakdownCard } from './components/BreakdownCard';
import { BusinessProfilesCard } from './components/BusinessProfilesCard';
import { useChangesModal } from './components/ChangesModal';
import { MainChart } from './components/MainChart';
import { Vitals } from './Vitals';
import { MetricTiles } from './components/MetricTiles';
import { Realtime, useRealtimeShown } from './components/Realtime';
import { WorldMap } from './components/WorldMap';
import { metricLabel } from './labels';
import { METRICS, type Marker } from '@seoproanalytics/core';

/** No changes (one list, so the chart is not redrawn for a new empty one). */
const NO_MARKERS: Marker[] = [];

export function Overview({ state, update }: Readonly<ViewProps>) {
	const stats = useStats(state);
	const series = useTimeseries(state);
	const history = useTimeseriesHistory(state);
	const markers = useMarkers(state, filteredPage(state.filters));
	const changes = useChangesModal(update, filteredPage(state.filters));
	const failed = (stats.isError && stats.error) || (series.isError && series.error) || null;
	const answer = stats.data;
	const empty = answer?.metrics.visits === 0;
	const realtime = useRealtimeShown();

	return (
		<>
			<Vitals chartOnly />
			{failed && (
				<Notice status="error" isDismissible={false}>
					{errorMessage(failed, __('The statistics could not be loaded. Reload the page to try again.', 'seoproanalytics'))}
				</Notice>
			)}

			<Card className="seoproan-summary">
				<div className="seoproan-summary__head">
					{answer && <PeriodLine range={answer.range} compare={answer.compare?.range} />}
					{realtime && <Realtime />}
				</div>
				<MetricTiles
					stats={answer}
					series={series.data}
					selected={state.metric}
					onSelect={(metric) => update({ metric })}
					loading={stats.isPending}
				/>
				<div className={`seoproan-summary__chart${series.isFetching && series.data ? ' is-refreshing' : ''}`}>
					{series.data ? (
						<MainChart
							series={series.data}
							metric={state.metric}
							label={metricLabel(state.metric)}
							format={METRICS[state.metric].format}
							per={METRICS[state.metric].per}
							better={METRICS[state.metric].better}
							markers={markers.data?.markers ?? NO_MARKERS}
							onMarker={changes.onMarker}
							history={history}
						/>
					) : (
						<div className={`seoproan-chart-placeholder${series.isError ? '' : ' seoproan-skeleton seoproan-skeleton--chart'}`} aria-busy={!series.isError} />
					)}
				</div>
			</Card>
			{changes.modal}

			{empty && (
				<Notice status="info" isDismissible={false} className="seoproan-notice">
					{state.filters.length
						? __('No visits match these filters in this period.', 'seoproanalytics')
						: __('No visits in this period yet. Visits show here within a minute or two of happening.', 'seoproanalytics')}
				</Notice>
			)}

			<div className="seoproan-grid">
				<BreakdownCard
					card="sources"
					title={__('Sources', 'seoproanalytics')}
					state={state}
					update={update}
					tabs={[
						{ dimension: 'channel', title: __('Channels', 'seoproanalytics') },
						{ dimension: 'source', title: __('Sources', 'seoproanalytics') },
						{ dimension: 'utm_campaign', title: __('Campaigns', 'seoproanalytics') },
					]}
				/>
				<BusinessProfilesCard state={state} update={update} />
				<BreakdownCard
					card="pages"
					title={__('Pages', 'seoproanalytics')}
					state={state}
					update={update}
					tabs={[
						{ dimension: 'page', title: __('Top pages', 'seoproanalytics') },
						{ dimension: 'entry', title: __('Entry pages', 'seoproanalytics') },
						{ dimension: 'exit', title: __('Exit pages', 'seoproanalytics') },
						{ dimension: 'not_found', title: __('Not found', 'seoproanalytics') },
					]}
				/>
				<BreakdownCard
					card="content"
					title={__('Content', 'seoproanalytics')}
					state={state}
					update={update}
					tabs={[
						{ dimension: 'author', title: __('Authors', 'seoproanalytics') },
						{ dimension: 'category', title: __('Categories', 'seoproanalytics') },
						{ dimension: 'post_type', title: __('Post types', 'seoproanalytics') },
					]}
				/>
				<BreakdownCard
					card="search"
					title={__('Site search', 'seoproanalytics')}
					state={state}
					update={update}
					tabs={[
						{ dimension: 'search', title: __('Searches', 'seoproanalytics') },
						{ dimension: 'no_results', title: __('No results', 'seoproanalytics') },
					]}
				/>
				<BreakdownCard
					card="locations"
					title={__('Locations', 'seoproanalytics')}
					state={state}
					update={update}
					tabs={[
						{ dimension: 'country', title: __('Countries', 'seoproanalytics') },
						{ dimension: 'language', title: __('Languages', 'seoproanalytics') },
					]}
				/>
				<WorldMap state={state} update={update} />
				<BreakdownCard
					card="devices"
					title={__('Devices', 'seoproanalytics')}
					state={state}
					update={update}
					tabs={[
						{ dimension: 'device', title: __('Devices', 'seoproanalytics') },
						{ dimension: 'browser', title: __('Browsers', 'seoproanalytics') },
						{ dimension: 'os', title: __('Systems', 'seoproanalytics') },
						{ dimension: 'login', title: __('Logged in', 'seoproanalytics') },
					]}
				/>
				<BreakdownCard
					card="events"
					title={shareAccess.token ? __('Events', 'seoproanalytics') : __('Events and A/B tests', 'seoproanalytics')}
					state={state}
					update={update}
					tabs={[
						{ dimension: 'event', title: __('Events', 'seoproanalytics') },
						// A/B tests are the owner's work in progress: not in shared reports.
						...(shareAccess.token ? [] : [{ dimension: 'variant' as const, title: __('A/B variants', 'seoproanalytics') }]),
					]}
				/>
			</div>
		</>
	);
}

/** The period shown, and the one it is compared with. */
export function PeriodLine({ range, compare }: Readonly<{ range: { from: string; to: string }; compare?: { from: string; to: string } }>) {
	return (
		<p className="seoproan-period">
			{rangeText(range.from, range.to)}
			{compare && (
				<span className="seoproan-period__compare">
					{sprintf(
						/* translators: %s: the comparison period's days, e.g. "1 Aug – 31 Aug 2026". */
						__('compared with %s', 'seoproanalytics'),
						rangeText(compare.from, compare.to)
					)}
				</span>
			)}
		</p>
	);
}
