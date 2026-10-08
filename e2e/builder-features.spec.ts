// Duplicate, column layouts and entrance animations, in the page editor and the component
// editor, through to save, reload, publishing and the live page (with and without JavaScript,
// with reduced motion, keyboard focus, and the script policy). Everything runs against the
// isolated e2e database; the AI panel uses the fake Claude Code CLI (no subscription usage).
import { expect, test, type FrameLocator, type Page } from '@playwright/test';
import { E2E_HOST, PORT } from './env';
import { BASE_URL, db } from './support';

type Spec = Record<string, unknown>;
let counter = 0;
const nid = (prefix: string) => `${prefix}${String(++counter).padStart(10 - prefix.length, '0')}`;

const text = (label: string, extra: Spec = {}): Spec => ({ type: 'text', version: 3, props: { text: label, element: 'p', style: {}, ...extra } });
const button = (label: string, href = '/contact'): Spec => ({
    type: 'button',
    version: 3,
    props: { label, href, variant: 'primary', size: 'medium', newTab: false, style: {} },
});
const group = (children: Spec[]): Spec => ({ type: 'group', version: 2, props: { element: 'div', style: {} }, children });
const section = (children: Spec[], style: Spec = {}): Spec => ({
    type: 'section',
    version: 2,
    props: { element: 'section', contentWidth: 'default', style },
    children,
});
const hero = (heading: string, style: Spec = {}): Spec => ({
    type: 'hero',
    version: 4,
    props: { heading, headingLevel: 'h1', text: '', image: null, style },
    children: [],
});
const columns = (cols: Spec[][], style: Spec = { root: { mobile: { columns: '1' } } }): Spec => ({
    type: 'columns',
    version: 3,
    props: { style },
    children: cols.map((c) => ({ type: 'column', version: 3, props: { style: {} }, children: c })),
});
/** Pushes what follows below the fold (1440 × 900). */
const spacer = (): Spec => text('Spacer', { style: { root: { base: { minHeight: '1400px' } } } });

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

async function openPage(page: Page, blocks: Spec[]) {
    const id = crypto.randomUUID();
    const path = `/features-${id.slice(0, 8)}`;
    const site = await siteId();
    await db.query('insert into pages (id, site_id, path, title) values ($1, $2, $3, $4)', [id, site, path, 'Features']);
    await db.query('insert into page_drafts (page_id, site_id, document, version) values ($1, $2, $3, 1)', [id, site, JSON.stringify(documentOf(blocks))]);
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto(`/admin/editor/${id}`);
    await expect(canvas(page).locator('main')).toBeVisible();
    return { id, path };
}

type Doc = { root: string; nodes: Record<string, { id: string; type: string; props: Record<string, unknown>; children?: string[] }> };
async function draft(id: string): Promise<Doc> {
    return (await db.query<{ document: Doc }>('select document from page_drafts where page_id = $1', [id])).rows[0]!.document;
}
const ofType = (doc: Doc, type: string) => Object.values(doc.nodes).filter((n) => n.type === type);

const canvas = (page: Page): FrameLocator => page.frameLocator('[data-testid="canvas"]');
const notice = (page: Page) => page.getByTestId('notice');
const layer = (page: Page, type: string) => page.locator(`[data-testid="layer"][data-node-type="${type}"]`);
const undo = (page: Page) => page.getByRole('button', { name: 'Undo' });
const redo = (page: Page) => page.getByRole('button', { name: 'Redo' });

async function saveDraft(page: Page) {
    await page.getByRole('button', { name: 'Save draft' }).click();
    await expect(page.getByTestId('save-status')).toHaveText('Draft saved');
}

async function selectLayer(page: Page, type: string, index = 0) {
    await page.getByRole('tab', { name: 'Layers' }).click();
    await layer(page, type).nth(index).locator('button').first().click();
}

async function publish(page: Page) {
    await page.getByRole('button', { name: 'Publish', exact: true }).click();
    await expect(notice(page)).toContainText('Published');
}

