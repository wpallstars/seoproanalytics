/**
 * Campaign links: build an address with campaign tags (utm_source,
 * utm_medium, utm_campaign, utm_term, utm_content) from presets for common
 * places, copy it, and find it again later (the last ones built, kept in
 * this browser). Visits through it show under Sources → Campaigns. Tags
 * are lower-cased as the statistics store them.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { useId, useMemo, useState } from 'react';
import { Button, Modal, Notice, TextControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import type { ViewState } from '@seoproanalytics/core';
import { useBreakdown } from '../api';
import { boot } from '../boot';

const TAGS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] as const;
type Tag = (typeof TAGS)[number];
type Tags = Record<Tag, string>;

/** Last links built, in this browser. */
const STORE = 'seoproanalytics-campaign-links';
const KEEP = 10;

interface Preset {
	label: string;
	source: string;
	medium: string;
}

const presets = (): Preset[] => [
	{ label: __('Newsletter', 'seoproanalytics'), source: 'newsletter', medium: 'email' },
	{ label: __('Facebook post', 'seoproanalytics'), source: 'facebook', medium: 'social' },
	{ label: __('Instagram', 'seoproanalytics'), source: 'instagram', medium: 'social' },
	{ label: __('LinkedIn', 'seoproanalytics'), source: 'linkedin', medium: 'social' },
	{ label: __('X (Twitter)', 'seoproanalytics'), source: 'x', medium: 'social' },
	{ label: __('YouTube', 'seoproanalytics'), source: 'youtube', medium: 'video' },
	{ label: __('Google Ads', 'seoproanalytics'), source: 'google', medium: 'cpc' },
	{ label: __('Microsoft Ads', 'seoproanalytics'), source: 'bing', medium: 'cpc' },
	{ label: __('Partner or affiliate', 'seoproanalytics'), source: 'partner', medium: 'affiliate' },
	{ label: __('QR code or print', 'seoproanalytics'), source: 'qr', medium: 'offline' },
];

/** A tag as the statistics will store it: trimmed, lower case, spaces as hyphens. */
function clean(value: string): string {
	return value.trim().toLowerCase().replace(/\s+/g, '-').slice(0, 200);
}

/** The address with the tags, or '' while it is not a valid http(s) address. */
export function campaignUrl(page: string, tags: Tags): string {
	let address: URL;
	try {
		address = new URL(page.trim());
	} catch {
		return '';
	}
	if (address.protocol !== 'https:' && address.protocol !== 'http:') {
		return '';
	}
	for (const tag of TAGS) {
		const value = clean(tags[tag]);
		if (value) {
			address.searchParams.set(tag, value);
		} else {
			address.searchParams.delete(tag);
		}
	}
	return address.toString();
}

function readStore(): string[] {
	try {
		const saved: unknown = JSON.parse(window.localStorage.getItem(STORE) ?? '[]');
		return Array.isArray(saved) ? saved.filter((url): url is string => typeof url === 'string').slice(0, KEEP) : [];
	} catch {
		return [];
	}
}

function writeStore(urls: string[]): void {
	try {
		window.localStorage.setItem(STORE, JSON.stringify(urls.slice(0, KEEP)));
	} catch {
		// Private browsing or full storage: the list is a convenience only.
	}
}

function home(): string {
	return boot.siteHost ? `${window.location.protocol}//${boot.siteHost}/` : '';
}

const EMPTY: Tags = { utm_source: '', utm_medium: '', utm_campaign: '', utm_term: '', utm_content: '' };

/** The Sources card's "Campaign link" button and its builder. */
export function CampaignBuilder({ state }: Readonly<{ state: ViewState }>) {
	const [open, setOpen] = useState(false);
	return (
		<>
			<Button variant="tertiary" size="compact" icon="admin-links" onClick={() => setOpen(true)}>
				{__('Campaign link', 'seoproanalytics')}
			</Button>
			{open && <BuilderModal state={state} close={() => setOpen(false)} />}
		</>
	);
}

