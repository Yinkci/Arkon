// Deleting the selection from the canvas (and the same behaviour from the toolbar, Layers and
// Delete/Backspace), and the Columns layout contract in the editor: one width per column, kept
// with its columns through deletes and moves, invalid custom widths refused before applying,
// older drafts that break it opened in recovery. Isolated e2e database only.
import { expect, test, type FrameLocator, type Page } from '@playwright/test';
import { E2E_HOST, PORT } from './env';
import { PNG_1X1 } from './fixtures';
import { db } from './support';

type Spec = Record<string, unknown>;
let counter = 0;
const nid = (prefix: string) => `${prefix}${String(++counter).padStart(10 - prefix.length, '0')}`;
const text = (label: string): Spec => ({ type: 'text', version: 3, props: { text: label, element: 'p', style: {} } });
const button = (label: string): Spec => ({
    type: 'button',
    version: 3,
    props: { label, href: '/contact', variant: 'primary', size: 'medium', newTab: false, style: {} },
});
const section = (children: Spec[]): Spec => ({ type: 'section', version: 2, props: { element: 'section', contentWidth: 'default', style: {} }, children });
const hero = (heading: string, children: Spec[] = []): Spec => ({
    type: 'hero',
    version: 4,
    props: { heading, headingLevel: 'h1', text: '', image: null, style: {} },
    children,
});
const columns = (
    cols: Spec[][],
    root: Spec = { base: { columns: ['1fr', '2fr', '1fr', '1fr', '1fr', '1fr'].slice(0, cols.length).join(' ') }, mobile: { columns: '1' } },
): Spec => ({
    type: 'columns',
    version: 3,
    props: { style: { root } },
    children: cols.map((c) => ({ type: 'column', version: 3, props: { style: {} }, children: c })),
});

function documentOf(blocks: Spec[], rootType = 'page', rootVersion = 3) {
    const nodes: Record<string, Spec> = {};
    const add = (spec: Spec): string => {
        const id = nid(String(spec.type).slice(0, 3));
        const children = (spec.children as Spec[] | undefined)?.map(add);
        nodes[id] = { id, type: spec.type, version: spec.version, props: spec.props, ...(children ? { children } : {}) };
        return id;
    };
    const root = nid('root');
    nodes[root] = { id: root, type: rootType, version: rootVersion, props: {}, children: blocks.map(add) };
    return { schemaVersion: 1, root, nodes, seo: {} };
}

async function siteId() {
    return (await db.query<{ site_id: string }>('select site_id from site_domains where hostname = $1', [`${E2E_HOST}:${PORT}`])).rows[0]!.site_id;
}

async function openPage(page: Page, blocks: Spec[] | ReturnType<typeof documentOf>) {
    const id = crypto.randomUUID();
    const site = await siteId();
    const document = Array.isArray(blocks) ? documentOf(blocks) : blocks;
    await db.query('insert into pages (id, site_id, path, title) values ($1, $2, $3, $4)', [id, site, `/delete-${id.slice(0, 8)}`, 'Delete']);
    await db.query('insert into page_drafts (page_id, site_id, document, version) values ($1, $2, $3, 1)', [id, site, JSON.stringify(document)]);
    const writes: string[] = [];
    page.on('request', (r) => {
        if (r.method() === 'POST' && /\/(save|publish)$/.test(new URL(r.url()).pathname)) writes.push(r.url());
    });
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto(`/admin/editor/${id}`);
    await expect(canvas(page).locator('main')).toBeVisible();
    return { id, writes };
}

type Doc = { root: string; nodes: Record<string, { id: string; type: string; props: Record<string, unknown>; children?: string[] }> };
const draft = async (id: string): Promise<Doc> =>
    (await db.query<{ document: Doc }>('select document from page_drafts where page_id = $1', [id])).rows[0]!.document;
const ofType = (doc: Doc, type: string) => Object.values(doc.nodes).filter((n) => n.type === type);
const canvas = (page: Page): FrameLocator => page.frameLocator('[data-testid="canvas"]');
const notice = (page: Page) => page.getByTestId('notice');
const undo = (page: Page) => page.locator('header').getByRole('button', { name: 'Undo', exact: true });
const redo = (page: Page) => page.locator('header').getByRole('button', { name: 'Redo', exact: true });
const layer = (page: Page, type: string) => page.locator(`[data-testid="layer"][data-node-type="${type}"]`);

