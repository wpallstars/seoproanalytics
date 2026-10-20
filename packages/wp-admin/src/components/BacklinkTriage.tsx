/**
 * Search → Backlinks: the owner's classes of links, referring pages and
 * sites, and the filters, search and sort of the lists (GET /backlinks,
 * POST /backlinks/classify). Links and Lost links take every filter; Sites
 * linking and Reported pages only Classes. Rows with a class hidden until ticked (Ignore, Spam) are
 * hidden until the Classes filter asks for them.
 *
 * A class can come from the row itself, from its referring page (Reported
 * pages) or from a line of the class lists (Settings → Backlinks); the
 * menu says where, and can put a link's site on a list so every link from
 * it, now and later, has the class.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { Fragment, useEffect, useState } from 'react';
import { addQueryArgs } from '@wordpress/url';
import { Button, CheckboxControl, Dropdown, Notice, SelectControl, TextControl } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	BACKLINK_CLASSES,
	BACKLINK_PAGE_ONLY_CLASSES,
	BACKLINK_HIDDEN_CLASSES,
	BACKLINK_METRICS,
	BACKLINK_RELS,
	type BacklinkClass,
	type BacklinkClassInfo,
	type BacklinkClassFilter,
	type BacklinkClassifyScope,
	type BacklinkInherited,
	type BacklinkKind,
	type BacklinkMetric,
	type BacklinkRelFilter,
	type BacklinkSearchIn,
	type BacklinkSort,
	type BacklinkTriage,
	type BacklinksAnswer,
	type SortOrder,
} from '@seoproanalytics/core';
import { classifyBacklinks, errorMessage, type ClassifyTarget } from '../api';
import { boot, locale } from '../boot';
import { useDataSet } from '../data';

/**
 * The classes in use on this site (Settings → Backlinks → Classes): the
 * built-in ones less those turned off, and the site's own. Without them
 * (a screen PHP does not tell), the built-in ones.
 */
const CLASSES: readonly BacklinkClassInfo[] = boot.backlinkClasses.length
	? boot.backlinkClasses
	: BACKLINK_CLASSES.map((name) => ({ name, label: '', hidden: BACKLINK_HIDDEN_CLASSES.includes(name), pageOnly: BACKLINK_PAGE_ONLY_CLASSES.includes(name), own: false }));
const CLASS_INFO = new Map(CLASSES.map((info) => [info.name, info]));
/** Every class in use. */
const IN_USE: readonly BacklinkClass[] = CLASSES.map((info) => info.name);
/** Classes hidden until ticked, after the line. */
const HIDDEN: readonly BacklinkClass[] = CLASSES.filter((info) => info.hidden).map((info) => info.name);
/** A page's classes, not a site's. */
const PAGE_ONLY: readonly BacklinkClass[] = CLASSES.filter((info) => info.pageOnly).map((info) => info.name);

/** A class's name, as the owner asked for them; a class no longer in use shows its key. */
export function className(name: BacklinkClassFilter): string {
	const names: Partial<Record<BacklinkClassFilter, string>> = {
		brand_citation: __('Citation of Brand Name', 'seoproanalytics'),
		nap_citation: __('Citation of Name, Address, Phone (NAP)', 'seoproanalytics'),
		cross_site: __('Cross-Site', 'seoproanalytics'),
		documentation: __('Documentation', 'seoproanalytics'),
		forum: __('Forum', 'seoproanalytics'),
		general: __('General', 'seoproanalytics'),
		guest_post: __('Guest Post', 'seoproanalytics'),
		guide: __('Guide', 'seoproanalytics'),
		home_page: __('Home Page', 'seoproanalytics'),
		integration: __('Integration', 'seoproanalytics'),
		link_swap: __('Link Swap', 'seoproanalytics'),
		listicle: __('Listicle', 'seoproanalytics'),
		logo_linked: __('Logo Linked', 'seoproanalytics'),
		monitor: __('Monitor', 'seoproanalytics'),
		needs_updating: __('Needs Updating', 'seoproanalytics'),
		niche_edit: __('Niche Edit', 'seoproanalytics'),
		organic_mention: __('Organic Mention', 'seoproanalytics'),
		paid_link: __('Paid Link', 'seoproanalytics'),
		partner: __('Partner', 'seoproanalytics'),
		press_organic: __('Press Organic', 'seoproanalytics'),
		press_release: __('Press Release', 'seoproanalytics'),
		private_blog: __('Private Blog (PBN)', 'seoproanalytics'),
		profile_page: __('Profile Page', 'seoproanalytics'),
		blog: __('Public Blog', 'seoproanalytics'),
		quote: __('Quote', 'seoproanalytics'),
		redirect: __('Redirect', 'seoproanalytics'),
		site_footer: __('Site Footer', 'seoproanalytics'),
		social_media: __('Social Media', 'seoproanalytics'),
		statistics: __('Statistics', 'seoproanalytics'),
		topical: __('Topical', 'seoproanalytics'),
		url_linked: __('URL Linked', 'seoproanalytics'),
		url_unlinked: __('URL Unlinked', 'seoproanalytics'),
		video: __('Video', 'seoproanalytics'),
		wiki: __('Wiki', 'seoproanalytics'),
		ignore: __('Ignore', 'seoproanalytics'),
		spam: __('Spam', 'seoproanalytics'),
		none: __('No class', 'seoproanalytics'),
	};
	return names[name] ?? (CLASS_INFO.get(name)?.label || name);
}

