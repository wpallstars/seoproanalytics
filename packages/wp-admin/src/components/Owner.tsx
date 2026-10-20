/**
 * Owners: who is responsible for the SEO of a page, search target, Plan
 * item or experiment (GET and POST /owners). The filter (everyone, mine,
 * nobody's or one person's), the cell each row shows its owner in, and the
 * summary of who owns what. Responsibility only: a page's author and last
 * editor stay facts of their own. Shown to people who can edit posts,
 * never in shared reports (the hook is off there and rows have no owner).
 *
 * Administrators give work to anyone; people who can own take work
 * nobody else was given and give their own back.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { useState } from 'react';
import { Button, SelectControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { formatDecimal, formatNumber, type Owner, type OwnerKind, type OwnersAnswer, type ViewState } from '@seoproanalytics/core';
import { errorMessage, setOwners, useOwners } from '../api';
import { locale } from '../boot';
import { useDataSet } from '../data';
import { TableScroll } from './TableScroll';

const number = (value: number) => formatNumber(value, locale, false);

type OwnerScope = Pick<ViewState, 'range' | 'from' | 'to' | 'filters' | 'engine'>;

/**
 * The people who can own and what this person may do, for a report's
 * owner filter and cells; undefined while loading, for people who cannot
 * edit posts and in shared reports. Page filters do not change who can
 * own, so they are left out (one answer serves every report).
 */
export function useOwnerPeople(scope: OwnerScope): OwnersAnswer | undefined {
	const query = useOwners({ range: scope.range, from: scope.from, to: scope.to, filters: [], compare: 'none', engine: scope.engine });
	return query.isError ? undefined : query.data;
}

/** An owner's name: the person, or nobody. */
export function ownerName(owner: Owner): string {
	return owner.id !== null && owner.name ? owner.name : __('Nobody', 'seoproanalytics');
}

/** Where an owner comes from, said under the name; '' when set by someone to a person. */
function sourceNote(owner: Owner): string {
	if (owner.source === 'default') {
		return __('By default', 'seoproanalytics');
	}
	return owner.source === 'assigned' && owner.id === null ? __('Set to nobody', 'seoproanalytics') : '';
}

interface FilterProps {
	owners: OwnersAnswer;
	value: string;
	onChange: (owner: string | undefined) => void;
}

/** Everyone's, mine, nobody's or one person's. */
export function OwnerFilter({ owners, value, onChange }: Readonly<FilterProps>) {
	const people = owners.people.filter((p) => !p.me);
	return (
		<SelectControl
			__nextHasNoMarginBottom
			label={__('Owner', 'seoproanalytics')}
			value={value}
			options={[
				{ value: '', label: __('Everyone', 'seoproanalytics') },
				...(owners.can_take ? [{ value: 'me', label: __('Mine', 'seoproanalytics') }] : []),
				{ value: 'none', label: __('Nobody', 'seoproanalytics') },
				...people.map((p) => ({ value: String(p.id), label: p.name })),
			]}
			onChange={(next: string) => onChange(next || undefined)}
		/>
	);
}

interface CellProps {
	owners: OwnersAnswer;
	owner: Owner | null | undefined;
	kind: OwnerKind;
	/** The object: a page's path, a target's search, a Plan item's key or an experiment's id. */
	id: string;
	onError: (message: string) => void;
}

