import { router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { SharedProps } from '@/types';
import { visibleDestinations } from '@/lib/adminNavigation';
import { Button } from './ui';
import { Icon } from './Icon';

export function AdminSearch() {
    const { can } = usePage<SharedProps>().props;
    const dialog = useRef<HTMLDialogElement>(null);
    const field = useRef<HTMLInputElement>(null);
    const [open, setOpen] = useState(false),
        [query, setQuery] = useState(''),
        [pages, setPages] = useState<{ id: string; title: string; path: string }[]>([]),
        [status, setStatus] = useState(''),
        [selected, setSelected] = useState(0);
    function close() {
        dialog.current?.close();
        setOpen(false);
    }
    function show() {
        setQuery('');
        setSelected(0);
        setOpen(true);
        dialog.current?.showModal();
        field.current?.focus();
    }
    useEffect(() => {
        const key = (e: KeyboardEvent) => {
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                if (dialog.current?.open) close();
                else show();
            }
        };
        window.addEventListener('keydown', key);
        return () => window.removeEventListener('keydown', key);
    }, []);
    useEffect(() => {
        setSelected(0);
        setPages([]);
        setStatus('');
        if (!open || !query.trim()) return;
        const controller = new AbortController();
        const timer = setTimeout(() => {
            setStatus('Searching pages…');
            fetch('/admin/search?q=' + encodeURIComponent(query.trim()), { signal: controller.signal, headers: { Accept: 'application/json' } })
                .then(async (response) => {
                    if (!response.ok) throw Error();
                    const result = await response.json();
                    if (!controller.signal.aborted) {
                        setPages(result.pages);
                        setStatus(result.pages.length ? '' : 'No matching pages.');
                    }
                })
                .catch(() => {
                    if (!controller.signal.aborted) setStatus('Search unavailable. Try again.');
                });
        }, 200);
        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [query, open]);
    const destinations = visibleDestinations(can).filter((item) => (item.group + ' ' + item.label).toLowerCase().includes(query.toLowerCase()));
    const results = [
        ...destinations.map((item) => ({ label: item.label, detail: item.group, href: item.href })),
        ...pages.map((page) => ({ label: page.title, detail: page.path, href: '/admin/editor/' + page.id })),
    ];
    useEffect(() => {
        if (open) document.getElementById('admin-command-' + selected)?.scrollIntoView({ block: 'nearest' });
    }, [selected, results.length, open]);
    function go(href: string) {
        close();
        router.visit(href);
    }
    return (
        <>
            <button
                type="button"
                onClick={show}
                className="group inline-flex h-control w-auto items-center gap-2.5 rounded-md border border-line bg-canvas px-3 text-control text-muted transition-colors hover:border-line-strong hover:text-fg md:w-72"
            >
                <Icon name="search" className="size-4 text-faint group-hover:text-muted" />
                <span className="flex-1 text-left">Search</span>
                <kbd className="hidden rounded-sm border border-line bg-surface px-1 font-sans text-3xs text-faint sm:inline">Ctrl / ⌘ K</kbd>
            </button>
            <dialog
                ref={dialog}
                aria-labelledby="admin-search-title"
                onClose={() => setOpen(false)}
                onClick={(e) => {
                    if (e.target === dialog.current) close();
                }}
                className="m-auto w-[min(38rem,calc(100%_-_2rem))] rounded-xl border border-line bg-surface p-0 text-fg shadow-pop backdrop:bg-scrim"
            >
                <div>
                    <div className="flex h-12 items-center justify-between border-b border-line pr-2 pl-4">
                        <h2 id="admin-search-title" className="t-title">
                            Go to a page or section
                        </h2>
                        <Button variant="ghost" size="sm" onClick={close}>
                            Close
                        </Button>
                    </div>
                    <div className="p-3">
                        <label className="sr-only" htmlFor="admin-search-input">
                            Search pages and sections
                        </label>
                        <input
                            id="admin-search-input"
                            maxLength={120}
                            role="combobox"
                            aria-autocomplete="list"
                            aria-expanded={open}
                            aria-controls="admin-search-results"
                            aria-activedescendant={results[selected] ? 'admin-command-' + selected : undefined}
                            ref={field}
                            className="ui-input h-10 w-full text-sm"
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                            placeholder="Try Home, navigation or global styles"
                            onKeyDown={(e) => {
                                if (e.key === 'ArrowDown') {
                                    e.preventDefault();
                                    setSelected((n) => Math.max(0, Math.min(n + 1, results.length - 1)));
                                }
                                if (e.key === 'ArrowUp') {
                                    e.preventDefault();
                                    setSelected((n) => Math.max(0, n - 1));
                                }
                                if (e.key === 'Enter' && results[selected]) {
                                    e.preventDefault();
                                    go(results[selected]!.href);
                                }
                            }}
                        />
                        <p role="status" className="mt-2 px-1 t-meta">
                            {status || 'Search existing pages or choose a section. Use ↑ ↓ and Enter, or Tab.'}
                        </p>
                        <ul id="admin-search-results" role="listbox" aria-label="Pages and sections" className="ak-scroll mt-2 max-h-80">
                            {results.map((item, index) => (
                                <li key={item.href} role="presentation">
                                    <button
                                        type="button"
                                        role="option"
                                        aria-selected={index === selected}
                                        id={'admin-command-' + index}
                                        onClick={() => go(item.href)}
                                        className={
                                            'flex h-9 w-full items-center justify-between gap-3 rounded-md px-3 text-left text-ui ' +
                                            (index === selected ? 'bg-accent-soft text-fg' : 'hover:bg-hover')
                                        }
                                    >
                                        <span>{item.label}</span>
                                        <span className={`truncate text-muted ${item.detail.startsWith('/') ? 'font-mono text-2xs' : 'text-xs'}`}>
                                            {item.detail}
                                        </span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    </div>
                </div>
            </dialog>
        </>
    );
}
