// The shared admin list pattern (Pages, Forms, Media): status tabs, row checkboxes with select-all,
// a bulk bar that appears with a selection, and a row "More" menu. Selection lives in one hook so
// every list behaves the same; the server stays the source of truth for what the rows are.
import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { Icon } from './Icon';
import { Segmented } from './ui';

/** Selected ids among the visible ones. Ids that leave the list (moved, filtered, paged) leave the selection. */
export function useSelection(ids: string[]) {
    const [selected, setSelected] = useState<Set<string>>(() => new Set());
    const key = ids.join(',');
    useEffect(() => {
        setSelected((current) => {
            const kept = new Set([...current].filter((id) => ids.includes(id)));
            return kept.size === current.size ? current : kept;
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [key]);
    const toggle = useCallback((id: string) => {
        setSelected((current) => {
            const next = new Set(current);
            if (next.has(id)) next.delete(id);
            else next.add(id);
            return next;
        });
    }, []);
    const all = ids.length > 0 && ids.every((id) => selected.has(id));
    return {
        ids: [...selected],
        count: selected.size,
        has: (id: string) => selected.has(id),
        toggle,
        all,
        some: selected.size > 0 && !all,
        toggleAll: () => setSelected(all ? new Set() : new Set(ids)),
        clear: () => setSelected(new Set()),
    };
}
export type Selection = ReturnType<typeof useSelection>;

/** A real checkbox (keyboard, labels, forms) styled for lists. */
export function Checkbox({
    checked,
    indeterminate = false,
    onChange,
    label,
    className = '',
}: {
    checked: boolean;
    indeterminate?: boolean;
    onChange(): void;
    label: string;
    className?: string;
}) {
    const ref = useRef<HTMLInputElement>(null);
    useEffect(() => {
        if (ref.current) ref.current.indeterminate = indeterminate;
    }, [indeterminate]);
    return <input ref={ref} type="checkbox" className={`ui-check ${className}`} checked={checked} onChange={onChange} aria-label={label} />;
}

export function SelectAllCheckbox({ selection, label }: { selection: Selection; label: string }) {
    return <Checkbox checked={selection.all} indeterminate={selection.some} onChange={selection.toggleAll} label={label} />;
}

/** Appears with a selection: the count, the actions for it, and Clear selection. */
export function BulkBar({ selection, noun, children }: { selection: Selection; noun: [string, string]; children: ReactNode }) {
    if (selection.count === 0) return null;
    return (
        <div
            role="region"
            aria-label="Bulk actions"
            data-testid="bulk-bar"
            className="flex flex-wrap items-center gap-2 rounded-lg border border-accent-line bg-accent-soft px-3 py-2"
        >
            <p className="mr-2 text-sm font-medium t-num">
                {selection.count} {selection.count === 1 ? noun[0] : noun[1]} selected
            </p>
            {children}
            <button type="button" onClick={selection.clear} className="ml-auto rounded-md px-2 py-1 text-sm text-muted hover:bg-hover hover:text-fg">
                Clear selection
            </button>
        </div>
    );
}

/** Status tabs with counts (All, Published, …, Trash). */
export function StatusTabs<T extends string>({
    label,
    value,
    onChange,
    tabs,
}: {
    label: string;
    value: T;
    onChange(value: T): void;
    tabs: { value: T; label: string; count: number }[];
}) {
    return (
        <div className="max-w-full overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
            <Segmented<T>
                label={label}
                value={value}
                onChange={onChange}
                options={tabs.map((tab) => ({ value: tab.value, label: tab.label, badge: <span className="text-faint t-num">{tab.count}</span> }))}
            />
        </div>
    );
}

// Only one row menu is open at a time: opening one closes the other.
let closeOpenMenu: (() => void) | null = null;

/**
 * A row's "More" menu. Rendered in a portal next to its trigger, kept inside the viewport and never
 * clipped by the table; it follows its trigger when the page scrolls. Closes on an outside click,
 * Escape (focus returns to the trigger), choosing an item, opening another row's menu, or when the
 * trigger scrolls out of view. Arrow keys move between items.
 */
export function RowMenu({ label, children, trigger = 'More' }: { label: string; children: (close: () => void) => ReactNode; trigger?: ReactNode }) {
    const [open, setOpen] = useState(false);
    const [position, setPosition] = useState<{ top: number; left: number } | null>(null);
    const button = useRef<HTMLButtonElement>(null);
    const menu = useRef<HTMLDivElement>(null);
    const close = useCallback((focus = false) => {
        setOpen(false);
        setPosition(null);
        if (focus) button.current?.focus();
    }, []);
    const closeHere = useMemo(() => () => close(), [close]);

    useEffect(() => {
        if (!open) return;
        if (closeOpenMenu && closeOpenMenu !== closeHere) closeOpenMenu();
        closeOpenMenu = closeHere;
        const outside = (event: PointerEvent) => {
            const target = event.target as Node;
            if (!menu.current?.contains(target) && !button.current?.contains(target)) close();
        };
        const key = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                close(true);
            }
        };
        // Follow the trigger (once per frame); a trigger scrolled out of view closes the menu.
        let frame = 0;
        const follow = () => {
            cancelAnimationFrame(frame);
            frame = requestAnimationFrame(() => {
                const anchor = button.current?.getBoundingClientRect();
                if (!anchor || anchor.bottom < 0 || anchor.top > window.innerHeight) close();
                else place();
            });
        };
        document.addEventListener('pointerdown', outside, true);
        document.addEventListener('keydown', key);
        window.addEventListener('resize', follow);
        window.addEventListener('scroll', follow, true);
        return () => {
            document.removeEventListener('pointerdown', outside, true);
            document.removeEventListener('keydown', key);
            cancelAnimationFrame(frame);
            window.removeEventListener('resize', follow);
            window.removeEventListener('scroll', follow, true);
            if (closeOpenMenu === closeHere) closeOpenMenu = null;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, close, closeHere]);

    // Below the trigger, right-aligned, flipped up near the bottom, always inside the viewport.
    const place = () => {
        if (!button.current || !menu.current) return;
        const anchor = button.current.getBoundingClientRect();
        const box = menu.current.getBoundingClientRect();
        const gap = 4;
        const below = anchor.bottom + gap + box.height <= window.innerHeight - 8;
        const top = below ? anchor.bottom + gap : Math.max(8, anchor.top - gap - box.height);
        const left = Math.min(Math.max(8, anchor.right - box.width), window.innerWidth - box.width - 8);
        setPosition({ top, left });
    };
    useLayoutEffect(() => {
        if (!open) return;
        place();
        menu.current?.querySelector<HTMLElement>('[role="menuitem"]')?.focus({ preventScroll: true });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const onMenuKey = (event: React.KeyboardEvent) => {
        if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;
        event.preventDefault();
        const items = [...(menu.current?.querySelectorAll<HTMLElement>('[role="menuitem"]') ?? [])];
        const index = items.indexOf(document.activeElement as HTMLElement);
        const next =
            event.key === 'Home' ? 0 : event.key === 'End' ? items.length - 1 : (index + (event.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
        items[next]?.focus();
    };

    return (
        <>
            <button
                ref={button}
                type="button"
                aria-haspopup="menu"
                aria-expanded={open}
                aria-label={label}
                onClick={() => (open ? close() : setOpen(true))}
                className={`inline-flex h-7 items-center gap-1 rounded-md px-2 text-xs text-muted hover:bg-hover hover:text-fg ${open ? 'bg-hover text-fg' : ''}`}
            >
                {trigger}
                <Icon name="chevronDown" className="size-3" />
            </button>
            {open &&
                createPortal(
                    <div
                        ref={menu}
                        role="menu"
                        aria-label={label}
                        onKeyDown={onMenuKey}
                        onClick={(event) => {
                            if ((event.target as HTMLElement).closest('[role="menuitem"]')) close();
                        }}
                        style={{ top: position?.top ?? 0, left: position?.left ?? 0, visibility: position ? 'visible' : 'hidden' }}
                        className="fixed z-40 flex min-w-44 flex-col rounded-lg border border-line bg-surface p-1 shadow-pop"
                    >
                        {children(closeHere)}
                    </div>,
                    document.body,
                )}
        </>
    );
}

const item = 'flex h-8 w-full items-center gap-2 rounded-md px-2.5 text-left text-sm outline-none';
export function MenuItem({
    onSelect,
    href,
    external,
    tone = 'default',
    children,
}: {
    onSelect?(): void;
    href?: string;
    external?: boolean;
    tone?: 'default' | 'danger';
    children: ReactNode;
}) {
    const className = `${item} ${tone === 'danger' ? 'text-danger hover:bg-danger-soft focus:bg-danger-soft' : 'text-fg hover:bg-hover focus:bg-hover'}`;
    return href ? (
        <a role="menuitem" href={href} className={className} {...(external ? { target: '_blank', rel: 'noreferrer' } : {})}>
            {children}
            {external && <Icon name="external" className="ml-auto size-3.5 text-faint" />}
        </a>
    ) : (
        <button role="menuitem" type="button" onClick={onSelect} className={className}>
            {children}
        </button>
    );
}

/** One empty state for lists and their Trash. */
export function ListEmpty({ icon = 'pages', title, children }: { icon?: Parameters<typeof Icon>[0]['name']; title: string; children?: ReactNode }) {
    return (
        <div className="grid place-items-center rounded-lg border border-dashed border-line-strong px-6 py-14 text-center" data-testid="list-empty">
            <span className="grid size-10 place-items-center rounded-lg bg-sunken text-muted">
                <Icon name={icon} className="size-5" />
            </span>
            <p className="mt-3 t-title">{title}</p>
            {children && <p className="mt-1 max-w-sm t-meta">{children}</p>}
        </div>
    );
}