const collator = new Intl.Collator(locale, { sensitivity: 'base', numeric: true });

/** Whether a class is hidden until ticked (Ignore and Spam to start), listed after a line. */
function isHidden(name: BacklinkClassFilter): boolean {
	return HIDDEN.includes(name);
}

/** Classes in the order they are shown: A to Z by their names in this language, then those hidden, A to Z. */
export function sortClasses<T extends BacklinkClassFilter>(names: readonly T[]): T[] {
	return [...names].sort((a, b) => Number(isHidden(a)) - Number(isHidden(b)) || collator.compare(className(a), className(b)));
}

/** Every choice of the Classes filter: rows without a class, then the classes. */
const CLASS_FILTERS: readonly BacklinkClassFilter[] = ['none', ...sortClasses(IN_USE)];

/** Checkboxes of classes, with a line before those hidden until ticked. */
function ClassChecks<T extends BacklinkClassFilter>({ names, render }: Readonly<{ names: readonly T[]; render: (name: T) => React.ReactNode }>) {
	const split = names.findIndex(isHidden);
	return (
		<>
			{names.map((name, i) => (
				<Fragment key={name}>
					{i === split && i > 0 && <hr className="seoproan-backlinks__divider" />}
					{render(name)}
				</Fragment>
			))}
		</>
	);
}

/** Settings → Backlinks, where the class lists are; '' when the settings are out of reach. */
export function listsUrl(): string {
	return boot.settingsUrl ? addQueryArgs(boot.settingsUrl, { tab: 'backlinks' }) : '';
}

/** Where a class a row did not get itself comes from, in words; '' for its own. */
export function inheritedHint(inherited: BacklinkInherited | undefined, name: BacklinkClass): string {
	const from = inherited?.[name];
	if (!from) {
		return '';
	}
	return from.from === 'page'
		? __('From its referring page (Reported pages). Untick to take it off here only.', 'seoproanalytics')
		: /* translators: %s: a line of a class list, e.g. "linktr.ee". */ sprintf(__('From “%s” in Settings → Backlinks. Untick to take it off here only.', 'seoproanalytics'), from.line);
}

/** A metric's short name, for headers and the ranges. */
export function metricName(metric: BacklinkMetric): string {
	const names: Record<BacklinkMetric, string> = {
		site_dr: __('Site DR', 'seoproanalytics'),
		site_ld: __('Site LD', 'seoproanalytics'),
		page_dr: __('Page DR', 'seoproanalytics'),
		page_ld: __('Page LD', 'seoproanalytics'),
	};
	return names[metric];
}

/** What a metric is, for tooltips. */
export function metricHint(metric: BacklinkMetric): string {
	const hints: Record<BacklinkMetric, string> = {
		site_dr: __('The linking site’s rank, 0–100', 'seoproanalytics'),
		site_ld: __('Sites linking to the linking site', 'seoproanalytics'),
		page_dr: __('The linking page’s rank, 0–100', 'seoproanalytics'),
		page_ld: __('Sites linking to the linking page', 'seoproanalytics'),
	};
	return hints[metric];
}

function relName(rel: BacklinkRelFilter): string {
	const names: Record<BacklinkRelFilter, string> = {
		followed: __('followed', 'seoproanalytics'),
		nofollow: 'nofollow',
		sponsored: 'sponsored',
		ugc: 'ugc',
	};
	return names[rel];
}

