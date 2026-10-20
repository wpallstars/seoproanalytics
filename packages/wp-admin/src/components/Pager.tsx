/**
 * Report card scaffolding shared by the paged lists: the error, loading and
 * not-connected states above a list, and the "1–25 of 80" pager under it.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { Button, Notice } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { formatNumber } from '@seoproanalytics/core';
import { errorMessage } from '../api';
import { locale } from '../boot';

const number = (value: number) => formatNumber(value, locale, false);

type LoadStateProps = {
	/** The report query. */
	query: { isError: boolean; error: unknown; data?: unknown };
	/** Shown when the request fails without its own message. */
	failed: string;
	/** Shown, once loaded, while the source is not connected; omit when there is no connection. */
	unconnected?: string;
};

/** The error notice, the loading skeleton, or the not-connected note. */
export function LoadState({ query, failed, unconnected }: Readonly<LoadStateProps>) {
	if (query.isError) {
		return (
			<Notice status="error" isDismissible={false} className="seoproan-notice">
				{errorMessage(query.error, failed)}
			</Notice>
		);
	}
	if (!query.data) {
		return <div className="seoproan-skeleton seoproan-skeleton--table" aria-busy="true" />;
	}
	return unconnected ? (
		<div className="seoproan-app-empty">
			<p>{unconnected}</p>
		</div>
	) : null;
}

type PagerProps = {
	/** First row shown, zero-based. */
	offset: number;
	perPage: number;
	/** Rows in the whole list. */
	total: number;
	/** Whether rows follow this page. */
	more: boolean;
	/** The pager's accessible name. */
	label: string;
	onChange: (offset: number) => void;
};

/** Previous and Next under a list longer than a page; nothing otherwise. */
export function Pager({ offset, perPage, total, more, label, onChange }: Readonly<PagerProps>) {
	if (total <= perPage) {
		return null;
	}
	const shown = sprintf(
		/* translators: 1: first row shown, 2: last row shown, 3: number of rows. */
		__('%1$s–%2$s of %3$s', 'seoproanalytics'),
		number(offset + 1),
		number(Math.min(offset + perPage, total)),
		number(total)
	);
	return (
		<nav className="seoproan-changes__pager" aria-label={label}>
			<span className="seoproan-muted">{shown}</span>
			<Button variant="secondary" disabled={offset === 0} onClick={() => onChange(Math.max(0, offset - perPage))}>
				{__('Previous', 'seoproanalytics')}
			</Button>
			<Button variant="secondary" disabled={!more} onClick={() => onChange(offset + perPage)}>
				{__('Next', 'seoproanalytics')}
			</Button>
		</nav>
	);
}