/** A row's owner, and what this person may change: anyone (administrators), or take and give back. */
export function OwnerCell({ owners, owner, kind, id, onError }: Readonly<CellProps>) {
	const data = useDataSet();
	const [busy, setBusy] = useState(false);
	if (!owner) {
		return null;
	}
	const set = async (user: string) => {
		setBusy(true);
		try {
			const done = await setOwners(data, kind, [id], user);
			onError(done.skipped[0]?.reason ?? '');
		} catch (e) {
			onError(errorMessage(e, __('The owner could not be saved. Try again.', 'seoproanalytics')));
		}
		setBusy(false);
	};
	const name = ownerName(owner);
	if (owners.can_assign) {
		const value = owner.source === 'assigned' ? String(owner.id ?? 'none') : 'default';
		return (
			<SelectControl
				__nextHasNoMarginBottom
				size="compact"
				hideLabelFromVision
				label={__('Owner', 'seoproanalytics')}
				value={value}
				disabled={busy}
				options={[
					{
						value: 'default',
						// The name first, so a narrow column still shows who owns it.
						label:
							owner.source === 'assigned'
								? __('Default (as worked out)', 'seoproanalytics')
								: /* translators: %s: the owner by default, a person or "Nobody". */ sprintf(__('%s (default)', 'seoproanalytics'), name),
					},
					{ value: 'none', label: __('Nobody', 'seoproanalytics') },
					...owners.people.map((p) => ({ value: String(p.id), label: p.name })),
				]}
				onChange={(next: string) => void set(next)}
			/>
		);
	}
	const mine = owner.id === owners.me;
	const note = sourceNote(owner);
	return (
		<>
			{name}
			{note && <span className="seoproan-meta">{note}</span>}
			{owners.can_take && !mine && (owner.source !== 'assigned' || owner.id === null) && (
				<span className="seoproan-meta">
					<Button variant="link" disabled={busy} onClick={() => void set('me')}>
						{__('Take it', 'seoproanalytics')}
					</Button>
				</span>
			)}
			{owners.can_take && mine && owner.source === 'assigned' && (
				<span className="seoproan-meta">
					<Button variant="link" disabled={busy} onClick={() => void set('default')}>
						{__('Give it back', 'seoproanalytics')}
					</Button>
				</span>
			)}
		</>
	);
}

/** Who owns what: each person's pages, open Plan items and their potential clicks, open targets and running experiments; then nobody's. Choosing a person shows their work. */
export function OwnersSummary({ owners, pick }: Readonly<{ owners: OwnersAnswer; pick: (owner: string | undefined) => void }>) {
	const rows = [
		...owners.people.map((p) => ({ key: p.me ? 'me' : String(p.id), name: p.me ? sprintf(/* translators: %s: a person's name. */ __('%s (you)', 'seoproanalytics'), p.name) : p.name, counts: p })),
		{ key: 'none', name: __('Nobody', 'seoproanalytics'), counts: owners.nobody },
	];
	return (
		<>
			<h3 className="seoproan-subtitle">{__('Owners', 'seoproanalytics')}</h3>
			<p className="seoproan-note">
				{__(
					'Who is responsible for the SEO of what. A page’s owner is its author unless someone sets another; a search target, Plan item or experiment has its page’s owner unless set. Choose a name to see that person’s work.',
					'seoproanalytics'
				)}
			</p>
			<TableScroll label={__('Owners', 'seoproanalytics')}>
				<table className="widefat striped seoproan-table seoproan-owners">
					<thead>
						<tr>
							<th scope="col">{__('Owner', 'seoproanalytics')}</th>
							<th scope="col" className="num">
								{__('Pages', 'seoproanalytics')}
							</th>
							<th scope="col" className="num">
								{__('To do', 'seoproanalytics')}
							</th>
							<th scope="col" className="num">
								{__('Potential clicks', 'seoproanalytics')}
							</th>
							<th scope="col" className="num">
								{__('Open targets', 'seoproanalytics')}
							</th>
							<th scope="col" className="num">
								{__('Experiments running (due)', 'seoproanalytics')}
							</th>
						</tr>
					</thead>
					<tbody>
						{rows.map((row) => (
							<tr key={row.key}>
								<td>
									<button type="button" className="seoproan-link" onClick={() => pick(row.key)}>
										{row.name}
									</button>
								</td>
								<td className="num">{row.counts.pages === undefined ? '–' : number(row.counts.pages)}</td>
								<td className="num">{number(row.counts.items)}</td>
								<td className="num">{formatDecimal(row.counts.clicks, locale)}</td>
								<td className="num">{number(row.counts.targets)}</td>
								<td className="num">{`${number(row.counts.running)} (${number(row.counts.due)})`}</td>
							</tr>
						))}
					</tbody>
				</table>
			</TableScroll>
		</>
	);
}
