// Chrome shared by the page editor and the reusable-component editor: the save-state pill,
// the viewport switch, sidebar tabs and the notice banner. Same words, icons and tones in
// both, so each state reads the same everywhere.
import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
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
        <div role="group" aria-label="Viewport" className="inline-flex rounded-md border border-line bg-sunken p-0.5">
            {VIEWPORTS.map((v) => {
                const active = v.value === value;
                return (
                    <button
                        key={v.value}
                        type="button"
                        aria-pressed={active}
                        title={`${v.label} (${v.width})`}
                        onClick={() => onChange(v.value)}
                        className={`inline-flex h-7 items-center gap-1.5 rounded-[5px] px-2 text-xs font-medium transition-colors ${active ? 'bg-surface text-fg shadow-hairline' : 'text-muted hover:text-fg'}`}
                    >
                        <Icon name={v.icon} className="size-3.5" />
                        <span className="max-xl:sr-only">{v.label}</span>
                    </button>
                );
            })}
        </div>
    );
}

export function HistoryButtons({ canUndo, canRedo, onStep }: { canUndo: boolean; canRedo: boolean; onStep(direction: 'undo' | 'redo'): void }) {
    return (
        <div className="flex items-center">
            <button
                type="button"
                onClick={() => onStep('undo')}
                disabled={!canUndo}
                title="Undo (Ctrl+Z)"
                aria-label="Undo"
                className="grid size-8 place-items-center rounded-md text-muted hover:bg-sunken hover:text-fg disabled:opacity-35"
            >
                <Icon name="undo" />
            </button>
            <button
                type="button"
                onClick={() => onStep('redo')}
                disabled={!canRedo}
                title="Redo (Ctrl+Shift+Z)"
                aria-label="Redo"
                className="grid size-8 place-items-center rounded-md text-muted hover:bg-sunken hover:text-fg disabled:opacity-35"
            >
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
            className="group flex h-8 shrink-0 items-center gap-1 rounded-md pr-1.5 pl-1 text-muted hover:bg-sunken hover:text-fg"
        >
            <Icon name="arrowLeft" className="size-3.5" />
            <ArkonMark />
        </Link>
    );
}

export interface TabSpec<T extends string> {
    value: T;
    label: string;
    icon: IconName;
    badge?: ReactNode;
}

export function SidebarTabs<T extends string>({ tabs, value, onChange }: { tabs: TabSpec<T>[]; value: T; onChange(value: T): void }) {
    return (
        <div className="flex shrink-0 border-b border-line bg-surface px-1" role="tablist">
            {tabs.map((t) => {
                const active = t.value === value;
                return (
                    <button
                        key={t.value}
                        type="button"
                        role="tab"
                        aria-selected={active}
                        onClick={() => onChange(t.value)}
                        className={`relative flex h-10 flex-1 items-center justify-center gap-1.5 text-xs font-medium transition-colors ${active ? 'text-fg' : 'text-muted hover:text-fg'}`}
                    >
                        <Icon name={t.icon} className="size-3.5" />
                        {t.label}
                        {t.badge}
                        {active && <span aria-hidden className="absolute inset-x-2 bottom-0 h-0.5 rounded-full bg-accent" />}
                    </button>
                );
            })}
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
