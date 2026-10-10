// Dragging blocks: one lifecycle for the canvas, Layers and the palette, in both editors.
// These drags are quick, like a person's: the pointer is released as soon as it arrives,
// without waiting for any indicator. Every test checks the actual document order, that a
// drop is one undo step, and that cancelled or refused drags change nothing and send no
// save or publish request.
import { expect, test, type FrameLocator, type Locator, type Page } from '@playwright/test';
import { AUTH_STATE, E2E_HOST, PORT } from './env';
import { db } from './support';

type Spec = Record<string, unknown>;
let counter = 0;
const nid = (prefix: string) => `${prefix}${String(++counter).padStart(10 - prefix.length, '0')}`;
const text = (label: string, height = 100): Spec => ({
    type: 'text',
    version: 2,
    props: { text: label, element: 'p', style: { root: { base: { height: `${height}px` } } } },
});
const button = (label: string): Spec => ({
    type: 'button',
    version: 2,
    props: { label, href: '/', variant: 'secondary', size: 'medium', newTab: false, style: {} },
});
const image = (): Spec => ({ type: 'image', version: 3, props: { image: null, caption: 'Picture', loading: 'auto', style: {} } });
const group = (children: Spec[], style: Spec = {}): Spec => ({ type: 'group', version: 1, props: { element: 'div', style }, children });
const section = (children: Spec[]): Spec => ({ type: 'section', version: 1, props: { element: 'section', contentWidth: 'default', style: {} }, children });
const columns = (cols: Spec[][]): Spec => ({
    type: 'columns',
    version: 2,
    props: { style: { root: { mobile: { columns: '1' } } } },
    children: cols.map((c) => ({ type: 'column', version: 2, props: { style: {} }, children: c })),
});

/** A document from a tree of specs (the page's top-level blocks). */
function documentOf(blocks: Spec[]) {
    const nodes: Record<string, Spec> = {};
    const add = (spec: Spec): string => {
        const id = nid(String(spec.type).slice(0, 3));
        const children = (spec.children as Spec[] | undefined)?.map(add);
        nodes[id] = { id, type: spec.type, version: spec.version, props: spec.props, ...(children ? { children } : {}) };
        return id;
    };
    const root = nid('root');
    nodes[root] = { id: root, type: 'page', version: 3, props: {}, children: blocks.map(add) };
    return { schemaVersion: 1, root, nodes, seo: {} };
}

async function siteId() {
    return (await db.query<{ site_id: string }>('select site_id from site_domains where hostname = $1', [`${E2E_HOST}:${PORT}`])).rows[0]!.site_id;
}

/** Opens the page editor on a fresh page made of `blocks`; counts save and publish requests. */
async function openPage(page: Page, blocks: Spec[], viewport = { width: 1440, height: 900 }) {
    const id = crypto.randomUUID();
    const site = await siteId();
    await db.query('insert into pages (id, site_id, path, title) values ($1, $2, $3, $4)', [id, site, `/drag-${id.slice(0, 8)}`, 'Drag']);
    await db.query('insert into page_drafts (page_id, site_id, document, version) values ($1, $2, $3, 1)', [id, site, JSON.stringify(documentOf(blocks))]);
    const writes: string[] = [];
    page.on('request', (r) => {
        if (r.method() === 'POST' && /\/(save|publish)$/.test(new URL(r.url()).pathname)) writes.push(r.url());
    });
    await page.setViewportSize(viewport);
    await page.goto(`/admin/editor/${id}`);
    await expect(canvas(page).locator('main')).toBeVisible();
    return { id, writes };
}

const canvas = (page: Page): FrameLocator => page.frameLocator('[data-testid="canvas"]');
const texts = (page: Page, scope = 'main') => canvas(page).locator(`${scope} .ak-text2`).allTextContents();
const undo = (page: Page) => page.getByRole('button', { name: 'Undo' });
const dragStatus = (page: Page) => page.getByTestId('drag-status');