test.describe('duplicate', () => {
    test('toolbar, Layers, canvas and Ctrl/Cmd+D duplicate after the original, select the copy, one undo step each', async ({ page }) => {
        const { id } = await openPage(page, [hero('Top'), section([group([text('Inside A'), button('Book now')]), text('After')])]);

        // Toolbar: a nested group with its contents.
        await selectLayer(page, 'group');
        await page.getByRole('tab', { name: 'Properties' }).click();
        await page.getByTestId('duplicate-block').click();
        await expect(notice(page)).toContainText('Duplicated group; the copy is selected');
        await expect(canvas(page).locator('.ak-group')).toHaveCount(2);
        await expect(canvas(page).locator('.ak-section .ak-text2')).toHaveText(['Inside A', 'Inside A', 'After']);
        await expect(page.getByTestId('inspector-target')).toHaveText('Group');
        await undo(page).click();
        await expect(canvas(page).locator('.ak-group')).toHaveCount(1);
        await redo(page).click();
        await expect(canvas(page).locator('.ak-group')).toHaveCount(2);
        await undo(page).click();

        // Layers row: a button.
        await page.getByRole('tab', { name: 'Layers' }).click();
        await layer(page, 'button').first().getByTestId('layer-duplicate').click();
        await expect(canvas(page).locator('.ak-btn2')).toHaveText(['Book now', 'Book now']);

        // Canvas selection control: a text.
        await canvas(page).getByText('After', { exact: true }).click();
        await page.keyboard.press('Escape');
        await page.getByTestId('canvas-duplicate').click();
        await expect(canvas(page).locator('.ak-section > .ak-text2')).toHaveText(['After', 'After']);

        // Ctrl/Cmd+D with the editor focused (a Layers row), and inside the canvas when not typing.
        await selectLayer(page, 'section');
        await page.keyboard.press('ControlOrMeta+d');
        await expect(canvas(page).locator('.ak-section')).toHaveCount(2);
        await canvas(page).getByText('Inside A', { exact: true }).first().click(); // starts inline editing
        await page.keyboard.press('ControlOrMeta+d'); // typing: the browser's key, nothing duplicated
        await expect(canvas(page).locator('.ak-text2', { hasText: 'Inside A' })).toHaveCount(2);
        await page.keyboard.press('Escape'); // stop editing, the canvas keeps focus
        await page.keyboard.press('ControlOrMeta+d');
        await expect(canvas(page).locator('.ak-text2', { hasText: 'Inside A' })).toHaveCount(3);

        // Typing in the inspector: Ctrl+D is never taken from text fields.
        await page.getByRole('tab', { name: 'Properties' }).click();
        await page.getByLabel('Text', { exact: true }).focus();
        await page.keyboard.press('ControlOrMeta+d');
        await expect(canvas(page).locator('.ak-text2', { hasText: 'Inside A' })).toHaveCount(3);

        // Saved and reloaded: copies are independent nodes with fresh ids and the same props.
        await saveDraft(page);
        const doc = await draft(id);
        const buttons = ofType(doc, 'button');
        expect(buttons.length).toBeGreaterThanOrEqual(2);
        expect(new Set(Object.keys(doc.nodes)).size).toBe(Object.keys(doc.nodes).length);
        expect(buttons[0]!.props).toEqual(buttons[1]!.props);
        await page.reload();
        await expect(canvas(page).locator('.ak-section')).toHaveCount(2);
    });

    test('an unresolved link inside the block must be fixed first; the page is never duplicated', async ({ page }) => {
        const { id } = await openPage(page, [hero('Top'), section([button('Go')])]);
        await canvas(page).locator('.ak-btn2').click();
        await page.keyboard.press('Escape');
        await page.getByLabel('Link').fill('https://');
        await selectLayer(page, 'section');
        await page.getByRole('tab', { name: 'Properties' }).click();
        await page.getByTestId('duplicate-block').click();
        await expect(notice(page)).toContainText('Not duplicated. Fix or revert the button link in Button block first');
        await expect(page.getByTestId('unresolved-link')).toBeVisible();
        await expect(canvas(page).locator('.ak-section')).toHaveCount(1);
        await page.getByRole('button', { name: 'Revert link' }).click();
        await selectLayer(page, 'section');
        await page.keyboard.press('ControlOrMeta+d');
        await expect(canvas(page).locator('.ak-section')).toHaveCount(2);
        // Columns at six columns refuse a seventh, with the reason.
        await openPage(page, [columns([[], [], [], [], [], []])]);
        await selectLayer(page, 'column');
        await page.keyboard.press('ControlOrMeta+d');
        await expect(notice(page)).toContainText('Columns already has 6 columns, the most allowed');
        expect(ofType(await draft(id), 'section')).toHaveLength(1);
    });
});