async function saveDraft(page: Page) {
    await page.keyboard.press('ControlOrMeta+s');
    await expect(page.getByTestId('save-status')).toHaveText('Draft saved');
}

async function pick(page: Page, selector: string) {
    await canvas(page).locator(selector).first().click();
    await page.keyboard.press('Escape'); // leave inline text editing; the block stays selected
}

test('a button is deleted from the canvas without opening Layers: one undo step, redo, save and reload', async ({ page }) => {
    const { id, writes } = await openPage(page, [hero('Top', [button('Book now'), button('Call us')]), section([text('Below')])]);
    await pick(page, ':is(.ak-btn2,.ak-btn3) >> text=Book now');
    const remove = page.getByTestId('canvas-delete');
    await expect(remove).toHaveAccessibleName('Delete button');
    await expect(remove).toHaveAttribute('title', 'Delete button (Delete key)');
    await remove.click();
    await expect(canvas(page).locator(':is(.ak-btn2,.ak-btn3)')).toHaveText(['Call us']);
    await expect(notice(page)).toContainText('Deleted button. Nothing is saved or published');
    // The next button is selected; Layers was never needed.
    await expect(page.getByTestId('inspector-target')).toHaveText('Button');
    await expect(page.getByRole('tab', { name: 'Properties' })).toHaveAttribute('aria-selected', 'true');
    expect(writes).toEqual([]);

    // The notice's Undo is the same single step as Ctrl+Z; redo deletes it again.
    await page.getByTestId('notice-action').click();
    await expect(canvas(page).locator(':is(.ak-btn2,.ak-btn3)')).toHaveText(['Book now', 'Call us']);
    await redo(page).click();
    await expect(canvas(page).locator(':is(.ak-btn2,.ak-btn3)')).toHaveText(['Call us']);
    await undo(page).click();
    await expect(canvas(page).locator(':is(.ak-btn2,.ak-btn3)')).toHaveText(['Book now', 'Call us']);
    await redo(page).click();
    expect(writes).toEqual([]);
    await saveDraft(page);
    expect(ofType(await draft(id), 'button').map((b) => b.props.label)).toEqual(['Call us']);
    await page.reload();
    await expect(canvas(page).locator(':is(.ak-btn2,.ak-btn3)')).toHaveText(['Call us']);
});

test('a container with content asks first; cancel changes nothing; Delete removes it and its contents in one step', async ({ page }) => {
    const { id } = await openPage(page, [hero('Top'), section([text('Inside'), button('Go')])]);
    await saveDraft(page).catch(() => undefined);
    await page.getByRole('tab', { name: 'Layers' }).click();
    await layer(page, 'section').locator('button').first().click();
    await expect(page.getByTestId('canvas-delete')).toHaveAccessibleName('Delete section and its contents');
    await page.getByTestId('canvas-delete').click();
    const dialog = page.getByTestId('delete-dialog');
    await expect(dialog).toContainText('Delete section and its contents?');
    await expect(dialog).toContainText('The 2 blocks inside it will be deleted too.');
    await page.getByTestId('delete-cancel').click();
    await expect(dialog).not.toBeVisible();
    await expect(canvas(page).locator('.ak-section')).toHaveCount(1);
    await expect(undo(page)).toBeDisabled();

    // Escape cancels too.
    await page.getByTestId('canvas-delete').click();
    await page.keyboard.press('Escape');
    await expect(canvas(page).locator('.ak-section')).toHaveCount(1);

    // The same deletion from the Layers row (and the toolbar): it asks the same way.
    await layer(page, 'section').getByRole('button', { name: 'Remove Section' }).click();
    await page.getByTestId('delete-confirm').click();
    await expect(canvas(page).locator('.ak-section')).toHaveCount(0);
    await expect(canvas(page).getByText('Inside')).toHaveCount(0);
    await undo(page).click();
    await expect(canvas(page).locator('.ak-section .ak-text2')).toHaveText(['Inside']);
    await saveDraft(page);
    expect(ofType(await draft(id), 'section')).toHaveLength(1);
});

