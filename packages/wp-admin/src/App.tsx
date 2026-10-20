/**
 * The SEO Pro Analytics screen: the period and comparison, the Live/Demo
 * switch and filters (shared by every section), then the section the URL
 * hash names and its Info & ideas. The tab bar above it is the server's:
 * its tabs are marked by useNavCurrent, and the controls go on its right
 * (#seoproan-dashboard-controls).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { useEffect, useState } from 'react';
import { Button } from '@wordpress/components';
import { createPortal } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { buildHash, DEFAULT_STATE, switchView, type View, type ViewState } from '@seoproanalytics/core';
import { useDemo } from './api';
import { useDataSet } from './data';
import { useViewState } from './hash';
import { Controls } from './components/Controls';
import { DemoNotice, DemoSwitch } from './components/Demo';
import { FilterBar } from './components/FilterBar';
import { InfoPanel } from './components/InfoPanel';
import { Overview } from './Overview';
import { Search } from './Search';
import { Goals } from './Goals';
import { Funnels } from './Funnels';
import { Properties } from './Properties';
import { Clicks } from './Clicks';
import { AbTests } from './AbTests';
import { Changes } from './Changes';
import { boot } from './boot';
import { ShareEditor, ShareLink, Shares } from './Shares';

export interface ViewProps {
	state: ViewState;
	update: (patch: Partial<ViewState>) => void;
}

function markCurrent(link: HTMLAnchorElement, current: boolean, className: string, item: Element | null): void {
	link.classList.toggle(className, current);
	item?.classList.toggle(className, current);
	if (current) {
		link.setAttribute('aria-current', 'page');
	} else {
		link.removeAttribute('aria-current');
	}
}

/**
 * Mark the section shown (none for shared reports) in the admin submenu,
 * whose items differ only by hash, and in the screen's tabs, which the
 * server draws (SEOProAnalytics_Dashboard::render()) like the settings
 * screen's. The tabs' links keep the period, comparison and filters.
 */
function useNavCurrent(state: ViewState, shown: View | null): void {
	useEffect(() => {
		document.querySelectorAll<HTMLAnchorElement>('#toplevel_page_seoproanalytics-dashboard .wp-submenu a').forEach((link) => {
			if (new URLSearchParams(link.search).get('page') !== 'seoproanalytics-dashboard') {
				return;
			}
			const hash = link.hash.replace(/^#\/?/, '').split('?')[0] ?? '';
			// The menu's first item is the screen itself, with no hash: the default section.
			markCurrent(link, (hash || DEFAULT_STATE.view) === shown, 'current', link.parentElement);
		});
		document.querySelectorAll<HTMLAnchorElement>('#seoproan-dashboard-nav .seoproan-nav__tab').forEach((link) => {
			// sections_nav() supplies plain hash links; keep the period and filters when switching.
			const view = link.hash.replace(/^#\/?/, '').split('?')[0] as View;
			link.href = buildHash(switchView(state, view));
			markCurrent(link, view === shown, 'is-active', null);
		});
	}, [state, shown]);
}

export function App() {
	const [state, update] = useViewState();
	const [pageTitle] = useState(() => document.title);
	const [sharing, setSharing] = useState(false);
	const [link, setLink] = useState<{ url: string; name: string } | null>(null);
	const [shares, setShares] = useState(window.location.hash.startsWith('#/shares'));
	useEffect(() => {
		const change = () => setShares(window.location.hash.startsWith('#/shares'));
		window.addEventListener('hashchange', change);
		return () => window.removeEventListener('hashchange', change);
	}, []);
	const data = useDataSet();
	const demo = useDemo();
	const waiting = data === 'demo' && demo.data.status !== 'ready';
	const sharesShown = shares && boot.canManage;
	useNavCurrent(state, sharesShown ? null : state.view);
	const view = sharesShown ? 'shares' : state.view;
	useEffect(() => {
		const title = boot.viewTitles[view];
		const at = boot.screenTitle ? pageTitle.indexOf(boot.screenTitle) : -1;
		if (title && at !== -1) {
			// Always use the original title, preserving core's site/network/recovery suffix.
			document.title = pageTitle.slice(0, at) + title + pageTitle.slice(at + boot.screenTitle.length);
		}
	}, [pageTitle, view]);

	const props: ViewProps = { state, update };
	if (sharesShown) {
		return <div className="seoproan-app"><a href={`#/${DEFAULT_STATE.view}`}>{__('Back to reports', 'seoproanalytics')}</a><Shares state={state} /></div>;
	}
	let section = <Search {...props} />;
	if (state.view === 'overview') {
		section = <Overview {...props} />;
	} else if (state.view === 'goals') {
		section = <Goals {...props} />;
	} else if (state.view === 'funnels') {
		section = <Funnels {...props} />;
	} else if (state.view === 'properties') {
		section = <Properties {...props} />;
	} else if (state.view === 'clicks') {
		section = <Clicks {...props} />;
	} else if (state.view === 'ab-tests') {
		section = <AbTests {...props} />;
	} else if (state.view === 'changes') {
		section = <Changes {...props} />;
	}

	// The Live/Demo switch, the period and comparison, then Share: on the
	// right of the tab bar when the screen has its slot (the live count is
	// the Visitors tab's, so the bar keeps its room for the tabs). Share
	// shows with demo data too (so screenshots show every control), but only
	// live statistics can be shared, so there it cannot be pressed.
	const shareable = boot.canManage;
	const controls = (
		<>
			<DemoSwitch />
			{!waiting && <Controls state={state} update={update} />}
			{shareable && (
				<Button
					variant="secondary"
					disabled={data !== 'live'}
					accessibleWhenDisabled
					title={data === 'live' ? undefined : __('Only live statistics can be shared. Switch to Live to share this view.', 'seoproanalytics')}
					onClick={() => setSharing(true)}
				>
					{__('Share', 'seoproanalytics')}
				</Button>
			)}
		</>
	);
	const slot = document.getElementById('seoproan-dashboard-controls');

	return (
		<div className="seoproan-app">
			{sharing && <ShareEditor state={state} close={() => setSharing(false)} saved={(share) => setLink(share.url ? { url: share.url, name: share.name } : null)} />}
			{link && <ShareLink url={link.url} name={link.name} onDismiss={() => setLink(null)} />}
			{slot ? createPortal(controls, slot) : <div className="seoproan-toolbar">{controls}</div>}

			<DemoNotice />

			{!waiting && (
				<>
					<FilterBar filters={state.filters} update={update} />
					{section}
					<InfoPanel state={state} />
				</>
			)}
		</div>
	);
}
