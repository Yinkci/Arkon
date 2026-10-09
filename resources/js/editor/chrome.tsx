// Chrome shared by the page editor and the reusable-component editor: the toolbar, the panel
// workspace, the save-state pill, the viewport switch, sidebar tabs and the notice banner.
// Same words, icons and tones in both, so each state reads the same everywhere.
import { Link } from '@inertiajs/react';
import { useCallback, useEffect, useState, type ReactNode } from 'react';
import type { Issue } from '@/arkon/rules';
import { Icon, type IconName } from '@/Components/Icon';
import { ArkonMark, Button, Notice, StatusPill, type Tone } from '@/Components/ui';
import type { Viewport } from './Canvas';

export interface SaveState {
    text: string;
    tone: Tone;
    icon: IconName;
    busy?: boolean;
}

/** How the editor's draft stands, most urgent first. Text is what the status pill says. */
export function saveState(s: {
    activity: 'idle' | 'saving' | 'publishing' | 'restoring' | 'settings';
    conflict: boolean;
    recovery?: boolean;
    proposal?: boolean;
    inFlight: boolean;
    unresolved: number;
    unsaved: boolean;
}): SaveState {
    if (s.activity === 'saving') return { text: 'Saving…', tone: 'accent', icon: 'cloud', busy: true };
    if (s.activity === 'publishing') return { text: 'Publishing…', tone: 'accent', icon: 'globe', busy: true };
    if (s.activity === 'restoring') return { text: 'Restoring…', tone: 'accent', icon: 'history', busy: true };
    if (s.activity === 'settings') return { text: 'Updating title & URL…', tone: 'accent', icon: 'pages', busy: true };
    if (s.conflict) return { text: 'Out of date', tone: 'danger', icon: 'alert' };
    if (s.recovery) return { text: 'Needs repair', tone: 'danger', icon: 'alert' };
    if (s.proposal) return { text: 'Previewing AI proposal', tone: 'ai', icon: 'sparkle' };
    if (s.inFlight) return { text: 'Save not confirmed', tone: 'danger', icon: 'cloudOff' };
    if (s.unresolved > 0)
        // Saving applies valid changes only: never claim that what is on screen is saved.
        return {
            text: `${s.unsaved ? 'Unsaved changes, ' : ''}${s.unresolved} invalid field${s.unresolved === 1 ? '' : 's'} not saved`,
            tone: 'danger',
            icon: 'alert',
        };
    if (s.unsaved) return { text: 'Unsaved changes', tone: 'changed', icon: 'dots' };
    return { text: 'Draft saved', tone: 'neutral', icon: 'check' };
}

export function SaveStatus({ state, testId }: { state: SaveState; testId: string }) {
    return (
        <span aria-live="polite" className="inline-flex min-w-0">
            <StatusPill tone={state.tone} icon={state.icon} busy={state.busy} data-testid={testId} data-tone={state.tone}>
                {state.text}
            </StatusPill>
        </span>
    );
}

const VIEWPORTS: { value: Viewport; label: string; icon: IconName; width: string }[] = [
    { value: 'desktop', label: 'Desktop', icon: 'desktop', width: 'all screens' },
    { value: 'tablet', label: 'Tablet', icon: 'tablet', width: '820 px' },
    { value: 'mobile', label: 'Mobile', icon: 'mobile', width: '390 px' },
];

/** Which screen the canvas shows; it also chooses which screen size design values are edited for. */
export function ViewportSwitch({ value, onChange }: { value: Viewport; onChange(value: Viewport): void }) {
    return (
        <div role="group" aria-label="Viewport" className="inline-flex h-8 items-center rounded-md border border-line bg-sunken p-0.5">
            {VIEWPORTS.map((v) => {
                const active = v.value === value;
                return (
                    <button
                        key={v.value}
                        type="button"
                        aria-pressed={active}
                        title={`${v.label} (${v.width})`}
                        onClick={() => onChange(v.value)}
                        className={`inline-flex h-full items-center gap-1.5 rounded px-2 text-xs font-medium transition-[background-color,color,box-shadow] duration-100 ${active ? 'bg-surface text-fg shadow-raise' : 'text-muted hover:text-fg'}`}
                    >
                        <Icon name={v.icon} className="size-3.5" />
                        <span className="max-2xl:sr-only">{v.label}</span>
                    </button>
                );
            })}
        </div>
    );
}

export function HistoryButtons({ canUndo, canRedo, onStep }: { canUndo: boolean; canRedo: boolean; onStep(direction: 'undo' | 'redo'): void }) {
    const square =
        'grid size-8 place-items-center rounded-md text-muted transition-colors hover:bg-hover hover:text-fg disabled:pointer-events-none disabled:opacity-35';
    return (
        <div className="flex items-center">
            <button type="button" onClick={() => onStep('undo')} disabled={!canUndo} title="Undo (Ctrl+Z)" aria-label="Undo" className={square}>
                <Icon name="undo" />
            </button>
            <button type="button" onClick={() => onStep('redo')} disabled={!canRedo} title="Redo (Ctrl+Shift+Z)" aria-label="Redo" className={square}>
                <Icon name="redo" />
            </button>
        </div>
    );
}