test('Delete/Backspace delete the selection only from the selection interface, never while typing', async ({ page }) => {
    await openPage(page, [hero('Top'), section([text('Alpha'), text('Beta'), text('Gamma')])]);
    // Typing in the canvas: Backspace edits the text.
    await canvas(page).getByText('Alpha').click();
    await page.keyboard.press('End');
    await page.keyboard.press('Backspace');
    await expect(canvas(page).locator('.ak-text2').first()).toHaveText('Alph');
    // Not editing any more (Escape), focus still in the canvas: Delete deletes the selected block.
    await page.keyboard.press('Escape');
    await page.keyboard.press('Delete');
    await expect(canvas(page).locator('.ak-text2')).toHaveText(['Beta', 'Gamma']);
    // Inspector fields keep their keys.
    await page.getByLabel('Text', { exact: true }).focus();
    await page.keyboard.press('Backspace');
    await page.keyboard.press('Delete');
    await expect(canvas(page).locator('.ak-text2')).toHaveCount(2);
    // A Layers row: Backspace deletes it.
    await page.getByRole('tab', { name: 'Layers' }).click();
    await layer(page, 'text').last().locator('button').first().click();
    await page.keyboard.press('Backspace');
    await expect(canvas(page).locator('.ak-text2')).toHaveCount(1);
    // Anywhere else (the page header, a dialog) does nothing.
    await page.getByTestId('page-title').click();
    await page.keyboard.press('Delete');
    await expect(canvas(page).locator('.ak-text2')).toHaveCount(1);
});

test('a column takes its width with it; the last column and the page are refused; phones keep stacking', async ({ page }) => {
    const { id } = await openPage(page, [columns([[text('One')], [text('Two')], [text('Three')]])]);
    await page.getByRole('tab', { name: 'Layers' }).click();
    await layer(page, 'column').nth(1).locator('button').first().click();
    await expect(page.getByTestId('canvas-delete')).toHaveAccessibleName('Delete column and its contents');
    await page.getByTestId('canvas-delete').click();
    await page.getByTestId('delete-confirm').click();
    await expect(canvas(page).locator('[data-ak-type="column"]')).toHaveCount(2);
    await saveDraft(page);
    expect(ofType(await draft(id), 'columns')[0]!.props.style).toEqual({ root: { base: { columns: '1fr 1fr' }, mobile: { columns: '1' } } });

    // Down to one column: the last one can't go (control disabled with the reason; the key says so).
    await layer(page, 'column').nth(1).locator('button').first().click();
    await page.getByTestId('canvas-delete').click();
    await page.getByTestId('delete-confirm').click();
    await layer(page, 'column').first().locator('button').first().click();
    await expect(page.getByTestId('canvas-delete')).toBeDisabled();
    await expect(page.getByTestId('canvas-delete')).toHaveAttribute(
        'title',
        'A Columns block needs at least one column. Delete the whole Columns block instead.',
    );
    await page.keyboard.press('Delete');
    await expect(notice(page)).toContainText('Not deleted. A Columns block needs at least one column');
    await expect(canvas(page).locator('[data-ak-type="column"]')).toHaveCount(1);
    await saveDraft(page);
    expect(ofType(await draft(id), 'columns')[0]!.props.style).toEqual({ root: { mobile: { columns: '1' } } });

    // Nothing selected (the page): nothing to delete, the key does nothing.
    await page.getByRole('tab', { name: 'Properties' }).click();
    await page.getByRole('button', { name: 'Page settings' }).click();
    await expect(page.getByTestId('canvas-delete')).toHaveCount(0);
    await page.getByTestId('canvas-stage').focus();
    await page.keyboard.press('Delete');
    await expect(canvas(page).locator('.ak-cols')).toHaveCount(1);
});

test('with the hero image selected, the control removes the image; otherwise it deletes the whole hero', async ({ page }) => {
    await openPage(page, [hero('Gardens', [button('Book')]), section([text('Below')])]);
    await canvas(page).locator('h1').click();
    await page.keyboard.press('Escape');
    await page.getByTestId('part-root').click();
    await page.getByTestId('edit-hero-image').click();
    await page.getByLabel('Upload image').setInputFiles({ name: 'dot.png', mimeType: 'image/png', buffer: PNG_1X1 });
    await expect(canvas(page).locator('.ak-hero3__media')).toHaveCount(1);
    await expect(page.getByTestId('canvas-delete')).toHaveAccessibleName('Remove image');
    await page.getByTestId('canvas-delete').click();
    await expect(canvas(page).locator('.ak-hero3__media')).toHaveCount(0);
    await expect(canvas(page).locator('h1')).toHaveText('Gardens');
    await expect(notice(page)).toContainText('Removed the image');
    await page.getByTestId('notice-action').click();
    await expect(canvas(page).locator('.ak-hero3__media')).toHaveCount(1);

    // The hero itself: named as the whole section, and it asks (its button goes too).
    await page.getByTestId('part-root').click();
    await expect(page.getByTestId('canvas-delete')).toHaveAccessibleName('Delete hero section and its contents');
    await page.getByTestId('canvas-delete').click();
    await page.getByTestId('delete-confirm').click();
    await expect(canvas(page).locator('.ak-hero3')).toHaveCount(0);
    await expect(canvas(page).getByText('Below')).toBeVisible();
});

