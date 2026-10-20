/**
 * Time-series chart on uPlot. Points are placed by index, with labels the
 * caller makes in the site's time zone (uPlot's time axis would use the
 * browser's), so a comparison period lines up point for point.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import uPlot from 'uplot';
import 'uplot/dist/uPlot.min.css';

export interface ChartSeries {
	/** Name in the tooltip, e.g. "Visitors" or "Previous period". */
	label: string;
	values: Array<number | null>;
	color: string;
	dashed?: boolean;
	/** Shade the area under the line. */
	fill?: boolean;
	/** Each point's own label in the tooltip (a comparison's own dates). */
	pointLabels?: string[];
	/** Typical period's pointwise minimum and maximum, behind its line. */
	band?: { low: number[]; high: number[] };
	/**
	 * Index of the first point still being counted (today, this hour): the
	 * line into it and on from it is dotted, so a low number reads as
	 * unfinished rather than a drop.
	 */
	partialFrom?: number;
	/** The tooltip's figure, when not the chart's own (an average of counts keeps its fraction). */
	formatValue?: (value: number) => string;
	/**
	 * Colour the line by another one it crosses (a short-term average against
	 * a long-term one): `above` where its value is higher, `below` where it
	 * is lower, split where the two cross, and `color` faded where either is missing.
	 */
	cross?: CrossColors;
}

export interface CrossColors {
	/** The line it is read against, by index. */
	values: Array<number | null>;
	above: string;
	below: string;
}

/** A stretch of a crossing line, in point indexes: higher, lower, or not comparable (null). */
export interface CrossRun {
	from: number;
	to: number;
	side: 'above' | 'below' | null;
}

/** The side of a difference: zero takes the other end's side. */
function side(difference: number): 'above' | 'below' | null {
	if (difference > 0) {
		return 'above';
	}
	return difference < 0 ? 'below' : null;
}

/**
 * Where a line is above or below another, as runs between point indexes;
 * a segment whose ends are on opposite sides splits where the two cross
 * (straight lines between points, as drawn). Adjacent runs on one side merge.
 */
export function crossRuns(values: Array<number | null>, reference: Array<number | null>): CrossRun[] {
	const runs: CrossRun[] = [];
	const push = (from: number, to: number, which: CrossRun['side']) => {
		const last = runs[runs.length - 1];
		if (last && last.side === which && last.to === from) {
			last.to = to;
		} else {
			runs.push({ from, to, side: which });
		}
	};
	for (let i = 0; i + 1 < values.length; i++) {
		const a = values[i];
		const b = values[i + 1];
		const ra = reference[i];
		const rb = reference[i + 1];
		if (a === null || a === undefined || b === null || b === undefined || ra === null || ra === undefined || rb === null || rb === undefined) {
			push(i, i + 1, null);
			continue;
		}
		const da = a - ra;
		const db = b - rb;
		const start = side(da) ?? side(db) ?? 'above';
		const end = side(db) ?? start;
		if (start === end) {
			push(i, i + 1, start);
		} else {
			const at = i + da / (da - db);
			push(i, at, start);
			push(at, i + 1, end);
		}
	}
	return runs;
}

/** The colour of a crossing line at a point: by its side there, else the line's own. */
function crossColorAt(s: ChartSeries, index: number): string {
	const cross = s.cross;
	const value = s.values[index];
	const reference = cross?.values[index];
	if (!cross || value === null || value === undefined || reference === null || reference === undefined) {
		return s.color;
	}
	const which = side(value - reference);
	if (which === null) {
		return s.color;
	}
	return which === 'above' ? cross.above : cross.below;
}

/**
 * A crossing line's stroke: a gradient across the plot with hard stops where
 * the two lines cross, so each stretch is drawn in its side's colour.
 */
