import { expect, test, type Locator, type Page } from '@playwright/test';
import { E2E_EDITOR } from './fixtures';
import { BASE_URL, createPage } from './support';

const status = (page: Page) => page.getByTestId('save-status');
const notice = (page: Page) => page.getByTestId('notice');
const canvas = (page: Page) => page.frameLocator('[data-testid="canvas"]');
const layers = (page: Page) => page.getByTestId('layer');
const layer = (page: Page, type: string, nth = 0) => page.locator(`[data-testid="layer"][data-node-type="${type}"]`).nth(nth);

async function openLayers(page: Page) {
    await page.getByRole('tab', { name: 'Layers' }).click();
}

/** The structure the editor shows: [type, parent type] for every layer, in tree order. */
async function outline(page: Page): Promise<string[]> {
    return page.evaluate(() =>
        [...document.querySelectorAll<HTMLElement>('[data-testid="layer"]')].map((row) => {
            const parent = document.querySelector<HTMLElement>(`[data-testid="layer"][data-node-id="${row.dataset.parentId}"]`);
            return `${row.dataset.nodeType}<${parent?.dataset.nodeType ?? 'page'}`;
        }),
    );
}

async function publicHtml(page: Page, path: string) {
    const context = await page
        .context()
        .browser()!
        .newContext({ baseURL: BASE_URL, storageState: { cookies: [], origins: [] } });
    try {
        const response = await context.request.get(path);
        return { status: response.status(), html: await response.text() };
    } finally {
        await context.close();
    }
}

/** Drops `source` onto `target`: "inside" the middle of a container row, or just before/after a row. */
async function drop(source: Locator, target: Locator, where: 'inside' | 'before' | 'after') {
    const box = (await target.boundingBox())!;
    const y = where === 'inside' ? box.height / 2 : where === 'before' ? box.height * 0.2 : box.height * 0.8;
    await source.dragTo(target, { targetPosition: { x: box.width / 3, y } });
}

test('build a layout with the palette, layers, drag and drop and undo/redo, then publish clean HTML', async ({ page }) => {
    const id = await createPage('/builder', 'Builder');
    await page.goto(`/admin/editor/${id}`);
    await expect(canvas(page).locator('h1')).toHaveText('Builder');
    await openLayers(page);

    // Add: after the selected hero, a text block; then columns after it.
    await page.getByRole('button', { name: 'Add Text' }).click();
    await expect(canvas(page).getByText('Write something here.')).toBeVisible();
    await page.getByRole('button', { name: 'Add Columns' }).click();
    expect(await outline(page)).toEqual(['hero<page', 'text<page', 'columns<page', 'column<columns', 'column<columns']);

    // Selecting a column and adding puts the new block inside it; the next one goes after it.
    await layer(page, 'column', 0).getByRole('button').first().click();
    await page.getByRole('button', { name: 'Add Text' }).click();
    await page.getByRole('button', { name: 'Add Button' }).click();
    expect(await outline(page)).toEqual(['hero<page', 'text<page', 'columns<page', 'column<columns', 'text<column', 'button<column', 'column<columns']);
    // Things that may not go into a column land after the Columns block instead.
    await expect(page.getByRole('button', { name: 'Add Columns' })).toBeEnabled();

    // Inspector for the selected button: an unsafe link is refused and never applied; a safe one is.
    await page.getByRole('tab', { name: 'Properties' }).click();
    const link = page.getByLabel('Link');
    await link.fill('javascript:alert(1)');
    await expect(page.getByRole('alert').filter({ hasText: 'Use a link starting with' })).toBeVisible();
    await expect(canvas(page).locator('a.ak-button')).toHaveAttribute('href', '/');
    await link.fill('/contact');
    await expect(canvas(page).locator('a.ak-button')).toHaveAttribute('href', '/contact');
    await page.getByLabel('Label').fill('Contact us');
    await expect(canvas(page).locator('a.ak-button')).toHaveText('Contact us');

    // Accessible reordering: move the button above the text inside its column.
    await page.getByRole('button', { name: 'Move Button up' }).click();
    await openLayers(page);
    expect(await outline(page)).toEqual(['hero<page', 'text<page', 'columns<page', 'column<columns', 'button<column', 'text<column', 'column<columns']);

    // Drag and drop: the top-level text into the empty second column.
    await drop(layer(page, 'text', 0), layer(page, 'column', 1), 'inside');
    expect(await outline(page)).toEqual(['hero<page', 'columns<page', 'column<columns', 'button<column', 'text<column', 'column<columns', 'text<column']);
    // Invalid nesting is not offered: Columns dropped onto a column changes nothing.
    await drop(layer(page, 'columns'), layer(page, 'column', 0), 'inside');
    expect(await outline(page)).toEqual(['hero<page', 'columns<page', 'column<columns', 'button<column', 'text<column', 'column<columns', 'text<column']);
    // A new component can be dragged from the palette straight into place.
    await drop(page.getByRole('button', { name: 'Add Image' }), layer(page, 'hero'), 'after');
    expect(await outline(page)).toEqual([
        'hero<page',
        'image<page',
        'columns<page',
        'column<columns',
        'button<column',
        'text<column',
        'column<columns',
        'text<column',
    ]);

    // Undo and redo cover structural changes, one step each.
    await page.getByRole('button', { name: 'Undo' }).click(); // the dragged-in image
    await page.getByRole('button', { name: 'Undo' }).click(); // the drag into column 2
    expect(await outline(page)).toEqual(['hero<page', 'text<page', 'columns<page', 'column<columns', 'button<column', 'text<column', 'column<columns']);
    await page.getByRole('button', { name: 'Redo' }).click();
    expect(await outline(page)).toEqual(['hero<page', 'columns<page', 'column<columns', 'button<column', 'text<column', 'column<columns', 'text<column']);
    await expect(canvas(page).locator('.ak-image')).toHaveCount(0);

    // Responsive previews use real media queries: two columns side by side, stacked on mobile.
    const columnBoxes = async () =>
        canvas(page)
            .locator('.ak-column')
            .evaluateAll((els) => els.map((el) => Math.round(el.getBoundingClientRect().left)));
    await expect.poll(async () => new Set(await columnBoxes()).size).toBe(2);
    await page.getByRole('button', { name: 'mobile' }).click();
    await expect.poll(async () => new Set(await columnBoxes()).size).toBe(1);
    await page.getByRole('button', { name: 'desktop' }).click();

    // Save, reload: the structure persisted. Publish: clean, semantic HTML.
    await page.getByRole('button', { name: 'Save draft' }).click();
    await expect(status(page)).toHaveText('Draft saved');
    await page.reload();
    await openLayers(page);
    expect(await outline(page)).toEqual(['hero<page', 'columns<page', 'column<columns', 'button<column', 'text<column', 'column<columns', 'text<column']);
    await page.getByRole('button', { name: 'Publish' }).click();
    await expect(notice(page)).toContainText('Published');

    const live = await publicHtml(page, '/builder');
    expect(live.status).toBe(200);
    expect(live.html).toContain(
        '<div class="ak-columns ak-columns--stack-mobile ak-columns--n2"><div class="ak-column"><p class="ak-action"><a class="ak-button ak-button--primary" href="/contact">Contact us</a></p>',
    );
    for (const forbidden of ['data-ak-', '<script', 'contenteditable', 'draggable', 'Empty column', 'ak-image__empty', '/build/']) {
        expect(live.html).not.toContain(forbidden);
    }
});