/** The editor's back link: the Arkon mark, to where the user came from. */
export function BackMark({ href, label }: { href: string; label: string }) {
    return (
        <Link
            href={href}
            aria-label={label}
            title={label}
            className="group flex h-8 shrink-0 items-center gap-1 rounded-md pr-1 pl-1 text-muted transition-colors hover:bg-hover hover:text-fg"
        >
            <Icon name="arrowLeft" className="size-3.5 transition-transform group-hover:-translate-x-0.5" />
            <ArkonMark className="size-6" />
        </Link>
    );
}

/** A thin vertical divider between toolbar groups. */
export function ToolbarDivider({ className = '' }: { className?: string }) {
    return <span aria-hidden="true" className={`h-5 w-px shrink-0 bg-line ${className}`} />;
}

/**
 * The editor's top bar: identity on the left, how the canvas is viewed in the middle, and
 * what happens to the draft on the right. The middle stays centred over the canvas on wide
 * screens; on narrow ones the three groups wrap.
 */
export function EditorToolbar({ start, center, end }: { start: ReactNode; center: ReactNode; end: ReactNode }) {
    return (
        <header className="flex min-h-12 shrink-0 flex-wrap items-center gap-x-2 gap-y-1.5 border-b border-line bg-surface px-2 py-1.5 sm:px-3 xl:grid xl:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] xl:py-0">
            <div className="flex min-w-0 items-center gap-2">{start}</div>
            <div className="ml-auto flex items-center gap-1 xl:ml-0">{center}</div>
            <div className="ml-auto flex min-w-0 items-center justify-end gap-1.5 max-md:w-full">{end}</div>
        </header>
    );
}

export interface TabSpec<T extends string> {
    value: T;
    label: string;
    icon: IconName;
    badge?: ReactNode;
}

export function SidebarTabs<T extends string>({ tabs, value, onChange, aside }: { tabs: TabSpec<T>[]; value: T; onChange(value: T): void; aside?: ReactNode }) {
    return (
        <div className="flex h-10 shrink-0 items-stretch gap-1 border-b border-line bg-surface px-2">
            <div className="flex min-w-0 flex-1 items-stretch gap-1" role="tablist">
                {tabs.map((t) => {
                    const active = t.value === value;
                    return (
                        <button
                            key={t.value}
                            type="button"
                            role="tab"
                            aria-selected={active}
                            onClick={() => onChange(t.value)}
                            className={`relative flex min-w-0 items-center gap-1.5 text-xs font-medium whitespace-nowrap transition-colors ${tabs.length === 1 ? 'justify-start px-2' : 'flex-auto justify-center px-1.5'} ${active ? 'text-fg' : 'text-muted hover:text-fg'}`}
                        >
                            {tabs.length < 5 && <Icon name={t.icon} className={`size-3.5 ${active ? 'text-accent' : ''}`} />}
                            <span className="truncate">{t.label}</span>
                            {t.badge}
                            {active && <span aria-hidden className="absolute inset-x-1 -bottom-px h-0.5 rounded-full bg-accent" />}
                        </button>
                    );
                })}
            </div>
            {aside && <div className="flex items-center">{aside}</div>}
        </div>
    );
}

/** Wide enough for the outline and the inspector beside a desktop-width canvas (960 px minimum). */
const WIDE = '(min-width: 1536px)';

export function useWideLayout(): boolean {
    const [wide, setWide] = useState(() => typeof matchMedia === 'function' && matchMedia(WIDE).matches);
    useEffect(() => {
        if (typeof matchMedia !== 'function') return;
        const media = matchMedia(WIDE);
        const update = () => setWide(media.matches);
        update();
        media.addEventListener('change', update);
        return () => media.removeEventListener('change', update);
    }, []);
    return wide;
}

const OUTLINE_KEY = 'arkon.editor.outline';

/** Whether the left panel is shown on wide screens (remembered per browser). */
export function useOutlinePreference(): [boolean, (open: boolean) => void] {
    const [open, setOpen] = useState(() => {
        try {
            return localStorage.getItem(OUTLINE_KEY) !== 'hidden';
        } catch {
            return true;
        }
    });
    const update = useCallback((value: boolean) => {
        setOpen(value);
        try {
            localStorage.setItem(OUTLINE_KEY, value ? 'shown' : 'hidden');
        } catch {
            // Storage unavailable: the choice lasts for this page only.
        }
    }, []);
    return [open, update];
}