function crossStroke(u: uPlot, s: ChartSeries | undefined): CanvasRenderingContext2D['strokeStyle'] {
	if (!s?.cross) {
		return s?.color ?? '';
	}
	const left = u.bbox.left;
	const width = u.bbox.width;
	const runs = crossRuns(s.values, s.cross.values);
	if (!(width > 0) || runs.length === 0) {
		return s.color;
	}
	const gradient = u.ctx.createLinearGradient(left, 0, left + width, 0);
	const offset = (index: number) => Math.min(1, Math.max(0, (u.valToPos(index, 'x', true) - left) / width));
	const colors = { above: s.cross.above, below: s.cross.below };
	// Faded where there is nothing to compare, so it is not read as the other line.
	const faded = alpha(s.color, 0.45);
	for (const run of runs) {
		const color = run.side ? colors[run.side] : faded;
		gradient.addColorStop(offset(run.from), color);
		gradient.addColorStop(offset(run.to), color);
	}
	return gradient;
}

export interface TimeseriesConfig {
	/** Axis label of each point, by index. */
	labels: string[];
	series: ChartSeries[];
	height: number;
	formatValue: (value: number) => string;
	/** Counts use whole-number gridlines; rates and averages keep fractional steps. */
	integer?: boolean;
	/**
	 * A rank (average position): lower is better, so the axis runs from the
	 * best at the top down, fitted to the values rather than from zero.
	 */
	invert?: boolean;
	/** Colour of axis text and grid lines. */
	axisColor: string;
	gridColor: string;
	/** Called after each draw (data, size), e.g. to place a markers lane. */
	onDraw?: () => void;
}

export interface TimeseriesChart {
	update: (config: TimeseriesConfig) => void;
	resize: (width: number) => void;
	/** A point's x position in client pixels; null outside the points. */
	clientX: (index: number) => number | null;
	/** Show a thin vertical line at a point (null hides it). */
	guide: (index: number | null) => void;
	destroy: () => void;
}

/** Whether a series has a partial tail inside its points. */
function hasPartial(s: ChartSeries, length: number): s is ChartSeries & { partialFrom: number } {
	return s.partialFrom !== undefined && s.partialFrom > 0 && s.partialFrom < length;
}

function alpha(color: string, opacity: number): string {
	const hex = color.trim().replace('#', '');
	if (/^[0-9a-f]{6}$/i.test(hex)) {
		const n = Number.parseInt(hex, 16);
		return `rgba(${(n >> 16) & 255}, ${(n >> 8) & 255}, ${n & 255}, ${opacity})`;
	}
	return color;
}

/** A tooltip that follows the cursor, listing each series at that point. */
function tooltipPlugin(getConfig: () => TimeseriesConfig): uPlot.Plugin {
	let tip: HTMLDivElement;
	return {
		hooks: {
			init: (u: uPlot) => {
				tip = document.createElement('div');
				tip.className = 'seoproan-chart-tip';
				tip.setAttribute('aria-hidden', 'true');
				tip.style.display = 'none';
				u.over.appendChild(tip);
				u.over.addEventListener('mouseleave', () => {
					tip.style.display = 'none';
				});
			},
			setCursor: (u: uPlot) => {
				const idx = u.cursor.idx;
				if (idx === null || idx === undefined) {
					tip.style.display = 'none';
					return;
				}
				const config = getConfig();
				tip.textContent = '';
				config.series.forEach((s) => {
					const value = s.values[idx];
					const row = document.createElement('div');
					row.className = 'seoproan-chart-tip__row';
					const swatch = document.createElement('span');
					swatch.className = 'seoproan-chart-tip__swatch' + (s.dashed ? ' is-dashed' : '');
					swatch.style.borderColor = crossColorAt(s, idx);
					const when = document.createElement('span');
					when.className = 'seoproan-chart-tip__when';
					when.textContent = s.pointLabels?.[idx] ?? config.labels[idx] ?? '';
					const what = document.createElement('strong');
					what.textContent = value === null || value === undefined ? '—' : (s.formatValue ?? config.formatValue)(value);
					if (s.band && s.band.low[idx] !== undefined && s.band.high[idx] !== undefined) {
						what.textContent += ` (${config.formatValue(s.band.low[idx]!)}–${config.formatValue(s.band.high[idx]!)})`;
					}
					row.append(swatch, when, what);
					tip.appendChild(row);
				});
				tip.style.display = 'block';
				const left = u.cursor.left ?? 0;
				const width = u.over.clientWidth;
				// Keep the tip inside the plot: right of the cursor, or left near the edge.
				const flip = left > width - tip.offsetWidth - 16;
				tip.style.left = `${flip ? left - tip.offsetWidth - 12 : left + 12}px`;
				tip.style.top = '8px';
			},
		},
	};
}

