/**
 * Shared reports for administrators: the Share editor (a modal from the
 * Share button or the list), the list of reports, and the private link,
 * recovered by the owner from an encrypted copy, with Copy and Open.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */
import { useEffect, useId, useLayoutEffect, useRef, useState, type FormEvent, type KeyboardEvent, type PointerEvent } from 'react';
import apiFetch from '@wordpress/api-fetch';
import { Button, CheckboxControl, Modal, Notice, SelectControl, TextControl, TextareaControl } from '@wordpress/components';
import { addQueryArgs } from '@wordpress/url';
import { __, sprintf } from '@wordpress/i18n';
import { shareSectionKey, shareView, switchView, SHARE_VIEWS, type SearchEngine, type View, type ViewState } from '@seoproanalytics/core';
import { errorMessage } from './api';
import { boot, locale } from './boot';
import { filterLabel, sectionLabel } from './labels';

export interface ShareBranding {
    title: string;
    logo: number;
    agency: string;
    agency_logo: number;
    website: string;
    byline: string;
    accent: string;
    mode: 'light' | 'dark' | 'system';
    credit: boolean;
}
interface ReportEmail {
    enabled: boolean;
    recipients: string[];
    interval: 'daily' | 'weekdays' | 'weekly' | 'monthly' | 'quarterly';
    hour: number;
    intro: string;
    events: boolean;
    notes: boolean;
    last_period?: string;
    last_sent?: number;
    last_error?: string;
}
export interface SharedReport {
    id: string;
    name: string;
    note: string;
    views: ViewState[];
    locked_filters: ViewState['filters'];
    max_days: number;
    expires: number;
    hide_sensitive: boolean;
    hide_realtime: boolean;
    created: number;
    last_opened: number;
    opens: number;
    revoked: boolean;
    protected: boolean;
    copyable: boolean;
    email_password: boolean;
    password_stored: boolean;
    branding: ShareBranding;
    email?: ReportEmail;
    url?: string;
}
/** GET /shares. */
interface SharesAnswer {
    shares: SharedReport[];
    defaults: ShareBranding;
    /** Logo attachment id => image address. */
    logos: Record<string, string>;
    /** Search engines with data: a Search section for each. */
    engines: SearchEngine[];
    /** Sections with live data (shareSectionKey() names), in tab order: a new report starts with these. */
    sections?: string[];
    email_intros?: Record<string, string>;
}

const path = '/seoproanalytics/v1/shares';
const MAX_SECTIONS = 10;
const DEFAULT_BRANDING: ShareBranding = { title: '', logo: 0, agency: '', agency_logo: 0, website: '', byline: '', accent: '#2271b1', mode: 'system', credit: true };
const DEFAULT_EMAIL: ReportEmail = { enabled: false, recipients: [], interval: 'weekly', hour: 8, intro: '', events: true, notes: false };
const emailIntervals = () => [
    { value: 'daily', label: __('Daily', 'seoproanalytics') },
    { value: 'weekdays', label: __('Weekdays', 'seoproanalytics') },
    { value: 'weekly', label: __('Weekly', 'seoproanalytics') },
    { value: 'monthly', label: __('Monthly', 'seoproanalytics') },
    { value: 'quarterly', label: __('Quarterly', 'seoproanalytics') },
];
const emailBadge = (interval: ReportEmail['interval']) => ({
    daily: __('Emailed daily', 'seoproanalytics'), weekdays: __('Emailed on weekdays', 'seoproanalytics'), weekly: __('Emailed weekly', 'seoproanalytics'), monthly: __('Emailed monthly', 'seoproanalytics'), quarterly: __('Emailed quarterly', 'seoproanalytics'),
})[interval];
/** Sections only page filters can narrow (search data and the change log have no visits). */
const PAGE_ONLY: ReadonlySet<string> = new Set(['search', 'changes']);

/** WordPress's Media Library frame (wp_enqueue_media() on this screen). */
interface MediaFrame {
    on: (event: 'select', callback: () => void) => void;
    open: () => void;
    state: () => { get: (name: 'selection') => { first: () => { toJSON: () => { id: number; url: string; sizes?: { medium?: { url: string } } } } } };
}
const mediaLibrary = () => (window as unknown as { wp?: { media?: (options: Record<string, unknown>) => MediaFrame } }).wp?.media;

function chooseImage(title: string, done: (id: number, url: string) => void): void {
    const media = mediaLibrary();
    if (!media) {
        return;
    }
    const frame = media({ title, multiple: false, library: { type: ['image/png', 'image/jpeg', 'image/webp', 'image/gif'] }, button: { text: __('Use this image', 'seoproanalytics') } });
    frame.on('select', () => {
        const picked = frame.state().get('selection').first().toJSON();
        done(picked.id, picked.sizes?.medium?.url ?? picked.url);
    });
    frame.open();
}

