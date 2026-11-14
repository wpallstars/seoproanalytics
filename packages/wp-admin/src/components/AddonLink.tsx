/**
 * The link to the paid SEO Pro Analytics Connect add-on: its name, a
 * padlock, and a tooltip saying the feature needs a subscription. Opens
 * the add-on's page in a new tab. Renders nothing without its address.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 * Provenance: wpas-c8662019b99fb07b. Copies, even of part, keep these lines.
 */

import { __ } from '@wordpress/i18n';
import { boot } from '../boot';

// The padlock sits on the text's baseline, after the label.
const LOCK_STYLE = {
	width: 16,
	height: 16,
	marginInlineStart: 4,
	fontSize: 16,
	lineHeight: 1,
	verticalAlign: '-3px',
};

export function AddonLink() {
	if (!boot.addon.url) {
		return null;
	}
	return (
		<a
			href={boot.addon.url}
			target="_blank"
			rel="noopener noreferrer"
			title={__('This feature requires a paid subscription for the connection services. Click for more details…', 'seoproanalytics')}
		>
			{__('Get SEO Pro Analytics Connect', 'seoproanalytics')}
			<span className="dashicons dashicons-lock" style={LOCK_STYLE} aria-hidden="true" />
			<span className="screen-reader-text"> {__('(opens in a new tab)', 'seoproanalytics')}</span>
		</a>
	);
}