test('columns: invalid custom widths are refused before applying; moving a column to another block keeps both valid', async ({ page }) => {
    const { id } = await openPage(page, [columns([[text('A')], [text('B')]]), columns([[text('C')], [text('D')], [text('E')]])]);
    await page.getByRole('tab', { name: 'Layers' }).click();
    await layer(page, 'columns').first().locator('button').first().click();
    await page.getByRole('tab', { name: 'Properties' }).click();
    const widths = page.getByLabel('Custom widths (all screens)', { exact: true });
    await widths.fill('1fr 2fr 1fr');
    await expect(page.getByText('Columns: 3 widths for 2 columns; give one width per column')).toBeVisible();
    await expect(undo(page)).toBeDisabled();
    await widths.fill('2fr 1fr');
    await expect(page.getByText('give one width per column')).toHaveCount(0);

    // Drag column B (Layers) into the second block: the first keeps one column, the second gains one.
    await page.getByRole('tab', { name: 'Layers' }).click();
    const source = layer(page, 'column').nth(1);
    const target = layer(page, 'column').nth(2);
    const from = (await source.boundingBox())!;
    const to = (await target.boundingBox())!;
    await page.mouse.move(from.x + 30, from.y + from.height / 2);
    await page.mouse.down();
    await page.mouse.move(to.x + 40, to.y + 4, { steps: 6 });
    await page.mouse.up();
    await expect(notice(page)).toContainText('Column widths on all screens went back to equal, because they were set for 3 columns.');
    await expect(layer(page, 'column')).toHaveCount(5);
    await saveDraft(page);
    const doc = await draft(id);
    const [first, second] = doc.nodes[doc.root]!.children!.map((childId) => doc.nodes[childId]!);
    expect(first!.children).toHaveLength(1);
    expect(second!.children).toHaveLength(4);
    expect(first!.props.style).toEqual({ root: { mobile: { columns: '1' } } });
    expect(second!.props.style).toEqual({ root: { mobile: { columns: '1' } } });
    await undo(page).click();
    await expect(layer(page, 'column')).toHaveCount(5);
    await expect(canvas(page).locator('.ak-cols').first().locator('[data-ak-type="column"]')).toHaveCount(2);
});

test('read-only states offer no delete: an older draft whose widths break the rule opens in recovery and is repaired explicitly', async ({ page }) => {
    const doc = documentOf([
        columns([[text('A')], [text('B')]], { base: { columns: '1fr 2fr' }, tablet: { columns: '1fr 1fr 1fr 1fr 1fr' }, mobile: { columns: '1' } }),
    ]);
    const { id } = await openPage(page, doc);
    const stored = JSON.stringify(await draft(id));
    await expect(page.getByTestId('recovery')).toContainText('Columns widths were saved before a Columns block needed one width per column');
    await expect(page.getByTestId('stored-value')).toHaveText('1fr 1fr 1fr 1fr 1fr');
    // Locked: no canvas controls, keys do nothing, the stored draft is untouched.
    await canvas(page).getByText('A', { exact: true }).click();
    await expect(page.getByTestId('canvas-delete')).toHaveCount(0);
    await page.keyboard.press('Delete');
    await expect(canvas(page).locator('.ak-text2')).toHaveCount(2);
    expect(JSON.stringify(await draft(id))).toBe(stored);
    // The repair: equal widths on tablets, then an ordinary save.
    await page.getByLabel('Equal widths on tablets (the columns and their content stay)').check();
    await page.getByRole('button', { name: 'Apply repair' }).click();
    await saveDraft(page);
    expect(ofType(await draft(id), 'columns')[0]!.props.style).toEqual({ root: { base: { columns: '1fr 2fr' }, mobile: { columns: '1' } } });
    await page.reload();
    await expect(page.getByTestId('recovery')).toHaveCount(0);
});

