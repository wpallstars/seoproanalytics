/**
 * The fields of a goal or funnel step: a page or an event, which one, and
 * its name. Pages and events the site has seen are offered as you type.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { useId } from 'react';
import { SelectControl, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import type { GoalKind, GoalStep } from '@seoproanalytics/core';
import { useBreakdown, useProperties } from '../api';

interface Props {
	step: GoalStep;
	onChange: (step: GoalStep) => void;
	/** Label for the name field; steps may leave it empty. */
	nameLabel: string;
	nameHelp?: string;
}

export function kindLabel(kind: GoalKind): string {
	return kind === 'page' ? __('Page viewed', 'seoproanalytics') : __('Event sent', 'seoproanalytics');
}

/** The most seen pages or events of the last 90 days, unfiltered. */
function Suggestions({ id, kind }: Readonly<{ id: string; kind: GoalKind }>) {
	const query = useBreakdown({ range: '90d', filters: [] }, kind === 'page' ? 'page' : 'event', 100);
	return (
		<datalist id={id}>
			{(query.data?.rows ?? []).filter((row) => row.value !== '').map((row) => (
				<option key={row.value} value={row.value} />
			))}
		</datalist>
	);
}

/** Suggestions remain optional: new keys and values can be entered directly. */
function PropertyFields({ step, onChange }: Readonly<Pick<Props, 'step' | 'onChange'>>) {
	const list = useId();
	const query = useProperties({ range: '90d', filters: [] }, '', step.match.includes('*') ? '' : step.match, 100);
	return (
		<fieldset className="seoproan-fieldset">
			<legend>{__('Where property (optional)', 'seoproanalytics')}</legend>
			<div className="seoproan-form__row">
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={__('Property key', 'seoproanalytics')}
					help={__('Leave both fields empty to count every matching event.', 'seoproanalytics')}
					value={step.prop ?? ''}
					list={list}
					autoComplete="off"
					maxLength={300}
					onChange={(prop: string) => onChange({ ...step, prop })}
				/>
				<datalist id={list}>
					{(query.data?.rows ?? []).filter((row) => row.value !== '').map((row) => <option key={row.value} value={row.value} />)}
				</datalist>
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={__('Is value', 'seoproanalytics')}
					help={__('Use * for any text. The property must belong to this event.', 'seoproanalytics')}
					value={step.value ?? ''}
					maxLength={300}
					onChange={(value: string) => onChange({ ...step, value })}
				/>
			</div>
		</fieldset>
	);
}

export function StepFields({ step, onChange, nameLabel, nameHelp }: Readonly<Props>) {
	const list = useId();
	return (
		<div className="seoproan-step-fields">
			<SelectControl
				__nextHasNoMarginBottom
				label={__('Counts when', 'seoproanalytics')}
				value={step.kind}
				options={[
					{ value: 'event', label: kindLabel('event') },
					{ value: 'page', label: kindLabel('page') },
				]}
				onChange={(kind: string) => onChange({ ...step, kind: kind === 'page' ? 'page' : 'event', ...(kind === 'page' ? { prop: '', value: '' } : {}) })}
			/>
			<TextControl
				__nextHasNoMarginBottom
				label={step.kind === 'page' ? __('Page', 'seoproanalytics') : __('Event name', 'seoproanalytics')}
				help={
					step.kind === 'page'
						? __('A path such as /pricing/. Use * for any text: /blog/* is every blog page.', 'seoproanalytics')
						: __('As your site sends it, such as Purchase. Use * for any text.', 'seoproanalytics')
				}
				value={step.match}
				list={list}
				autoComplete="off"
				onChange={(match: string) => onChange({ ...step, match })}
			/>
			<Suggestions id={list} kind={step.kind} />
			{step.kind === 'event' && <PropertyFields step={step} onChange={onChange} />}
			<TextControl
				__nextHasNoMarginBottom
				label={nameLabel}
				help={nameHelp}
				value={step.name}
				onChange={(name: string) => onChange({ ...step, name })}
			/>
		</div>
	);
}
