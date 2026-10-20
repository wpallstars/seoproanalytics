/**
 * The chosen metric over time, with the comparison period as a dashed
 * line, and the changes in the range in a markers lane under it, with
 * search engine updates' rollouts as bars. The chart
 * is drawn for sight; a table carries the same numbers and changes for
 * screen readers, and the lane's markers are buttons.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { useEffect, useId, useMemo, useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	createMarkersLane,
	createTimeseries,
	trailingAverage,
	trendFits,
	TREND_PAIRS,
	TREND_WINDOWS,
	type ChartSeries,
	type TrendMode,
	type MarkersLane,
	type MarkersLaneConfig,
	type TimeseriesChart,
	type TimeseriesConfig,
} from '@seoproanalytics/charts';
import { formatMetric, type ChartData, type Grain, type Marker, type MetricFormat } from '@seoproanalytics/core';
import type { ChartHistory } from '../api';
import { locale } from '../boot';
import { changesByPoint, chartMarkers, chartSpans, groupColors } from '../changelog';
import { axisLabel, longLabel } from '../dates';
import { compareLabel } from '../labels';
import type { MarkerPick } from './ChangesModal';

interface Props<K extends string> {
	/** Visits (timeseries) or search (the search report's points). */
	series: ChartData<{ t: string } & Record<K, number>>;
	metric: K;
	/** The metric's name and format. */
	label: string;
	format: MetricFormat;
	height?: number;
	/** Changes in the range, for the markers lane; none: no lane. */
	markers?: Marker[];
	/** A marker chosen: the changes it covers and their days. */
	onMarker?: (pick: MarkerPick) => void;
	/** A rank (average position): first place at the top; 0 (no rank) is a gap. */
	rank?: boolean;
	/** A rate's count (visits, impressions), which weighs it in the trend lines. */
	per?: string;
	/**
	 * Which way is better, for the short-term trend's colour: green while it
	 * is better than the long-term one. Left out: down for a rank, else up.
	 */
	better?: 'up' | 'down';
	/**
	 * The same report over earlier days, so the trend line's first points
	 * average whole windows; left out: the series holds all the history
	 * there is (Page experience).
	 */
	history?: ChartHistory;
}

/** The trends in order of preference: the default is the first this grain has room for. */
const TREND_MODES: TrendMode[] = ['short', 'long'];

/**
 * The trend shown: the one chosen on this chart ('' for none) when this
 * grain has room for it, else the default, the first that fits (Short, or
 * Long where Short does not fit, as on monthly charts).
 */
function activeTrend<T extends { mode: TrendMode }>(modes: T[], chosen: TrendMode | '' | null): T | null {
	if (chosen === '') {
		return null;
	}
	return modes.find((m) => m.mode === chosen) ?? modes[0] ?? null;
}

/** Trend windows that suit a grain: two points or more, and no more than a week of hours. */
function trendWindows(grain: Grain): number[] {
	return TREND_WINDOWS.filter((days) => trendFits(days, grain) && (grain !== 'hour' || days <= 7));
}

/** A trend's two windows in days. */
interface TrendPair {
	short: number;
	long: number;
}

/**
 * A trend's windows at a grain: its long-term window, if it suits the grain,
 * and the first window from its short-term one up that suits the grain and
 * is shorter (a week of weeks is one point: 4 weeks instead). Null when none.
 */
function trendPair(mode: TrendMode, grain: Grain): TrendPair | null {
	const fits = trendWindows(grain);
	const [short, long] = TREND_PAIRS[mode];
	const first = fits.find((days) => days >= short && days < long);
	return fits.includes(long) && first ? { short: first, long } : null;
}

/** A trend's name and tooltip: "Short: 1-week average against 13-week average". */
function trendTitle(mode: TrendMode, pair: TrendPair): string {
	return sprintf(
		/* translators: 1: "Short" or "Long", 2: the short-term average, e.g. "1-week average", 3: the long-term one, e.g. "13-week average". */
		__('%1$s: %2$s against %3$s', 'seoproanalytics'),
		trendModeLabel(mode),
		trendLabel(pair.short),
		trendLabel(pair.long)
	);
}

function trendModeLabel(mode: TrendMode): string {
	return mode === 'short' ? __('Short', 'seoproanalytics') : __('Long', 'seoproanalytics');
}

