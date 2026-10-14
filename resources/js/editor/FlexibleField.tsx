import { useState } from 'react';
import { Icon } from '@/Components/Icon';
import { Button, Field } from '@/Components/ui';
import type { ContentFieldDefinition } from '@/types';

/** One section an author added: its own id, which kind it is, and the values of that kind. */
type Block = Record<string, unknown> & { _id?: string; _layout?: string };

export interface LayoutInfo {
    key: string;
    label: string;
    fields: ContentFieldDefinition[];
    min?: number | null;
    max?: number | null;
}

const layoutsOf = (field: ContentFieldDefinition): LayoutInfo[] =>
    Array.isArray((field as { layouts?: unknown }).layouts) ? ((field as { layouts: LayoutInfo[] }).layouts ?? []) : [];

const blocksOf = (value: unknown): Block[] => (Array.isArray(value) ? (value.filter((b) => b && typeof b === 'object') as Block[]) : []);

/** "Quote — Design is how it works", from the first words the section holds. */
function describe(layout: LayoutInfo | undefined, block: Block): string {
    const name = layout?.label ?? 'Section of a removed kind';
    for (const child of layout?.fields ?? []) {
        const value = block[child.key];
        if (typeof value === 'string' && value.replace(/<[^>]*>/g, '').trim() !== '') {
            const text = value
                .replace(/<[^>]*>/g, '')
                .replace(/\s+/g, ' ')
                .trim();
            return `${name} — ${text.length > 60 ? `${text.slice(0, 60)}…` : text}`;
        }
    }
    return name;
}

