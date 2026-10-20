/**
 * Trailing averages for a chart's trend line: each point's average over
 * the window that ends at it. Rates are weighted (CTR and position by
 * impressions, bounce rate by visits), so a quiet day counts as little as
 * it weighs. A point has no average until the data covers its whole
 * window, so the line never starts on a half-empty window.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

/** A chart's points: an hour, a day, a week or a month each. */
export type AverageGrain = 'hour' | 'day' | 'week' | 'month';

/** Trend windows offered, in days: 1, 4, 13 and 52 weeks. */
export const TREND_WINDOWS = [7, 28, 91, 364] as const;

export type TrendWindow = (typeof TREND_WINDOWS)[number];

/** A trend: a short-term average read against a long-term one. */
export type TrendMode = 'short' | 'long';

/**
 * Each trend's windows in days, short-term then long-term: Short is a week
 * against a quarter, Long a month against a year. At a coarse grain the
 * short-term window moves up to the next one that holds two points.
 */
export const TREND_PAIRS: Readonly<Record<TrendMode, readonly [TrendWindow, TrendWindow]>> = {
	short: [7, 91],
	long: [28, 364],
};

export interface AveragePoint {
	/** The point's start, in milliseconds. */
	time: number;
	/** Null: not counted (unfinished, or no figure). */
	value: number | null;
	/** The value's weight (impressions, visits); left out: every point weighs one. */
	weight?: number;
}

const HOUR = 3600 * 1000;
const DAY = 24 * HOUR;

/** How many points a window of days holds at a grain (a month is 1/12 of 52 weeks). */
export function windowPoints(days: number, grain: AverageGrain): number {
	switch (grain) {
		case 'hour':
			return days * 24;
		case 'week':
			return Math.round(days / 7);
		case 'month':
			return Math.round((days * 12) / 364);
		default:
			return days;
	}
}

/** Whether a window is worth a line at a grain: it covers two points or more. */
export function trendFits(days: number, grain: AverageGrain): boolean {
	return windowPoints(days, grain) >= 2;
}

/** A point's weight: its own, or one; nothing without a value. */
function weightOf(point: AveragePoint): number {
	if (point.value === null || !Number.isFinite(point.value)) {
		return 0;
	}
	return point.weight ?? 1;
}

/** The weighted mean of points [from, to]; null when nothing weighs. */
function mean(points: AveragePoint[], from: number, to: number): number | null {
	let sum = 0;
	let weights = 0;
	for (let j = from; j <= to; j++) {
		const point = points[j];
		const weight = point ? weightOf(point) : 0;
		if (point && weight > 0) {
			sum += (point.value ?? 0) * weight;
			weights += weight;
		}
	}
	return weights > 0 ? sum / weights : null;
}

/**
 * Each point's trailing average over `days`. Weeks and months go by point
 * count (they are regular); hours and days by time, half a step's leeway
 * either side, so a daylight-saving change or a missing sample neither adds
 * nor drops a day. Data starts at the first point that has some (weight,
 * or value without weights): zeros before it are not history.
 */
export function trailingAverage(points: AveragePoint[], days: number, grain: AverageGrain): Array<number | null> {
	const first = points.findIndex((p) => p.value !== null && (p.weight ?? p.value) > 0);
	if (first < 0) {
		return points.map(() => null);
	}
	if (grain === 'week' || grain === 'month') {
		const count = windowPoints(days, grain);
		return points.map((_, i) => (i - count + 1 >= first ? mean(points, i - count + 1, i) : null));
	}
	const step = grain === 'hour' ? HOUR : DAY;
	const span = days * DAY;
	const start = points[first]?.time ?? 0;
	let from = 0;
	return points.map((point, i) => {
		const edge = point.time - span + step / 2;
		while (from < i && (points[from]?.time ?? 0) <= edge) {
			from++;
		}
		// The data must reach back to the window's first step.
		return start < edge + step ? mean(points, from, i) : null;
	});
}
