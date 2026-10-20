/**
 * The days a period and its comparison cover, by the API's rules
 * (SEOProAnalytics_Query::range() and compare_range()), so a menu can name
 * them and leave out a comparison that does not suit the period. Days are
 * YYYY-MM-DD in the site's time zone; `to` is the day after the last.
 * Search reports end their periods at the newest search day, so their days
 * come from the answer, not from here.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { COMPARE_KEYS, type CompareKey, type RangeKey } from './types';

/** Days from `from` to the day before `to`. */
export interface DaySpan {
	from: string;
	to: string;
}

/** A comparison the menu offers, with its days when they are known. */
export interface CompareOption {
	key: CompareKey;
	days: DaySpan | null;
}

/** A year earlier on the same weekday: 52 weeks (SEOProAnalytics_Query::YEAR_WEEKS_DAYS). */
export const YEAR_WEEKS_DAYS = 364;

const DAY_MS = 86_400_000;

function parse(day: string): Date {
	return new Date(Date.UTC(Number(day.slice(0, 4)), Number(day.slice(5, 7)) - 1, Number(day.slice(8, 10))));
}

function format(date: Date): string {
	return date.toISOString().slice(0, 10);
}

function addDays(day: string, days: number): string {
	const date = parse(day);
	date.setUTCDate(date.getUTCDate() + days);
	return format(date);
}

/** As PHP's modify('-1 month'): a day the month does not have rolls over (29 February - 1 year → 1 March). */
function addMonths(day: string, months: number): string {
	const date = parse(day);
	date.setUTCMonth(date.getUTCMonth() + months);
	return format(date);
}

function daysBetween(from: string, to: string): number {
	return Math.round((parse(to).getTime() - parse(from).getTime()) / DAY_MS);
}

/** The same day a month earlier, or that month's last day when it is shorter (31 March → 28 February). */
function monthBack(day: string): string {
	const date = parse(day);
	const year = date.getUTCFullYear();
	const month = date.getUTCMonth();
	const last = new Date(Date.UTC(year, month, 0)).getUTCDate();
	return format(new Date(Date.UTC(year, month - 1, Math.min(date.getUTCDate(), last))));
}

/** The week of the month a day is in: 0 for the 1st to 7th, 1 for the 8th to 14th. */
function monthWeek(day: string): number {
	return Math.floor((Number(day.slice(8, 10)) - 1) / 7);
}

/**
 * Days back to the same weekday and week of the previous month: 28, or 35
 * when 28 days back is still this month or another week of the previous
 * one (SEOProAnalytics_Query::month_weeks_days()).
 */
function monthWeeksDays(day: string): number {
	const back = addDays(day, -28);
	return back.slice(0, 7) === monthBack(day).slice(0, 7) && monthWeek(back) === monthWeek(day) ? 28 : 35;
}