/** A new id for a section, so a block is itself from the moment it is added. */
const newId = (): string =>
    typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : `s-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;

/**
 * A content-sections field: an ordered sequence of sections, each of a kind the field offers.
 *
 * Every section keeps its own id, so moving, duplicating and editing one talk about that
 * section rather than about "the fourth one" — which is what makes a reorder a reorder and not
 * a rewrite. Sections of a kind that has since been removed are kept and said so, because their
 * content is still the author's.
 */
export function FlexibleField({
    field,
    value,
    canEdit,
    onChange,
    renderChild,
}: {
    field: ContentFieldDefinition;
    value: unknown;
    canEdit: boolean;
    onChange(value: unknown): void;
    renderChild(child: ContentFieldDefinition, childValue: unknown, onChildChange: (next: unknown) => void): React.ReactNode;
}) {
    const layouts = layoutsOf(field);
    const blocks = blocksOf(value);
    const byKey = new Map(layouts.map((layout) => [layout.key, layout]));
    const [collapsed, setCollapsed] = useState<Record<string, boolean>>({});
    const [adding, setAdding] = useState(false);
    const addLabel = (field.settings.addLabel as string) || 'Add a section';
    const most = field.settings.maxRows as number | undefined;
    const full = most !== undefined && most !== null && blocks.length >= most;

    const give = (next: Block[]) => onChange(next.length === 0 ? null : next);
    const put = (id: string, values: Block) => give(blocks.map((block) => (block._id === id ? values : block)));
    const move = (index: number, by: number) => {
        const next = [...blocks];
        const to = index + by;
        if (to < 0 || to >= next.length) return;
        [next[index], next[to]] = [next[to]!, next[index]!];
        give(next);
    };
    const add = (layout: LayoutInfo) => {
        setAdding(false);
        give([...blocks, { _id: newId(), _layout: layout.key }]);
    };
    const duplicate = (index: number) => {
        const copy = { ...structuredClone(blocks[index]!), _id: newId() };
        give([...blocks.slice(0, index + 1), copy, ...blocks.slice(index + 1)]);
    };
    const countOf = (key: string) => blocks.filter((block) => block._layout === key).length;

    return (
        <Field label={field.label} hint={(field.settings.help as string) || undefined}>
            {() => (
                <div className="space-y-2" data-testid={`sections-${field.key}`}>
                    {blocks.map((block, index) => {
                        const id = String(block._id ?? index);
                        const layout = byKey.get(String(block._layout ?? ''));
                        const shut = collapsed[id] ?? blocks.length > 2;
                        return (
                            <div key={id} className="rounded border border-line" data-testid={`section-${id}`}>
                                <div className="flex items-center gap-1.5 bg-surface px-2 py-1.5">
                                    <button
                                        type="button"
                                        className="flex min-w-0 flex-1 items-center gap-1.5 text-left text-ui"
                                        aria-expanded={!shut}
                                        onClick={() => setCollapsed((c) => ({ ...c, [id]: !shut }))}
                                    >
                                        <Icon name="chevronRight" className={`size-3.5 shrink-0 transition-transform ${shut ? '' : 'rotate-90'}`} aria-hidden />
                                        <span className="truncate">{describe(layout, block)}</span>
                                    </button>
                                    {canEdit && (
                                        <>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                icon="chevronUp"
                                                aria-label={`Move section ${index + 1} up`}
                                                disabled={index === 0}
                                                onClick={() => move(index, -1)}
                                            >
                                                <span className="sr-only">Move up</span>
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                icon="chevronDown"
                                                aria-label={`Move section ${index + 1} down`}
                                                disabled={index === blocks.length - 1}
                                                onClick={() => move(index, 1)}
                                            >
                                                <span className="sr-only">Move down</span>
                                            </Button>
                                            {layout && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    icon="copy"
                                                    aria-label={`Duplicate section ${index + 1}`}
                                                    disabled={layout.max != null && countOf(layout.key) >= layout.max}
                                                    onClick={() => duplicate(index)}
                                                >
                                                    <span className="sr-only">Duplicate</span>
                                                </Button>
                                            )}
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                icon="trash"
                                                aria-label={`Remove section ${index + 1}`}
                                                onClick={() => give(blocks.filter((_, i) => i !== index))}
                                            >
                                                <span className="sr-only">Remove</span>
                                            </Button>
                                        </>
                                    )}
                                </div>
                                {!shut && (
                                    <div className="space-y-3 border-t border-line px-2 py-2">
                                        {layout ? (
                                            layout.fields.map((child) =>
                                                renderChild(child, block[child.key], (next) =>
                                                    put(id, { ...block, [child.key]: next === '' || next === undefined ? null : next }),
                                                ),
                                            )
                                        ) : (
                                            <p className="t-meta">
                                                This section is of a kind that was removed from this content type (“{String(block._layout ?? '')}”). Its content
                                                is kept; remove the section or add that kind back to edit it.
                                            </p>
                                        )}
                                    </div>
                                )}
                            </div>
                        );
                    })}

                    {canEdit && !full && layouts.length > 0 && (
                        <div className="relative">
                            {adding ? (
                                <ul className="rounded border border-line bg-surface p-1" role="listbox" aria-label="Kinds of section">
                                    {layouts.map((layout) => {
                                        const atMost = layout.max != null && countOf(layout.key) >= layout.max;
                                        return (
                                            <li key={layout.key}>
                                                <button
                                                    type="button"
                                                    role="option"
                                                    aria-selected={false}
                                                    disabled={atMost}
                                                    className="block w-full rounded px-2 py-1.5 text-left text-ui hover:bg-hover disabled:opacity-40"
                                                    onClick={() => add(layout)}
                                                >
                                                    {layout.label}
                                                    {atMost && <span className="t-meta ml-1.5">already at its limit</span>}
                                                </button>
                                            </li>
                                        );
                                    })}
                                    <li>
                                        <button
                                            type="button"
                                            className="block w-full rounded px-2 py-1.5 text-left text-2xs text-muted hover:bg-hover"
                                            onClick={() => setAdding(false)}
                                        >
                                            Cancel
                                        </button>
                                    </li>
                                </ul>
                            ) : (
                                <Button size="sm" icon="plus" onClick={() => setAdding(true)}>
                                    {addLabel}
                                </Button>
                            )}
                        </div>
                    )}
                </div>
            )}
        </Field>
    );
}