type Range = [number | null, number | null];
type DateField = NonNullable<BacklinkTriage['date']>;

/** The triage as chosen on screen. */
export interface TriageChoice {
	/** Classes left out of the Classes filter: those hidden until ticked (Settings → Backlinks → Classes). */
	hidden: BacklinkClassFilter[];
	/** rel values left out. */
	relHidden: BacklinkRelFilter[];
	search: string;
	searchIn: BacklinkSearchIn | 'all';
	date: DateField;
	from: string;
	to: string;
	ranges: Partial<Record<BacklinkMetric, Range>>;
	sort?: BacklinkSort;
	order?: SortOrder;
}

export const TRIAGE_DEFAULT: TriageChoice = { hidden: [...HIDDEN], relHidden: [], search: '', searchIn: 'all', date: 'first_seen', from: '', to: '', ranges: {} };

/** The choice as GET /backlinks arguments; the date field is first_seen on links that are not lost. */
export function toTriage(choice: TriageChoice, kind: BacklinkKind): BacklinkTriage {
	const out: BacklinkTriage = {};
	if (choice.hidden.length) {
		out.classes = CLASS_FILTERS.filter((name) => !choice.hidden.includes(name));
	}
	if (choice.relHidden.length) {
		out.rel = BACKLINK_RELS.filter((rel) => !choice.relHidden.includes(rel));
	}
	if (choice.search.trim()) {
		out.search = choice.search.trim();
		if (choice.searchIn !== 'all') {
			out.search_in = [choice.searchIn];
		}
	}
	if (choice.from || choice.to) {
		out.date = choice.date === 'lost' && kind !== 'lost' ? 'first_seen' : choice.date;
		out.date_from = choice.from || undefined;
		out.date_to = choice.to || undefined;
	}
	const ranges = Object.fromEntries(Object.entries(choice.ranges).filter(([, range]) => range && (range[0] !== null || range[1] !== null)));
	if (Object.keys(ranges).length) {
		out.ranges = ranges;
	}
	if (choice.sort && (choice.sort !== 'lost' || kind === 'lost')) {
		out.sort = choice.sort;
		out.order = choice.order;
	}
	return out;
}

/** Whether any filter or search narrows the list (sorting does not). */
export function isFiltered(choice: TriageChoice): boolean {
	return choice.hidden.length > 0 || choice.relHidden.length > 0 || choice.search.trim() !== '' || choice.from !== '' || choice.to !== '' || Object.values(choice.ranges).some((range) => range && (range[0] !== null || range[1] !== null));
}

/** A value that follows another after it has been still for a moment: typing asks once. */
export function useSettled<T>(value: T, ms = 350): T {
	const [settled, setSettled] = useState(value);
	useEffect(() => {
		const timer = window.setTimeout(() => setSettled(value), ms);
		return () => window.clearTimeout(timer);
	}, [value, ms]);
	return settled;
}

/** A filter's button and its panel. */
function FilterMenu({ label, summary, active, children }: Readonly<{ label: string; summary: string; active: boolean; children: React.ReactNode }>) {
	return (
		<Dropdown
			className="seoproan-backlinks__menu"
			popoverProps={{ placement: 'bottom-start', flip: true, shift: true }}
			renderToggle={({ isOpen, onToggle }) => (
				<Button variant="secondary" className={active ? 'is-filtered' : undefined} aria-expanded={isOpen} onClick={onToggle} __next40pxDefaultSize>
					{label}
					{summary && <span className="seoproan-backlinks__summary">{summary}</span>}
				</Button>
			)}
			renderContent={() => <div className="seoproan-backlinks__panel">{children}</div>}
		/>
	);
}

/** One end of a range: empty is open. */
function bound(text: string): number | null {
	if (text.trim() === '') {
		return null;
	}
	const value = Math.max(0, Math.floor(Number(text)));
	return Number.isFinite(value) ? value : null;
}

interface TriageBarProps {
	kind: BacklinkKind;
	choice: TriageChoice;
	onChange: (next: TriageChoice) => void;
	facets: BacklinksAnswer['facets'];
}