/** Selects a block on the canvas by its text, so its Move handle shows. */
async function select(page: Page, label: string) {
    await canvas(page).locator('.ak-text2, :is(.ak-btn2,.ak-btn3)', { hasText: label }).first().click();
    await expect(page.getByTestId('canvas-drag-handle')).toBeVisible();
    await page.keyboard.press('Escape'); // leave inline text editing
}

/** Where a canvas element is, in page coordinates (the iframe's offset included). */
async function box(locator: Locator) {
    const b = await locator.boundingBox();
    if (!b) throw new Error('not visible');
    return b;
}

/** Press, move in a few steps and release at once: no pause, no waiting for feedback. */
async function quickDrag(page: Page, from: { x: number; y: number }, to: { x: number; y: number }, steps = 4) {
    await page.mouse.move(from.x, from.y);
    await page.mouse.down();
    await page.mouse.move(to.x, to.y, { steps });
    await page.mouse.up();
}

async function handleCenter(page: Page) {
    const b = await box(page.getByTestId('canvas-drag-handle'));
    return { x: b.x + b.width / 2, y: b.y + b.height / 2 };
}

/** Delays the canvas's geometry and scroll messages to the editor (out of order when `reverse`). */
async function slowBridge(page: Page) {
    await page.addInitScript(() => {
        if (window !== window.top) return;
        const w = window as unknown as { __delay: number; __reverse: boolean };
        w.__delay = 0;
        w.__reverse = false;
        let n = 0;
        const add = window.addEventListener.bind(window);
        window.addEventListener = ((type: string, listener: EventListenerOrEventListenerObject, options?: AddEventListenerOptions) => {
            if (type !== 'message' || typeof listener !== 'function') return add(type, listener, options);
            return add(
                type,
                (event: Event) => {
                    const data = (event as MessageEvent).data;
                    if (w.__delay && data?.source === 'arkon-canvas' && (data.type === 'geometry' || data.type === 'scrolled')) {
                        // Later messages overtake earlier ones when reversed.
                        const delay = w.__reverse ? Math.max(0, w.__delay - 120 * n++) : w.__delay;
                        setTimeout(() => listener.call(window, event), delay);
                    } else listener.call(window, event);
                },
                options,
            );
        }) as typeof window.addEventListener;
    });
}