/** A rank's axis: the values with a tenth's margin each side, never above first place. */
function rankRange(min: number | null, max: number | null): uPlot.Range.MinMax {
	if (min === null || max === null || !Number.isFinite(min) || !Number.isFinite(max)) {
		return [1, 10];
	}
	const pad = Math.max((max - min) * 0.1, 0.5);
	return [Math.max(1, min - pad), max + pad];
}

function options(config: TimeseriesConfig, width: number, getConfig: () => TimeseriesConfig): uPlot.Options {
	const font = '12px -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif';
	let index = 1;
	const bands: uPlot.Band[] = [];
	// Each uPlot series' own series in the config (none for x).
	const owners: Array<number | null> = [null];
	config.series.forEach((series, k) => {
		if (series.band) {
			bands.push({ series: [index, index + 1], fill: alpha(series.color, 0.12) });
			index += 2;
			owners.push(k, k);
		}
		const lines = hasPartial(series, config.labels.length) ? 2 : 1;
		index += lines;
		owners.push(...Array<number>(lines).fill(k));
	});
	// The hover dot in the line's colour there (a crossing line's gradient is no CSS colour).
	const pointColor = (u: uPlot, seriesIdx: number): string => {
		const k = owners[seriesIdx];
		const s = k === null || k === undefined ? undefined : getConfig().series[k];
		return s ? crossColorAt(s, u.cursor.idx ?? 0) : '';
	};
	return {
		width,
		height: config.height,
		legend: { show: false },
		bands,
		cursor: {
			y: false,
			points: { size: 7, fill: pointColor, stroke: pointColor },
			drag: { x: false, y: false, setScale: false },
		},
		scales: {
			x: { time: false },
			y: config.invert ? {
				// Best rank at the top.
				dir: -1,
				range: (_u, min, max) => rankRange(min, max),
			} : {
				range: (_u, _min, max) => [0, max > 0 ? max * 1.1 : 1],
			},
		},
		axes: [
			{
				stroke: config.axisColor,
				font,
				grid: { show: false },
				ticks: { show: false },
				space: 64,
				incrs: [1, 2, 3, 4, 5, 6, 7, 10, 12, 14, 15, 24, 28, 30, 48, 60, 90, 120, 180, 365],
				values: (_u, splits) => splits.map((i) => (Number.isInteger(i) ? getConfig().labels[i] ?? '' : '')),
			},
			{
				stroke: config.axisColor,
				font,
				size: 56,
				grid: { stroke: config.gridColor, width: 1 },
				ticks: { show: false },
				incrs: config.integer ? Array.from({ length: 16 }, (_, i) => [1, 2, 5].map((n) => n * 10 ** i)).flat() : undefined,
				values: (_u, splits) => splits.map((v) => getConfig().formatValue(v)),
			},
		],
		series: [
			{},
			...config.series.flatMap((s, k) => {
				const bounds: uPlot.Series[] = s.band ? [
					{ stroke: s.color, width: 0, points: { show: false } },
					{ stroke: s.color, width: 0, points: { show: false } },
				] : [];
				const line: uPlot.Series = {
					label: s.label,
					// Read at each draw, so the crossings follow the data and the plot's size.
					stroke: s.cross ? (u) => crossStroke(u, getConfig().series[k]) : s.color,
					width: s.dashed ? 1.5 : 2,
					dash: s.dashed ? [5, 4] : undefined,
					fill: s.fill ? alpha(s.color, 0.12) : undefined,
					// An inverted axis shades down to the worst rank shown, under the line as it is drawn.
					fillTo: config.invert ? (u) => u.scales.y?.max ?? 0 : undefined,
					points: { show: false },
					spanGaps: false,
				};
				if (!hasPartial(s, config.labels.length)) {
					return [...bounds, line];
				}
				// The unfinished tail: a second, dotted line with a lighter fill.
				return [...bounds, line, { ...line, dash: [2, 4], fill: s.fill ? alpha(s.color, 0.05) : undefined }];
			}),
		],
		plugins: [tooltipPlugin(getConfig), { hooks: { draw: () => getConfig().onDraw?.() } }],
	};
}