/** The Classes filter's menu. */
function ClassesFilter({ kind, choice, onChange, facets }: Readonly<TriageBarProps>) {
	const set = (patch: Partial<TriageChoice>) => onChange({ ...choice, ...patch });
	const shown = CLASS_FILTERS.length - choice.hidden.length;
	const legends: Partial<Record<BacklinkKind, string>> = {
		domains: __('Show sites classed', 'seoproanalytics'),
		reported: __('Show pages classed', 'seoproanalytics'),
	};
	const hints: Partial<Record<BacklinkKind, string>> = {
		domains: __('A site shows when every class it has is ticked. Its classes come from the lists in Settings → Backlinks.', 'seoproanalytics'),
		reported: __('A page shows when every class it has is ticked.', 'seoproanalytics'),
	};
	return (
		<FilterMenu label={__('Classes', 'seoproanalytics')} summary={choice.hidden.length ? `${shown}/${CLASS_FILTERS.length}` : ''} active={choice.hidden.length > 0}>
			<fieldset>
				<legend className="seoproan-backlinks__legend">{legends[kind] ?? __('Show links classed', 'seoproanalytics')}</legend>
				<ClassChecks
					names={CLASS_FILTERS}
					render={(name) => {
						const on = !choice.hidden.includes(name);
						return (
							<CheckboxControl
								__nextHasNoMarginBottom
								label={facets ? `${className(name)} (${facets.classes[name] ?? 0})` : className(name)}
								checked={on}
								onChange={() => set({ hidden: on ? [...choice.hidden, name] : choice.hidden.filter((item) => item !== name) })}
							/>
						);
					}}
				/>
			</fieldset>
			<p className="seoproan-meta">{hints[kind] ?? __('A link shows when every class it has is ticked.', 'seoproanalytics')}</p>
			<div className="seoproan-backlinks__panel-actions">
				<Button variant="link" onClick={() => set({ hidden: [] })}>{__('All', 'seoproanalytics')}</Button>
				<Button variant="link" onClick={() => set({ hidden: [...HIDDEN] })}>{__('Default', 'seoproanalytics')}</Button>
			</div>
		</FilterMenu>
	);
}

/** Sites linking and Reported pages: only the Classes filter. */
export function ClassesBar(props: Readonly<TriageBarProps>) {
	const { choice, onChange } = props;
	return (
		<div className="seoproan-backlinks__triage" role="search">
			<ClassesFilter {...props} />
			{choice.hidden.length > 0 && !sameClasses(choice.hidden, HIDDEN) && (
				<Button variant="tertiary" __next40pxDefaultSize onClick={() => onChange({ ...choice, hidden: [...HIDDEN] })}>
					{__('Reset filters', 'seoproanalytics')}
				</Button>
			)}
		</div>
	);
}

/** Whether two class lists hold the same classes. */
function sameClasses(a: readonly BacklinkClassFilter[], b: readonly BacklinkClassFilter[]): boolean {
	return a.length === b.length && a.every((name) => b.includes(name));
}