test.describe('canvas', () => {
    test('a quick release lands where it is released, even when the canvas answers late and out of order', async ({ page }) => {
        await slowBridge(page);
        const { writes } = await openPage(page, [text('Block 1'), text('Block 2'), text('Block 3'), text('Block 4')]);
        await select(page, 'Block 1');
        const third = await box(canvas(page).locator('.ak-text2', { hasText: 'Block 3' }));
        const fourth = await box(canvas(page).locator('.ak-text2', { hasText: 'Block 4' }));
        await page.evaluate(() => {
            (window as unknown as { __delay: number; __reverse: boolean }).__delay = 400;
            (window as unknown as { __reverse: boolean }).__reverse = true;
        });
        // Over Block 3's lower half, then Block 4's lower half, released immediately.
        await page.mouse.move(...(Object.values(await handleCenter(page)) as [number, number]));
        await page.mouse.down();
        await page.mouse.move(third.x + 30, third.y + third.height - 8, { steps: 3 });
        await page.mouse.move(fourth.x + 30, fourth.y + fourth.height - 8, { steps: 2 });
        await page.mouse.up();
        await expect.poll(() => texts(page)).toEqual(['Block 2', 'Block 3', 'Block 4', 'Block 1']);
        await expect(dragStatus(page)).toHaveCount(0);
        // One operation: one undo restores the original order, and nothing more is undoable.
        await undo(page).click();
        await expect.poll(() => texts(page)).toEqual(['Block 1', 'Block 2', 'Block 3', 'Block 4']);
        await expect(undo(page)).toBeDisabled();
        expect(writes).toEqual([]);
    });

    test('holding the pointer still at the bottom edge keeps scrolling, and the drop follows the scrolled content', async ({ page }) => {
        const blocks = Array.from({ length: 20 }, (_, i) => text(`Block ${i + 1}`));
        await openPage(page, blocks);
        await select(page, 'Block 1');
        const frame = await box(page.getByTestId('canvas'));
        const scrollY = () =>
            canvas(page)
                .locator('body')
                .evaluate(() => window.scrollY);
        await page.mouse.move(...(Object.values(await handleCenter(page)) as [number, number]));
        await page.mouse.down();
        await page.mouse.move(frame.x + frame.width / 2, frame.y + frame.height - 10, { steps: 6 });
        await expect.poll(scrollY).toBeGreaterThan(0);
        const first = await scrollY();
        await page.waitForTimeout(800);
        const later = await scrollY();
        expect(later).toBeGreaterThan(first + 200);
        // Scrolled to the end without moving the pointer: the destination is the end of the page.
        await expect.poll(scrollY, { timeout: 15_000 }).toBeGreaterThanOrEqual(
            await canvas(page)
                .locator('body')
                .evaluate(() => document.documentElement.scrollHeight - innerHeight - 1),
        );
        await expect(dragStatus(page)).toContainText('After Text: Block 20');
        await page.mouse.up();
        await expect.poll(async () => (await texts(page)).at(-1)).toBe('Block 1');
        // Scrolling stops when the drag ends.
        const stopped = await scrollY();
        await canvas(page)
            .locator('body')
            .evaluate(() => window.scrollTo(0, 0));
        await page.waitForTimeout(300);
        expect(await scrollY()).toBe(0);
        expect(stopped).toBeGreaterThan(0);
    });

    test('gaps and padding are slots between neighbours; first, last and empty containers; nested reparenting', async ({ page }) => {
        await openPage(page, [section([text('Alpha', 60), text('Beta', 60)]), group([]), text('Tail', 60)]);
        await select(page, 'Tail');
        // Into the gap between Alpha and Beta (inside the section).
        const alpha = await box(canvas(page).locator('.ak-text2', { hasText: 'Alpha' }));
        const beta = await box(canvas(page).locator('.ak-text2', { hasText: 'Beta' }));
        await quickDrag(page, await handleCenter(page), { x: alpha.x + 40, y: (alpha.y + alpha.height + beta.y) / 2 });
        await expect.poll(() => texts(page, 'section')).toEqual(['Alpha', 'Tail', 'Beta']);
        // Into the section's top padding: first in the section.
        await select(page, 'Beta');
        const sectionBox = await box(canvas(page).locator('section.ak-section'));
        const alphaNow = await box(canvas(page).locator('.ak-text2', { hasText: 'Alpha' }));
        await quickDrag(page, await handleCenter(page), { x: sectionBox.x + sectionBox.width / 2, y: (sectionBox.y + 16 + alphaNow.y) / 2 });
        await expect.poll(() => texts(page, 'section')).toEqual(['Beta', 'Alpha', 'Tail']);
        // Into the empty group (anywhere inside it): reparented out of the section.
        await select(page, 'Alpha');
        const empty = await box(canvas(page).locator('.ak-group'));
        await quickDrag(page, await handleCenter(page), { x: empty.x + empty.width / 2, y: empty.y + empty.height / 2 });
        await expect.poll(() => texts(page, '.ak-group')).toEqual(['Alpha']);
        await expect.poll(() => texts(page, 'section')).toEqual(['Beta', 'Tail']);
        // Three drops, three undo steps.
        for (let i = 0; i < 3; i++) await undo(page).click();
        await expect.poll(() => texts(page, 'section')).toEqual(['Alpha', 'Beta']);
        await expect(undo(page)).toBeDisabled();
    });

    test('side-by-side and reversed layouts map positions to document order', async ({ page }) => {
        // row-reverse: A is drawn on the right, C on the left.
        await openPage(page, [group([text('A', 80), text('B', 80), text('C', 80)], { root: { base: { direction: 'row-reverse' } } }), text('Mover', 60)]);
        await select(page, 'Mover');
        const c = await box(canvas(page).locator('.ak-text2', { hasText: 'C' }).first());
        const b = await box(canvas(page).locator('.ak-text2', { hasText: /^B$/ }));
        // Visually between C (left) and B (middle) = between B and C in document order.
        await quickDrag(page, await handleCenter(page), { x: (c.x + c.width + b.x) / 2, y: b.y + b.height / 2 });
        await expect.poll(() => texts(page, '.ak-group')).toEqual(['A', 'B', 'Mover', 'C']);
        // And it is drawn exactly there: between C and B.
        const xs = await canvas(page)
            .locator('.ak-group > .ak-text2')
            .evaluateAll((els) =>
                els
                    .map((el) => [el.textContent, Math.round(el.getBoundingClientRect().left)] as const)
                    .sort((p, q) => p[1] - q[1])
                    .map(([t]) => t),
            );
        expect(xs).toEqual(['C', 'Mover', 'B', 'A']);
    });

    test('an image block moves between columns; a button goes between two text blocks', async ({ page }) => {
        await openPage(page, [
            columns([
                [text('Left text', 60), image()],
                [text('Right one', 60), text('Right two', 60)],
            ]),
        ]);
        await canvas(page).locator('.ak-img2').click();
        await expect(page.getByTestId('canvas-drag-handle')).toBeVisible();
        await expect(page.getByTestId('canvas-drag-handle')).toHaveAccessibleName('Move Image block');
        const one = await box(canvas(page).locator('.ak-text2', { hasText: 'Right one' }));
        const two = await box(canvas(page).locator('.ak-text2', { hasText: 'Right two' }));
        await quickDrag(page, await handleCenter(page), { x: one.x + 30, y: (one.y + one.height + two.y) / 2 });
        await expect
            .poll(() =>
                canvas(page)
                    .locator('.ak-col')
                    .nth(1)
                    .locator(':scope > *')
                    .evaluateAll((els) => els.map((e) => e.className.split(' ')[0])),
            )
            .toEqual(['ak-text2', 'ak-img2', 'ak-text2']);
        // A new button from the palette, into the left column after its text (the text's lower half).
        await page.getByRole('tab', { name: 'Layers' }).click();
        const palette = await box(page.getByRole('button', { name: 'Add Button' }));
        const left = await box(canvas(page).locator('.ak-text2', { hasText: 'Left text' }));
        await quickDrag(page, { x: palette.x + palette.width / 2, y: palette.y + palette.height / 2 }, { x: left.x + 30, y: left.y + left.height - 6 }, 8);
        await expect
            .poll(() =>
                canvas(page)
                    .locator('.ak-col')
                    .first()
                    .locator(':scope > *')
                    .evaluateAll((els) => els.map((e) => e.className.split(' ')[0])),
            )
            .toEqual(['ak-text2', 'ak-action2']);
    });

    test('invalid destinations are explained and change nothing', async ({ page }) => {
        const { writes } = await openPage(page, [text('Top', 60), columns([[text('In column', 60)], [text('Other', 60)]])]);
        await page.getByRole('tab', { name: 'Layers' }).click();
        const hero = await box(page.getByRole('button', { name: 'Add Hero' }));
        const inColumn = await box(canvas(page).locator('.ak-text2', { hasText: 'In column' }));
        await page.mouse.move(hero.x + hero.width / 2, hero.y + hero.height / 2);
        await page.mouse.down();
        // A hero over the middle of a column can't go in the column: the status names the place it
        // can go instead (beside the Columns block), and that is where it lands.
        await page.mouse.move(inColumn.x + 20, inColumn.y + inColumn.height * 0.2, { steps: 6 });
        await expect(dragStatus(page)).toContainText('After Text: Top');
        await page.mouse.up();
        await expect(canvas(page).locator('.ak-col .ak-hero3')).toHaveCount(0);
        await expect(canvas(page).locator('main > .ak-hero3')).toHaveCount(1);
        await undo(page).click();
        await expect(canvas(page).locator('.ak-hero3')).toHaveCount(0);
        // Truly refused: the Columns row dropped into its own column (in Layers). Explained; nothing changes.
        const columnsRow = await box(page.locator('[data-testid="layer"][data-node-type="columns"]'));
        const columnRow = await box(page.locator('[data-testid="layer"][data-node-type="column"]').first());
        await page.mouse.move(columnsRow.x + 40, columnsRow.y + columnsRow.height / 2);
        await page.mouse.down();
        await page.mouse.move(columnRow.x + 60, columnRow.y + columnRow.height / 2, { steps: 6 });
        await expect(dragStatus(page)).toContainText('Not allowed here: A block can’t go inside itself');
        await expect(page.getByTestId('layers-drop-indicator')).toHaveAttribute('data-result', 'invalid');
        await page.mouse.up();
        await expect(undo(page)).toBeDisabled();
        await expect.poll(() => texts(page)).toEqual(['Top', 'In column', 'Other']);
        // Over the editor's toolbar (no drop zone): nothing happens.
        await page.getByRole('tab', { name: 'Properties' }).click();
        const top = await box(canvas(page).locator('.ak-text2', { hasText: 'Top' }));
        await select(page, 'Top');
        await quickDrag(page, await handleCenter(page), { x: top.x + 30, y: 10 });
        await expect.poll(() => texts(page)).toEqual(['Top', 'In column', 'Other']);
        await expect(undo(page)).toBeDisabled();
        expect(writes).toEqual([]);
    });
});

