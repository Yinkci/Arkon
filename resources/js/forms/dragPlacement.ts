import type { Resolution, Rect } from '@/arkon/editor/placement';
import type { DragSource } from '@/editor/drag/controller';

export type FormRowGeometry = { id: string; rect: Rect; cards: { id: string; rect: Rect }[] };
/** Stable content-space measurements: feedback transforms never become collision input. */
export function formDrop(
    rows: FormRowGeometry[],
    source: DragSource,
    x: number,
    y: number,
    previous: { parentId: string; index: number } | null,
    mobile = false,
): Resolution {
    const line = (index: number, top: number, row: FormRowGeometry): Resolution => ({
        kind: 'place',
        parentId: 'newrow:' + index,
        index: 0,
        placement: { parentId: 'newrow:' + index, index: 0 },
        label: 'New row ' + (index + 1),
        indicator: { kind: 'line', axis: 'y', rect: { ...row.rect, top, height: 3 } },
    });
    for (let n = 0; n < rows.length; n++) {
        const row = rows[n]!,
            r = row.rect,
            band = Math.min(24, r.height * 0.25);
        if (y < r.top + band + (previous?.parentId === 'newrow:' + n ? 6 : previous?.parentId === row.id ? -6 : 0)) return line(n, r.top - 8, row);
        if (y > r.top + r.height - band + (previous?.parentId === 'newrow:' + (n + 1) ? -6 : previous?.parentId === row.id ? 6 : 0)) continue;
        if (mobile) {
            // A stacked mobile preview must not silently create desktop columns.
            return line(y < r.top + r.height / 2 ? n : n + 1, y < r.top + r.height / 2 ? r.top - 8 : r.top + r.height + 8, row);
        }
        if (row.cards.length >= 4 && !row.cards.some((c) => c.id === source.nodeId)) return { kind: 'invalid', reason: 'A row can have up to four fields.' };
        let index = row.cards.filter((c) => x > c.rect.left + c.rect.width / 2).length;
        if (previous?.parentId === row.id && Math.abs(index - previous.index) === 1) {
            const boundary = row.cards[Math.min(index, previous.index)];
            if (boundary && Math.abs(x - (boundary.rect.left + boundary.rect.width / 2)) < 8) index = previous.index;
        }
        const before = row.cards[index],
            after = row.cards[index - 1];
        const left = before ? before.rect.left - 6 : after ? after.rect.left + after.rect.width + 6 : r.left;
        return {
            kind: 'place',
            parentId: row.id,
            index,
            placement: { parentId: row.id, index },
            label: 'Column ' + (index + 1) + ' in row ' + (n + 1),
            indicator: { kind: 'line', axis: 'x', rect: { left, top: r.top, width: 3, height: r.height } },
        };
    }
    const last = rows.at(-1);
    return last
        ? line(rows.length, last.rect.top + last.rect.height + 8, last)
        : {
              kind: 'place',
              parentId: 'newrow:0',
              index: 0,
              placement: { parentId: 'newrow:0', index: 0 },
              label: 'First row',
              indicator: { kind: 'inside', axis: 'y', rect: { left: 16, top: 16, width: 200, height: 100 } },
          };
}

export function scrollVelocity(y: number, top: number, bottom: number): number {
    const edge = 48;
    return y < top + edge
        ? -450 * Math.max(0, Math.min(1, (top + edge - y) / edge))
        : y > bottom - edge
          ? 450 * Math.max(0, Math.min(1, (y - bottom + edge) / edge))
          : 0;
}
