/**
 * Translated names of ranges, metrics, dimensions and values.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { __, sprintf } from '@wordpress/i18n';
import { COMPARE_KEYS, type CompareKey, type Dimension, type Filter, type MetricKey, type Operator, type RangeKey, type View, type ViewState } from '@seoproanalytics/core';
import { locale } from './boot';

/** API values stay open-ended; only presentation names are known here. */
export function appearanceLabel(value: string): string {
	const names: Record<string, string> = {
		AMP_BLUE_LINK: __('AMP blue link', 'seoproanalytics'),
		AMP_TOP_STORIES: __('AMP top stories', 'seoproanalytics'),
		AMP_IMAGE_RESULT: __('AMP image result', 'seoproanalytics'),
		VIDEO: __('Video', 'seoproanalytics'),
		PRODUCT_SNIPPETS: __('Product snippets', 'seoproanalytics'),
		MERCHANT_LISTINGS: __('Merchant listings', 'seoproanalytics'),
		REVIEW_SNIPPET: __('Review snippets', 'seoproanalytics'),
		FORUMS: __('Forums', 'seoproanalytics'),
		AMP_STORY: __('AMP story', 'seoproanalytics'),
	};
	const sentence = value.replace(/_/g, ' ').toLowerCase();
	return names[value] ?? sentence.charAt(0).toUpperCase() + sentence.slice(1);
}

export function viewLabel(view: View): string {
	const labels: Record<View, string> = {
		search: __('Search', 'seoproanalytics'),
		overview: __('Visitors', 'seoproanalytics'),
		goals: __('Goals', 'seoproanalytics'),
		funnels: __('Funnels', 'seoproanalytics'),
		properties: __('Properties', 'seoproanalytics'),
		clicks: __('Clicks', 'seoproanalytics'),
		'ab-tests': __('A/B tests', 'seoproanalytics'),
		changes: __('Changes', 'seoproanalytics'),
	};
	return labels[view];
}

/** A shared report's section: its tab, and for Search its engine. */
export function sectionLabel(state: Pick<ViewState, 'view' | 'engine'>): string {
	if (state.view !== 'search') {
		return viewLabel(state.view);
	}
	/* translators: %s: a search engine, such as Google or Bing. */
	return sprintf(__('Search: %s', 'seoproanalytics'), state.engine === 'bing' ? __('Bing', 'seoproanalytics') : __('Google', 'seoproanalytics'));
}

/** A filter as people read it: Country is United Kingdom, Spain. */
export function filterLabel(filter: Filter): string {
	return `${dimensionLabel(filter.dimension)} ${operatorLabel(filter.op)} ${filter.values.map((value) => valueLabel(filter.dimension, value)).join(', ')}`;
}

export function rangeLabel(key: RangeKey): string {
	const labels: Record<RangeKey, string> = {
		realtime: __('Last 30 minutes', 'seoproanalytics'),
		today: __('Today', 'seoproanalytics'),
		yesterday: __('Yesterday', 'seoproanalytics'),
		'24h': __('Last 24 hours', 'seoproanalytics'),
		'7d': __('Last 7 days', 'seoproanalytics'),
		'28d': __('Last 4 weeks', 'seoproanalytics'),
		'30d': __('Last 30 days', 'seoproanalytics'),
		'90d': __('Last 90 days', 'seoproanalytics'),
		'91d': __('Last 13 weeks', 'seoproanalytics'),
		'182d': __('Last 26 weeks', 'seoproanalytics'),
		'364d': __('Last 52 weeks', 'seoproanalytics'),
		week: __('This week', 'seoproanalytics'),
		month: __('This month', 'seoproanalytics'),
		year: __('This year', 'seoproanalytics'),
		'12mo': __('Last 12 months', 'seoproanalytics'),
		lastyear: __('Last year', 'seoproanalytics'),
		all: __('All time', 'seoproanalytics'),
		custom: __('Custom dates', 'seoproanalytics'),
	};
	return labels[key];
}

/** A heading in the period menu and the ranges under it; ranges without a heading follow the groups. */
export interface RangeGroup {
	label?: string;
	keys: RangeKey[];
}

/**
 * The period menu. Weeks are whole weeks, so the previous period meets the
 * same weekdays. 30d, 90d and realtime stay valid in links and the API but
 * are left out; the menu shows one only while it is the chosen period.
 */
export function rangeMenu(): RangeGroup[] {
	return [
		{ label: __('Days', 'seoproanalytics'), keys: ['today', 'yesterday', '24h', '7d'] },
		{ label: __('Weeks', 'seoproanalytics'), keys: ['28d', '91d', '182d', '364d'] },
		{ label: __('Calendar', 'seoproanalytics'), keys: ['week', 'month', 'year', '12mo', 'lastyear'] },
		{ keys: ['all', 'custom'] },
	];
}

/** A comparison's name: in the menu, the chart's legend and its table (an answer's `compare.range.key`; unknown keys read as the period before). */
export function compareLabel(key: string): string {
	const labels: Record<CompareKey, string> = {
		none: __('No comparison', 'seoproanalytics'),
		prev: __('Previous period', 'seoproanalytics'),
		typical: __('Typical period', 'seoproanalytics'),
		week: __('A week earlier, same weekdays', 'seoproanalytics'),
		month_weekday: __('A month earlier, same weekdays', 'seoproanalytics'),
		month: __('A month earlier, same dates', 'seoproanalytics'),
		year_weekday: __('A year earlier, same weekdays', 'seoproanalytics'),
		year: __('A year earlier, same dates', 'seoproanalytics'),
	};
	return (COMPARE_KEYS as readonly string[]).includes(key) ? labels[key as CompareKey] : labels.prev;
}