test.describe('ending a drag without dropping', () => {
    test('Escape, window blur and leaving the window cancel; late messages from a cancelled drag are ignored', async ({ page }) => {
        await slowBridge(page);
        const { writes } = await openPage(page, [text('One'), text('Two'), text('Three')]);
        await select(page, 'One');
        const three = await box(canvas(page).locator('.ak-text2', { hasText: 'Three' }));
        const handle = await handleCenter(page);
        // Escape.
        await page.mouse.move(handle.x, handle.y);
        await page.mouse.down();
        await page.mouse.move(three.x + 20, three.y + three.height - 5, { steps: 5 });
        await expect(dragStatus(page)).toContainText('After Text: Three');
        await page.keyboard.press('Escape');
        await expect(dragStatus(page)).toHaveCount(0);
        await page.mouse.up();
        // Window blur (e.g. switching applications) while delayed geometry is still on its way.
        await page.evaluate(() => ((window as unknown as { __delay: number }).__delay = 300));
        await page.mouse.move(handle.x, handle.y);
        await page.mouse.down();
        await page.mouse.move(three.x + 20, three.y + three.height - 5, { steps: 5 });
        await page.evaluate(() => window.dispatchEvent(new Event('blur')));
        await expect(dragStatus(page)).toHaveCount(0);
        await page.waitForTimeout(500); // the delayed replies arrive now, for a drag that has ended
        await page.mouse.up();
        await expect(dragStatus(page)).toHaveCount(0);
        await page.evaluate(() => ((window as unknown as { __delay: number }).__delay = 0));
        // Released outside the window.
        await page.mouse.move(handle.x, handle.y);
        await page.mouse.down();
        await page.mouse.move(handle.x, -40, { steps: 5 });
        await page.mouse.up();
        // Nothing moved, nothing to undo, nothing saved; no overlay left behind.
        await expect.poll(() => texts(page)).toEqual(['One', 'Two', 'Three']);
        await expect(undo(page)).toBeDisabled();
        await expect(page.getByTestId('drag-preview')).toHaveCount(0);
        await expect(page.getByTestId('canvas')).not.toHaveClass(/pointer-events-none/);
        expect(writes).toEqual([]);
        // A click on the handle without moving is a click, not a drag.
        await page.getByTestId('canvas-drag-handle').click();
        await expect(dragStatus(page)).toHaveCount(0);
        await expect.poll(() => texts(page)).toEqual(['One', 'Two', 'Three']);
    });

    test('a document change during the drag (undo) and a lock (stale save) cancel instead of applying a stale placement', async ({ page }) => {
        const { writes } = await openPage(page, [text('First'), text('Second'), text('Third')]);
        // An earlier edit, so there is something to undo during the drag.
        await page.getByRole('tab', { name: 'Layers' }).click();
        await page.getByRole('button', { name: 'Add Text' }).click();
        await expect(canvas(page).locator('.ak-text2')).toHaveCount(4);
        await page.getByRole('tab', { name: 'Properties' }).click();
        await select(page, 'First');
        // Keyboard focus in the editor (not the canvas), as after using any editor control.
        await page.getByTestId('page-title').click();
        const third = await box(canvas(page).locator('.ak-text2', { hasText: 'Third' }));
        await page.mouse.move(...(Object.values(await handleCenter(page)) as [number, number]));
        await page.mouse.down();
        await page.mouse.move(third.x + 20, third.y + third.height - 5, { steps: 5 });
        await page.keyboard.press('ControlOrMeta+Z'); // the added text disappears mid-drag
        await page.mouse.up();
        await expect(page.getByTestId('notice')).toContainText('changed while you were dragging');
        await expect.poll(() => texts(page)).toEqual(['First', 'Second', 'Third']);
        // The page becomes locked (a save finds it changed elsewhere) while dragging: the drag ends, nothing moves.
        await page.getByRole('button', { name: 'Redo' }).click();
        await expect(canvas(page).locator('.ak-text2')).toHaveCount(4);
        await page.route('**/admin/api/pages/*/save', (route) =>
            route.fulfill({
                status: 409,
                contentType: 'application/json',
                body: JSON.stringify({ ok: false, code: 'STALE_VERSION', message: 'stale', currentVersion: 9 }),
            }),
        );
        await select(page, 'First');
        await page.getByTestId('page-title').click();
        await page.mouse.move(...(Object.values(await handleCenter(page)) as [number, number]));
        await page.mouse.down();
        await page.mouse.move(third.x + 20, third.y + third.height - 5, { steps: 5 });
        await expect(dragStatus(page)).toBeVisible();
        await page.keyboard.press('ControlOrMeta+S');
        await expect(page.getByTestId('save-status')).toHaveText('Out of date');
        await expect(dragStatus(page)).toHaveCount(0);
        await page.mouse.up();
        expect((await texts(page))[0]).toBe('First');
        expect(writes.filter((w) => w.endsWith('/publish'))).toEqual([]);
    });
});