/** Search, Classes, Rel, DR and LD, and Dates above the list. */
export function TriageBar({ kind, choice, onChange, facets }: Readonly<TriageBarProps>) {
	const set = (patch: Partial<TriageChoice>) => onChange({ ...choice, ...patch });
	const toggle = <T extends string>(list: T[], value: T, on: boolean): T[] => (on ? list.filter((item) => item !== value) : [...list, value]);
	const ranged = BACKLINK_METRICS.filter((metric) => {
		const range = choice.ranges[metric];
		return range && (range[0] !== null || range[1] !== null);
	}).length;
	const dateFields: { value: DateField; label: string }[] = [
		{ value: 'first_seen', label: __('First found', 'seoproanalytics') },
		{ value: 'last_seen', label: __('Last found', 'seoproanalytics') },
		...(kind === 'lost' ? [{ value: 'lost' as DateField, label: __('Lost', 'seoproanalytics') }] : []),
	];
	return (
		<div className="seoproan-backlinks__triage" role="search">
			<TextControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				type="search"
				label={__('Search links', 'seoproanalytics')}
				value={choice.search}
				maxLength={200}
				onChange={(search) => set({ search })}
			/>
			<SelectControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={__('Search in', 'seoproanalytics')}
				value={choice.searchIn}
				options={[
					{ value: 'all', label: __('Everything', 'seoproanalytics') },
					{ value: 'source', label: __('Linking page', 'seoproanalytics') },
					{ value: 'page', label: __('Links to', 'seoproanalytics') },
					{ value: 'anchor', label: __('Text', 'seoproanalytics') },
				]}
				onChange={(searchIn: string) => set({ searchIn: searchIn as TriageChoice['searchIn'] })}
			/>
			<ClassesFilter kind={kind} choice={choice} onChange={onChange} facets={facets} />
			<FilterMenu label={__('Rel', 'seoproanalytics')} summary={choice.relHidden.length ? `${BACKLINK_RELS.length - choice.relHidden.length}/${BACKLINK_RELS.length}` : ''} active={choice.relHidden.length > 0}>
				<fieldset>
					<legend className="seoproan-backlinks__legend">{__('Show links that are', 'seoproanalytics')}</legend>
					{BACKLINK_RELS.map((rel) => {
						const on = !choice.relHidden.includes(rel);
						return <CheckboxControl __nextHasNoMarginBottom key={rel} label={relName(rel)} checked={on} onChange={() => set({ relHidden: toggle(choice.relHidden, rel, !on) })} />;
					})}
				</fieldset>
			</FilterMenu>
			<FilterMenu label={__('DR and LD', 'seoproanalytics')} summary={ranged ? String(ranged) : ''} active={ranged > 0}>
				<fieldset className="seoproan-backlinks__ranges">
					<legend className="seoproan-backlinks__legend">{__('Only links within', 'seoproanalytics')}</legend>
					{BACKLINK_METRICS.map((metric) => {
						const range = choice.ranges[metric] ?? [null, null];
						const most = facets?.max[metric] ?? 0;
						const setRange = (next: Range) => set({ ranges: { ...choice.ranges, [metric]: next } });
						return (
							<div className="seoproan-backlinks__range" key={metric} title={metricHint(metric)}>
								<span className="seoproan-backlinks__range-name">{metricName(metric)}</span>
								<TextControl __nextHasNoMarginBottom type="number" min={0} label={__('From', 'seoproanalytics')} value={range[0] === null ? '' : String(range[0])} placeholder="0" onChange={(text) => setRange([bound(text), range[1]])} />
								<TextControl __nextHasNoMarginBottom type="number" min={0} label={__('To', 'seoproanalytics')} value={range[1] === null ? '' : String(range[1])} placeholder={most ? String(most) : ''} onChange={(text) => setRange([range[0], bound(text)])} />
							</div>
						);
					})}
				</fieldset>
				<p className="seoproan-meta">{__('DR: rank, 0–100. LD: sites linking to it. Links not measured yet are left out while a range is set.', 'seoproanalytics')}</p>
				{ranged > 0 && (
					<div className="seoproan-backlinks__panel-actions">
						<Button variant="link" onClick={() => set({ ranges: {} })}>{__('Clear', 'seoproanalytics')}</Button>
					</div>
				)}
			</FilterMenu>
			<FilterMenu label={__('Dates', 'seoproanalytics')} summary={choice.from || choice.to ? '1' : ''} active={!!(choice.from || choice.to)}>
				<SelectControl __nextHasNoMarginBottom label={__('Date', 'seoproanalytics')} value={choice.date === 'lost' && kind !== 'lost' ? 'first_seen' : choice.date} options={dateFields} onChange={(date: string) => set({ date: date as DateField })} />
				<TextControl __nextHasNoMarginBottom type="date" label={__('From', 'seoproanalytics')} value={choice.from} max={choice.to || undefined} onChange={(from) => set({ from })} />
				<TextControl __nextHasNoMarginBottom type="date" label={__('To', 'seoproanalytics')} value={choice.to} min={choice.from || undefined} onChange={(to) => set({ to })} />
				{(choice.from || choice.to) && (
					<div className="seoproan-backlinks__panel-actions">
						<Button variant="link" onClick={() => set({ from: '', to: '' })}>{__('Clear', 'seoproanalytics')}</Button>
					</div>
				)}
			</FilterMenu>
			{isFiltered(choice) && (
				<Button variant="tertiary" __next40pxDefaultSize onClick={() => onChange({ ...TRIAGE_DEFAULT, sort: choice.sort, order: choice.order })}>
					{__('Reset filters', 'seoproanalytics')}
				</Button>
			)}
		</div>
	);
}