export function metricLabel(key: MetricKey): string {
	const labels: Record<MetricKey, string> = {
		visitors: __('Visitors', 'seoproanalytics'),
		visits: __('Visits', 'seoproanalytics'),
		pageviews: __('Pageviews', 'seoproanalytics'),
		views_per_visit: __('Views per visit', 'seoproanalytics'),
		bounce_rate: __('Bounce rate', 'seoproanalytics'),
		visit_duration: __('Visit duration', 'seoproanalytics'),
		events: __('Events', 'seoproanalytics'),
	};
	return labels[key];
}

export function metricHelp(key: MetricKey): string {
	const help: Partial<Record<MetricKey, string>> = {
		visitors: __('Different people each day, added up over the days. No one is followed from one day to the next.', 'seoproanalytics'),
		visits: __('Visits to the site. A visit ends after 30 minutes without a page or event.', 'seoproanalytics'),
		bounce_rate: __('Visits with one page and nothing else done, as a share of all visits.', 'seoproanalytics'),
		visit_duration: __('Average time a visit had the site on screen.', 'seoproanalytics'),
	};
	return help[key] ?? '';
}

export function dimensionLabel(key: Dimension): string {
	const labels: Record<Dimension, string> = {
		channel: __('Channel', 'seoproanalytics'),
		source: __('Source', 'seoproanalytics'),
		utm_source: __('UTM source', 'seoproanalytics'),
		utm_medium: __('UTM medium', 'seoproanalytics'),
		utm_campaign: __('Campaign', 'seoproanalytics'),
		utm_term: __('UTM term', 'seoproanalytics'),
		utm_content: __('UTM content', 'seoproanalytics'),
		country: __('Country', 'seoproanalytics'),
		device: __('Device', 'seoproanalytics'),
		browser: __('Browser', 'seoproanalytics'),
		os: __('Operating system', 'seoproanalytics'),
		language: __('Language', 'seoproanalytics'),
		login: __('Logged in', 'seoproanalytics'),
		entry: __('Entry page', 'seoproanalytics'),
		exit: __('Exit page', 'seoproanalytics'),
		page: __('Page', 'seoproanalytics'),
		not_found: __('Page not found', 'seoproanalytics'),
		search: __('Site search', 'seoproanalytics'),
		no_results: __('Search with no results', 'seoproanalytics'),
		author: __('Author', 'seoproanalytics'),
		category: __('Category', 'seoproanalytics'),
		post_type: __('Post type', 'seoproanalytics'),
		event: __('Event', 'seoproanalytics'),
		variant: __('A/B variant', 'seoproanalytics'),
	};
	return labels[key];
}

export function operatorLabel(op: Operator): string {
	const labels: Record<Operator, string> = {
		is: __('is', 'seoproanalytics'),
		is_not: __('is not', 'seoproanalytics'),
		contains: __('contains', 'seoproanalytics'),
		matches: __('matches', 'seoproanalytics'),
	};
	return labels[op];
}

const regionNames = (() => {
	try {
		return new Intl.DisplayNames([locale], { type: 'region' });
	} catch {
		return null;
	}
})();

const languageNames = (() => {
	try {
		return new Intl.DisplayNames([locale], { type: 'language' });
	} catch {
		return null;
	}
})();

/** A dimension value as people read it; the API's label when it has one. */
export function valueLabel(dimension: Dimension, value: string, apiLabel?: string): string {
	if (value === '') {
		// The API names empty values for each dimension (Direct, Unknown, (none)).
		return apiLabel || (dimension === 'source' ? __('Direct', 'seoproanalytics') : __('(none)', 'seoproanalytics'));
	}
	if (dimension === 'country' && /^[A-Z]{2}$/.test(value)) {
		return regionNames?.of(value) ?? value;
	}
	if (dimension === 'language' && /^[a-z]{2,3}(-[A-Za-z]{2,4})?$/.test(value)) {
		try {
			return languageNames?.of(value) ?? value;
		} catch {
			return value;
		}
	}
	if (dimension === 'channel') {
		const channels: Record<string, string> = {
			direct: __('Direct', 'seoproanalytics'),
			organic_search: __('Organic search', 'seoproanalytics'),
			paid_search: __('Paid search', 'seoproanalytics'),
			ai: __('AI assistants', 'seoproanalytics'),
			organic_social: __('Organic social', 'seoproanalytics'),
			paid_social: __('Paid social', 'seoproanalytics'),
			email: __('Email', 'seoproanalytics'),
			referral: __('Referral', 'seoproanalytics'),
			paid_other: __('Other paid', 'seoproanalytics'),
			business_profile: __('Business profiles', 'seoproanalytics'),
		};
		return channels[value] ?? apiLabel ?? value;
	}
	if (dimension === 'device') {
		const devices: Record<string, string> = {
			desktop: __('Desktop', 'seoproanalytics'),
			mobile: __('Mobile', 'seoproanalytics'),
			tablet: __('Tablet', 'seoproanalytics'),
			unknown: __('Unknown', 'seoproanalytics'),
		};
		return devices[value] ?? apiLabel ?? value;
	}
	if (dimension === 'login') {
		const logins: Record<string, string> = {
			logged_in: __('Logged in', 'seoproanalytics'),
			logged_out: __('Not logged in', 'seoproanalytics'),
		};
		return logins[value] ?? apiLabel ?? value;
	}
	return apiLabel || value;
}