test.describe('Layers', () => {
    test('rows drag onto the canvas and within the outline; the outline scrolls while holding near its edge', async ({ page }) => {
        const blocks = Array.from({ length: 40 }, (_, i) => text(`Row ${i + 1}`, 40));
        const { writes } = await openPage(page, blocks, { width: 1440, height: 700 });
        await page.getByRole('tab', { name: 'Layers' }).click();
        const rows = page.getByTestId('layer');
        // Row 1 dragged onto the canvas, between Row 2 and Row 3.
        const row1 = await box(rows.first());
        const r2 = await box(canvas(page).locator('.ak-text2', { hasText: /^Row 2$/ }));
        const r3 = await box(canvas(page).locator('.ak-text2', { hasText: /^Row 3$/ }));
        await quickDrag(page, { x: row1.x + 40, y: row1.y + row1.height / 2 }, { x: r2.x + 30, y: (r2.y + r2.height + r3.y) / 2 }, 8);
        await expect.poll(async () => (await texts(page)).slice(0, 3)).toEqual(['Row 2', 'Row 1', 'Row 3']);
        // Within the outline: hold Row 2 near the bottom of the sidebar; it scrolls on its own.
        const scroller = page.getByTestId('sidebar-body');
        const area = await box(scroller);
        const row2 = await box(rows.first());
        await page.mouse.move(row2.x + 40, row2.y + row2.height / 2);
        await page.mouse.down();
        await page.mouse.move(row2.x + 40, area.y + area.height - 8, { steps: 6 });
        const scrollTop = () => scroller.evaluate((el) => el.scrollTop);
        await expect.poll(scrollTop).toBeGreaterThan(0);
        const first = await scrollTop();
        await page.waitForTimeout(600);
        expect(await scrollTop()).toBeGreaterThan(first + 100);
        await page.keyboard.press('Escape');
        await page.mouse.up();
        await expect.poll(async () => (await texts(page)).slice(0, 3)).toEqual(['Row 2', 'Row 1', 'Row 3']);
        await undo(page).click();
        await expect.poll(async () => (await texts(page)).slice(0, 3)).toEqual(['Row 1', 'Row 2', 'Row 3']);
        expect(writes).toEqual([]);
    });
});