/** The private link, with Copy and Open (a new window). */
export function ShareLink({ url, name, onDismiss }: Readonly<{ url: string; name?: string; onDismiss: () => void }>) {
    const id = useId();
    const input = useRef<HTMLInputElement>(null);
    const [copied, setCopied] = useState(false);
    const copy = () => {
        input.current?.select();
        navigator.clipboard.writeText(url).then(
            () => setCopied(true),
            () => setCopied(false)
        );
    };
    return (
        <Notice status="success" className="seoproan-notice seoproan-share-link" onRemove={onDismiss}>
            <p className="seoproan-share-link__title">
                <strong>{name ? sprintf(/* translators: %s: a shared report's name. */ __('The private link to “%s”', 'seoproanalytics'), name) : __('The private link', 'seoproanalytics')}</strong>
            </p>
            <p className="seoproan-share-link__help">{__('Keep this link private. When an encrypted copy is available, you can copy it again from Shared reports. Making a new link stops the old one.', 'seoproanalytics')}</p>
            <div className="seoproan-share-link__row">
                <label className="screen-reader-text" htmlFor={id}>{__('Private link', 'seoproanalytics')}</label>
                <input ref={input} id={id} className="seoproan-share-link__url code" readOnly value={url} onFocus={(event) => event.currentTarget.select()} />
                <Button variant="primary" icon={copied ? 'yes' : 'admin-page'} onClick={copy}>
                    {copied ? __('Copied', 'seoproanalytics') : __('Copy link', 'seoproanalytics')}
                </Button>
                <Button variant="secondary" icon="external" href={url} target="_blank" rel="noopener noreferrer">
                    {__('Open', 'seoproanalytics')}
                    <span className="screen-reader-text">{__('(opens in a new window)', 'seoproanalytics')}</span>
                </Button>
            </div>
            <span aria-live="polite" className="screen-reader-text">{copied ? __('Link copied.', 'seoproanalytics') : ''}</span>
        </Notice>
    );
}

function LogoField({ label, help, id, url, choose, clear }: Readonly<{ label: string; help: string; id: number; url: string; choose: () => void; clear: () => void }>) {
    const canChoose = !!mediaLibrary();
    return (
        <div className="seoproan-share-logo">
            <span className="seoproan-share-logo__label">{label}</span>
            {id > 0 && url && <img src={url} alt="" className="seoproan-share-logo__image" />}
            <div className="seoproan-share-logo__actions">
                <Button variant="secondary" disabled={!canChoose} accessibleWhenDisabled onClick={choose}>
                    {id ? __('Change', 'seoproanalytics') : __('Choose from the Media Library', 'seoproanalytics')}
                </Button>
                {id > 0 && <Button variant="tertiary" isDestructive onClick={clear}>{__('Remove', 'seoproanalytics')}</Button>}
            </div>
            <p className="seoproan-share-logo__help">{canChoose ? help : __('The Media Library did not load. Reload the page to choose a logo.', 'seoproanalytics')}</p>
        </div>
    );
}

/** Six dots: a handle to drag (WordPress's own drag handle, drawn here). */
function DragHandleIcon() {
    return (
        <svg width="24" height="24" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            {[8, 15].flatMap((x) => [7, 12, 17].map((y) => <circle key={`${x}-${y}`} cx={x + 0.5} cy={y} r="1.5" />))}
        </svg>
    );
}

/**
 * The sections in order. Drag one by its handle (mouse, pen or touch); or
 * Tab to the handle, press Space, move it with the arrow keys (Home, End)
 * and press Space again; Escape puts it back. Each move is announced.
 */
