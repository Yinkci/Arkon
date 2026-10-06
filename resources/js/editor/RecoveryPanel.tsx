import { useId, useState } from 'react';
import { currentDefinition } from '@/arkon/components/registry';
import { nodeLabel } from '@/arkon/editor/structure';
import { matches, message } from '@/arkon/rules';
import type { PageDocument } from '@/arkon/schema/document';
import type { PageOperation } from '@/arkon/schema/operations';
import type { RecoveryItem } from '@/types';

type Choice = { action: 'correct'; value: string } | { action: 'remove' } | null;

const keyOf = (item: RecoveryItem) => `${item.nodeId}:${item.path}`;
const linkError = (value: string) => (value.length > 2000 ? message('tooLong', { max: 2000 }) : matches('link', value) ? null : message('unsafeLink'));

/** The repair as document operations: corrections for kept blocks, then removals. Null until every item is resolved. */
export function repairOps(items: RecoveryItem[], choices: Record<string, Choice>): PageOperation[] | null {
    const removed = new Set(items.filter((item) => choices[keyOf(item)]?.action === 'remove').map((item) => item.nodeId));
    const ops: PageOperation[] = [];
    for (const item of items) {
        if (removed.has(item.nodeId)) continue;
        const choice = choices[keyOf(item)];
        if (choice?.action !== 'correct' || linkError(choice.value) !== null) return null;
        ops.push({ op: 'updateProps', nodeId: item.nodeId, set: { [item.path]: choice.value } });
    }
    return [...ops, ...[...removed].map((nodeId): PageOperation => ({ op: 'removeNode', nodeId }))];
}

export interface RecoveryPanelProps {
    items: RecoveryItem[];
    document: PageDocument;
    canEdit: boolean;
    onShow(nodeId: string): void;
    /** Applies the repair to the draft (unsaved, like any edit). */
    onApply(ops: PageOperation[]): void;
}

/**
 * A draft saved before a rule was tightened (links with backslashes). It is shown as stored,
 * but nothing else can change until each affected value is corrected or its block removed:
 * the stored values are listed exactly, and nothing is replaced automatically.
 */
export function RecoveryPanel({ items, document, canEdit, onShow, onApply }: RecoveryPanelProps) {
    const [choices, setChoices] = useState<Record<string, Choice>>({});
    const headingId = useId();
    const ops = repairOps(items, choices);
    const choose = (item: RecoveryItem, choice: Choice) => setChoices((current) => ({ ...current, [keyOf(item)]: choice }));

    return (
        <section aria-labelledby={headingId} className="space-y-4 p-4" data-testid="recovery">
            <div>
                <h2 id={headingId} className="text-sm font-semibold">
                    This draft needs repair
                </h2>
                <p className="mt-1 text-xs text-zinc-600">
                    {items.length === 1 ? 'A link was' : `${items.length} links were`} saved before links with backslashes were refused (browsers can send{' '}
                    <code>/\</code> to another website). Correct or remove each one to keep editing. Nothing changes until you apply the repair, and the live
                    page and history stay as they are.
                </p>
            </div>
            <ol className="space-y-3">
                {items.map((item) => (
                    <RecoveryRow
                        key={keyOf(item)}
                        item={item}
                        label={document.nodes[item.nodeId] ? nodeLabel(document.nodes[item.nodeId]!) : (currentDefinition(item.type)?.label ?? item.type)}
                        choice={choices[keyOf(item)] ?? null}
                        canEdit={canEdit}
                        onShow={() => onShow(item.nodeId)}
                        onChoose={(choice) => choose(item, choice)}
                    />
                ))}
            </ol>
            {canEdit ? (
                <div className="space-y-1">
                    <button
                        type="button"
                        disabled={ops === null}
                        onClick={() => ops && onApply(ops)}
                        className="w-full rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
                    >
                        Apply repair
                    </button>
                    <p className="text-xs text-zinc-500">Then save the draft. Publishing works again once it is saved.</p>
                </div>
            ) : (
                <p className="text-xs text-zinc-600">Only members who can edit this page can repair it.</p>
            )}
        </section>
    );
}

function RecoveryRow(props: { item: RecoveryItem; label: string; choice: Choice; canEdit: boolean; onShow(): void; onChoose(choice: Choice): void }) {
    const { item, label, choice, canEdit } = props;
    const ids = { name: useId(), input: useId(), error: useId() };
    const error = choice?.action === 'correct' ? linkError(choice.value) : null;
    return (
        <li className="space-y-2 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm" data-testid="recovery-item" data-node-id={item.nodeId}>
            <div className="flex items-start justify-between gap-2">
                <p className="font-medium">{label}</p>
                <button type="button" onClick={props.onShow} className="shrink-0 text-xs text-indigo-700 hover:underline">
                    Show
                </button>
            </div>
            <p className="text-xs">
                Stored link:{' '}
                <code className="rounded bg-white px-1 break-all" data-testid="stored-value">
                    {item.value}
                </code>
            </p>
            <fieldset disabled={!canEdit} className="space-y-1 text-xs">
                <legend className="sr-only">Repair for {label}</legend>
                <label className="flex items-center gap-2">
                    <input
                        type="radio"
                        name={ids.name}
                        checked={choice?.action === 'correct'}
                        onChange={() => props.onChoose({ action: 'correct', value: choice?.action === 'correct' ? choice.value : '' })}
                    />
                    Correct the link
                </label>
                {choice?.action === 'correct' && (
                    <div className="pl-5">
                        <label htmlFor={ids.input} className="sr-only">
                            New link for {label}
                        </label>
                        <input
                            id={ids.input}
                            value={choice.value}
                            placeholder="/contact or https://example.com"
                            aria-invalid={error !== null && choice.value !== ''}
                            aria-describedby={ids.error}
                            onChange={(e) => props.onChoose({ action: 'correct', value: e.target.value })}
                            className="w-full rounded-md border border-zinc-300 bg-white px-2 py-1 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
                        />
                        <p id={ids.error} className="mt-1 text-red-700">
                            {choice.value !== '' ? error : null}
                        </p>
                    </div>
                )}
                <label className="flex items-center gap-2">
                    <input type="radio" name={ids.name} checked={choice?.action === 'remove'} onChange={() => props.onChoose({ action: 'remove' })} />
                    Remove this block
                </label>
            </fieldset>
        </li>
    );
}