test('the reusable-component editor uses the same dragging', async ({ page }) => {
    const site = await siteId();
    const id = crypto.randomUUID();
    const doc = documentOf([text('Banner title', 60), button('Shop now'), text('Fine print', 40)]);
    const fragment = { ...doc, nodes: { ...doc.nodes, [doc.root]: { ...doc.nodes[doc.root], type: 'fragment', version: 1 } } };
    await db.query('insert into reusable_components (id, site_id, name, draft) values ($1, $2, $3, $4)', [id, site, 'Drag banner', JSON.stringify(fragment)]);
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto(`/admin/components/${id}`);
    await canvas(page).locator(':is(.ak-btn2,.ak-btn3)').click();
    await expect(page.getByTestId('canvas-drag-handle')).toHaveAccessibleName('Move Button block');
    const fine = await box(canvas(page).locator('.ak-text2', { hasText: 'Fine print' }));
    await quickDrag(page, await handleCenter(page), { x: fine.x + 20, y: fine.y + fine.height + 6 });
    await expect
        .poll(() =>
            canvas(page)
                .locator('main > *, body > * > *')
                .evaluateAll((els) => els.map((e) => e.textContent?.trim()).filter(Boolean)),
        )
        .toContain('Shop now');
    await expect.poll(() => canvas(page).locator('.ak-text2, :is(.ak-btn2,.ak-btn3)').allTextContents()).toEqual(['Banner title', 'Fine print', 'Shop now']);
    await page.getByRole('button', { name: 'Undo' }).click();
    await expect.poll(() => canvas(page).locator('.ak-text2, :is(.ak-btn2,.ak-btn3)').allTextContents()).toEqual(['Banner title', 'Shop now', 'Fine print']);
});

