/**
 * Tiles that pick what a view's table shows (Clicks, Crawlers): each a
 * button with a label, a total, a note and the change on the period
 * compared with.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { __ } from '@wordpress/i18n';
import { Change } from './Change';

export interface KindTile<K extends string> {
	kind: K;
	label: string;
	/** The total, formatted, or '–'. */
	value: string;
	/** The note under it, or ''. */
	foot: string;
	/** Relative change, or undefined without a comparison. */
	change?: number | null;
	/** Which way is good news; up when unset. */
	better?: 'up' | 'down';
}

export function KindTiles<K extends string>({ tiles, selected, onPick }: Readonly<{ tiles: readonly KindTile<K>[]; selected: K; onPick: (kind: K) => void }>) {
	return (
		<div className="seoproan-tiles" role="group" aria-label={__('Show', 'seoproanalytics')}>{/* NOSONAR: a group of buttons; a fieldset would bring its own border, padding and min-width. */}
			{tiles.map((t) => (
				<button
					key={t.kind}
					type="button"
					className={`seoproan-tile${selected === t.kind ? ' is-selected' : ''}`}
					aria-pressed={selected === t.kind}
					onClick={() => onPick(t.kind)}
				>
					<span className="seoproan-tile__label">{t.label}</span>
					<span className="seoproan-tile__value">{t.value}</span>
					<span className="seoproan-tile__foot">
						<span className="seoproan-muted">{t.foot}</span>
						{t.change !== undefined && <Change change={t.change} better={t.better} />}
					</span>
				</button>
			))}
		</div>
	);
}