function BuilderModal({ state, close }: Readonly<{ state: ViewState; close: () => void }>) {
	const [page, setPage] = useState(home);
	const [tags, setTags] = useState<Tags>(EMPTY);
	const [recent, setRecent] = useState(readStore);
	const [copied, setCopied] = useState('');
	const campaigns = useBreakdown(state, 'utm_campaign', 50);
	const sources = useBreakdown(state, 'utm_source', 50);
	const campaignList = useId();
	const sourceList = useId();
	const url = useMemo(() => campaignUrl(page, tags), [page, tags]);
	const ready = url !== '' && clean(tags.utm_source) !== '' && clean(tags.utm_campaign) !== '';
	const set = (tag: Tag) => (value: string) => setTags((now) => ({ ...now, [tag]: value }));

	const copy = (text: string) => {
		const done = () => {
			setCopied(text);
			const next = [text, ...recent.filter((saved) => saved !== text)].slice(0, KEEP);
			setRecent(next);
			writeStore(next);
		};
		if (navigator.clipboard?.writeText) {
			navigator.clipboard.writeText(text).then(done, () => setCopied(''));
		} else {
			done();
		}
	};

	return (
		<Modal title={__('Campaign link', 'seoproanalytics')} onRequestClose={close} size="large">
			<div className="seoproan-campaign">
				<p className="seoproan-muted">
					{__('Add campaign tags to a link you share, so its visits show under Sources → Campaigns and can be filtered in every report. A source and a campaign are needed; the medium groups sources into channels (email, social, cpc for paid search).', 'seoproanalytics')}
				</p>
				<div className="seoproan-campaign__presets" role="group" aria-label={__('Presets', 'seoproanalytics')}>
					{presets().map((preset) => (
						<Button
							key={preset.label}
							variant="secondary"
							size="compact"
							isPressed={clean(tags.utm_source) === preset.source && clean(tags.utm_medium) === preset.medium}
							onClick={() => setTags((now) => ({ ...now, utm_source: preset.source, utm_medium: preset.medium }))}
						>
							{preset.label}
						</Button>
					))}
				</div>
				<TextControl __nextHasNoMarginBottom type="url" label={__('Page address', 'seoproanalytics')} value={page} onChange={setPage} help={url === '' && page.trim() !== '' ? __('Use a full address, starting with https://.', 'seoproanalytics') : undefined} />
				<div className="seoproan-campaign__fields">
					<TextControl __nextHasNoMarginBottom label={__('Source (utm_source), needed', 'seoproanalytics')} value={tags.utm_source} onChange={set('utm_source')} list={sourceList} placeholder="newsletter" />
					<TextControl __nextHasNoMarginBottom label={__('Medium (utm_medium)', 'seoproanalytics')} value={tags.utm_medium} onChange={set('utm_medium')} placeholder="email" />
					<TextControl __nextHasNoMarginBottom label={__('Campaign (utm_campaign), needed', 'seoproanalytics')} value={tags.utm_campaign} onChange={set('utm_campaign')} list={campaignList} placeholder="spring-sale" />
					<TextControl __nextHasNoMarginBottom label={__('Term (utm_term)', 'seoproanalytics')} value={tags.utm_term} onChange={set('utm_term')} help={__('Paid search keyword, if any.', 'seoproanalytics')} />
					<TextControl __nextHasNoMarginBottom label={__('Content (utm_content)', 'seoproanalytics')} value={tags.utm_content} onChange={set('utm_content')} help={__('Tells apart links in the same campaign, such as header and footer.', 'seoproanalytics')} />
				</div>
				<datalist id={campaignList}>
					{(campaigns.data?.rows ?? []).filter((r) => r.value !== '').map((r) => <option key={r.value} value={r.value} />)}
				</datalist>
				<datalist id={sourceList}>
					{(sources.data?.rows ?? []).filter((r) => r.value !== '').map((r) => <option key={r.value} value={r.value} />)}
				</datalist>

				<div>
					<strong>{__('Link', 'seoproanalytics')}</strong>
					<code className="seoproan-campaign__url">{ready ? url : __('Fill in the page address, a source and a campaign.', 'seoproanalytics')}</code>
				</div>
				<div>
					<Button variant="primary" disabled={!ready} accessibleWhenDisabled onClick={() => copy(url)}>
						{__('Copy link', 'seoproanalytics')}
					</Button>{' '}
					<Button variant="tertiary" onClick={() => setTags(EMPTY)}>
						{__('Clear', 'seoproanalytics')}
					</Button>
				</div>
				{copied && (
					<Notice status="success" isDismissible={false}>
						<span aria-live="polite">{__('Copied.', 'seoproanalytics')}</span>
					</Notice>
				)}

				{recent.length > 0 && (
					<div>
						<h3 className="seoproan-subtitle">{__('Recently copied (this browser)', 'seoproanalytics')}</h3>
						<ul>
							{recent.map((saved) => (
								<li key={saved}>
									<code>{saved}</code>{' '}
									<Button
										variant="link"
										onClick={() => copy(saved)}
										aria-label={sprintf(/* translators: %s: a link. */ __('Copy %s', 'seoproanalytics'), saved)}
									>
										{__('Copy', 'seoproanalytics')}
									</Button>
								</li>
							))}
						</ul>
					</div>
				)}
			</div>
		</Modal>
	);
}