function SectionOrder({ views, setViews }: Readonly<{ views: ViewState[]; setViews: (views: ViewState[]) => void }>) {
    const help = useId();
    const list = useRef<HTMLOListElement>(null);
    const handles = useRef(new Map<string, HTMLButtonElement>());
    // Picked up from the keyboard: the section and where it was.
    const [held, setHeld] = useState<{ key: string; from: number } | null>(null);
    // Dragged with a pointer.
    const [dragging, setDragging] = useState<string | null>(null);
    const [said, setSaid] = useState('');
    const keys = views.map((view) => shareSectionKey(view));
    const count = views.length;
    const moveTo = (key: string, to: number) => {
        const from = keys.indexOf(key);
        if (from < 0 || to < 0 || to >= count || from === to) {
            return;
        }
        const next = [...views];
        const [item] = next.splice(from, 1);
        next.splice(to, 0, item!);
        setViews(next);
    };
    /* translators: 1: a report section, such as Visitors, 2: its place in the list, 3: the number of sections. */
    const at = (name: string, place: number) => sprintf(__('%1$s, position %2$d of %3$d.', 'seoproanalytics'), name, place + 1, count);
    // React may move the focused handle in the page: keep the focus on the one held.
    useLayoutEffect(() => {
        if (held) {
            handles.current.get(held.key)?.focus();
        }
    }, [views, held]);

    const onKey = (event: KeyboardEvent<HTMLButtonElement>, key: string) => {
        const place = keys.indexOf(key);
        const name = sectionLabel(views[place]!);
        if (event.key === ' ' || event.key === 'Enter') {
            event.preventDefault();
            if (held?.key === key) {
                setHeld(null);
                setSaid(`${sprintf(/* translators: %s: a report section. */ __('%s dropped.', 'seoproanalytics'), name)} ${at(name, place)}`);
            } else {
                setHeld({ key, from: place });
                setSaid(`${sprintf(/* translators: %s: a report section. */ __('%s picked up.', 'seoproanalytics'), name)} ${at(name, place)} ${__('Move it with the up and down arrow keys, then press Space to drop it, or Escape to cancel.', 'seoproanalytics')}`);
            }
            return;
        }
        if (held?.key !== key) {
            return;
        }
        if (event.key === 'Escape') {
            // Not the dialog's Escape: only the move is cancelled.
            event.preventDefault();
            event.stopPropagation();
            moveTo(key, held.from);
            setHeld(null);
            setSaid(`${sprintf(/* translators: %s: a report section. */ __('Moving %s cancelled.', 'seoproanalytics'), name)} ${at(name, held.from)}`);
            return;
        }
        const to = ({ ArrowUp: place - 1, ArrowDown: place + 1, Home: 0, End: count - 1 } as Record<string, number>)[event.key];
        if (to === undefined) {
            return;
        }
        event.preventDefault();
        if (to >= 0 && to < count && to !== place) {
            moveTo(key, to);
            setSaid(at(name, to));
        }
    };

    // While dragging, the page follows the pointer (the list's items move under it, so not the handle's own events).
    useEffect(() => {
        if (!dragging) {
            return;
        }
        // Its place: how many other sections' middles are above the pointer.
        const follow = (event: globalThis.PointerEvent) => {
            const from = keys.indexOf(dragging);
            let to = 0;
            Array.from(list.current?.children ?? []).forEach((item, i) => {
                const box = item.getBoundingClientRect();
                if (i !== from && event.clientY > box.top + box.height / 2) {
                    to++;
                }
            });
            moveTo(dragging, to);
        };
        const drop = () => {
            setDragging(null);
            const place = keys.indexOf(dragging);
            const name = sectionLabel(views[place]!);
            setSaid(`${sprintf(/* translators: %s: a report section. */ __('%s dropped.', 'seoproanalytics'), name)} ${at(name, place)}`);
        };
        window.addEventListener('pointermove', follow);
        window.addEventListener('pointerup', drop);
        window.addEventListener('pointercancel', drop);
        return () => {
            window.removeEventListener('pointermove', follow);
            window.removeEventListener('pointerup', drop);
            window.removeEventListener('pointercancel', drop);
        };
    });

    return (
        <>
            <p id={help} className="seoproan-note">
                {__('Drag a section by its handle to change the order. With the keyboard: Tab to the handle, press Space, move it with the arrow keys, and press Space again.', 'seoproanalytics')}
            </p>
            <ol ref={list} className={`seoproan-share-sections${dragging ? ' is-dragging' : ''}`}>
                {views.map((view, index) => {
                    const key = keys[index]!;
                    const name = sectionLabel(view);
                    return (
                        <li key={key} className={`seoproan-share-sections__item${held?.key === key || dragging === key ? ' is-moving' : ''}`}>
                            <button
                                type="button"
                                ref={(el) => {
                                    if (el) {
                                        handles.current.set(key, el);
                                    } else {
                                        handles.current.delete(key);
                                    }
                                }}
                                className="seoproan-share-sections__handle"
                                aria-label={sprintf(/* translators: %s: a report section, such as Visitors. */ __('Move %s', 'seoproanalytics'), name)}
                                aria-describedby={help}
                                aria-pressed={held?.key === key}
                                disabled={count < 2}
                                onKeyDown={(event) => onKey(event, key)}
                                onBlur={() => window.requestAnimationFrame(() => setHeld((was) => (was?.key === key && document.activeElement !== handles.current.get(key) ? null : was)))}
                                onPointerDown={(event: PointerEvent<HTMLButtonElement>) => {
                                    if (event.button !== 0 || count < 2) {
                                        return;
                                    }
                                    // No text selection or scrolling while dragging.
                                    event.preventDefault();
                                    setHeld(null);
                                    setDragging(key);
                                }}
                            >
                                <DragHandleIcon />
                            </button>
                            <span className="seoproan-share-sections__name">{name}</span>
                            <Button size="small" variant="tertiary" isDestructive disabled={count === 1} accessibleWhenDisabled onClick={() => setViews(views.filter((_, i) => i !== index))}>
                                {__('Remove', 'seoproanalytics')}
                                <span className="screen-reader-text"> {name}</span>
                            </Button>
                        </li>
                    );
                })}
            </ol>
            <p className="screen-reader-text" aria-live="assertive" aria-atomic="true">{said}</p>
        </>
    );
}