/** Today in a time zone: a name (Europe/London) or an offset (+01:00), as wp_timezone_string() gives. */
export function todayIn(timeZone: string, now: Date = new Date()): string {
	const offset = /^([+-])(\d{2}):(\d{2})$/.exec(timeZone);
	if (offset) {
		const minutes = (offset[1] === '-' ? -1 : 1) * (Number(offset[2]) * 60 + Number(offset[3]));
		return format(new Date(now.getTime() + minutes * 60_000));
	}
	try {
		const parts = new Intl.DateTimeFormat('en-CA', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(now);
		const part = (type: string) => parts.find((p) => p.type === type)?.value ?? '';
		return `${part('year')}-${part('month')}-${part('day')}`;
	} catch {
		return format(now);
	}
}

/**
 * A period's days, or null for the last 30 minutes and 24 hours (by the
 * clock), all time (from the first visit) and an unfinished custom range.
 * `startOfWeek` is WordPress's (0 Sunday to 6 Saturday).
 */
export function periodDays(range: RangeKey, today: string, startOfWeek = 1, custom: { from?: string; to?: string } = {}): DaySpan | null {
	const next = addDays(today, 1);
	switch (range) {
		case 'today':
			return { from: today, to: next };
		case 'yesterday':
			return { from: addDays(today, -1), to: today };
		case '12mo':
			return { from: addDays(addMonths(today, -12), 1), to: next };
		case 'week':
			return { from: addDays(today, -((parse(today).getUTCDay() - startOfWeek + 7) % 7)), to: next };
		case 'month':
			return { from: `${today.slice(0, 8)}01`, to: next };
		case 'year':
			return { from: `${today.slice(0, 5)}01-01`, to: next };
		case 'lastyear': {
			const jan = `${today.slice(0, 5)}01-01`;
			return { from: addMonths(jan, -12), to: jan };
		}
		case 'custom':
			return custom.from && custom.to ? { from: custom.from, to: addDays(custom.to, 1) } : null;
		case 'realtime':
		case '24h':
		case 'all':
			return null;
		default:
			// 7d, 28d, 30d, 90d, 91d, 182d, 364d: that many days to today.
			return { from: addDays(next, -Number.parseInt(range, 10)), to: next };
	}
}

/** Calendar ranges whose period before is the same part of the previous week, month or year. */
function calendarBack(range: RangeKey): ((day: string) => string) | null {
	switch (range) {
		case 'week':
			return (day) => addDays(day, -7);
		case 'month':
			return (day) => addMonths(day, -1);
		case 'year':
		case 'lastyear':
			return (day) => addMonths(day, -12);
		default:
			return null;
	}
}

/** The period before: for this week, month and year so far, the same part of the previous one. */
function previousDays(range: RangeKey, period: DaySpan): DaySpan {
	const back = calendarBack(range);
	if (back) {
		const to = back(period.to);
		return { from: back(period.from), to: to > period.from ? period.from : to };
	}
	return { from: addDays(period.from, -daysBetween(period.from, period.to)), to: period.from };
}

/** The days a comparison covers before any cut for a period still under way, or null for none. */
export function compareDays(range: RangeKey, period: DaySpan, compare: CompareKey): DaySpan | null {
	const back = (days: number): DaySpan => ({ from: addDays(period.from, -days), to: addDays(period.to, -days) });
	switch (compare) {
		case 'none':
			return null;
		case 'typical': {
			const periods = typicalPeriods(period);
			return periods.length === 4 ? { from: periods[3]!.from, to: periods[0]!.to } : null;
		}
		case 'week':
			return back(7);
		case 'month_weekday':
			return back(monthWeeksDays(period.from));
		case 'year_weekday':
			return back(YEAR_WEEKS_DAYS);
		case 'month': {
			const from = monthBack(period.from);
			return { from, to: addDays(from, daysBetween(period.from, period.to)) };
		}
		case 'year':
			return { from: addMonths(period.from, -12), to: addMonths(period.to, -12) };
		default:
			return previousDays(range, period);
	}
}

function sameDays(a: DaySpan | null, b: DaySpan): boolean {
	return a !== null && a.from === b.from && a.to === b.to;
}

/** Four earlier disjoint weekday-aligned periods, newest first (PHP typical_periods()). */
export function typicalPeriods(period: DaySpan): DaySpan[] {
	const days = daysBetween(period.from, period.to);
	if (days < 1 || days > 28) {
		return [];
	}
	const step = Math.ceil(days / 7) * 7;
	return Array.from({ length: 4 }, (_, i) => ({ from: addDays(period.from, -step * (i + 1)), to: addDays(period.to, -step * (i + 1)) }));
}

/**
 * The comparisons that suit a period, in menu order. No comparison and the
 * period before always; the others when they end before the period starts
 * and are not the same days as one already listed. Without days (the last
 * 30 minutes or 24 hours) all of them; all time has none to offer.
 */
export function compareOptions(range: RangeKey, period: DaySpan | null): CompareOption[] {
	if (range === 'all') {
		return [{ key: 'none', days: null }];
	}
	const options: CompareOption[] = [];
	for (const key of COMPARE_KEYS) {
		if (key === 'typical' && (!period || typicalPeriods(period).length === 0)) {
			continue;
		}
		const days = period ? compareDays(range, period, key) : null;
		const fits = !period || !days || key === 'prev' || (days.to <= period.from && !options.some((option) => sameDays(option.days, days)));
		if (fits) {
			options.push({ key, days });
		}
	}
	return options;
}

/** The comparison a period starts with: one day meets the same weekday a week earlier; longer periods the period before. */
export function defaultCompare(range: RangeKey): CompareKey {
	return range === 'today' || range === 'yesterday' ? 'week' : 'prev';
}

/**
 * The comparison after the period changes: a period's default moves to the
 * new period's default; a comparison chosen by hand stays while it suits
 * the new period (`offered`, from compareOptions()). All time keeps it, as
 * it compares with nothing.
 */
export function compareAfter(compare: CompareKey, was: RangeKey, range: RangeKey, offered: readonly CompareOption[]): CompareKey {
	if (range === 'all') {
		return compare;
	}
	const wanted = compare === defaultCompare(was) ? defaultCompare(range) : compare;
	return offered.some((option) => option.key === wanted) ? wanted : defaultCompare(range);
}
