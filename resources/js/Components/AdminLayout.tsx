import { Link, router, usePage } from '@inertiajs/react';
import { useId, useState, type ReactNode } from 'react';
import { useTheme, type ThemePreference } from '@/lib/theme';
import type { SharedProps } from '@/types';

const NAV = [
    { href: '/admin', label: 'Dashboard', active: (url: string) => url === '/admin' || url.startsWith('/admin?') },
    { href: '/admin/pages', label: 'Pages', active: (url: string) => url.startsWith('/admin/pages') },
];
const PLANNED = ['Content', 'Media', 'Design', 'SEO', 'AI', 'Site health', 'Settings'];

const THEMES: { value: ThemePreference; label: string; icon: ReactNode }[] = [
    {
        value: 'system',
        label: 'System',
        icon: <path d="M2.5 3.5h11v7h-11zM6 13.5h4M8 10.5v3" />,
    },
    {
        value: 'light',
        label: 'Light',
        icon: <path d="M8 5.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5zM8 1.5v1.5M8 13v1.5M1.5 8H3M13 8h1.5M3.4 3.4l1 1M11.6 11.6l1 1M3.4 12.6l1-1M11.6 4.4l1-1" />,
    },
    {
        value: 'dark',
        label: 'Dark',
        icon: <path d="M13.5 9.5A5.5 5.5 0 0 1 6.5 2.5a5.5 5.5 0 1 0 7 7z" />,
    },
];

const capitalize = (text: string) => text.charAt(0).toUpperCase() + text.slice(1);

/**
 * The admin frame: site identity, navigation, account and theme. On small screens the
 * sidebar collapses behind a menu button. Screens inside it use the theme tokens.
 */
export function AdminLayout({ children }: { children: ReactNode }) {
    const { auth, site } = usePage<SharedProps>().props;
    const url = usePage().url;
    const { preference, theme, setPreference } = useTheme();
    const [menuOpen, setMenuOpen] = useState(false);
    const navId = useId();
    const siteName = site?.name ?? 'Arkon';

    return (
        <div data-theme={theme} className="min-h-dvh bg-canvas text-fg lg:flex">
            <header className="sticky top-0 z-20 flex h-12 items-center justify-between border-b border-line bg-raised px-4 lg:hidden">
                <SiteMark name={siteName} />
                <button
                    type="button"
                    aria-expanded={menuOpen}
                    aria-controls={navId}
                    onClick={() => setMenuOpen((open) => !open)}
                    className="-mr-2 rounded-md px-2 py-1 text-sm text-muted hover:bg-line/60 hover:text-fg"
                >
                    {menuOpen ? 'Close' : 'Menu'}
                </button>
            </header>

            <aside
                id={navId}
                className={`${menuOpen ? 'flex' : 'hidden'} flex-col border-b border-line bg-raised lg:sticky lg:top-0 lg:flex lg:h-dvh lg:w-60 lg:shrink-0 lg:border-r lg:border-b-0`}
            >
                <div className="hidden h-14 items-center px-4 lg:flex">
                    <SiteMark name={siteName} role={site?.role} />
                </div>

                <nav aria-label="Main" className="flex-1 overflow-y-auto px-2 py-2 text-sm lg:py-1">
                    <ul className="space-y-px">
                        {NAV.map((item) => {
                            const current = item.active(url);
                            return (
                                <li key={item.href}>
                                    <Link
                                        href={item.href}
                                        aria-current={current ? 'page' : undefined}
                                        className={`flex h-8 items-center rounded-md px-2.5 ${
                                            current ? 'bg-line/70 font-medium text-fg' : 'text-muted hover:bg-line/50 hover:text-fg'
                                        }`}
                                    >
                                        {item.label}
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>
                    <p id={`${navId}-soon`} className="mt-6 mb-1 px-2.5 text-xs text-faint">
                        Coming later
                    </p>
                    <ul aria-labelledby={`${navId}-soon`} className="space-y-px">
                        {PLANNED.map((label) => (
                            <li key={label} aria-disabled="true" className="flex h-7 items-center px-2.5 text-faint">
                                {label}
                            </li>
                        ))}
                    </ul>
                </nav>

                <div className="space-y-3 border-t border-line p-3">
                    <ThemeSwitch value={preference} onChange={setPreference} />
                    <div className="flex items-center justify-between gap-2 px-1">
                        <div className="min-w-0 text-sm">
                            <p className="truncate font-medium">{auth.user?.name}</p>
                            <p className="truncate text-xs text-muted">{auth.user?.email}</p>
                        </div>
                        <button
                            type="button"
                            onClick={() => router.post('/logout')}
                            className="shrink-0 rounded-md px-2 py-1 text-xs text-muted hover:bg-line/60 hover:text-fg"
                        >
                            Sign out
                        </button>
                    </div>
                </div>
            </aside>

            <main className="min-w-0 flex-1">{children}</main>
        </div>
    );
}

function SiteMark({ name, role }: { name: string; role?: string }) {
    return (
        <div className="flex min-w-0 items-center gap-2.5">
            <span aria-hidden="true" className="grid size-6 shrink-0 place-items-center rounded-md bg-fg text-[11px] font-semibold text-canvas">
                {name.trim().charAt(0).toUpperCase() || 'A'}
            </span>
            <span className="min-w-0 leading-tight">
                <span className="block truncate text-sm font-medium">{name}</span>
                {role && <span className="block text-xs text-muted">{capitalize(role)}</span>}
            </span>
        </div>
    );
}

function ThemeSwitch({ value, onChange }: { value: ThemePreference; onChange(value: ThemePreference): void }) {
    const name = useId();
    return (
        <fieldset className="flex rounded-md border border-line bg-canvas p-0.5">
            <legend className="sr-only">Colour theme</legend>
            {THEMES.map((option) => (
                <label
                    key={option.value}
                    title={option.label}
                    className="flex h-6 flex-1 cursor-pointer items-center justify-center rounded text-muted has-checked:bg-surface has-checked:text-fg has-checked:shadow-hairline has-focus-visible:outline-2 has-focus-visible:outline-accent hover:text-fg"
                >
                    <input
                        type="radio"
                        name={name}
                        value={option.value}
                        checked={value === option.value}
                        onChange={() => onChange(option.value)}
                        className="sr-only"
                    />
                    <svg
                        aria-hidden="true"
                        viewBox="0 0 16 16"
                        className="size-3.5"
                        fill="none"
                        stroke="currentColor"
                        strokeWidth="1.4"
                        strokeLinecap="round"
                        strokeLinejoin="round"
                    >
                        {option.icon}
                    </svg>
                    <span className="sr-only">{option.label}</span>
                </label>
            ))}
        </fieldset>
    );
}
