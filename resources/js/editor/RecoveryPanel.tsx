import { useId, useState } from 'react';
import { currentDefinition } from '@/arkon/components/registry';
import { nodeLabel } from '@/arkon/editor/structure';
import { matches, message } from '@/arkon/rules';
import type { PageDocument } from '@/arkon/schema/document';
import type { PageOperation } from '@/arkon/schema/operations';
import type { RecoveryItem } from '@/types';
import { withStyleValue } from '@/arkon/style/edit';
import type { Breakpoint, Style } from '@/arkon/style/schema';
import { buttonClass } from '@/Components/ui';

type Choice = { action: 'correct'; value: string } | { action: 'reset' } | { action: 'remove' } | null;

const keyOf = (item: RecoveryItem) => `${item.nodeId}:${item.path}`;
/** Columns widths saved before "one width per column" was required (style.root.<screen>.columns). */
export const isWidthItem = (item: RecoveryItem) => item.path.startsWith('style.root.') && item.path.endsWith('.columns');
const SCREEN: Record<string, string> = { base: 'all screens', tablet: 'tablets', mobile: 'phones' };
const linkError = (value: string) => (value.length > 2000 ? message('tooLong', { max: 2000 }) : matches('link', value) ? null : message('unsafeLink'));

/**
 * The repair as document operations: corrections for kept blocks (a link corrected, or Columns
 * widths on a screen back to equal), then removals. Null until every item is resolved.
 */
export function repairOps(items: RecoveryItem[], choices: Record<string, Choice>, doc?: PageDocument): PageOperation[] | null {
    const removed = new Set(items.filter((item) => choices[keyOf(item)]?.action === 'remove').map((item) => item.nodeId));
    const ops: PageOperation[] = [];
    const resets = new Map<string, string[]>();
    for (const item of items) {
        if (removed.has(item.nodeId)) continue;
        const choice = choices[keyOf(item)];
        if (isWidthItem(item)) {
            if (choice?.action !== 'reset' || !doc?.nodes[item.nodeId]) return null;
            resets.set(item.nodeId, [...(resets.get(item.nodeId) ?? []), item.path.split('.')[2]!]);
            continue;
        }
        if (choice?.action !== 'correct' || linkError(choice.value) !== null) return null;
        ops.push({ op: 'updateProps', nodeId: item.nodeId, set: { [item.path]: choice.value } });
    }
    for (const [nodeId, screens] of resets) {
        let style = (doc!.nodes[nodeId]!.props.style as Style | undefined) ?? {};
        for (const screen of screens) style = withStyleValue(style, 'root', screen as Breakpoint, 'columns', null);
        ops.push({ op: 'updateProps', nodeId, set: { style } });
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
    const ops = repairOps(items, choices, document);
    const links = items.filter((item) => !isWidthItem(item)).length;
    const widths = items.length - links;
    const choose = (item: RecoveryItem, choice: Choice) => setChoices((current) => ({ ...current, [keyOf(item)]: choice }));

    return (
        <section aria-labelledby={headingId} className="space-y-4 p-4" data-testid="recovery">
            <div>
                <h2 id={headingId} className="t-title">
                    This draft needs repair
                </h2>
                <p className="mt-1 text-xs leading-snug text-muted">
                    {links > 0 && (
                        <>
                            {links === 1 ? 'A link was' : `${links} links were`} saved before links with backslashes were refused (browsers can send{' '}
                            <code>/\</code> to another website).{' '}
                        </>
                    )}
                    {widths > 0 &&
                        `${widths === 1 ? 'Columns widths were' : `${widths} Columns widths were`} saved before a Columns block needed one width per column. `}
                    Repair or remove each one to keep editing. Nothing changes until you apply the repair, and the live page and history stay as they are.
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
                    <button type="button" disabled={ops === null} onClick={() => ops && onApply(ops)} className={buttonClass('primary', 'md', 'w-full')}>
                        Apply repair
                    </button>
                    <p className="text-2xs text-muted">Then save the draft. Publishing works again once it is saved.</p>
                </div>
            ) : (
                <p className="text-xs text-muted">Only members who can edit this page can repair it.</p>
            )}
        </section>
    );
}

function RecoveryRow(props: { item: RecoveryItem; label: string; choice: Choice; canEdit: boolean; onShow(): void; onChoose(choice: Choice): void }) {
    const { item, label, choice, canEdit } = props;
    const ids = { name: useId(), input: useId(), error: useId() };
    const error = choice?.action === 'correct' ? linkError(choice.value) : null;
    return (
        <li className="space-y-2 rounded-md border border-changed/30 bg-changed-soft p-3 text-ui" data-testid="recovery-item" data-node-id={item.nodeId}>
            <div className="flex items-start justify-between gap-2">
                <p className="font-medium">{label}</p>
                <button type="button" onClick={props.onShow} className="shrink-0 text-xs font-medium text-accent hover:underline">
                    Show
                </button>
            </div>
            <p className="text-xs">
                {isWidthItem(item) ? `Stored widths (${SCREEN[item.path.split('.')[2]!] ?? item.path}): ` : 'Stored link: '}
                <code className="rounded bg-surface px-1 font-mono break-all" data-testid="stored-value">
                    {item.value}
                </code>
            </p>
            {isWidthItem(item) && <p className="text-xs text-muted">{item.message}</p>}
            <fieldset disabled={!canEdit} className="space-y-1 text-xs">
                <legend className="sr-only">Repair for {label}</legend>
                {isWidthItem(item) && (
                    <label className="flex items-center gap-2">
                        <input type="radio" name={ids.name} checked={choice?.action === 'reset'} onChange={() => props.onChoose({ action: 'reset' })} />
                        Equal widths on {SCREEN[item.path.split('.')[2]!] ?? 'this screen'} (the columns and their content stay)
                    </label>
                )}
                <label className={`flex items-center gap-2 ${isWidthItem(item) ? 'hidden' : ''}`}>
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
                            className="ui-input"
                        />
                        <p id={ids.error} className="mt-1 text-danger">
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
