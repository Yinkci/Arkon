import { useMemo, useState } from 'react';
import { createPattern, patterns } from '@/arkon/editor/patterns';
import { currentDefinition } from '@/arkon/components/registry';
import { addableTypes, createNodes, insertOps, insertionFor } from '@/arkon/editor/structure';
import { createColumns } from '@/arkon/editor/columns';
import type { NodeId, PageDocument } from '@/arkon/schema/document';
import type { PageOperation } from '@/arkon/schema/operations';
import type { ReusableComponentInfo } from '@/types';
import { Icon, type IconName } from '@/Components/Icon';
import { ColumnsPicker } from './ColumnsPicker';
import { useDragController } from './drag/DragProvider';

/** Where each block belongs in the picker. Anything not listed is a theme component and goes last. */
const GROUPS: { name: string; types: string[] }[] = [
    { name: 'Layout', types: ['section', 'group', 'columns'] },
    { name: 'Content', types: ['hero', 'text', 'button', 'collection', 'sections'] },
    { name: 'Media', types: ['image', 'icon', 'slider'] },
    { name: 'Forms', types: ['form'] },
    { name: 'Navigation', types: ['navigation', 'logo', 'back-to-top'] },
];

const ICON: Record<string, IconName> = {
    section: 'layers',
    group: 'grip',
    columns: 'columns',
    hero: 'home',
    text: 'text',
    button: 'link',
    image: 'image',
    icon: 'sparkle',
    slider: 'play',
    form: 'form',
    navigation: 'menu',
    logo: 'tag',
    'back-to-top': 'arrowUp',
    collection: 'grid',
    sections: 'list',
    'section-template': 'layers',
    instance: 'component',
};

const matches = (needle: string, ...text: string[]) => needle === '' || text.some((t) => t.toLowerCase().includes(needle));

/**
 * Everything that can be added to the page, in one place: starting layouts, the blocks by kind and the
 * site's published reusable components, with a search. It opens above the outline rather than replacing it,
 * so a block can be clicked to insert it or dragged onto the canvas or into the outline, using the editor's
 * shared drag controller (so placement rules and feedback are the same). It stays open until closed.
 */