/** A trend's name: "4-week average". */
function trendLabel(days: number): string {
	const weeks = days / 7;
	/* translators: %s: number of weeks the trend line averages, e.g. 4. */
	return sprintf(_n('%s-week average', '%s-week average', weeks, 'seoproanalytics'), weeks.toLocaleString(locale));
}

/** A day (YYYY-MM-DD) moved by some days. */
function addDays(day: string, days: number): string {
	const date = new Date(`${day}T00:00:00Z`);
	date.setUTCDate(date.getUTCDate() + days);
	return date.toISOString().slice(0, 10);
}

/** Days of lead-in beyond the window: one more point, so its first average is whole. */
const LEAD_STEP: Record<Grain, number> = { hour: 1, day: 1, week: 7, month: 31 };

/**
 * The days a trend's history covers: the window (and a point) before the
 * first point, to the period's last day (its end is midnight after it, or
 * now while today is in it).
 */
function historyDays(series: ChartData, days: number): { from: string; to: string } {
	const first = (series.points[0]?.t ?? series.range.from).slice(0, 10);
	const [day = '', time = ''] = series.range.to.split('T');
	return { from: addDays(first, -(days + LEAD_STEP[series.grain])), to: time.startsWith('00:00:00') ? addDays(day, -1) : day };
}

/** A point's figure: a rank of 0 (no impressions) is none. */
function chartFigure(value: number | undefined, rank: boolean): number | null {
	if (value === undefined || !Number.isFinite(value)) {
		return null;
	}
	return rank && value <= 0 ? null : value;
}

/**
 * The trend line over the points shown: averages over the source (the
 * history, or the series itself), lined up on the last point shown.
 * Unfinished points are left out. Null when the two do not line up.
 */
function trendValues(source: ChartData, shown: ChartData, metric: string, per: string | undefined, rank: boolean, days: number): Array<number | null> | null {
	const partial = partialIndex(source);
	const figure = (p: { t: string }, key: string) => (p as unknown as Record<string, number | undefined>)[key];
	const averages = trailingAverage(
		source.points.map((p, i) => ({
			time: Date.parse(p.t),
			value: i === partial ? null : chartFigure(figure(p, metric), rank),
			weight: per ? figure(p, per) ?? 0 : undefined,
		})),
		days,
		source.grain
	);
	if (partial !== undefined) {
		averages[partial] = null;
	}
	const last = shown.points[shown.points.length - 1]?.t;
	const end = source.points.length - 1 - [...source.points].reverse().findIndex((p) => p.t === last);
	if (end >= source.points.length) {
		return null;
	}
	const start = end - shown.points.length + 1;
	return shown.points.map((_, i) => averages[start + i] ?? null);
}

/**
 * The days some points cover (site time zone, as the points' times carry
 * it): a month's run to its last day, but not past the answer's day; a
 * week's to the day before the next point (or six days on).
 */
function pointDays(series: ChartData, indexes: number[]): { from: string; to: string } {
	const first = Math.min(...indexes);
	const last = Math.max(...indexes);
	const from = (series.points[first]?.t ?? '').slice(0, 10);
	let to = (series.points[last]?.t ?? '').slice(0, 10);
	if (series.grain === 'week' && to) {
		const next = (series.points[last + 1]?.t ?? '').slice(0, 10);
		const end = new Date(`${next || to}T00:00:00Z`);
		end.setUTCDate(end.getUTCDate() + (next ? -1 : 6));
		to = end.toISOString().slice(0, 10);
	}
	if (series.grain === 'month' && to) {
		const [y, m] = to.split('-').map(Number);
		to = new Date(Date.UTC(y ?? 1970, m ?? 1, 0)).toISOString().slice(0, 10);
		const today = (series.generated ?? '').slice(0, 10);
		if (today && today < to) {
			to = today;
		}
	}
	return { from, to };
}

/**
 * A computed colour the regexes don't read (oklch(), lab()) as sRGB
 * channels: painted on a one-pixel canvas, which converts it.
 */
function paintedChannels(computed: string): number[] | undefined {
	const context = document.createElement('canvas').getContext('2d', { willReadFrequently: true });
	if (!context) {
		return undefined;
	}
	context.fillStyle = '#000';
	context.fillStyle = computed;
	context.fillRect(0, 0, 1, 1);
	return Array.from(context.getImageData(0, 0, 1, 1).data.slice(0, 3));
}