test('the component editor deletes from the canvas the same way', async ({ page }) => {
    const site = await siteId();
    const id = crypto.randomUUID();
    const document = documentOf([section([text('Card'), button('More')]), text('Note')], 'fragment', 1);
    await db.query('insert into reusable_components (id, site_id, name, draft) values ($1, $2, $3, $4)', [
        id,
        site,
        `Delete ${id.slice(0, 6)}`,
        JSON.stringify(document),
    ]);
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto(`/admin/components/${id}`);
    await canvas(page).getByText('Note').click();
    await page.keyboard.press('Escape');
    await expect(page.getByTestId('canvas-delete')).toHaveAccessibleName('Delete text');
    await page.getByTestId('canvas-delete').click();
    await expect(canvas(page).getByText('Note')).toHaveCount(0);
    await canvas(page).getByText('Card').click();
    await page.keyboard.press('Escape');
    await page.getByRole('tab', { name: 'Layers' }).click();
    await layer(page, 'section').locator('button').first().click();
    await page.keyboard.press('Delete');
    await page.getByTestId('delete-confirm').click();
    await expect(canvas(page).locator('.ak-section')).toHaveCount(0);
    await undo(page).click();
    await expect(canvas(page).locator('.ak-section')).toHaveCount(1);
});

/**
 * The reproduction: delete "Book now", restore it with the header Undo, rename "Call us" in the
 * inspector, then the old notice. Its action must never undo the rename. Checked on the
 * controlled inspector field once the notice is gone (the canvas redraw is asynchronous, so an
 * immediate canvas assertion could pass on old HTML) and in the saved draft.
 */
async function undoDeleteNeverUndoesAnotherEdit(page: Page, label: () => ReturnType<Page['getByLabel']>, saved: () => Promise<string[]>) {
    await pick(page, ':is(.ak-btn2,.ak-btn3) >> text=Book now');
    await page.getByTestId('canvas-delete').click();
    await expect(page.getByTestId('notice-action')).toHaveText('Undo delete');
    await undo(page).click();
    await expect(canvas(page).locator(':is(.ak-btn2,.ak-btn3)')).toHaveText(['Book now', 'Call us']);
    // The deletion was undone: the notice can't offer to undo it any more.
    await expect(page.getByTestId('notice-action')).toHaveCount(0);
    await pick(page, ':is(.ak-btn2,.ak-btn3) >> text=Call us');
    await label().fill('New call label');
    await expect(canvas(page).locator(':is(.ak-btn2,.ak-btn3)')).toHaveText(['Book now', 'New call label']);
    const action = page.getByTestId('notice-action');
    if ((await action.count()) > 0 && (await action.isEnabled())) await action.click();
    await expect(action).toHaveCount(0);
    await expect(label()).toHaveValue('New call label');
    await expect(canvas(page).locator(':is(.ak-btn2,.ak-btn3)')).toHaveText(['Book now', 'New call label']);
    await page.keyboard.press('ControlOrMeta+s');
    await expect.poll(saved).toEqual(['Book now', 'New call label']);
}

test('Undo delete is bound to its deletion: never undoes a later edit (page editor)', async ({ page }) => {
    const { id } = await openPage(page, [hero('Top', [button('Book now'), button('Call us')])]);
    await undoDeleteNeverUndoesAnotherEdit(
        page,
        () => page.getByLabel('Label', { exact: true }),
        async () => {
            const doc = await draft(id);
            return (doc.nodes[Object.values(doc.nodes).find((n) => n.type === 'hero')!.id]!.children ?? []).map((c) => String(doc.nodes[c]!.props.label));
        },
    );
});

test('Undo delete is bound to its deletion: never undoes a later edit (component editor)', async ({ page }) => {
    const site = await siteId();
    const id = crypto.randomUUID();
    const document = documentOf([section([button('Book now'), button('Call us')])], 'fragment', 1);
    await db.query('insert into reusable_components (id, site_id, name, draft) values ($1, $2, $3, $4)', [
        id,
        site,
        `Undo ${id.slice(0, 6)}`,
        JSON.stringify(document),
    ]);
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto(`/admin/components/${id}`);
    await expect(canvas(page).locator(':is(.ak-btn2,.ak-btn3)')).toHaveCount(2);
    await undoDeleteNeverUndoesAnotherEdit(
        page,
        () => page.getByLabel('Label', { exact: true }),
        async () => {
            const doc = (await db.query<{ draft: Doc }>('select draft from reusable_components where id = $1', [id])).rows[0]!.draft;
            return ofType(doc, 'button')
                .map((b) => String(b.props.label))
                .sort();
        },
    );
});
