/**
 * A view's "Only on page" form, for its card header: a page path (with
 * suggestions; * for any text), Apply, and Any page to clear it. Controls
 * passed as children come first, such as a crawler group.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { useEffect, useId, useState, type ReactNode } from 'react';
import { Button, CardHeader, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { PeriodLine } from '../Overview';

type Range = { from: string; to: string };

/** A page-filtered view's card header: title, period, extra lines and the PageFilter. */
export function PageCardHeader({
	title,
	range,
	compare,
	info,
	filter,
}: Readonly<{ title: string; range?: Range; compare?: Range; info?: ReactNode; filter: ReactNode }>) {
	return (
		<CardHeader className="seoproan-app-card__header">
			<div>
				<h2 className="seoproan-app-card__title">{title}</h2>
				{range && <PeriodLine range={range} compare={compare} />}
				{info}
			</div>
			{filter}
		</CardHeader>
	);
}

export function PageFilter({
	page,
	suggestions,
	onApply,
	children,
}: Readonly<{ page: string; suggestions: readonly string[]; onApply: (page: string) => void; children?: ReactNode }>) {
	// The box is a draft until Apply; it follows the address (back button, links).
	const [typed, setTyped] = useState(page);
	useEffect(() => setTyped(page), [page]);
	const list = useId();
	return (
		<form
			className="seoproan-properties__event"
			onSubmit={(e) => {
				e.preventDefault();
				onApply(typed.trim());
			}}
		>
			{children}
			<TextControl
				__nextHasNoMarginBottom
				label={__('Only on page', 'seoproanalytics')}
				value={typed}
				list={list}
				autoComplete="off"
				placeholder={__('Any page; * for any text', 'seoproanalytics')}
				onChange={setTyped}
			/>
			<datalist id={list}>
				{suggestions.map((value) => (
					<option key={value} value={value} />
				))}
			</datalist>
			<Button variant="secondary" type="submit">
				{__('Apply', 'seoproanalytics')}
			</Button>
			{page && (
				<Button
					variant="link"
					onClick={() => {
						setTyped('');
						onApply('');
					}}
				>
					{__('Any page', 'seoproanalytics')}
				</Button>
			)}
		</form>
	);
}