test.describe('columns', () => {
    test('the layout picker creates real columns with their widths, in one undo step', async ({ page }) => {
        await openPage(page, [hero('Top')]);
        await page.getByRole('tab', { name: 'Layers' }).click();
        await page.getByTestId('add-columns').click();
        await page.getByTestId('columns-preset-5').click();
        await expect(canvas(page).locator('[data-ak-type="column"]')).toHaveCount(5);
        await expect(layer(page, 'column')).toHaveCount(5);
        await expect(page.getByTestId('empty-column-add')).toHaveCount(5);
        await undo(page).click();
        await expect(canvas(page).locator('[data-ak-type="column"]')).toHaveCount(0);

        await page.getByRole('tab', { name: 'Layers' }).click();
        await page.getByTestId('add-columns').click();
        await page.getByTestId('columns-preset-1fr-2fr-1fr').click();
        await expect(canvas(page).locator('[data-ak-type="column"]')).toHaveCount(3);
        const widths = await canvas(page)
            .locator('[data-ak-type="column"]')
            .evaluateAll((els) => els.map((el) => el.getBoundingClientRect().width));
        expect(widths).toHaveLength(3);
        expect(widths[1]! / widths[0]!).toBeGreaterThan(1.8);
        expect(Math.abs(widths[0]! - widths[2]!)).toBeLessThan(2);
    });

    test('count selector: more keeps content, fewer asks (cancel, move, delete), widths reconcile, phones stack', async ({ page }) => {
        const { id, path } = await openPage(page, [hero('Top'), columns([[text('First')], [text('Second')]])]);
        await selectLayer(page, 'columns');
        await page.getByRole('tab', { name: 'Properties' }).click();
        const count = page.getByRole('group', { name: 'Number of columns' });
        await expect(count.getByRole('button', { name: '2', exact: true })).toHaveAttribute('aria-pressed', 'true');

        // Proportions, then more columns: content stays, stale proportions go back to equal (said so).
        await page.getByTestId('columns-width-1fr-2fr').click();
        await count.getByRole('button', { name: '4', exact: true }).click();
        await expect(page.getByTestId('columns-message')).toHaveText('Column widths on all screens went back to equal, because they were set for 2 columns.');
        await expect(canvas(page).locator('[data-ak-type="column"]')).toHaveCount(4);
        await expect(canvas(page).locator('[data-ak-type="column"]').nth(0)).toHaveText('First');
        await expect(canvas(page).locator('[data-ak-type="column"]').nth(1)).toHaveText('Second');

        // Populate an empty column from the canvas.
        await page.getByTestId('empty-column-add').first().click();
        await page.getByRole('menuitem', { name: 'Text' }).click();
        await expect(canvas(page).locator('[data-ak-type="column"]').nth(2).locator('.ak-text2')).toHaveCount(1);
        await canvas(page).locator('[data-ak-type="column"]').nth(2).locator('.ak-text2').click();
        await page.keyboard.press('ControlOrMeta+a');
        await page.keyboard.type('Third');
        await page.keyboard.press('Escape');
        await selectLayer(page, 'columns');
        await page.getByRole('tab', { name: 'Properties' }).click();

        // Fewer columns that hold blocks: nothing changes until the user chooses.
        await count.getByRole('button', { name: '2', exact: true }).click();
        const dialog = page.getByTestId('reduce-columns-dialog');
        await expect(dialog).toContainText('The columns being removed hold 1 block');
        await page.getByTestId('reduce-cancel').click();
        await expect(canvas(page).locator('[data-ak-type="column"]')).toHaveCount(4);
        await count.getByRole('button', { name: '2', exact: true }).click();
        await page.getByTestId('reduce-move').click();
        await expect(canvas(page).locator('[data-ak-type="column"]')).toHaveCount(2);
        await expect(canvas(page).locator('[data-ak-type="column"]').nth(1).locator('.ak-text2')).toHaveText(['Second', 'Third']);
        await undo(page).click();
        await expect(canvas(page).locator('[data-ak-type="column"]')).toHaveCount(4);
        await expect(canvas(page).locator('[data-ak-type="column"]').nth(2)).toHaveText('Third');
        await count.getByRole('button', { name: '2', exact: true }).click();
        await expect(page.getByTestId('reduce-delete')).toHaveText('Delete 1 block');
        await page.getByTestId('reduce-delete').click();
        await expect(canvas(page).locator('[data-ak-type="column"]')).toHaveCount(2);
        await expect(canvas(page).getByText('Third')).toHaveCount(0);

        // Phones stack by default; changing the phone layout never removes columns.
        await page.getByRole('group', { name: 'Viewport' }).getByRole('button', { name: 'Mobile' }).click();
        const lefts = await canvas(page)
            .locator('[data-ak-type="column"]')
            .evaluateAll((els) => els.map((el) => Math.round(el.getBoundingClientRect().left)));
        expect(new Set(lefts).size).toBe(1);
        await page.getByLabel('Phones (599 px and narrower)').selectOption('row');
        await expect(canvas(page).locator('[data-ak-type="column"]')).toHaveCount(2);
        await expect
            .poll(() =>
                canvas(page)
                    .locator('[data-ak-type="column"]')
                    .evaluateAll((els) => new Set(els.map((el) => Math.round(el.getBoundingClientRect().left))).size),
            )
            .toBe(2);
        await page.getByLabel('Phones (599 px and narrower)').selectOption('stack');

        await saveDraft(page);
        const doc = await draft(id);
        const block = ofType(doc, 'columns')[0]!;
        expect(block.children).toHaveLength(2);
        expect(block.props.style).toEqual({ root: { mobile: { columns: '1' } } });
        await publish(page);
        expect((await publicGet(page, path)).html).toContain('ak-cols--n2');
    });
});

