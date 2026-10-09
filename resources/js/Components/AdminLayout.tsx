import { Link, router, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useId, useRef, useState, type ReactNode } from 'react';
import { useTheme, type ThemePreference } from '@/lib/theme';
import type { SharedProps } from '@/types';
import { Icon, type IconName } from './Icon';
import { ArkonMark } from './ui';
import { AdminSearch } from './AdminSearch';
import { currentDestination, visibleDestinations, type AdminDestination } from '@/lib/adminNavigation';

const THEMES: { value: ThemePreference; label: string; icon: IconName }[] = [
    { value: 'system', label: 'System', icon: 'monitor' },
    { value: 'light', label: 'Light', icon: 'sun' },
    { value: 'dark', label: 'Dark', icon: 'moon' },
];

const capitalize = (text: string) => text.charAt(0).toUpperCase() + text.slice(1);
const host = (url: string) => url.replace(/^https?:\/\//, '').replace(/\/$/, '');

const CLOSED_KEY = 'arkon.nav.closed';
const COLLAPSED_KEY = 'arkon.nav.collapsed';

function readStored<T>(key: string, fallback: T): T {
    try {
        const raw = localStorage.getItem(key);
        return raw === null ? fallback : (JSON.parse(raw) as T);
    } catch {
        return fallback;
    }
}
function writeStored(key: string, value: unknown) {
    try {
        localStorage.setItem(key, JSON.stringify(value));
    } catch {
        // Storage unavailable: the choice lasts for this page only.
    }
}

/**
 * The admin frame. A graphite navigation rail (site identity, grouped destinations, account)
 * beside a light workspace with a sticky header. The rail is the product's permanent place;
 * screens render inside `.ak-comfortable`, the admin's reading density. On small screens the
 * rail collapses behind a menu button; on large ones it can shrink to icons.
 */
export function AdminLayout({ children }: { children: ReactNode }) {
    const { auth, site, can } = usePage<SharedProps>().props;
    const url = usePage().url;
    const { preference, theme, setPreference } = useTheme();
    const [menuOpen, setMenuOpen] = useState(false);
    const navId = useId();
    const siteName = site?.name ?? 'Arkon';
    const items = visibleDestinations(can);
    const current = currentDestination(url);
    const [collapsed, setCollapsedState] = useState(() => readStored(COLLAPSED_KEY, false));
    const setCollapsed = useCallback((value: boolean) => {
        setCollapsedState(value);
        writeStored(COLLAPSED_KEY, value);
    }, []);
    const [closedGroups, setClosedGroups] = useState<string[]>(() => readStored(CLOSED_KEY, []));
    const toggleGroup = (group: string) =>
        setClosedGroups((closed) => {
            const next = closed.includes(group) ? closed.filter((g) => g !== group) : [...closed, group];
            writeStored(CLOSED_KEY, next);
            return next;
        });
    useEffect(() => {
        setMenuOpen(false);
    }, [url]);
    // A long navigation on a short screen: keep the current destination in view (no animation).
    const navRef = useRef<HTMLElement>(null);
    useEffect(() => {
        navRef.current?.querySelector<HTMLElement>('[aria-current="page"]')?.scrollIntoView({ block: 'nearest' });
    }, [url, collapsed]);
    useEffect(() => {
        const close = (e: KeyboardEvent) => {
            if (e.key === 'Escape') setMenuOpen(false);
        };
        window.addEventListener('keydown', close);
        return () => window.removeEventListener('keydown', close);
    }, []);

    // The overview (the dashboard) sits on its own at the top; everything else is grouped.
    const pinned = items.filter((item) => item.group === 'Overview');
    const groups = Array.from(new Set(items.filter((item) => item.group !== 'Overview').map((item) => item.group)));

    return (
        <div data-theme={theme} className="ak-comfortable min-h-dvh bg-canvas text-fg lg:flex">
            <header className="sticky top-0 z-20 flex h-14 items-center justify-between bg-nav px-4 text-nav-fg lg:hidden">
                <SiteIdentity name={siteName} url={site?.url} />
                <button
                    type="button"
                    aria-expanded={menuOpen}
                    aria-controls={navId}
                    onClick={() => setMenuOpen((open) => !open)}
                    className="-mr-2 inline-flex h-9 items-center gap-2 rounded-md px-2.5 text-sm text-nav-text hover:bg-nav-hover hover:text-nav-fg"
                >
                    <Icon name={menuOpen ? 'close' : 'menu'} className="size-[18px]" />
                    {menuOpen ? 'Close' : 'Menu'}
                </button>
            </header>

            <aside
                id={navId}
                className={`${menuOpen ? 'flex' : 'hidden'} flex-col bg-nav text-nav-text lg:border-r lg:border-nav-edge lg:sticky lg:top-0 lg:flex lg:h-dvh lg:shrink-0 lg:transition-[width] lg:duration-150 ${collapsed ? 'lg:w-[4.5rem]' : 'lg:w-[17rem]'}`}
            >
                <div className={`hidden h-16 shrink-0 items-center lg:flex ${collapsed ? 'justify-center' : 'px-5'}`}>
                    {collapsed ? (
                        <span title={siteName}>
                            <ArkonMark inverse className="size-8" />
                        </span>
                    ) : (
                        <SiteIdentity name={siteName} url={site?.url} role={site?.role} />
                    )}
                </div>

                <nav ref={navRef} aria-label="Main" className="ak-scroll ak-scroll-dark ak-scroll-fade min-h-0 flex-1 scroll-py-8 pt-2 pr-0.5 pb-6 pl-3 lg:pt-3">
                    <ul className="space-y-0.5">
                        {pinned.map((item) => (
                            <NavItem key={item.href} item={item} active={current?.href === item.href} collapsed={collapsed} />
                        ))}
                    </ul>
                    {groups.map((group) => {
                        const groupItems = items.filter((item) => item.group === group);
                        const holdsCurrent = groupItems.some((item) => item.href === current?.href);
                        const open = collapsed || holdsCurrent || !closedGroups.includes(group);
                        const listId = `${navId}-${group.replace(/\W+/g, '-')}`;
                        return (
                            <section key={group} className="mt-5" aria-label={group}>
                                {collapsed ? (
                                    <h2 className="sr-only">{group}</h2>
                                ) : (
                                    <h2>
                                        <button
                                            type="button"
                                            aria-expanded={open}
                                            aria-controls={listId}
                                            disabled={holdsCurrent}
                                            onClick={() => toggleGroup(group)}
                                            className="group/heading flex h-7 w-full items-center justify-between rounded px-3 text-2xs font-semibold tracking-[0.08em] text-nav-faint uppercase hover:text-nav-muted disabled:cursor-default disabled:hover:text-nav-faint"
                                        >
                                            {group}
                                            {!holdsCurrent && (
                                                <Icon
                                                    name="chevronDown"
                                                    className={`size-3 opacity-0 transition-[transform,opacity] duration-150 group-hover/heading:opacity-100 group-focus-visible/heading:opacity-100 ${open ? '' : '-rotate-90 opacity-100'}`}
                                                />
                                            )}
                                        </button>
                                    </h2>
                                )}
                                <ul id={listId} hidden={!open} className="mt-1 space-y-0.5">
                                    {groupItems.map((item) => (
                                        <NavItem key={item.href} item={item} active={current?.href === item.href} collapsed={collapsed} />
                                    ))}
                                </ul>
                            </section>
                        );
                    })}
                </nav>

                <div className="shrink-0 space-y-2 border-t border-nav-line p-3">
                    <div className={`flex items-center gap-3 rounded-lg ${collapsed ? 'lg:flex-col lg:gap-2' : 'px-2 py-1'}`}>
                        <Initials name={auth.user?.name} />
                        <div className={collapsed ? 'lg:hidden' : 'min-w-0 flex-1'}>
                            <p className="truncate text-sm font-medium text-nav-fg">{auth.user?.name}</p>
                            <p className="truncate text-xs text-nav-muted">{auth.user?.email}</p>
                        </div>
                        <button
                            type="button"
                            onClick={() => router.post('/logout')}
                            title="Sign out"
                            className="grid size-8 shrink-0 place-items-center rounded-md text-nav-muted transition-colors duration-100 hover:bg-nav-hover hover:text-nav-fg"
                        >
                            <Icon name="logout" className="size-4" />
                            <span className="sr-only">Sign out</span>
                        </button>
                    </div>
                    <div className={`flex items-center gap-2 ${collapsed ? 'lg:justify-center' : ''}`}>
                        <div className={`min-w-0 flex-1 ${collapsed ? 'lg:hidden' : ''}`}>
                            <ThemeSwitch value={preference} onChange={setPreference} />
                        </div>
                        <button
                            type="button"
                            className="hidden size-8 shrink-0 place-items-center rounded-md text-nav-faint transition-colors duration-100 hover:bg-nav-hover hover:text-nav-text lg:grid"
                            aria-label={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
                            title={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
                            onClick={() => setCollapsed(!collapsed)}
                        >
                            <Icon name={collapsed ? 'chevronRight' : 'chevronLeft'} className="size-4" />
                        </button>
                    </div>
                </div>
            </aside>

            <div className="flex min-w-0 flex-1 flex-col">
                <a href="#admin-content" className="sr-only focus:not-sr-only focus:block focus:bg-surface focus:p-3">
                    Skip to content
                </a>
                <header className="sticky top-0 z-10 flex h-16 shrink-0 items-center justify-between gap-4 border-b border-line bg-surface/90 px-5 backdrop-blur-md max-lg:top-14 sm:px-10">
                    <p className="flex min-w-0 items-center gap-2 truncate text-sm text-muted">
                        <span className="max-sm:hidden">{current?.group}</span>
                        <Icon name="chevronRight" className="size-3.5 text-faint max-sm:hidden" />
                        <span className="truncate font-semibold text-fg">{current?.label ?? 'Arkon'}</span>
                    </p>
                    <div className="flex items-center gap-2">
                        <AdminSearch />
                        {site?.url && (
                            <a
                                href={site.url}
                                target="_blank"
                                rel="noreferrer"
                                className="inline-flex h-control items-center gap-2 rounded-md border border-line-strong bg-surface px-control text-control font-medium text-fg shadow-hairline transition-colors hover:bg-raised"
                            >
                                <Icon name="external" />
                                <span className="max-sm:sr-only">View site</span>
                            </a>
                        )}
                    </div>
                </header>
                <main id="admin-content" tabIndex={-1} className="flex-1 outline-none">
                    {children}
                </main>
            </div>
        </div>
    );
}

function NavItem({ item, active, collapsed }: { item: AdminDestination; active: boolean; collapsed: boolean }) {
    return (
        <li>
            <Link
                href={item.href}
                title={collapsed ? item.label : undefined}
                aria-label={collapsed ? item.label : undefined}
                aria-current={active ? 'page' : undefined}
                className={
                    `group/nav relative flex h-9 items-center gap-3 rounded-md px-3 text-sm transition-colors duration-100 ${collapsed ? 'lg:justify-center lg:px-0' : ''} ` +
                    (active ? 'bg-nav-active font-medium text-nav-fg' : 'text-nav-text hover:bg-nav-hover hover:text-nav-fg')
                }
            >
                {active && <span aria-hidden="true" className="absolute inset-y-2 left-0 w-[3px] rounded-r-full bg-nav-accent" />}
                <Icon
                    name={item.icon}
                    className={`size-[18px] ${active ? 'text-nav-accent' : 'text-nav-muted transition-colors duration-100 group-hover/nav:text-nav-text'}`}
                />
                <span className={collapsed ? 'lg:sr-only' : 'truncate'}>{item.label}</span>
            </Link>
        </li>
    );
}

function SiteIdentity({ name, url, role }: { name: string; url?: string | null; role?: string }) {
    return (
        <div className="flex min-w-0 items-center gap-3">
            <ArkonMark inverse className="size-8 shrink-0" />
            <span className="min-w-0 leading-tight">
                <span className="block truncate text-sm font-semibold text-nav-fg">{name}</span>
                <span className="block truncate text-xs text-nav-muted">
                    {url ? <span className="font-mono text-[0.6875rem]">{host(url)}</span> : role ? `${capitalize(role)} · Arkon` : 'Arkon'}
                </span>
            </span>
        </div>
    );
}

function Initials({ name }: { name?: string | null }) {
    const initials =
        (name ?? '?')
            .split(/\s+/)
            .filter(Boolean)
            .slice(0, 2)
            .map((part) => part[0]!.toUpperCase())
            .join('') || '?';
    return (
        <span
            aria-hidden="true"
            className="grid size-8 shrink-0 place-items-center rounded-md bg-nav-raised text-xs font-semibold text-nav-text ring-1 ring-nav-line"
        >
            {initials}
        </span>
    );
}

function ThemeSwitch({ value, onChange }: { value: ThemePreference; onChange(value: ThemePreference): void }) {
    const name = useId();
    return (
        <fieldset className="flex rounded-md bg-nav-raised p-0.5 ring-1 ring-nav-line">
            <legend className="sr-only">Colour theme</legend>
            {THEMES.map((option) => (
                <label
                    key={option.value}
                    title={option.label}
                    className="flex h-7 flex-1 cursor-pointer items-center justify-center gap-1.5 rounded text-xs text-nav-muted transition-colors duration-100 hover:text-nav-fg has-checked:bg-nav-active has-checked:text-nav-fg has-focus-visible:outline-2 has-focus-visible:outline-nav-accent"
                >
                    <input
                        type="radio"
                        name={name}
                        value={option.value}
                        checked={value === option.value}
                        onChange={() => onChange(option.value)}
                        className="sr-only"
                    />
                    <Icon name={option.icon} className="size-3.5" />
                    <span>{option.label}</span>
                </label>
            ))}
        </fieldset>
    );
}
