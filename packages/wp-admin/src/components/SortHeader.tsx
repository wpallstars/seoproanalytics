/**
 * A column header that sorts its table. The first click sorts the column
 * its natural way (most first; lowest first for position, as a lower
 * position is better; newest first for days); a click on the column
 * already sorted reverses it. The arrow shows the direction: ↓ highest
 * (or newest) at the top, ↑ lowest (or oldest). Without onSort (paper)
 * the header is plain text, with the arrow on the sorted column.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { __, sprintf } from '@wordpress/i18n';
import { naturalOrder, type SortOrder } from '@seoproanalytics/core';

/** A table's sort and how to change it. */
export interface TableSortProps<K extends string> {
	sort: K;
	order: SortOrder;
	/** Sort by a column in a direction; left out where the table cannot be sorted (paper). */
	onSort?: (sort: K, order: SortOrder) => void;
}

interface SortHeaderProps<K extends string> extends TableSortProps<K> {
	column: K;
	label: string;
	/** The cell's class: num (the default) for figures. */
	className?: string;
	/** What the column holds, for its first click and tooltip: text sorts A to Z first, dates newest first. */
	type?: 'text' | 'date';
}

/** What a click does, for the button's tooltip. */
function hint(column: string, label: string, next: SortOrder, type?: 'text' | 'date'): string {
	if (type === 'text') {
		return next === 'asc'
			? sprintf(/* translators: %s: column name, e.g. "Linking page". */ __('Sort by %s, A to Z', 'seoproanalytics'), label)
			: sprintf(/* translators: %s: column name, e.g. "Linking page". */ __('Sort by %s, Z to A', 'seoproanalytics'), label);
	}
	if (column === 'day' || type === 'date') {
		return next === 'asc'
			? sprintf(/* translators: %s: column name, e.g. "Day". */ __('Sort by %s, oldest first', 'seoproanalytics'), label)
			: sprintf(/* translators: %s: column name, e.g. "Day". */ __('Sort by %s, newest first', 'seoproanalytics'), label);
	}
	return next === 'asc'
		? sprintf(/* translators: %s: column name, e.g. "Position". */ __('Sort by %s, lowest first', 'seoproanalytics'), label)
		: sprintf(/* translators: %s: column name, e.g. "Clicks". */ __('Sort by %s, highest first', 'seoproanalytics'), label);
}

export function SortHeader<K extends string>({ column, label, sort, order, onSort, className = 'num', type }: Readonly<SortHeaderProps<K>>) {
	const active = sort === column;
	const arrow = active && <span aria-hidden="true">{order === 'asc' ? ' ↑' : ' ↓'}</span>;
	const ariaSort = active ? (order === 'asc' ? 'ascending' : 'descending') : undefined;
	if (!onSort) {
		return (
			<th scope="col" className={className || undefined} aria-sort={ariaSort}>
				{label}
				{arrow}
			</th>
		);
	}
	// The column's natural way first; the sorted column turns round.
	const first: SortOrder = type === 'text' ? 'asc' : naturalOrder(column);
	const next: SortOrder = active ? (order === 'asc' ? 'desc' : 'asc') : first;
	return (
		<th scope="col" className={className || undefined} aria-sort={ariaSort}>
			<button type="button" className={`seoproan-sort${active ? ' is-active' : ''}`} title={hint(column, label, next, type)} onClick={() => onSort(column, next)}>
				{label}
				{arrow}
			</button>
		</th>
	);
}