/** A public page fetched from Node (no browser, no cookies), with its script policy. */
async function publicGet(page: Page, path: string) {
    const context = await page
        .context()
        .browser()!
        .newContext({ baseURL: BASE_URL, storageState: { cookies: [], origins: [] } });
    try {
        const response = await context.request.get(path);
        return { html: await response.text(), csp: response.headers()['content-security-policy'] ?? '' };
    } finally {
        await context.close();
    }
}

test.describe('entrance animations', () => {
    // The inspector, previews and the live page's entrances: e2e/motion.spec.ts.
    test('on page load: the entrance is CSS; the page allows only the motion runtime (it keeps focused content shown)', async ({ page }) => {
        const { path } = await openPage(page, [
            hero('Plain'),
            section([text('Fades on load')], { root: { base: { animation: 'fade', animationTrigger: 'load' } } }),
        ]);
        await publish(page);
        const { html, csp } = await publicGet(page, path);
        expect(html.match(/<script/g)).toHaveLength(1);
        expect(html).toContain('<script src="/_arkon/motion-3.js" integrity="sha384-');
        expect(html).toContain('ak-anim');
        expect(csp).toMatch(/script-src [^;]*\/_arkon\/motion-3\.js;/);
    });
});

test('the component editor: duplicate, columns picker and animation settings', async ({ page }) => {
    const site = await siteId();
    const id = crypto.randomUUID();
    const document = documentOf([section([text('Card'), button('More')])], 'fragment', 1);
    await db.query('insert into reusable_components (id, site_id, name, draft) values ($1, $2, $3, $4)', [
        id,
        site,
        `Card ${id.slice(0, 6)}`,
        JSON.stringify(document),
    ]);
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto(`/admin/components/${id}`);
    await expect(canvas(page).locator('.ak-section')).toBeVisible();
    await page.getByRole('tab', { name: 'Layers' }).click();
    await layer(page, 'section').locator('button').first().click();
    await page.keyboard.press('ControlOrMeta+d');
    await expect(canvas(page).locator('.ak-section')).toHaveCount(2);
    await page.getByRole('tab', { name: 'Properties' }).click();
    await page.getByTestId('animation-effect').selectOption('zoom');
    await expect(page.getByTestId('animation-section')).toContainText('On a page, the animation is left off if this block holds');
    await page.getByTestId('add-inside-columns').click();
    await page.getByTestId('columns-preset-3').click();
    await expect(canvas(page).locator('.ak-section').nth(1).locator('[data-ak-type="column"]')).toHaveCount(3);
    await page.getByRole('button', { name: 'Save draft' }).click();
    await expect(page.getByTestId('component-save-status')).toHaveText('Draft saved');
    const saved = (await db.query<{ draft: Doc }>('select draft from reusable_components where id = $1', [id])).rows[0]!.draft;
    expect(ofType(saved, 'section')).toHaveLength(2);
    expect(ofType(saved, 'column')).toHaveLength(3);
    expect(JSON.stringify(saved)).toContain('"animation":"zoom"');
});

