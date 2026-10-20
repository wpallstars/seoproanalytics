/**
 * Range and comparison pickers, with day fields for a custom range.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { useState } from 'react';
import { Button, SelectControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { compareAfter, compareOptions, periodDays, todayIn, type CompareKey, type CompareOption, type RangeKey, type ViewState } from '@seoproanalytics/core';
import { boot } from '../boot';
import { daysText } from '../dates';
import { compareLabel, rangeLabel, rangeMenu } from '../labels';

interface Props {
	state: ViewState;
	update: (patch: Partial<ViewState>) => void;
}

function today(): string {
	const d = new Date();
	return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function daysAgo(days: number): string {
	const d = new Date();
	d.setDate(d.getDate() - days);
	return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

/** The comparisons that suit a period (compareOptions() in packages/core/src/compare.ts), with their days. */
function offered(state: ViewState, range: RangeKey, from?: string, to?: string): CompareOption[] {
	return compareOptions(range, periodDays(range, todayIn(boot.timezone), boot.startOfWeek, { from, to })).filter((option) => option.key !== 'typical' || state.view === 'overview');
}

/**
 * A comparison in the menu: its name, and the days it means where they are
 * known. Search periods end at the newest search day, so Search shows the
 * days only in its "compared with" line. The chosen one shows its name
 * alone, so the closed menu stays narrow; its days are in that line.
 */
function compareText(option: CompareOption, withDays: boolean): string {
	const label = compareLabel(option.key);
	if (!withDays || !option.days || option.key === 'none') {
		return label;
	}
	/* translators: 1: a comparison, such as "A week earlier, same weekdays"; 2: its days, such as "Sun 4 Oct 2026". */
	return sprintf(__('%1$s: %2$s', 'seoproanalytics'), label, daysText(option.days.from, option.days.to));
}

export function Controls({ state, update }: Readonly<Props>) {
	const [editing, setEditing] = useState(false);
	const [from, setFrom] = useState(state.from ?? daysAgo(29));
	const [to, setTo] = useState(state.to ?? today());
	const custom = editing || state.range === 'custom';
	const options = offered(state, state.range, state.from, state.to);
	// A comparison left out for this period (from a link) is shown while chosen.
	const compares = state.compare === 'typical' || options.some((option) => option.key === state.compare) ? options : [{ key: state.compare, days: null }, ...options];
	/** The new period, and the comparison that goes with it. */
	const choose = (range: RangeKey, days: { from?: string; to?: string } = {}) => {
		const compare = compareAfter(state.compare, state.range, range, offered(state, range, days.from, days.to));
		update({ range, from: days.from, to: days.to, compare });
	};

	const picked: RangeKey = custom ? 'custom' : state.range;
	const groups = rangeMenu();
	// A range left out of the menu (30d, 90d from an older link) is shown while chosen.
	const listed = groups.some((group) => group.keys.includes(picked));
	const option = (key: RangeKey) => (
		<option key={key} value={key}>
			{rangeLabel(key)}
		</option>
	);

	return (
		<div className="seoproan-controls">
			<SelectControl
				__nextHasNoMarginBottom
				label={__('Period', 'seoproanalytics')}
				hideLabelFromVision
				value={picked}
				onChange={(value: string) => {
					if (value === 'custom') {
						setEditing(true);
						return;
					}
					setEditing(false);
					choose(value as RangeKey);
				}}
			>
				{!listed && option(picked)}
				{groups.map((group) =>
					group.label ? (
						<optgroup key={group.label} label={group.label}>
							{group.keys.map(option)}
						</optgroup>
					) : (
						group.keys.map(option)
					),
				)}
			</SelectControl>
			{custom && (
				<form
					className="seoproan-custom-range"
					onSubmit={(event) => {
						event.preventDefault();
						if (from && to && from <= to) {
							setEditing(false);
							choose('custom', { from, to });
						}
					}}
				>
					<label>
						<span className="screen-reader-text">{__('First day', 'seoproanalytics')}</span>
						<input type="date" value={from} max={to || undefined} onChange={(e) => setFrom(e.target.value)} required />
					</label>
					<span aria-hidden="true">–</span>
					<label>
						<span className="screen-reader-text">{__('Last day', 'seoproanalytics')}</span>
						<input type="date" value={to} min={from || undefined} onChange={(e) => setTo(e.target.value)} required />
					</label>
					<Button variant="secondary" type="submit" disabled={!from || !to || from > to}>
						{__('Apply', 'seoproanalytics')}
					</Button>
				</form>
			)}
			<SelectControl
				__nextHasNoMarginBottom
				className="seoproan-compare"
				label={__('Compare with', 'seoproanalytics')}
				hideLabelFromVision
				value={compares.some((option) => option.key === state.compare) ? state.compare : 'none'}
				options={compares.map((option) => ({ value: option.key, label: compareText(option, state.view !== 'search' && option.key !== state.compare) }))}
				disabled={state.range === 'all'}
				onChange={(value: string) => update({ compare: value as CompareKey })}
			/>
		</div>
	);
}