test('removing and reordering with the structure toolbar, and the last column cannot be removed', async ({ page }) => {
    const id = await createPage('/builder-toolbar', 'Toolbar');
    await page.goto(`/admin/editor/${id}`);
    await openLayers(page);
    await page.getByRole('button', { name: 'Add Columns' }).click();
    await page.getByRole('tab', { name: 'Properties' }).click();
    // Columns inspector: remove one column; the last one stays.
    await page.getByRole('button', { name: 'Remove last column' }).click();
    await expect(page.getByRole('button', { name: 'Remove last column' })).toBeDisabled();
    await page.getByRole('button', { name: 'Add column' }).click();
    await expect(page.getByText('2 columns')).toBeVisible();

    // Toolbar: move Columns above the hero, then remove it (undo brings it back).
    await page.getByRole('toolbar').getByRole('button', { name: 'Move Columns up' }).click();
    await openLayers(page);
    expect(await outline(page)).toEqual(['columns<page', 'column<columns', 'column<columns', 'hero<page']);
    await page.getByRole('button', { name: 'Remove Columns' }).click();
    expect(await outline(page)).toEqual(['hero<page']);
    await page.getByRole('button', { name: 'Undo' }).click();
    expect(await outline(page)).toEqual(['columns<page', 'column<columns', 'column<columns', 'hero<page']);
    await expect(layers(page)).toHaveCount(4);
});

test.describe('as an editor', () => {
    test.use({ storageState: { cookies: [], origins: [] } });

    test('can build and save but not publish', async ({ page }) => {
        const id = await createPage('/builder-editor', 'Editor layout');
        await page.goto('/login');
        await page.getByLabel('Email').fill(E2E_EDITOR.email);
        await page.getByLabel('Password').fill(E2E_EDITOR.password);
        await page.getByRole('button', { name: 'Sign in' }).click();
        await expect(page.getByRole('heading', { name: `Welcome, ${E2E_EDITOR.name}` })).toBeVisible();

        await page.goto(`/admin/editor/${id}`);
        await openLayers(page);
        await page.getByRole('button', { name: 'Add Columns' }).click();
        await page.getByRole('button', { name: 'Save draft' }).click();
        await expect(status(page)).toHaveText('Draft saved');
        await expect(page.getByRole('button', { name: 'Publish' })).toBeDisabled();
        expect((await publicHtml(page, '/builder-editor')).status).toBe(404);
    });
});