test('AI panel (fake Claude Code): duplicate a button, five equal columns, a fade-up entrance; review, apply, undo, publish', async ({ page }) => {
    test.setTimeout(120_000);
    const { id, path } = await openPage(page, [{ ...hero('Gardens'), children: [button('Book now')] }]);
    await page.getByRole('tab', { name: 'AI' }).click();
    await expect(page.getByTestId('ai-connection')).toHaveAttribute('data-ready', 'true');
    // Other specs may have used this minute's requests (5 per user per minute): wait for room, never bypass the limit.
    await expect
        .poll(
            async () =>
                Number((await db.query<{ n: string }>("select count(*) as n from ai_proposals where created_at > now() - interval '62 seconds'")).rows[0]!.n),
            { timeout: 70_000, intervals: [2_000] },
        )
        .toBeLessThan(5);
    await page.getByLabel('Ask AI to change this page').fill('MOCK-BUILDER: duplicate the button as Call us, add five equal columns and fade the section up');
    await page.getByRole('button', { name: 'Generate proposal' }).click();
    const proposal = page.getByTestId('ai-proposal');
    await expect(proposal).toBeVisible({ timeout: 30_000 });
    await expect(page.getByTestId('ai-changes').getByRole('listitem').first()).toHaveText('Duplicate Button “Book now” inside Hero “Gardens”');
    await expect(page.getByTestId('ai-changes')).toContainText('Change new Section: style');
    // Nothing changed yet: the preview shows it, the draft does not.
    await expect(canvas(page).locator('.ak-btn2')).toHaveText(['Book now', 'Call us']);
    expect(ofType(await draft(id), 'button')).toHaveLength(1);

    await proposal.getByRole('button', { name: 'Apply to draft' }).click();
    await expect(notice(page)).toContainText('Applied to the draft and saved. Nothing is published');
    await expect(canvas(page).locator('[data-ak-type="column"]')).toHaveCount(5);
    await expect(canvas(page).locator('section.ak-section')).toHaveClass(/ak-anim ak-reveal/);
    const doc = await draft(id);
    expect(
        ofType(doc, 'button')
            .map((b) => b.props.label)
            .sort(),
    ).toEqual(['Book now', 'Call us']);
    expect(ofType(doc, 'section')[0]!.props.style).toEqual({ root: { base: { animation: 'fade-up', animationTrigger: 'view' } } });

    // One undo step reverts the whole proposal; redo brings it back; publishing stays explicit.
    await undo(page).click();
    await expect(canvas(page).locator('.ak-btn2')).toHaveText(['Book now']);
    await expect(canvas(page).locator('[data-ak-type="column"]')).toHaveCount(0);
    await redo(page).click();
    await expect(canvas(page).locator('[data-ak-type="column"]')).toHaveCount(5);
    expect((await publicGet(page, path)).html).not.toContain('Call us');
    await publish(page);
    const live = await publicGet(page, path);
    expect(live.html).toContain('>Call us</a>');
    expect(live.html).toContain('ak-cols--n5');
    expect(live.csp).toContain('/_arkon/motion-3.js');
});
