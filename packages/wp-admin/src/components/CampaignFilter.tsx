/**
 * A business profile's name as a filter: choosing it shows only the visits
 * from its tagged link (utm_campaign is its campaign); choosing it again
 * removes the filter.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { __ } from '@wordpress/i18n';
import { hasFilterValue, toggleFilterValue } from '@seoproanalytics/core';
import type { ViewProps } from '../App';

export function CampaignFilter({ campaign, name, state, update }: Readonly<ViewProps & { campaign: string; name: string }>) {
	const active = hasFilterValue(state.filters, 'utm_campaign', campaign, 'is');
	return (
		<button
			type="button"
			className={`seoproan-link${active ? ' is-active' : ''}`}
			aria-pressed={active}
			title={active ? __('Remove this filter', 'seoproanalytics') : __('Show only visits from this profile\'s link', 'seoproanalytics')}
			onClick={() => update({ filters: toggleFilterValue(state.filters, 'utm_campaign', campaign, 'is') })}
		>
			{name}
		</button>
	);
}