export function ShareEditor({ state, share, close, saved }: Readonly<{ state: ViewState; share?: SharedReport; close: () => void; saved: (share: SharedReport) => void }>) {
    const first = shareView(state) ?? { ...state, view: 'overview' as const };
    const [name, setName] = useState(share?.name ?? '');
    const [note, setNote] = useState(share?.note ?? '');
    const [views, setViews] = useState<ViewState[]>(share?.views ?? [first]);
    const [locked, setLocked] = useState(share?.locked_filters ?? []);
    const [password, setPassword] = useState('');
    const [clearPassword, setClearPassword] = useState(false);
    const [emailPassword, setEmailPassword] = useState(share?.email_password ?? false);
    const [email, setEmail] = useState<ReportEmail>({ ...DEFAULT_EMAIL, ...share?.email });
    const [recipients, setRecipients] = useState(share?.email?.recipients.join('\n') ?? '');
    const [intros, setIntros] = useState<Record<string, string>>({});
    const [testMessage, setTestMessage] = useState('');
    const [expires, setExpires] = useState(share?.expires ? new Date(share.expires * 1000).toISOString().slice(0, 10) : '');
    const [days, setDays] = useState(String(share?.max_days ?? 0));
    const [sensitive, setSensitive] = useState(share?.hide_sensitive ?? true);
    const [realtime, setRealtime] = useState(share?.hide_realtime ?? true);
    const [branding, setBranding] = useState<ShareBranding>(share?.branding ?? DEFAULT_BRANDING);
    const [logos, setLogos] = useState<Record<string, string>>({});
    const [engines, setEngines] = useState<SearchEngine[]>(['google']);
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    // A section's view: the one on screen when it is that section; else its defaults, with the period and filters on screen.
    const sectionView = (key: string): ViewState | null => {
        if (shareSectionKey(first) === key) {
            return first;
        }
        const [view, engine] = key.split(':') as [View, SearchEngine | undefined];
        if (!SHARE_VIEWS.includes(view)) {
            return null;
        }
        const next = { ...switchView(first, view), ...(engine && engine !== 'google' ? { engine } : {}) };
        return shareView(next) ?? next;
    };
    useEffect(() => {
        void apiFetch<SharesAnswer>({ path })
            .then((answer) => {
                // A new report starts from the settings' branding, and with every section that has data (the one on screen first).
                if (!share) {
                    setBranding(answer.defaults);
                    const keys = [shareSectionKey(first), ...(answer.sections ?? []).filter((key) => key !== shareSectionKey(first))].slice(0, MAX_SECTIONS);
                    const start = keys.map(sectionView).filter((view): view is ViewState => !!view);
                    // Only while the list is as it opened: never undo a choice made meanwhile.
                    setViews((was) => (was.length === 1 && shareSectionKey(was[0]!) === shareSectionKey(first) ? start : was));
                }
                setLogos(answer.logos ?? {});
                setIntros(answer.email_intros ?? {});
                setEngines(answer.engines?.length ? answer.engines : ['google']);
            })
            .catch((e: unknown) => setError(errorMessage(e, __('The branding from the settings could not be loaded.', 'seoproanalytics'))));
        // Once per editor: first and sectionView follow the view it opened with.
    }, [share]);

    const brand = <K extends keyof ShareBranding>(key: K, value: ShareBranding[K]) => setBranding((was) => ({ ...was, [key]: value }));
    // Every tab, once; Search once for each engine with data.
    const chosen = new Set(views.map((view) => shareSectionKey(view)));
    type Section = { view: View; engine?: SearchEngine };
    const offered = SHARE_VIEWS.flatMap((view): Section[] => (view === 'search' ? engines.map((engine) => ({ view, engine })) : [{ view }]))
        .filter((section) => !chosen.has(shareSectionKey(section)));
    const add = (key: string) => {
        const next = offered.some((s) => shareSectionKey(s) === key) && views.length < MAX_SECTIONS ? sectionView(key) : null;
        if (next) {
            setViews([...views, next]);
        }
    };
    const lockable = share?.locked_filters.length ? share.locked_filters : first.filters;
    const pageOnlyClash = locked.some((f) => f.dimension !== 'page') && views.some((v) => PAGE_ONLY.has(v.view));
    const settingsLink = boot.settingsUrl ? addQueryArgs(boot.settingsUrl, { tab: 'shared-reports' }) : '';

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        if (busy) {
            return;
        }
        if (!name.trim()) {
            setError(__('Give the report a name, so you can find it in the list of shared reports.', 'seoproanalytics'));
            return;
        }
        setBusy(true);
        setError('');
        try {
            const answer = await apiFetch<SharedReport>({ path: share ? `${path}/${share.id}` : path, method: 'POST', data: {
                name, note, views, locked_filters: locked, max_days: Number(days), expires: expires ? Math.floor(new Date(`${expires}T23:59:59`).getTime() / 1000) : 0,
                hide_sensitive: sensitive, hide_realtime: realtime, branding, email_password: emailPassword,
                email: { enabled: email.enabled, interval: email.interval, hour: email.hour, intro: email.intro, events: email.events, notes: email.notes, recipients: recipients.split(/\r?\n/).map((address) => address.trim()).filter(Boolean) },
                ...(!share || password || clearPassword ? { password: clearPassword ? '' : password } : {}),
            } });
            saved(answer);
            close();
        } catch (e) {
            setError(errorMessage(e, __('The report could not be saved. Try again.', 'seoproanalytics')));
            setBusy(false);
        }
    };

    return (
        <Modal
            title={share ? __('Edit shared report', 'seoproanalytics') : __('Share this view', 'seoproanalytics')}
            onRequestClose={close}
            // The Media Library opens over it: a click there is not a click away.
            shouldCloseOnClickOutside={false}
            className="seoproan-modal is-wide seoproan-share-editor"
        >
            <form onSubmit={submit} className="seoproan-form" noValidate>
                {error && <Notice status="error" isDismissible={false}>{error}</Notice>}
                <TextControl __nextHasNoMarginBottom __next40pxDefaultSize label={__('Name', 'seoproanalytics')} help={__('For you, in the list of shared reports. Readers see the report title.', 'seoproanalytics')} value={name} onChange={setName} required />
                <TextareaControl __nextHasNoMarginBottom label={__('Note for the reader (optional)', 'seoproanalytics')} value={note} onChange={setNote} rows={3} />

                <fieldset className="seoproan-fieldset">
                    <legend>{__('Sections, in this order', 'seoproanalytics')}</legend>
                    <SectionOrder views={views} setViews={setViews} />
                    {offered.length > 0 && views.length < MAX_SECTIONS && (
                        <SelectControl
                            __nextHasNoMarginBottom
                            __next40pxDefaultSize
                            label={__('Add a section', 'seoproanalytics')}
                            value=""
                            options={[{ label: __('Choose a section…', 'seoproanalytics'), value: '' }, ...offered.map((s) => ({ label: sectionLabel(s), value: shareSectionKey(s) }))]}
                            onChange={add}
                        />
                    )}
                </fieldset>

                <fieldset className="seoproan-fieldset">
                    <legend>{__('What readers can see', 'seoproanalytics')}</legend>
                    <CheckboxControl
                        __nextHasNoMarginBottom
                        label={__('Lock the filters on every section', 'seoproanalytics')}
                        help={lockable.length
                            ? sprintf(/* translators: %s: filters, such as "Country is Spain". */ __('Readers cannot remove: %s.', 'seoproanalytics'), lockable.map(filterLabel).join('; '))
                            : __('To lock filters, close this, filter the view (choose a row, such as a country), then share it.', 'seoproanalytics')}
                        checked={locked.length > 0}
                        disabled={!lockable.length}
                        onChange={(checked) => setLocked(checked ? lockable : [])}
                    />
                    {pageOnlyClash && (
                        <Notice status="warning" isDismissible={false} className="seoproan-notice">
                            {__('Search and Changes can only be locked to pages. Remove the other filters or those sections.', 'seoproanalytics')}
                        </Notice>
                    )}
                    <CheckboxControl __nextHasNoMarginBottom label={__('Hide realtime visitors', 'seoproanalytics')} checked={realtime} onChange={setRealtime} />
                    <CheckboxControl __nextHasNoMarginBottom label={__('Hide site search terms and referrer addresses', 'seoproanalytics')} checked={sensitive} onChange={setSensitive} />
                    <div className="seoproan-form__row">
                        <TextControl __nextHasNoMarginBottom __next40pxDefaultSize label={__('Days readers can look back', 'seoproanalytics')} help={__('0 for no limit.', 'seoproanalytics')} type="number" min={0} max={3650} value={days} onChange={setDays} />
                        <TextControl __nextHasNoMarginBottom __next40pxDefaultSize label={__('Expires (optional)', 'seoproanalytics')} type="date" value={expires} onChange={setExpires} />
                    </div>
                    <TextControl
                        __nextHasNoMarginBottom
                        __next40pxDefaultSize
                        label={__('Password (optional)', 'seoproanalytics')}
                        type="password"
                        value={password}
                        onChange={setPassword}
                        autoComplete="new-password"
                        help={share?.protected ? __('Leave empty to keep the password.', 'seoproanalytics') : undefined}
                    />
                    {share?.protected && <CheckboxControl __nextHasNoMarginBottom label={__('Remove the password', 'seoproanalytics')} checked={clearPassword} onChange={setClearPassword} />}
                    <CheckboxControl
                        __nextHasNoMarginBottom
                        label={__('Include the password in emails', 'seoproanalytics')}
                        help={__('Stores an encrypted copy only when you enter the password here. Anyone with an email containing both the link and password can open the report. This does not schedule emails.', 'seoproanalytics')}
                        checked={emailPassword}
                        onChange={setEmailPassword}
                    />
                    {emailPassword && share?.protected && !share.password_stored && !password && !clearPassword && (
                        <p className="seoproan-note">{__('Enter the password again to include it in emails', 'seoproanalytics')}</p>
                    )}
                </fieldset>

                <fieldset className="seoproan-fieldset">
                    <legend>{__('Email this report', 'seoproanalytics')}</legend>
                    <CheckboxControl __nextHasNoMarginBottom label={__('Send on a schedule', 'seoproanalytics')} checked={email.enabled} onChange={(enabled) => setEmail({ ...email, enabled })} />
                    <TextareaControl __nextHasNoMarginBottom label={__('Recipients', 'seoproanalytics')} help={__('Up to twenty addresses, one per line. Each person gets a separate email.', 'seoproanalytics')} value={recipients} onChange={setRecipients} />
                    <div className="seoproan-form__row">
                        <SelectControl __nextHasNoMarginBottom __next40pxDefaultSize label={__('Send', 'seoproanalytics')} value={email.interval} options={emailIntervals()} onChange={(interval) => setEmail({ ...email, interval: interval as ReportEmail['interval'] })} />
                        <TextControl __nextHasNoMarginBottom __next40pxDefaultSize label={__('Hour (site time)', 'seoproanalytics')} help={__('0–23. Sends after daily summaries are ready; missed jobs send the latest period only.', 'seoproanalytics')} type="number" min={0} max={23} value={String(email.hour)} onChange={(hour) => setEmail({ ...email, hour: Number(hour) })} />
                    </div>
                    <TextareaControl __nextHasNoMarginBottom label={__('Message (optional)', 'seoproanalytics')} maxLength={2000} placeholder={intros[email.interval] ?? ''} value={email.intro} onChange={(intro) => setEmail({ ...email, intro })} />
                    <CheckboxControl __nextHasNoMarginBottom label={__("List the site's changes in this period", 'seoproanalytics')} checked={email.events} onChange={(events) => setEmail({ ...email, events })} />
                    <CheckboxControl __nextHasNoMarginBottom label={__('Include notes from the timeline', 'seoproanalytics')} help={__('Notes are written by your team. Only turn this on if they are fit for the reader.', 'seoproanalytics')} checked={email.notes} onChange={(notes) => setEmail({ ...email, notes })} />
                    <p className="seoproan-note">{__('Save changes before testing. The test uses the saved report and sends only to you, without recording the period as sent.', 'seoproanalytics')}</p>
                    {testMessage && <Notice status="success" isDismissible={false}>{testMessage}</Notice>}
                    <Button variant="secondary" disabled={!share || busy} accessibleWhenDisabled onClick={async () => {
                        if (!share) { return; }
                        setBusy(true); setError(''); setTestMessage('');
                        try {
                            await apiFetch({ path: `${path}/${share.id}/email/test`, method: 'POST' });
                            setTestMessage(__('Test accepted by WordPress. Check your inbox.', 'seoproanalytics'));
                        } catch (e) { setError(errorMessage(e, __('The test could not be sent.', 'seoproanalytics'))); }
                        finally { setBusy(false); }
                    }}>{__('Send a test to me', 'seoproanalytics')}</Button>
                    {!share && <p className="seoproan-note">{__('Save the report first to send a test.', 'seoproanalytics')}</p>}
                </fieldset>

                <fieldset className="seoproan-fieldset">
                    <legend>{__('Branding', 'seoproanalytics')}</legend>
                    <p className="seoproan-note">
                        {__('Starts from the shared reports settings; changes here are for this report only.', 'seoproanalytics')}{' '}
                        {settingsLink && <a href={settingsLink}>{__('Change the defaults', 'seoproanalytics')}</a>}
                    </p>
                    <TextControl __nextHasNoMarginBottom __next40pxDefaultSize label={__('Report title', 'seoproanalytics')} help={__('The site name when left empty.', 'seoproanalytics')} value={branding.title} onChange={(value) => brand('title', value)} />
                    <LogoField
                        label={__('Report logo', 'seoproanalytics')}
                        help={__('The site logo when left empty.', 'seoproanalytics')}
                        id={branding.logo}
                        url={logos[branding.logo] ?? ''}
                        choose={() => chooseImage(__('Report logo', 'seoproanalytics'), (id, url) => { setLogos((was) => ({ ...was, [id]: url })); brand('logo', id); })}
                        clear={() => brand('logo', 0)}
                    />
                    <div className="seoproan-form__row">
                        <TextControl __nextHasNoMarginBottom __next40pxDefaultSize label={__('Agency name', 'seoproanalytics')} value={branding.agency} onChange={(value) => brand('agency', value)} />
                        <TextControl __nextHasNoMarginBottom __next40pxDefaultSize label={__('Agency website', 'seoproanalytics')} type="url" value={branding.website} onChange={(value) => brand('website', value)} />
                    </div>
                    <TextControl __nextHasNoMarginBottom __next40pxDefaultSize label={__('Byline', 'seoproanalytics')} help={__('Before the agency name at the foot, such as “Prepared by”.', 'seoproanalytics')} value={branding.byline} onChange={(value) => brand('byline', value)} />
                    <LogoField
                        label={__('Agency logo', 'seoproanalytics')}
                        help={__('At the foot of the report.', 'seoproanalytics')}
                        id={branding.agency_logo}
                        url={logos[branding.agency_logo] ?? ''}
                        choose={() => chooseImage(__('Agency logo', 'seoproanalytics'), (id, url) => { setLogos((was) => ({ ...was, [id]: url })); brand('agency_logo', id); })}
                        clear={() => brand('agency_logo', 0)}
                    />
                    <div className="seoproan-share-accent" role="group" aria-label={__('Accent colour', 'seoproanalytics')}>{/* NOSONAR: a group of buttons; a fieldset would bring its own border, padding and min-width. */}
                        <span className="seoproan-share-accent__label">{__('Accent colour', 'seoproanalytics')}</span>
                        <div className="seoproan-share-accent__swatches">
                            {boot.sharePalette.map((swatch) => (
                                <button
                                    key={swatch.color}
                                    type="button"
                                    className="seoproan-share-accent__swatch"
                                    style={{ background: swatch.color }}
                                    aria-pressed={branding.accent.toLowerCase() === swatch.color.toLowerCase()}
                                    title={`${swatch.name} (${swatch.color})`}
                                    onClick={() => brand('accent', swatch.color)}
                                >
                                    <span className="screen-reader-text">{swatch.name}</span>
                                </button>
                            ))}
                            <label className="seoproan-share-accent__other">
                                <input type="color" value={branding.accent} onChange={(event) => brand('accent', event.currentTarget.value)} />
                                {__('Other colour', 'seoproanalytics')}
                            </label>
                        </div>
                    </div>
                    <SelectControl
                        __nextHasNoMarginBottom
                        __next40pxDefaultSize
                        label={__('Light or dark', 'seoproanalytics')}
                        value={branding.mode}
                        options={[{ label: __('Follow the reader’s device', 'seoproanalytics'), value: 'system' }, { label: __('Light', 'seoproanalytics'), value: 'light' }, { label: __('Dark', 'seoproanalytics'), value: 'dark' }]}
                        onChange={(mode) => brand('mode', mode as ShareBranding['mode'])}
                    />
                    <CheckboxControl __nextHasNoMarginBottom label={__('Show “Statistics logged by SEO Pro Analytics for WordPress”', 'seoproanalytics')} checked={branding.credit} onChange={(credit) => brand('credit', credit)} />
                </fieldset>

                <div className="seoproan-form__actions">
                    <Button variant="tertiary" onClick={close} disabled={busy}>{__('Cancel', 'seoproanalytics')}</Button>
                    <Button variant="primary" type="submit" isBusy={busy} disabled={busy || pageOnlyClash} accessibleWhenDisabled>
                        {share ? __('Save', 'seoproanalytics') : __('Save and make the link', 'seoproanalytics')}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}

