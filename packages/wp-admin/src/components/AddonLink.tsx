/**
 * The link to the paid SEO Pro Analytics Connect add-on: its name, a
 * padlock, and a tooltip saying the feature needs a subscription. Opens
 * the add-on's page in a new tab. Renders nothing without its address.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { __ } from '@wordpress/i18n';
import { boot } from '../boot';

export function AddonLink() {
	if (!boot.addon.url) {
		return null;
	}
	return (
		<a
			className="seoproan-addon-link"
			href={boot.addon.url}
			target="_blank"
			rel="noopener noreferrer"
			title={__('This feature requires a paid subscription for the connection services. Click for more details…', 'seoproanalytics')}
		>
			{__('Get SEO Pro Analytics Connect', 'seoproanalytics')}
			<span className="dashicons dashicons-lock" aria-hidden="true" />
			<span className="screen-reader-text"> {__('(opens in a new tab)', 'seoproanalytics')}</span>
		</a>
	);
}
