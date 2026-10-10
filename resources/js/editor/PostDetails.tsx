import { usePage } from '@inertiajs/react';
import { useId, useState } from 'react';
import { Icon } from '@/Components/Icon';
import { Button, PanelSection } from '@/Components/ui';
import { api } from '@/lib/api';
import type { ContentDetailsValues, EditorContent, MediaInfo, SharedProps } from '@/types';
import { MediaPicker } from './MediaPicker';

/**
 * A post's details besides its blocks: excerpt, featured image, categories and tags. They belong
 * to the draft like the title: saved here, live (on the site and in the API) once published.
 */
export function PostDetails({
    pageId,
    content,
    media,
    canEdit,
    canUpload,
    onUpload,
}: {
    pageId: string;
    content: EditorContent;
    media: MediaInfo[];
    canEdit: boolean;
    canUpload: boolean;
    onUpload(file: File, progress?: (percent: number) => void): Promise<MediaInfo | null>;
}) {
    // Editors may add terms; renaming and deleting them is on the Categories & tags screen.
    const canAddTerms = !!usePage<SharedProps>().props.can['term.create'];
    const [saved, setSaved] = useState<ContentDetailsValues>(content.values);
    const [values, setValues] = useState<ContentDetailsValues>(content.values);
    const [taxonomies, setTaxonomies] = useState(content.taxonomies);
    const [pending, setPending] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [savedNote, setSavedNote] = useState(false);
    const ids = { excerpt: useId(), error: useId() };
    const changed = JSON.stringify(values) !== JSON.stringify(saved);

    const toggle = (taxonomy: string, id: string) =>
        setValues((v) => {
            const current = v.terms[taxonomy] ?? [];
            return { ...v, terms: { ...v.terms, [taxonomy]: current.includes(id) ? current.filter((x) => x !== id) : [...current, id] } };
        });

    async function addTerm(taxonomy: string, name: string): Promise<string | null> {
        const result = await api<{ id: string; name: string }>(`/terms/${taxonomy}`, { body: { name } });
        if (!result.ok) return result.issues?.length ? result.issues.map((i) => i.message).join(' ') : result.message;
        setTaxonomies((list) => list.map((t) => (t.name === taxonomy ? { ...t, terms: [...t.terms, { id: result.data.id, name: result.data.name }] } : t)));
        toggle(taxonomy, result.data.id);
        return null;
    }

    async function save(event: React.FormEvent) {
        event.preventDefault();
        setPending(true);
        setError(null);
        setSavedNote(false);
        try {
            const result = await api<ContentDetailsValues>(`/pages/${pageId}/details`, {
                body: { ...(content.details ? { excerpt: values.excerpt, featuredMediaId: values.featuredMediaId } : {}), terms: values.terms },
            });
            if (!result.ok) {
                setError(result.issues?.length ? result.issues.map((i) => i.message).join(' ') : result.message);
                return;
            }
            setSaved(result.data);
            setValues(result.data);
            setSavedNote(true);
        } catch {
            setError('The outcome could not be confirmed (network problem). Save again: it only sets these values.');
        } finally {
            setPending(false);
        }
    }

    return (
        <PanelSection title={`${content.label} details`}>
            <form className="space-y-4" aria-label={`${content.label} details`} onSubmit={save} data-testid="post-details">
                {content.details && (
                    <>
                        <div>
                            <label htmlFor={ids.excerpt} className="ui-label">
                                Excerpt
                            </label>
                            <textarea
                                id={ids.excerpt}
                                className="ui-input min-h-20 text-sm"
                                maxLength={1000}
                                disabled={!canEdit}
                                value={values.excerpt}
                                onChange={(e) => setValues((v) => ({ ...v, excerpt: e.target.value }))}
                            />
                            <p className="mt-1 text-2xs text-muted">A short summary for lists, feeds and the API.</p>
                        </div>
                        <div>
                            <p className="ui-label">Featured image</p>
                            <MediaPicker
                                value={values.featuredMediaId}
                                media={media}
                                canEdit={canEdit}
                                canUpload={canUpload}
                                scopeKey="featured"
                                onChoose={(id) => setValues((v) => ({ ...v, featuredMediaId: id }))}
                                onUpload={onUpload}
                            />
                        </div>
                    </>
                )}
                {taxonomies.map((taxonomy) => (
                    <TermChooser
                        key={taxonomy.name}
                        label={taxonomy.plural}
                        terms={taxonomy.terms}
                        chosen={values.terms[taxonomy.name] ?? []}
                        canEdit={canEdit}
                        canAdd={canEdit && canAddTerms}
                        onToggle={(id) => toggle(taxonomy.name, id)}
                        onAdd={(name) => addTerm(taxonomy.name, name)}
                    />
                ))}
                {error && (
                    <p id={ids.error} role="alert" className="flex items-start gap-1.5 text-2xs text-danger">
                        <Icon name="alert" className="mt-px size-3.5" />
                        {error}
                    </p>
                )}
                {savedNote && !changed && (
                    <p className="text-2xs text-muted" role="status">
                        Saved. Visitors see these details once you publish.
                    </p>
                )}
                <Button type="submit" className="w-full" busy={pending} disabled={!canEdit || !changed || pending}>
                    {pending ? 'Saving…' : `Save ${content.label.toLowerCase()} details`}
                </Button>
            </form>
        </PanelSection>
    );
}

function TermChooser({
    label,
    terms,
    chosen,
    canEdit,
    canAdd,
    onToggle,
    onAdd,
}: {
    label: string;
    terms: { id: string; name: string }[];
    chosen: string[];
    canEdit: boolean;
    canAdd: boolean;
    onToggle(id: string): void;
    onAdd(name: string): Promise<string | null>;
}) {
    const [name, setName] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [adding, setAdding] = useState(false);
    const inputId = useId();
    async function add() {
        if (!name.trim() || adding) return;
        setAdding(true);
        const problem = await onAdd(name.trim()).catch(() => 'Could not add it (network problem).');
        setAdding(false);
        setError(problem);
        if (!problem) setName('');
    }
    return (
        <fieldset>
            <legend className="ui-label">{label}</legend>
            {terms.length === 0 ? (
                <p className="text-2xs text-muted">None yet.</p>
            ) : (
                <ul className="max-h-40 space-y-1 overflow-auto">
                    {terms.map((term) => (
                        <li key={term.id}>
                            <label className="flex items-center gap-2 text-ui">
                                <input
                                    type="checkbox"
                                    className="size-4 accent-[var(--ak-accent)]"
                                    checked={chosen.includes(term.id)}
                                    disabled={!canEdit}
                                    onChange={() => onToggle(term.id)}
                                />
                                {term.name}
                            </label>
                        </li>
                    ))}
                </ul>
            )}
            {canAdd && (
                <div className="mt-2 flex gap-1.5">
                    <label htmlFor={inputId} className="sr-only">
                        New {label.toLowerCase()}
                    </label>
                    <input
                        id={inputId}
                        className="ui-input h-7 min-w-0 flex-1 text-xs"
                        maxLength={100}
                        placeholder={`Add ${label.toLowerCase()}`}
                        value={name}
                        onChange={(e) => setName(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') {
                                e.preventDefault();
                                void add();
                            }
                        }}
                    />
                    <Button type="button" size="sm" icon="plus" busy={adding} disabled={!name.trim() || adding} onClick={() => void add()}>
                        Add
                    </Button>
                </div>
            )}
            {error && (
                <p role="alert" className="mt-1 text-2xs text-danger">
                    {error}
                </p>
            )}
        </fieldset>
    );
}