export function BlockPicker({
    document: doc,
    selectedId,
    canEdit,
    components,
    onStructure,
    onClose,
}: {
    document: PageDocument;
    selectedId: NodeId | null;
    canEdit: boolean;
    components: ReusableComponentInfo[];
    onStructure(ops: PageOperation[], select?: NodeId | null): void;
    onClose(): void;
}) {
    const controller = useDragController();
    const [query, setQuery] = useState('');
    const [pickingColumns, setPickingColumns] = useState(false);
    const needle = query.trim().toLowerCase();
    const published = components.filter((c) => c.published !== null);

    const insert = (type: string, props: Record<string, unknown> = {}) => {
        const placement = insertionFor(doc, selectedId, type);
        if (!placement) return;
        const nodes = createNodes(type, props);
        onStructure(insertOps(placement, nodes), nodes[0]!.id);
    };

    const blocks = useMemo(() => {
        const addable = addableTypes();
        const named = addable.map((type) => ({ type, label: currentDefinition(type)?.label ?? type }));
        const known = new Set(GROUPS.flatMap((g) => g.types));
        return [
            ...GROUPS.map((g) => ({ name: g.name, items: named.filter((b) => g.types.includes(b.type)) })),
            { name: 'From your theme', items: named.filter((b) => !known.has(b.type) && b.type !== 'instance') },
        ].filter((g) => g.items.length > 0);
    }, []);

    const row = 'flex h-8 w-full items-center gap-2 rounded-md px-2 text-left text-xs text-fg hover:bg-hover disabled:opacity-40';
    const visiblePatterns = patterns.filter((p) => matches(needle, p.name, p.description));
    const visibleComponents = published.filter((c) => matches(needle, c.name));
    const groups = blocks.map((g) => ({ ...g, items: g.items.filter((b) => matches(needle, b.label, b.type)) })).filter((g) => g.items.length > 0);
    const empty = groups.length === 0 && visiblePatterns.length === 0 && visibleComponents.length === 0;

    return (
        <div className="flex max-h-[22rem] min-h-0 shrink-0 flex-col border-b border-line bg-raised" aria-label="Add a block" role="group">
            <div className="flex h-9 shrink-0 items-center gap-1 px-2">
                <h2 className="flex-1 pl-1 text-xs font-semibold">Add a block</h2>
                <button
                    type="button"
                    onClick={onClose}
                    aria-label="Close the block picker"
                    title="Close"
                    className="grid size-7 place-items-center rounded-md text-muted hover:bg-hover hover:text-fg"
                >
                    <Icon name="close" className="size-4" />
                </button>
            </div>
            <div className="shrink-0 p-2">
                <input
                    type="search"
                    className="ui-input"
                    autoFocus
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                    aria-label="Search blocks"
                    placeholder="Search blocks…"
                />
            </div>
            <div className="ak-scroll min-h-0 flex-1 overflow-y-auto px-2 pb-3">
                {empty && <p className="px-2 py-3 text-xs text-muted">Nothing matches “{query.trim()}”.</p>}

                {groups.map((group) => (
                    <section key={group.name} aria-label={group.name}>
                        <h3 className="px-2 pt-2 pb-1 t-eyebrow">{group.name}</h3>
                        {group.items.map(({ type, label }) => (
                            <button
                                key={type}
                                type="button"
                                disabled={!canEdit || insertionFor(doc, selectedId, type) === null}
                                data-testid={`add-${type}`}
                                aria-label={`Add ${label}`}
                                aria-expanded={type === 'columns' ? pickingColumns : undefined}
                                className={`${row} ${canEdit ? 'cursor-grab' : ''}`}
                                onPointerDown={(event) => {
                                    if (!canEdit || event.pointerType === 'touch') return; // touch: tap to add, so the list keeps scrolling
                                    controller.press({ type, label, origin: 'palette' }, event, event.currentTarget);
                                }}
                                onClick={() => {
                                    if (controller.consumeClick()) return; // the end of a drag, not a click
                                    // Columns: the layout is chosen first, so the columns are created with it.
                                    if (type === 'columns') setPickingColumns((open) => !open);
                                    else insert(type);
                                }}
                            >
                                <Icon name={ICON[type] ?? 'component'} className="size-3.5 text-faint" />
                                {label}
                            </button>
                        ))}
                        {group.name === 'Layout' && pickingColumns && (
                            <ColumnsPicker
                                disabled={!canEdit || insertionFor(doc, selectedId, 'columns') === null}
                                onCancel={() => setPickingColumns(false)}
                                onPick={(preset) => {
                                    const placement = insertionFor(doc, selectedId, 'columns');
                                    if (!placement) return;
                                    const nodes = createColumns(preset.count, preset.tracks);
                                    setPickingColumns(false);
                                    onStructure(insertOps(placement, nodes), nodes[0]!.id);
                                }}
                            />
                        )}
                    </section>
                ))}

                {visiblePatterns.length > 0 && (
                    <section aria-label="Starting layouts">
                        <h3 className="px-2 pt-2 pb-1 t-eyebrow">Starting layouts</h3>
                        {visiblePatterns.map((pattern) => (
                            <button
                                key={pattern.id}
                                type="button"
                                className={row}
                                title={pattern.description}
                                disabled={!canEdit || !insertionFor(doc, selectedId, pattern.template.type)}
                                onClick={() => {
                                    const placement = insertionFor(doc, selectedId, pattern.template.type);
                                    if (!placement) return;
                                    const nodes = createPattern(pattern.id, doc);
                                    onStructure(insertOps(placement, nodes), nodes[0]!.id);
                                }}
                            >
                                <Icon name="layers" className="size-3.5 text-faint" />
                                {pattern.name}
                            </button>
                        ))}
                    </section>
                )}

                {needle === '' && published.length === 0 ? null : (
                    <section aria-label="Reusable">
                        <h3 className="px-2 pt-2 pb-1 t-eyebrow">Reusable</h3>
                        {visibleComponents.map((component) => (
                            <button
                                key={component.id}
                                type="button"
                                className={row}
                                data-testid="add-instance"
                                disabled={!canEdit || insertionFor(doc, selectedId, 'instance') === null}
                                onClick={() => insert('instance', { componentId: component.id })}
                            >
                                <Icon name="component" className="size-3.5 text-faint" />
                                <span className="min-w-0 flex-1 truncate">{component.name}</span>
                            </button>
                        ))}
                        {visibleComponents.length === 0 && <p className="px-2 pb-1 text-2xs text-muted">No published components match.</p>}
                        <a href="/admin/design/components" className="ui-link block px-2 py-1 text-2xs">
                            Manage reusable components
                        </a>
                    </section>
                )}
            </div>
        </div>
    );
}