test('touch: the Move handle drags with a finger', async ({ browser }) => {
    const context = await browser.newContext({ storageState: AUTH_STATE, hasTouch: true, viewport: { width: 1280, height: 900 } });
    const page = await context.newPage();
    await openPage(page, [text('Tap one'), text('Tap two'), text('Tap three')], { width: 1280, height: 900 });
    await select(page, 'Tap one');
    const handle = await handleCenter(page);
    const target = await box(canvas(page).locator('.ak-text2', { hasText: 'Tap three' }));
    const client = await context.newCDPSession(page);
    const touch = (type: string, x: number, y: number) =>
        client.send('Input.dispatchTouchEvent', { type, touchPoints: type === 'touchEnd' ? [] : [{ x, y }] } as Parameters<
            typeof client.send<'Input.dispatchTouchEvent'>
        >[1]);
    await touch('touchStart', handle.x, handle.y);
    for (let i = 1; i <= 8; i++)
        await touch('touchMove', handle.x + ((target.x + 20 - handle.x) * i) / 8, handle.y + ((target.y + target.height - 6 - handle.y) * i) / 8);
    await touch('touchEnd', 0, 0);
    await expect.poll(() => texts(page)).toEqual(['Tap two', 'Tap three', 'Tap one']);
    await context.close();
});

test('canvas preview shows the actual component and cancels without writes', async ({ page }) => {
    const { writes } = await openPage(page, [text('Actual component preview'), text('Destination')]);
    await select(page, 'Actual component preview');
    const start = await handleCenter(page);
    await page.mouse.move(start.x, start.y);
    await page.mouse.down();
    await page.mouse.move(start.x + 80, start.y + 100, { steps: 5 });
    const preview = page.frameLocator('iframe[title="Dragged component preview"]');
    await expect(preview.locator('body')).toContainText('Actual component preview');
    await expect(page.locator('iframe[title="Dragged component preview"]')).toHaveAttribute('sandbox', '');
    await page.screenshot({
        path: 'C:/Users/jacob/Documents/Codex/2026-10-06/referenced-chatgpt-conversation-this-is-an/outputs/page-builder-drag-active.png',
    });
    await page.keyboard.press('Escape');
    await page.mouse.up();
    await expect(page.getByTestId('drag-preview')).toHaveCount(0);
    expect(await texts(page)).toEqual(['Actual component preview', 'Destination']);
    expect(writes).toEqual([]);
});
