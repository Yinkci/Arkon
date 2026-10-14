import { useEffect, useRef, useState } from 'react';
import { Button, Field } from '@/Components/ui';
import { api } from '@/lib/api';
import type { ContentFieldDefinition } from '@/types';

/** One entry as the picker shows it: enough to recognise it, never its own fields. */
export interface RelatedEntry {
    id: string;
    type: string;
    typeLabel: string;
    title: string;
    path: string;
    status: 'published' | 'draft' | 'trash';
}

/** What is stored: a reference, or a list of them in the order somebody chose. */
type Value = { id: string } | { id: string }[] | null;

const idsOf = (value: unknown): string[] => {
    const list = Array.isArray(value) ? value : value === null || value === undefined ? [] : [value];

    return list.map((one) => (one && typeof one === 'object' ? String((one as { id?: unknown }).id ?? '') : '')).filter(Boolean);
};

/**
 * A relationship field: which entries this one points at.
 *
 * Searching happens on the server, so a site with ten thousand services costs the same as one
 * with ten; what is stored is only the reference, so a title typed into another entry is never
 * copied here and can never go stale. A target that has been trashed keeps its place and says
 * so, because trashing is reversible and silently dropping the reference would not be.
 */
export function RelationshipField({
    field,
    value,
    canEdit,
    onChange,
}: {
    field: ContentFieldDefinition;
    value: unknown;
    canEdit: boolean;
    onChange(value: Value): void;
}) {
    const target = String(field.settings.targetType ?? '');
    const multiple = field.settings.multiple === true;
    const max = multiple ? Number(field.settings.maxItems ?? 0) || 50 : 1;
    const chosen = idsOf(value);

    const [known, setKnown] = useState<Record<string, RelatedEntry>>({});
    const [term, setTerm] = useState('');
    const [results, setResults] = useState<RelatedEntry[] | null>(null);
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState(false);
    const box = useRef<HTMLDivElement>(null);

    // What the chips need: the entries this field already points at, asked for by id so a
    // trashed one is still named.
    const missing = chosen.filter((id) => !known[id]);
    useEffect(() => {
        if (missing.length === 0) return;
        let live = true;
        void api<{ items: RelatedEntry[] }>(`/related?ids=${encodeURIComponent(missing.join(','))}`, { method: 'GET' })
            .then((result) => {
                if (live && result.ok) setKnown((k) => ({ ...k, ...Object.fromEntries(result.data.items.map((item) => [item.id, item])) }));
            })
            .catch(() => undefined);

        return () => {
            live = false;
        };
    }, [missing.join(',')]);

    // Searching is debounced: one request after the typing settles, not one per keystroke.
    useEffect(() => {
        if (!open || target === '') return;
        let live = true;
        setBusy(true);
        const timer = setTimeout(() => {
            void api<{ items: RelatedEntry[] }>(`/related?type=${encodeURIComponent(target)}&q=${encodeURIComponent(term)}`, { method: 'GET' })
                .then((result) => {
                    if (!live) return;
                    setResults(result.ok ? result.data.items : []);
                    setBusy(false);
                })
                .catch(() => {
                    if (live) {
                        setResults([]);
                        setBusy(false);
                    }
                });
        }, 200);

        return () => {
            live = false;
            clearTimeout(timer);
        };
    }, [open, term, target]);

    useEffect(() => {
        if (!open) return;
        const away = (event: MouseEvent) => {
            if (box.current && !box.current.contains(event.target as Node)) setOpen(false);
        };
        document.addEventListener('mousedown', away);

        return () => document.removeEventListener('mousedown', away);
    }, [open]);

    const give = (ids: string[]) => onChange(multiple ? ids.map((id) => ({ id })) : ids.length ? { id: ids[0]! } : null);

    const add = (entry: RelatedEntry) => {
        setKnown((k) => ({ ...k, [entry.id]: entry }));
        give(multiple ? [...chosen.filter((id) => id !== entry.id), entry.id].slice(0, max) : [entry.id]);
        setOpen(false);
        setTerm('');
    };
    const remove = (id: string) => give(chosen.filter((one) => one !== id));
    const move = (index: number, by: number) => {
        const next = [...chosen];
        const to = index + by;
        if (to < 0 || to >= next.length) return;
        [next[index], next[to]] = [next[to]!, next[index]!];
        give(next);
    };

    const full = multiple ? chosen.length >= max : chosen.length >= 1;

    return (
        <Field label={field.label} hint={(field.settings.help as string) || undefined}>
            {({ describedBy }) => (
                <div ref={box} aria-describedby={describedBy} className="space-y-2" data-testid={`relationship-${field.key}`}>
                    {chosen.length > 0 && (
                        <ul className="space-y-1">
                            {chosen.map((id, index) => {
                                const entry = known[id];
                                return (
                                    <li key={id} className="flex items-center gap-2 rounded border border-line bg-surface px-2 py-1 text-ui">
                                        <span className="min-w-0 flex-1 truncate">
                                            {entry ? entry.title : 'Loading…'}
                                            {entry && entry.status !== 'published' && (
                                                <span className="t-meta ml-1.5">{entry.status === 'trash' ? 'Trashed' : 'Draft'}</span>
                                            )}
                                        </span>
                                        {canEdit && multiple && chosen.length > 1 && (
                                            <>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    icon="chevronUp"
                                                    aria-label={`Move ${entry?.title ?? 'item'} up`}
                                                    disabled={index === 0}
                                                    onClick={() => move(index, -1)}
                                                >
                                                    <span className="sr-only">Move up</span>
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    icon="chevronDown"
                                                    aria-label={`Move ${entry?.title ?? 'item'} down`}
                                                    disabled={index === chosen.length - 1}
                                                    onClick={() => move(index, 1)}
                                                >
                                                    <span className="sr-only">Move down</span>
                                                </Button>
                                            </>
                                        )}
                                        {canEdit && (
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                icon="close"
                                                aria-label={`Remove ${entry?.title ?? 'item'}`}
                                                onClick={() => remove(id)}
                                            >
                                                <span className="sr-only">Remove</span>
                                            </Button>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                    )}

                    {canEdit && !full && (
                        <div className="relative">
                            <input
                                className="ui-input"
                                type="search"
                                placeholder={`Search ${field.label.toLowerCase()}…`}
                                value={term}
                                onFocus={() => setOpen(true)}
                                onChange={(e) => {
                                    setTerm(e.target.value);
                                    setOpen(true);
                                }}
                            />
                            {open && (
                                <ul className="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded border border-line bg-surface shadow-lg" role="listbox">
                                    {busy && results === null && <li className="t-meta px-2 py-1.5">Searching…</li>}
                                    {results?.filter((entry) => !chosen.includes(entry.id)).length === 0 && (
                                        <li className="t-meta px-2 py-1.5">Nothing found.</li>
                                    )}
                                    {results
                                        ?.filter((entry) => !chosen.includes(entry.id))
                                        .map((entry) => (
                                            <li key={entry.id}>
                                                <button
                                                    type="button"
                                                    role="option"
                                                    aria-selected={false}
                                                    className="block w-full px-2 py-1.5 text-left text-ui hover:bg-raised"
                                                    onClick={() => add(entry)}
                                                >
                                                    {entry.title}
                                                    <span className="t-meta ml-1.5">{entry.status === 'published' ? entry.path : 'Draft'}</span>
                                                </button>
                                            </li>
                                        ))}
                                </ul>
                            )}
                        </div>
                    )}
                    {target === '' && <p className="t-meta">This field has no kind of content to relate to yet.</p>}
                </div>
            )}
        </Field>
    );
}