export type PanelKey = 'inspect' | 'layers' | 'history' | 'ai' | 'seo';

/**
 * Canvas plus panels. Wide screens get a three-pane workspace: the outline (Layers, and
 * History/AI where available) on the left, the canvas in the middle and the inspector on
 * the right, so selecting never swaps panels. Narrower screens keep one tabbed sidebar.
 */
export function EditorWorkspace<T extends PanelKey>({
    canvas,
    tabs,
    tab,
    leftTab,
    onTab,
    render,
    replaceSidebar,
    outlineOpen = true,
}: {
    canvas: ReactNode;
    tabs: TabSpec<T>[];
    tab: T;
    leftTab: T;
    onTab(value: T): void;
    render(panel: T): ReactNode;
    /** Shown instead of every panel (e.g. a repair that must happen first). */
    replaceSidebar?: ReactNode;
    outlineOpen?: boolean;
}) {
    const wide = useWideLayout();
    const leftTabs = tabs.filter((t) => t.value !== 'inspect' && t.value !== 'seo');
    const inspectTab = tabs.find((t) => t.value === 'inspect')!;
    const panelClass = 'flex min-h-0 shrink-0 flex-col border-line bg-surface';

    if (wide) {
        return (
            <div className="flex min-h-0 flex-1">
                {outlineOpen && !replaceSidebar && (
                    <aside className={`${panelClass} w-[15rem] border-r`} aria-label="Outline">
                        <SidebarTabs<T> value={leftTab} onChange={onTab} tabs={leftTabs} />
                        <div className="ui-dense ak-scroll min-h-0 flex-1 overflow-x-hidden" data-testid="sidebar-body">
                            {render(leftTab)}
                        </div>
                    </aside>
                )}
                <section className="flex min-h-0 min-w-0 flex-1 flex-col" aria-label="Canvas">
                    {canvas}
                </section>
                <aside className={`${panelClass} w-[19rem] border-l`} aria-label="Sidebar">
                    {replaceSidebar ?? (
                        <>
                            <SidebarTabs<T>
                                value={(tab === 'seo' ? 'seo' : 'inspect') as T}
                                onChange={onTab}
                                tabs={tabs.filter((t) => t.value === 'inspect' || t.value === 'seo')}
                            />
                            <div className="ui-dense ak-scroll min-h-0 flex-1 overflow-x-hidden" data-testid="inspector-body">
                                {render((tab === 'seo' ? 'seo' : 'inspect') as T)}
                            </div>
                        </>
                    )}
                </aside>
            </div>
        );
    }

    return (
        <div className="flex min-h-0 flex-1 max-md:flex-col">
            <section className="flex min-h-0 min-w-0 flex-1 flex-col max-md:h-[55vh] max-md:flex-none" aria-label="Canvas">
                {canvas}
            </section>
            <aside className={`${panelClass} w-full max-md:flex-1 max-md:border-t md:w-[20rem] md:border-l xl:w-[21rem]`} aria-label="Sidebar">
                {replaceSidebar ?? (
                    <>
                        <SidebarTabs<T> value={tab} onChange={onTab} tabs={tabs} />
                        <div className="ui-dense ak-scroll min-h-0 flex-1 overflow-x-hidden" data-testid="sidebar-body">
                            {render(tab)}
                        </div>
                    </>
                )}
            </aside>
        </div>
    );
}

/** The editor's outcome banner (saved, published, problems), under the toolbar. */
export function EditorNotice({
    notice,
    conflict,
    onDismiss,
}: {
    notice: { tone: 'error' | 'info'; message: string; issues?: Issue[]; action?: { label: string; onClick(): void; available?(): boolean } };
    conflict?: boolean;
    onDismiss(): void;
}) {
    return (
        <div className="border-b border-line bg-surface px-3 py-2">
            <Notice
                tone={notice.tone === 'error' ? 'error' : 'success'}
                data-testid="notice"
                onDismiss={conflict ? undefined : onDismiss}
                action={
                    conflict ? (
                        <Button size="sm" variant="primary" icon="reset" onClick={() => location.reload()}>
                            Reload
                        </Button>
                    ) : notice.action && (notice.action.available?.() ?? true) ? (
                        <Button size="sm" icon="undo" onClick={notice.action.onClick} data-testid="notice-action">
                            {notice.action.label}
                        </Button>
                    ) : undefined
                }
            >
                <p>{notice.message}</p>
                {notice.issues && notice.issues.length > 0 && (
                    <ul className="list-disc space-y-0.5 pl-4 text-xs text-muted">
                        {notice.issues.slice(0, 5).map((issue, i) => (
                            <li key={i}>{issue.message}</li>
                        ))}
                    </ul>
                )}
            </Notice>
        </div>
    );
}