/** The classes as small badges; one from the row's page or a list says where in its tooltip. */
export function ClassBadges({ classes, inherited }: Readonly<{ classes: BacklinkClass[]; inherited?: BacklinkInherited }>) {
	if (!classes.length) {
		return null;
	}
	return (
		<span className="seoproan-backlinks__classes">
			{sortClasses(classes).map((name) => {
				const hint = inheritedHint(inherited, name);
				return (
					<span key={name} className={`seoproan-app-badge seoproan-backlinks__class is-${name.replace(/_/g, '-')}${hint ? ' is-inherited' : ''}`} title={hint || undefined}>
						{className(name)}
					</span>
				);
			})}
		</span>
	);
}

/** A row the menu classes. */
export interface ClassifyItem {
	/** The row's id: a link's or a referring page's (0 for a site). */
	id: number;
	/** Its classes now, and where those it did not get itself come from. */
	classes: BacklinkClass[];
	inherited?: BacklinkInherited;
	/** The linking site (for a site, the site itself). */
	host: string;
}

interface ClassifyMenuProps {
	items: ClassifyItem[];
	/** What the rows are: links, referring pages, or sites (whose classes are their lines on the class lists). */
	scope: BacklinkClassifyScope;
	/** The toggle's text. */
	label: string;
	/** For the toggle's accessible name. */
	describe: string;
	variant?: 'link' | 'secondary';
	onDone?: (message: string) => void;
}

/** What a classification asks the API to change. */
function targetOf(scope: BacklinkClassifyScope, items: ClassifyItem[]): ClassifyTarget {
	if (scope === 'sites') {
		return { sites: [...new Set(items.map((item) => item.host).filter(Boolean))] };
	}
	return scope === 'pages' ? { pages: items.map((item) => item.id) } : { ids: items.map((item) => item.id) };
}

/** What changed, in words. */
function doneMessage(scope: BacklinkClassifyScope, add: boolean, count: number, name: BacklinkClass, updated: number): string {
	const label = className(name);
	if (scope === 'sites') {
		if (!updated) {
			return add
				? /* translators: %s: a class, e.g. "Spam". */ sprintf(__('Already on the %s list.', 'seoproanalytics'), label)
				: /* translators: %s: a class, e.g. "Spam". */ sprintf(__('Not on the %s list.', 'seoproanalytics'), label);
		}
		return add
			? /* translators: 1: number of sites, 2: a class, e.g. "Spam". */ sprintf(_n('%1$s site added to the %2$s list: every link from it, now and later, has the class.', '%1$s sites added to the %2$s list: every link from them, now and later, has the class.', count, 'seoproanalytics'), String(count), label)
			: /* translators: 1: number of sites, 2: a class, e.g. "Spam". */ sprintf(_n('%1$s site taken off the %2$s list: its links no longer have the class, unless classed one by one.', '%1$s sites taken off the %2$s list: their links no longer have the class, unless classed one by one.', count, 'seoproanalytics'), String(count), label);
	}
	if (scope === 'pages') {
		return add
			? /* translators: 1: number of pages, 2: a class, e.g. "Listicle". */ sprintf(_n('%1$s page classed %2$s, with every link on it.', '%1$s pages classed %2$s, with every link on them.', count, 'seoproanalytics'), String(count), label)
			: /* translators: 1: number of pages, 2: a class, e.g. "Listicle". */ sprintf(_n('%2$s removed from %1$s page.', '%2$s removed from %1$s pages.', count, 'seoproanalytics'), String(count), label);
	}
	return add
		? /* translators: 1: number of links, 2: a class, e.g. "Monitor". */ sprintf(_n('%1$s link classed %2$s.', '%1$s links classed %2$s.', count, 'seoproanalytics'), String(count), label)
		: /* translators: 1: number of links, 2: a class, e.g. "Monitor". */ sprintf(_n('%2$s removed from %1$s link.', '%2$s removed from %1$s links.', count, 'seoproanalytics'), String(count), label);
}

/** How many rows have a class, and how many have it only from their page or a list. */
function holding(items: ClassifyItem[], name: BacklinkClass, sites: boolean) {
	if (sites) {
		// On the lists: the class a list gives the site.
		const listed = items.filter((item) => item.inherited?.[name]?.from === 'list').length;
		return { have: listed, fixed: 0 };
	}
	return {
		have: items.filter((item) => item.classes.includes(name)).length,
		fixed: items.filter((item) => item.inherited?.[name]).length,
	};
}