function data(config: TimeseriesConfig): uPlot.AlignedData {
	const xs = config.labels.map((_, i) => i);
	const columns = config.series.flatMap((s) => {
		const values = xs.map((i) => s.values[i] ?? null);
		const bounds = s.band ? [xs.map((i) => s.band!.high[i] ?? null), xs.map((i) => s.band!.low[i] ?? null)] : [];
		if (!hasPartial(s, xs.length)) {
			return [...bounds, values];
		}
		// Finished points solid; the tail dotted from the last finished point on.
		const from = s.partialFrom;
		return [...bounds, values.map((v, i) => (i < from ? v : null)), values.map((v, i) => (i >= from - 1 ? v : null))];
	});
	return [xs, ...columns] as uPlot.AlignedData;
}

/** Draw a chart into an element; update it with new data, resize with the element. */
export function createTimeseries(el: HTMLElement, initial: TimeseriesConfig): TimeseriesChart {
	let config = initial;
	const getConfig = () => config;
	let plot = new uPlot(options(config, el.clientWidth || 600, getConfig), data(config), el);
	let guide: HTMLDivElement | null = null;

	return {
		update(next) {
			const shape = next.integer !== config.integer || next.invert !== config.invert || next.series.length !== config.series.length || next.series.some((s, i) => {
				const was = config.series[i];
				const length = next.labels.length;
				return was?.color !== s.color || was.dashed !== s.dashed || was.fill !== s.fill || Boolean(was.band) !== Boolean(s.band) || Boolean(was.cross) !== Boolean(s.cross) || hasPartial(was, config.labels.length) !== hasPartial(s, length);
			});
			config = next;
			if (shape || next.height !== plot.height) {
				// Series changed: make the chart again (cheap; uPlot is small).
				const width = plot.width;
				plot.destroy();
				guide = null;
				plot = new uPlot(options(config, width, getConfig), data(config), el);
			} else {
				plot.setData(data(config));
			}
		},
		resize(width) {
			if (width > 0 && width !== plot.width) {
				plot.setSize({ width, height: config.height });
			}
		},
		clientX(index) {
			if (index < 0 || index >= config.labels.length) {
				return null;
			}
			const x = plot.valToPos(index, 'x');
			return Number.isFinite(x) ? plot.over.getBoundingClientRect().left + x : null;
		},
		guide(index) {
			if (index === null || index < 0 || index >= config.labels.length) {
				if (guide) {
					guide.style.display = 'none';
				}
				return;
			}
			if (!guide) {
				guide = document.createElement('div');
				guide.className = 'seoproan-chart-guide';
				plot.over.appendChild(guide);
			}
			guide.style.left = `${plot.valToPos(index, 'x')}px`;
			guide.style.display = 'block';
		},
		destroy() {
			plot.destroy();
			guide = null;
		},
	};
}