/**
 * A CSS colour as #rrggbb, which the chart can fade for its area. Plain hex
 * passes through; anything else (the lighter accent in dark mode, a named
 * colour) is resolved by the browser on a hidden probe inside the element.
 */
function hexColor(el: HTMLElement, value: string, fallback: string): string {
	if (/^#[0-9a-f]{6}$/i.test(value)) {
		return value;
	}
	const probe = document.createElement('span');
	probe.style.color = value;
	if (!value || !probe.style.color) {
		return fallback;
	}
	probe.style.display = 'none';
	el.appendChild(probe);
	const computed = getComputedStyle(probe).color;
	probe.remove();
	const rgb = /^rgba?\(\s*([\d.]+)[,\s]+([\d.]+)[,\s]+([\d.]+)/.exec(computed);
	const srgb = rgb ? null : /^color\(srgb\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)/.exec(computed);
	const channels = rgb ? rgb.slice(1, 4).map(Number) : srgb?.slice(1, 4).map((v) => Number(v) * 255) ?? paintedChannels(computed);
	if (!channels || channels.some((v) => Number.isNaN(v))) {
		return fallback;
	}
	return `#${channels.map((v) => Math.round(Math.min(255, Math.max(0, v))).toString(16).padStart(2, '0')).join('')}`;
}

/**
 * The accent: the screen's (lighter in dark mode, a report's own on a
 * shared report), else the admin colour scheme's.
 */
function themeColor(el: HTMLElement): string {
	const styles = getComputedStyle(el);
	const value = styles.getPropertyValue('--seoproan-accent').trim() || styles.getPropertyValue('--wp-admin-theme-color').trim();
	return hexColor(el, value, '#2271b1');
}

/**
 * Index of the point still being counted: the one whose period holds the
 * answer's time (today, this hour, this month). Times carry the site's
 * offset, so they compare as instants. Undefined when the range is over.
 */
export function partialIndex(series: ChartData): number | undefined {
	const now = Date.parse(series.generated ?? '') || Date.now();
	const points = series.points;
	for (let i = points.length - 1; i >= 0; i--) {
		const start = Date.parse(points[i]?.t ?? '');
		const end = Date.parse(i + 1 < points.length ? points[i + 1]?.t ?? '' : series.range.to);
		if (start <= now && now < end) {
			return i;
		}
		if (start <= now) {
			return undefined;
		}
	}
	return undefined;
}

/** A point's long label, marked "so far" while it is still being counted. */
function pointLabel(t: string, grain: ChartData['grain'], partial: boolean): string {
	const label = longLabel(t, grain);
	/* translators: %s: a day, hour or month still in progress, e.g. "Tue 6 Oct 2026". */
	return partial ? sprintf(__('%s (so far)', 'seoproanalytics'), label) : label;
}

/** Typical counts are averages, so do not round their quarter visits away. */
function chartValue(value: number, format: MetricFormat, typical: boolean): string {
	return typical && format === 'number' ? new Intl.NumberFormat(locale, { maximumFractionDigits: 2 }).format(value) : formatMetric(value, format, locale);
}

/** Click-through rate, average position and views per visit move in tenths, so their trends keep one decimal place. */
const TREND_TENTHS = new Set(['ctr', 'position', 'views_per_visit']);

/** A trend line's figure: whole numbers, or one decimal place for click-through rate, average position and views per visit. */
function trendValue(value: number, format: MetricFormat, metric: string): string {
	const digits = TREND_TENTHS.has(metric) ? 1 : 0;
	if (format === 'duration') {
		return formatMetric(value, format, locale);
	}
	const style = format === 'percent' ? 'percent' : 'decimal';
	return new Intl.NumberFormat(locale, { style, minimumFractionDigits: digits, maximumFractionDigits: digits }).format(value);
}

/**
 * A line and the colour token it takes: the accent, the comparison's grey,
 * or the text colour for the long-term trend. The short-term trend is the
 * faded text colour where it cannot be compared, and good or bad against `against`.
 */
type Painted = ChartSeries & { paint: 'main' | 'compare' | 'trend'; against?: Array<number | null> };

/** A chart's trend lines: its history's request, and both averages over the points shown. */
function useTrendLines(series: ChartData, metric: string, per: string | undefined, rank: boolean, pair: TrendPair | null, history: ChartHistory | undefined) {
	// One lead-in serves both: the long window's.
	const days = pair?.long ?? 0;
	const span = days > 0 && history ? historyDays(series, days) : null;
	// Weeks are of a page or query of a weekly engine: days ask for them again.
	const grain: Grain = series.grain === 'week' ? 'day' : series.grain;
	const lead = useQuery({
		queryKey: ['chart-history', ...(history?.key ?? []), span?.from, span?.to, grain],
		queryFn: () => (history && span ? history.load(span.from, span.to, grain) : Promise.reject(new Error('No history'))),
		enabled: Boolean(history && span),
		staleTime: 5 * 60 * 1000,
	});
	const source = history ? lead.data : series;
	const shortDays = pair?.short ?? 0;
	const lines = useMemo(() => {
		if (days <= 0 || !source || source.grain !== series.grain) {
			return null;
		}
		const shown = (values: Array<number | null> | null) => (values?.some((v) => v !== null) ? values : null);
		return {
			short: shown(trendValues(source, series, metric, per, rank, shortDays)),
			long: shown(trendValues(source, series, metric, per, rank, days)),
		};
	}, [source, series, metric, per, rank, days, shortDays]);
	return {
		short: lines?.short ?? null,
		long: lines?.long ?? null,
		loading: Boolean(history && span && lead.isPending),
		failed: Boolean(history && span && lead.isError),
	};
}

/** Why a trend chosen draws less than both lines: failed, or too little history for one. */
function trendNote(pair: TrendPair | null, trend: ReturnType<typeof useTrendLines>): string {
	if (!pair || trend.loading) {
		return '';
	}
	if (trend.failed) {
		return __('The trend lines could not be loaded.', 'seoproanalytics');
	}
	// The long window needs more history, so it is the one missing first.
	let missing = 0;
	if (!trend.short) {
		missing = pair.short;
	} else if (!trend.long) {
		missing = pair.long;
	}
	/* translators: %s: a trend's name, e.g. "52-week average". */
	return missing ? sprintf(__('Not enough history yet for a %s.', 'seoproanalytics'), trendLabel(missing)) : '';
}

export function MainChart<K extends string>({ series, metric, label, format, height = 260, markers, onMarker, rank = false, per, better, history }: Readonly<Props<K>>) {
	const holder = useRef<HTMLDivElement>(null);
	const laneHolder = useRef<HTMLDivElement>(null);
	const chart = useRef<TimeseriesChart | null>(null);
	const lane = useRef<MarkersLane | null>(null);
	const partial = useMemo(() => partialIndex(series), [series]);
	const byPoint = useMemo(() => changesByPoint(series, markers ?? []), [series, markers]);
	const pick = useRef(onMarker);
	pick.current = onMarker;
	const trendId = useId();
	// Null until someone chooses here. Kept only while this chart is shown,
	// never saved: leaving the section and coming back starts on the
	// default again, so a choice made for one look is not left behind.
	const [chosen, choose] = useState<TrendMode | '' | null>(null);
	// The trends this grain has room for (hours: none; months: Long only).
	const modes = TREND_MODES.flatMap((mode) => {
		const pair = trendPair(mode, series.grain);
		return pair ? [{ mode, pair }] : [];
	});
	const active = activeTrend(modes, chosen);
	const pair = active?.pair ?? null;
	const trend = useTrendLines(series, metric, per, rank, pair, history);
	const note = trendNote(pair, trend);
	const shortDays = pair?.short ?? 0;
	const longDays = pair?.long ?? 0;
	const goodWay = better ?? (rank ? 'down' : 'up');

	// Place the lane's markers where the chart draws its points.
	const layoutLane = () => {
		const current = chart.current;
		if (current) {
			lane.current?.layout((i) => current.clientX(i));
		}
	};

	const config = useMemo((): Omit<TimeseriesConfig, 'axisColor' | 'gridColor' | 'series'> & { series: Painted[] } => {
		const grain = series.grain;
		// Colours are filled in where the element's styles can be read.
		const figure = (p: Record<K, number>) => chartFigure(p[metric], rank);
		const list: Painted[] = [
			{
				label,
				values: series.points.map(figure),
				color: '',
				paint: 'main',
				fill: true,
				pointLabels: series.points.map((p, i) => pointLabel(p.t, grain, i === partial)),
				partialFrom: partial,
			},
		];
		if (series.compare) {
			const compare = series.compare;
			list.push({
				label: compareLabel(compare.range.key),
				values: compare.points.map(figure),
				color: '',
				paint: 'compare',
				dashed: true,
				pointLabels: compare.points.map((p) => compare.periods ? compareLabel('typical') : longLabel(p.t, grain)),
				band: compare.low && compare.high ? { low: compare.low.map((p) => p[metric]), high: compare.high.map((p) => p[metric]) } : undefined,
			});
		}
		const average = (values: Array<number | null>, days: number, against?: Array<number | null>): Painted => {
			const name = trendLabel(days);
			return {
				label: name,
				values,
				color: '',
				paint: 'trend',
				against,
				pointLabels: series.points.map(() => name),
				formatValue: (v: number) => trendValue(v, format, metric),
			};
		};
		// The long-term line under the short-term one, which is coloured by it.
		if (longDays && trend.long) {
			list.push(average(trend.long, longDays));
		}
		if (shortDays && trend.short) {
			list.push(average(trend.short, shortDays, trend.long ?? undefined));
		}
		return {
			labels: series.points.map((p) => axisLabel(p.t, grain)),
			series: list,
			height,
			integer: format === 'number' && !series.compare?.periods,
			invert: rank,
			formatValue: (v: number) => chartValue(v, format, Boolean(series.compare?.periods)),
		};
	}, [series, metric, label, height, format, partial, rank, trend.short, trend.long, shortDays, longDays]);

	// The palette changes without the data: a shared report's light/dark
	// switch, or wp-admin's colour mode (admin/js/seoproanalytics-theme.js).
	const [palette, setPalette] = useState(0);
	useEffect(() => {
		const repaint = () => setPalette((n) => n + 1);
		const report = holder.current?.closest('.seoproan-report');
		const observer = report ? new MutationObserver(repaint) : null;
		if (report) {
			observer?.observe(report, { attributes: true, attributeFilter: ['class'] });
		}
		document.addEventListener('seoproan-themechange', repaint);
		return () => {
			observer?.disconnect();
			document.removeEventListener('seoproan-themechange', repaint);
		};
	}, []);

	useEffect(() => {
		const el = holder.current;
		if (!el) {
			return;
		}
		const styles = getComputedStyle(el);
		const colors = {
			main: themeColor(el),
			compare: hexColor(el, styles.getPropertyValue('--seoproan-compare').trim(), '#8c8f94'),
			trend: hexColor(el, styles.getPropertyValue('--seoproan-text').trim(), '#1d2327'),
		};
		const good = hexColor(el, styles.getPropertyValue('--seoproan-good').trim(), '#007017');
		const bad = hexColor(el, styles.getPropertyValue('--seoproan-bad').trim(), '#b32d2e');
		const full: TimeseriesConfig = {
			...config,
			series: config.series.map(({ paint, against, ...s }) => ({
				...s,
				color: colors[paint],
				// Green while the short-term average is better than the long-term one.
				cross: against ? { values: against, above: goodWay === 'up' ? good : bad, below: goodWay === 'up' ? bad : good } : undefined,
			})),
			axisColor: styles.getPropertyValue('--seoproan-muted').trim() || '#50575e',
			gridColor: styles.getPropertyValue('--seoproan-grid').trim() || 'rgba(0, 0, 0, 0.06)',
			onDraw: layoutLane,
		};
		if (chart.current) {
			chart.current.update(full);
		} else {
			chart.current = createTimeseries(el, full);
		}
		// layoutLane reads refs only.
	}, [config, palette, goodWay]);

	useEffect(() => {
		const el = laneHolder.current;
		if (!el) {
			return;
		}
		const colors = groupColors(el);
		const laneConfig: MarkersLaneConfig = {
			markers: chartMarkers(byPoint, colors),
			spans: chartSpans(series, markers ?? [], colors),
			label: __('Changes', 'seoproanalytics'),
			pointLabel: (i) => (series.points[i] ? longLabel(series.points[i].t, series.grain) : ''),
			/* translators: %s: number of changes not listed. */
			moreText: (n) => sprintf(_n('and %s more', 'and %s more', n, 'seoproanalytics'), n.toLocaleString(locale)),
			onSelect: (indexes) => {
				const sorted = [...indexes].sort((a, b) => a - b);
				const label = (i: number) => laneConfig.pointLabel(i);
				const first = label(sorted[0] ?? 0);
				const last = label(sorted[sorted.length - 1] ?? 0);
				pick.current?.({
					markers: sorted.flatMap((i) => byPoint.get(i) ?? []),
					...pointDays(series, sorted),
					when: first === last ? first : `${first} – ${last}`,
				});
			},
			onHover: (i) => chart.current?.guide(i),
		};
		if (lane.current) {
			lane.current.update(laneConfig);
		} else {
			lane.current = createMarkersLane(el, laneConfig);
		}
		layoutLane();
	}, [byPoint, series, markers, palette]);

	useEffect(() => {
		const el = holder.current;
		if (!el || typeof ResizeObserver === 'undefined') {
			return;
		}
		const observer = new ResizeObserver((entries) => {
			const width = Math.floor(entries[0]?.contentRect.width ?? 0);
			chart.current?.resize(width);
		});
		observer.observe(el);
		return () => {
			observer.disconnect();
			lane.current?.destroy();
			lane.current = null;
			chart.current?.destroy();
			chart.current = null;
		};
	}, []);

	const compare = series.compare;
	// The table's average columns, short-term first.
	const averages = [
		{ days: shortDays, values: trend.short },
		{ days: longDays, values: trend.long },
	].filter((a): a is { days: number; values: Array<number | null> } => a.days > 0 && a.values !== null);
	return (
		<figure className="seoproan-chart">
			{modes.length > 0 && (
				<div className="seoproan-chart__tools">
					{note && <span className="seoproan-chart__note">{note}</span>}
					<span className="seoproan-chart__trend-label" id={trendId}>
						{__('Trend', 'seoproanalytics')}
					</span>
					<div className="seoproan-tabs seoproan-chart__trend" role="group" aria-labelledby={trendId}>
						<button
							type="button"
							className={`seoproan-tab${active ? '' : ' is-active'}`}
							aria-pressed={!active}
							title={__('No trend lines', 'seoproanalytics')}
							onClick={() => choose('')}
						>
							{__('Off', 'seoproanalytics')}
						</button>
						{modes.map(({ mode, pair: windows }) => (
							<button
								key={mode}
								type="button"
								className={`seoproan-tab${active?.mode === mode ? ' is-active' : ''}`}
								aria-pressed={active?.mode === mode}
								aria-busy={active?.mode === mode && trend.loading}
								title={trendTitle(mode, windows)}
								onClick={() => choose(mode)}
							>
								<span aria-hidden="true">{trendModeLabel(mode)}</span>
								<span className="screen-reader-text">{trendTitle(mode, windows)}</span>
							</button>
						))}
					</div>
				</div>
			)}
			<div ref={holder} className="seoproan-chart__plot" aria-hidden="true" style={{ minHeight: height }} />
			{markers !== undefined && <div ref={laneHolder} />}
			<figcaption className="screen-reader-text">
				{label}
			</figcaption>
			<table className="screen-reader-text">
				<thead>
					<tr>
						<th scope="col">{__('Period', 'seoproanalytics')}</th>
						<th scope="col">{label}</th>
						{compare && <th scope="col">{compareLabel(compare.range.key)}</th>}
						{compare?.low && compare.high && <th scope="col">{__('Typical low–high', 'seoproanalytics')}</th>}
						{averages.map((a) => (
							<th key={a.days} scope="col">{trendLabel(a.days)}</th>
						))}
						{markers !== undefined && <th scope="col">{__('Changes', 'seoproanalytics')}</th>}
					</tr>
				</thead>
				<tbody>
					{series.points.map((p, i) => {
						const before = compare?.points[i];
						return (
							<tr key={p.t}>
								<th scope="row">{pointLabel(p.t, series.grain, i === partial)}</th>
								<td>{formatMetric(p[metric], format, locale)}</td>
								{compare && <td>{before ? chartValue(before[metric], format, Boolean(compare.periods)) : '—'}</td>}
								{compare?.low && compare.high && <td>{compare.low[i] && compare.high[i] ? `${formatMetric(compare.low[i]![metric], format, locale)}–${formatMetric(compare.high[i]![metric], format, locale)}` : '—'}</td>}
								{averages.map((a) => {
									const value = a.values[i];
									return <td key={a.days}>{typeof value === 'number' ? trendValue(value, format, metric) : '—'}</td>;
								})}
								{markers !== undefined && <td>{(byPoint.get(i) ?? []).map((m) => m.label).join('; ')}</td>}
							</tr>
						);
					})}
				</tbody>
			</table>
		</figure>
	);
}