/**
 * Tick a class to add it, untick to remove it. With several rows, a class
 * some have shows half-ticked; ticking adds it to all. A class a row has
 * from its page or a list says where from, and unticking keeps it off that
 * row. Links and pages can instead put their sites on the class lists
 * (Settings → Backlinks), so every link from them, now and later, has it;
 * classes of one page (PAGE_ONLY) are not offered for
 * whole sites.
 */
export function ClassifyMenu({ items, scope, label, describe, variant = 'link', onDone }: Readonly<ClassifyMenuProps>) {
	const data = useDataSet();
	const [busy, setBusy] = useState<BacklinkClass | null>(null);
	const [error, setError] = useState('');
	const [wide, setWide] = useState(false);
	const asSites = scope === 'sites' || wide;
	const chosen: BacklinkClassifyScope = asSites ? 'sites' : scope;
	const hosts = [...new Set(items.map((item) => item.host).filter(Boolean))];
	const settings = listsUrl();
	async function apply(name: BacklinkClass, add: boolean) {
		setBusy(name);
		setError('');
		try {
			const done = await classifyBacklinks(targetOf(chosen, items), [name], add ? 'add' : 'remove', data);
			const count = chosen === 'sites' ? hosts.length : done.updated || items.length;
			onDone?.(doneMessage(chosen, add, count, name, done.updated));
		} catch (caught) {
			setError(errorMessage(caught, __('The classes could not be saved.', 'seoproanalytics')));
		} finally {
			setBusy(null);
		}
	}
	return (
		<Dropdown
			className="seoproan-backlinks__classify"
			popoverProps={{ placement: 'bottom-end', flip: true, shift: true }}
			renderToggle={({ isOpen, onToggle }) => (
				<Button variant={variant} className={variant === 'link' ? 'seoproan-research__toggle' : undefined} aria-expanded={isOpen} describedBy={describe} disabled={!items.length} onClick={onToggle}>
					{label}
				</Button>
			)}
			renderContent={() => (
				<div className="seoproan-backlinks__panel">
					{scope !== 'sites' && hosts.length > 0 && (
						<CheckboxControl
							__nextHasNoMarginBottom
							className="seoproan-backlinks__wide"
							label={
								hosts.length === 1
									? /* translators: %s: a site, e.g. "example.com". */ sprintf(__('Every link from %s', 'seoproanalytics'), hosts[0] ?? '')
									: /* translators: %s: number of sites. */ sprintf(__('Every link from these %s sites', 'seoproanalytics'), String(hosts.length))
							}
							help={__('Puts the site on the class’s list in Settings → Backlinks: links found from it later have the class too.', 'seoproanalytics')}
							checked={wide}
							disabled={busy !== null}
							onChange={setWide}
						/>
					)}
					<fieldset>
						<legend className="seoproan-backlinks__legend">{asSites && scope !== 'sites' ? __('Classes of the sites', 'seoproanalytics') : describe}</legend>
						<ClassChecks
							names={sortClasses(IN_USE.filter((name) => !asSites || !PAGE_ONLY.includes(name)))}
							render={(name) => {
								const { have, fixed } = holding(items, name, asSites);
								const all = have > 0 && have === items.length;
								const hint = items.length === 1 ? inheritedHint(items[0]?.inherited, name) : fixed ? __('Some have it from their page or Settings → Backlinks; unticking takes it off these rows only.', 'seoproanalytics') : '';
								return (
									<CheckboxControl
										__nextHasNoMarginBottom
										label={className(name)}
										help={hint || undefined}
										checked={all}
										indeterminate={have > 0 && !all}
										disabled={busy !== null}
										onChange={() => void apply(name, !all)}
									/>
								);
							}}
						/>
					</fieldset>
					{asSites && (
						<p className="seoproan-meta">
							{sprintf(
								/* translators: %s: classes, comma-separated, e.g. "Home Page, Listicle, Quote". */
								__('These describe one page, not a site, so they are classed per link or page: %s.', 'seoproanalytics'),
								sortClasses(PAGE_ONLY).map(className).join(', ')
							)}
						</p>
					)}
					{settings && (
						<p className="seoproan-meta">
							<a href={settings}>{__('Edit the class lists', 'seoproanalytics')}</a>
						</p>
					)}
					{error && (
						<Notice status="error" isDismissible={false} className="seoproan-notice">
							{error}
						</Notice>
					)}
				</div>
			)}
		/>
	);
}
