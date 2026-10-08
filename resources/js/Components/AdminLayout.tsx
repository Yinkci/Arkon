import { Link, router, usePage } from '@inertiajs/react';
import { useId, useState, type ReactNode } from 'react';
import { useTheme, type ThemePreference } from '@/lib/theme';
import type { SharedProps } from '@/types';
import { Icon, type IconName } from './Icon';
import { ArkonMark } from './ui';

const NAV: { href: string; label: string; icon: IconName; active(url: string): boolean }[] = [
    { href: '/admin', label: 'Dashboard', icon: 'home', active: (url) => url === '/admin' || url.startsWith('/admin?') },
    { href: '/admin/pages', label: 'Pages', icon: 'pages', active: (url) => url.startsWith('/admin/pages') },
    { href: '/admin/themes', label: 'Themes', icon: 'palette', active: (url) => url.startsWith('/admin/themes') },
    { href: '/admin/design', label: 'Design', icon: 'palette', active: (url) => url.startsWith('/admin/design') || url.startsWith('/admin/components') },
];

const THEMES: { value: ThemePreference; label: string; icon: IconName }[] = [
    { value: 'system', label: 'System', icon: 'monitor' },
    { value: 'light', label: 'Light', icon: 'sun' },
    { value: 'dark', label: 'Dark', icon: 'moon' },
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
                    className="-mr-2 inline-flex h-8 items-center gap-1.5 rounded-md px-2 text-[0.8125rem] text-muted hover:bg-sunken hover:text-fg"
                >
                    <Icon name={menuOpen ? 'close' : 'menu'} />
                    {menuOpen ? 'Close' : 'Menu'}
                </button>
            </header>

            <aside
                id={navId}
                className={`${menuOpen ? 'flex' : 'hidden'} flex-col border-b border-line bg-raised lg:sticky lg:top-0 lg:flex lg:h-dvh lg:w-60 lg:shrink-0 lg:border-r lg:border-b-0`}
            >
                <div className="hidden h-14 items-center border-b border-line px-4 lg:flex">
                    <SiteMark name={siteName} role={site?.role} />
                </div>

                <nav aria-label="Main" className="flex-1 overflow-y-auto px-2 py-3 text-[0.8125rem]">
                    <ul className="space-y-0.5">
                        {NAV.map((item) => {
                            const current = item.active(url);
                            return (
                                <li key={item.href}>
                                    <Link
                                        href={item.href}
                                        aria-current={current ? 'page' : undefined}
                                        className={`relative flex h-8 items-center gap-2.5 rounded-md px-2.5 ${current ? 'bg-surface font-medium text-fg shadow-hairline' : 'text-muted hover:bg-sunken hover:text-fg'}`}
                                    >
                                        {current && <span aria-hidden className="absolute top-1.5 bottom-1.5 left-0 w-0.5 rounded-full bg-accent" />}
                                        <Icon name={item.icon} className={current ? 'size-4 text-accent' : 'size-4'} />
                                        {item.label}
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>
                </nav>

                <div className="space-y-3 border-t border-line p-3">
                    <ThemeSwitch value={preference} onChange={setPreference} />
                    <div className="flex items-center justify-between gap-2 px-1">
                        <div className="min-w-0 text-[0.8125rem]">
                            <p className="truncate font-medium">{auth.user?.name}</p>
                            <p className="truncate text-xs text-muted">{auth.user?.email}</p>
                        </div>
                        <button
                            type="button"
                            onClick={() => router.post('/logout')}
                            className="inline-flex shrink-0 items-center gap-1 rounded-md px-2 py-1 text-xs text-muted hover:bg-sunken hover:text-fg"
                        >
                            <Icon name="logout" className="size-3.5" />
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
            <ArkonMark className="size-7 shrink-0" />
            <span className="min-w-0 leading-tight">
                <span className="block truncate text-[0.8125rem] font-semibold">{name}</span>
                {role && <span className="block text-[11px] text-muted">{capitalize(role)} · Arkon</span>}
            </span>
        </div>
    );
}

function ThemeSwitch({ value, onChange }: { value: ThemePreference; onChange(value: ThemePreference): void }) {
    const name = useId();
    return (
        <fieldset className="flex rounded-md border border-line bg-sunken p-0.5">
            <legend className="sr-only">Colour theme</legend>
            {THEMES.map((option) => (
                <label
                    key={option.value}
                    title={option.label}
                    className="flex h-7 flex-1 cursor-pointer items-center justify-center gap-1 rounded-[5px] text-[11px] text-muted has-checked:bg-surface has-checked:text-fg has-checked:shadow-hairline has-focus-visible:outline-2 has-focus-visible:outline-accent hover:text-fg"
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