const when = (time: number) => (time ? new Date(time * 1000).toLocaleString(locale, { dateStyle: 'medium', timeStyle: 'short' }) : '—');

export function Shares({ state }: Readonly<{ state: ViewState }>) {
    const [shares, setShares] = useState<SharedReport[]>([]);
    const [loaded, setLoaded] = useState(false);
    const [editing, setEditing] = useState<SharedReport | 'new' | null>(null);
    const [fresh, setFresh] = useState<{ url: string; name: string } | null>(null);
    const [error, setError] = useState('');
    const reload = () =>
        apiFetch<SharesAnswer>({ path })
            .then((answer) => setShares(answer.shares))
            .catch((e: unknown) => setError(errorMessage(e, __('The shared reports could not be loaded.', 'seoproanalytics'))))
            .finally(() => setLoaded(true));
    useEffect(() => { void reload(); }, []);
    const act = async (share: SharedReport, renew: boolean) => {
        try {
            const answer = await apiFetch<{ url?: string }>({ path: `${path}/${share.id}${renew ? '/renew' : ''}`, method: renew ? 'POST' : 'DELETE' });
            setFresh(answer.url ? { url: answer.url, name: share.name } : null);
            void reload();
        } catch (e) { setError(errorMessage(e, __('This report could not be changed. Try again.', 'seoproanalytics'))); }
    };
    const recover = async (share: SharedReport) => {
        setFresh(null);
        setError('');
        try {
            const answer = await apiFetch<{ url: string }>({ path: `${path}/${share.id}/link` });
            setFresh({ url: answer.url, name: share.name });
        } catch (e) {
            setError(errorMessage(e, __('Make a new link to copy or email it', 'seoproanalytics')));
            setShares((was) => was.map((item) => item.id === share.id ? { ...item, copyable: false } : item));
        }
    };
    return (
        <section className="seoproan-shares">
            <div className="seoproan-app-section__head">
                <h2 className="seoproan-app-section__title">{__('Shared reports', 'seoproanalytics')}</h2>
                <Button variant="primary" onClick={() => setEditing('new')}>{__('New shared report', 'seoproanalytics')}</Button>
            </div>
            <p className="seoproan-note">{__('Read-only reports for clients and colleagues, at a private link. New links are kept encrypted so you can copy them again. Making a new link stops the old one.', 'seoproanalytics')}</p>
            {error && <Notice status="error" className="seoproan-notice" onRemove={() => setError('')}>{error}</Notice>}
            {fresh && <ShareLink url={fresh.url} name={fresh.name} onDismiss={() => setFresh(null)} />}
            {loaded && !shares.length && <p>{__('No shared reports yet. Use Share on any tab, or New shared report.', 'seoproanalytics')}</p>}
            {shares.length > 0 && (
                <div className="seoproan-table-scroll">
                    <table className="widefat striped seoproan-shares__table">
                        <thead>
                            <tr>
                                <th scope="col">{__('Report', 'seoproanalytics')}</th>
                                <th scope="col">{__('Created', 'seoproanalytics')}</th>
                                <th scope="col">{__('Expires', 'seoproanalytics')}</th>
                                <th scope="col">{__('Last opened', 'seoproanalytics')}</th>
                                <th scope="col">{__('Opens', 'seoproanalytics')}</th>
                                <th scope="col"><span className="screen-reader-text">{__('Actions', 'seoproanalytics')}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            {shares.map((share) => (
                                <tr key={share.id}>
                                    <td>
                                        <strong>{share.name}</strong>
                                        {share.revoked && <span className="seoproan-app-badge">{__('Link stopped', 'seoproanalytics')}</span>}
                                        {share.protected && <span className="seoproan-app-badge">{__('Password', 'seoproanalytics')}</span>}
                                        {share.email?.enabled && <span className="seoproan-app-badge">{emailBadge(share.email.interval)}</span>}
                                        {share.email?.last_error ? <div className="seoproan-muted">{share.email.last_error}</div> : share.email?.last_sent ? <div className="seoproan-muted">{sprintf(/* translators: %s: last email date. */ __('Last emailed: %s', 'seoproanalytics'), when(share.email.last_sent))}</div> : null}
                                        {share.email?.last_period && <div className="seoproan-muted">{share.email.last_period}</div>}
                                        <div className="seoproan-muted">{share.views.map(sectionLabel).join(', ')}</div>
                                        {!share.copyable && !share.revoked && (!share.expires || share.expires > Date.now() / 1000) && <div className="seoproan-muted">{__('Make a new link to copy or email it', 'seoproanalytics')}</div>}
                                        {share.protected && share.email_password && !share.password_stored && <div className="seoproan-muted">{__('Enter the password again to include it in emails', 'seoproanalytics')}</div>}
                                    </td>
                                    <td>{when(share.created)}</td>
                                    <td>{share.expires ? when(share.expires) : __('Never', 'seoproanalytics')}</td>
                                    <td>{when(share.last_opened)}</td>
                                    <td>{share.opens}</td>
                                    <td>
                                        <span className="seoproan-actions">
                                            {share.copyable && !share.revoked && (!share.expires || share.expires > Date.now() / 1000) && (
                                                <Button variant="secondary" size="small" onClick={() => void recover(share)}>
                                                    {__('Copy link', 'seoproanalytics')}<span className="screen-reader-text"> {share.name}</span>
                                                </Button>
                                            )}
                                            <Button variant="secondary" size="small" onClick={() => setEditing(share)}>
                                                {__('Edit', 'seoproanalytics')}<span className="screen-reader-text"> {share.name}</span>
                                            </Button>
                                            <Button variant="secondary" size="small" onClick={() => void act(share, true)}>
                                                {__('Make a new link', 'seoproanalytics')}<span className="screen-reader-text"> {share.name}</span>
                                            </Button>
                                            {!share.revoked && (
                                                <Button variant="tertiary" size="small" isDestructive onClick={() => void act(share, false)}>
                                                    {__('Stop the link', 'seoproanalytics')}<span className="screen-reader-text"> {share.name}</span>
                                                </Button>
                                            )}
                                        </span>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
            {editing && (
                <ShareEditor
                    state={state}
                    share={editing === 'new' ? undefined : editing}
                    close={() => setEditing(null)}
                    saved={(share) => {
                        if (share.url) {
                            setFresh({ url: share.url, name: share.name });
                        }
                        void reload();
                    }}
                />
            )}
        </section>
    );
}
