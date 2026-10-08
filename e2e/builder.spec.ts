import { expect, test, type FrameLocator, type Locator, type Page } from '@playwright/test';
import { E2E_EDITOR, PNG_1X1 } from './fixtures';
import { BASE_URL, createPage, interceptNext, isAction, publicationCount } from './support';

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

/** Drags the source (a Layers row or palette item) onto the target: into the middle of a container row, or just before/after a row. A quick, ordinary drag: released on arrival. */
async function drop(page: Page, source: Locator, target: Locator, where: 'inside' | 'before' | 'after') {
    const s = (await source.boundingBox())!;
    const t = (await target.boundingBox())!;
    const y = where === 'inside' ? t.height / 2 : where === 'before' ? t.height * 0.15 : t.height * 0.85;
    await page.mouse.move(s.x + Math.min(40, s.width / 2), s.y + s.height / 2);
    await page.mouse.down();
    await page.mouse.move(t.x + t.width / 3, t.y + y, { steps: 6 });
    await page.mouse.up();
}

/** + Columns opens the layout picker; two equal columns are the classic choice. */
async function addColumns(page: Page) {
    await page.getByRole('button', { name: 'Add Columns', exact: true }).click();
    await page.getByRole('button', { name: 'Add Columns: 2 equal' }).click();
}

test('build a layout with the palette, layers, drag and drop and undo/redo, then publish clean HTML', async ({ page }) => {
    const id = await createPage('/builder', 'Builder');
    await page.goto(`/admin/editor/${id}`);
    await expect(canvas(page).locator('h1')).toHaveText('Builder');
    await openLayers(page);

    // Add: after the selected hero, a text block; then columns after it.
    await page.getByRole('button', { name: 'Add Text' }).click();
    await expect(canvas(page).getByText('Write something here.')).toBeVisible();
    await addColumns(page);
    expect(await outline(page)).toEqual(['hero<page', 'text<page', 'columns<page', 'column<columns', 'column<columns']);

    // Selecting a column and adding puts the new block inside it; the next one goes after it.
    await layer(page, 'column', 0).getByRole('button').first().click();
    await page.getByRole('button', { name: 'Add Text' }).click();
    await page.getByRole('button', { name: 'Add Button' }).click();
    expect(await outline(page)).toEqual(['hero<page', 'text<page', 'columns<page', 'column<columns', 'text<column', 'button<column', 'column<columns']);
    // Things that may not go into a column land after the Columns block instead.
    await expect(page.getByRole('button', { name: 'Add Columns', exact: true })).toBeEnabled();

    // Inspector for the selected button: an unsafe link is refused and never applied; a safe one is.
    await page.getByRole('tab', { name: 'Properties' }).click();
    const link = page.getByLabel('Link');
    await link.fill('javascript:alert(1)');
    await expect(page.getByRole('alert').filter({ hasText: 'Use a link starting with' })).toBeVisible();
    await expect(canvas(page).locator('a.ak-btn2')).toHaveAttribute('href', '/');
    await link.fill('/contact');
    await expect(canvas(page).locator('a.ak-btn2')).toHaveAttribute('href', '/contact');
    await page.getByLabel('Label').fill('Contact us');
    await expect(canvas(page).locator('a.ak-btn2')).toHaveText('Contact us');

    // Accessible reordering: move the button above the text inside its column.
    await page.getByRole('button', { name: 'Move Button up' }).click();
    await openLayers(page);
    expect(await outline(page)).toEqual(['hero<page', 'text<page', 'columns<page', 'column<columns', 'button<column', 'text<column', 'column<columns']);

    // Drag and drop: the top-level text into the empty second column.
    await drop(page, layer(page, 'text', 0), layer(page, 'column', 1), 'inside');
    expect(await outline(page)).toEqual(['hero<page', 'columns<page', 'column<columns', 'button<column', 'text<column', 'column<columns', 'text<column']);
    // Invalid nesting is not offered: a hero dropped onto a column changes nothing.
    await drop(page, layer(page, 'hero'), layer(page, 'column', 0), 'inside');
    expect(await outline(page)).toEqual(['hero<page', 'columns<page', 'column<columns', 'button<column', 'text<column', 'column<columns', 'text<column']);
    // A new component can be dragged from the palette straight into place.
    await drop(page, page.getByRole('button', { name: 'Add Image' }), layer(page, 'hero'), 'after');
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
    await page.getByRole('button', { name: 'Undo', exact: true }).click(); // the dragged-in image
    await page.getByRole('button', { name: 'Undo', exact: true }).click(); // the drag into column 2
    expect(await outline(page)).toEqual(['hero<page', 'text<page', 'columns<page', 'column<columns', 'button<column', 'text<column', 'column<columns']);
    await page.getByRole('button', { name: 'Redo' }).click();
    expect(await outline(page)).toEqual(['hero<page', 'columns<page', 'column<columns', 'button<column', 'text<column', 'column<columns', 'text<column']);
    await expect(canvas(page).locator('.ak-img2')).toHaveCount(0);

    // Responsive previews use real media queries: two columns side by side, stacked on mobile.
    const columnBoxes = async () =>
        canvas(page)
            .locator('.ak-col')
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
    expect(live.html).toMatch(
        /<div class="ak-cols ak-flow ak-cols--n2 ak-s[0-9a-f]{10}"><div class="ak-col"><p class="ak-action2 ak-flow"><a class="ak-btn2 ak-btn2--primary" href="\/contact">Contact us<\/a><\/p>/,
    );
    for (const forbidden of ['data-ak-', '<script', 'contenteditable', 'draggable', 'Empty column', 'ak-image__empty', '/build/']) {
        expect(live.html).not.toContain(forbidden);
    }
});

test('removing and reordering with the structure toolbar, and the last column cannot be removed', async ({ page }) => {
    const id = await createPage('/builder-toolbar', 'Toolbar');
    await page.goto(`/admin/editor/${id}`);
    await openLayers(page);
    await addColumns(page);
    await page.getByRole('tab', { name: 'Properties' }).click();
    // Columns inspector: remove one column; the last one stays.
    await page.getByRole('button', { name: 'Remove last column' }).click();
    await expect(page.getByRole('button', { name: 'Remove last column' })).toBeDisabled();
    await page.getByRole('button', { name: 'Add a column' }).click();
    await expect(page.getByRole('group', { name: 'Number of columns' }).getByRole('button', { name: '2', exact: true })).toHaveAttribute(
        'aria-pressed',
        'true',
    );

    // Toolbar: move Columns above the hero, then remove it (undo brings it back).
    await page.getByRole('toolbar').getByRole('button', { name: 'Move Columns up' }).click();
    await openLayers(page);
    expect(await outline(page)).toEqual(['columns<page', 'column<columns', 'column<columns', 'hero<page']);
    await page.getByRole('button', { name: 'Remove Columns' }).click();
    expect(await outline(page)).toEqual(['hero<page']);
    await page.getByRole('button', { name: 'Undo', exact: true }).click();
    expect(await outline(page)).toEqual(['columns<page', 'column<columns', 'column<columns', 'hero<page']);
    await expect(layers(page)).toHaveCount(4);
});

test('an unfinished link is kept and flagged, and blocks Preview and Publish until fixed or reverted', async ({ page }) => {
    const id = await createPage('/link-draft', 'Link draft');
    await page.goto(`/admin/editor/${id}`);
    await openLayers(page);
    await page.getByRole('button', { name: 'Add Button' }).click();
    await page.getByRole('tab', { name: 'Properties' }).click();
    await page.getByLabel('Link').fill('/contact');
    await page.getByRole('button', { name: 'Save draft' }).click();
    await expect(status(page)).toHaveText('Draft saved');

    // Half-typed: not applied to the page, and the editor says so instead of "Draft saved".
    await page.getByLabel('Link').fill('https://');
    await expect(page.getByRole('alert').filter({ hasText: 'Use a link starting with' })).toBeVisible();
    await expect(status(page)).toHaveText('1 invalid field not saved');
    await page.keyboard.press('ControlOrMeta+s');
    await expect(status(page)).toHaveText('1 invalid field not saved');
    // Valid changes still save, without claiming the link was saved too.
    await page.getByLabel('Label').fill('Talk to us');
    await expect(status(page)).toHaveText('Unsaved changes, 1 invalid field not saved');
    await page.getByRole('button', { name: 'Save draft' }).click();
    await expect(status(page)).toHaveText('1 invalid field not saved');
    await expect(canvas(page).locator('a.ak-btn2')).toHaveAttribute('href', '/contact');

    // Selecting something else and coming back keeps what was typed.
    await openLayers(page);
    await layer(page, 'hero').getByRole('button').first().click();
    await layer(page, 'button').getByRole('button').first().click();
    await page.getByRole('tab', { name: 'Properties' }).click();
    await expect(page.getByLabel('Link')).toHaveValue('https://');

    // Publish and Preview are blocked (no popup), and the field is shown again.
    await openLayers(page);
    const popups: Page[] = [];
    page.on('popup', (popup) => popups.push(popup));
    await page.getByRole('button', { name: 'Publish' }).click();
    await expect(notice(page)).toContainText('Fix or revert the Button link before publishing');
    await expect(page.getByLabel('Link')).toBeVisible();
    await page.getByRole('button', { name: 'Preview' }).click();
    await expect(notice(page)).toContainText('Fix or revert the Button link before previewing');
    expect(popups).toHaveLength(0);
    expect((await publicHtml(page, '/link-draft')).status).toBe(404);

    // Leaving warns: reload/close (beforeunload) and in-app navigation (declined here).
    expect(
        await page.evaluate(() => {
            const event = new Event('beforeunload', { cancelable: true });
            window.dispatchEvent(event);
            return event.defaultPrevented;
        }),
    ).toBe(true);
    page.once('dialog', (dialog) => void dialog.dismiss());
    await page.getByRole('link', { name: 'Arkon' }).click();
    await expect(page).toHaveURL(new RegExp(`/admin/editor/${id}$`));
    await expect(page.getByLabel('Link')).toHaveValue('https://');

    // Revert puts the applied link back; fixing it applies the new one.
    await page.getByRole('button', { name: 'Revert link' }).click();
    await expect(page.getByLabel('Link')).toHaveValue('/contact');
    await expect(status(page)).toHaveText('Draft saved');
    await page.getByLabel('Link').fill('https://example.com/');
    await expect(status(page)).toHaveText('Unsaved changes');
    await page.getByRole('button', { name: 'Publish' }).click();
    await expect(notice(page)).toContainText('Published');
    expect((await publicHtml(page, '/link-draft')).html).toContain('href="https://example.com/"');
});

/** A page with a saved button linking to /contact, its label changed (unsaved), the button selected in Properties. */
async function buttonWithUnsavedLabel(page: Page, path: string): Promise<string> {
    const id = await createPage(path, 'Held action');
    await page.goto(`/admin/editor/${id}`);
    await openLayers(page);
    await page.getByRole('button', { name: 'Add Button' }).click();
    await page.getByRole('tab', { name: 'Properties' }).click();
    await page.getByLabel('Link').fill('/contact');
    await page.getByRole('button', { name: 'Save draft' }).click();
    await expect(status(page)).toHaveText('Draft saved');
    await page.getByLabel('Label').fill('Get in touch');
    await expect(status(page)).toHaveText('Unsaved changes');
    return id;
}

test('a link made invalid while Publish waits for its save blocks the publication', async ({ page }) => {
    const id = await buttonWithUnsavedLabel(page, '/held-publish');
    const publishRequests: string[] = [];
    page.on('request', (request) => {
        if (isAction(request, 'publish')) publishRequests.push(request.url());
    });
    const slow = await interceptNext(page, 'save', 'delay');
    const saveSent = page.waitForRequest((request) => isAction(request, 'save'));
    await page.getByRole('button', { name: 'Publish' }).click();
    await saveSent;
    // While the save is held, the link becomes unfinished.
    await page.getByLabel('Link').fill('https://');
    slow.release();

    await expect(notice(page)).toContainText('Fix or revert the Button link before publishing');
    await expect(status(page)).toHaveText('1 invalid field not saved');
    await expect(page.getByLabel('Link')).toHaveValue('https://');
    await expect(page.getByLabel('Link')).toHaveAttribute('aria-invalid', 'true');
    expect(publishRequests).toEqual([]);
    expect(await publicationCount(id)).toBe(0);
    expect((await publicHtml(page, '/held-publish')).status).toBe(404);
    await slow.stop();

    // Fixed: publishing works normally, with the label saved while it was held.
    await page.getByLabel('Link').fill('https://example.com/');
    await page.getByRole('button', { name: 'Publish' }).click();
    await expect(notice(page)).toContainText('Published');
    expect(await publicationCount(id)).toBe(1);
    const live = await publicHtml(page, '/held-publish');
    expect(live.html).toContain('<a class="ak-btn2 ak-btn2--primary" href="https://example.com/">Get in touch</a>');
});

test('a link made invalid while Preview waits for its save closes the preview instead of showing it', async ({ page }) => {
    const id = await buttonWithUnsavedLabel(page, '/held-preview');
    const slow = await interceptNext(page, 'save', 'delay');
    const saveSent = page.waitForRequest((request) => isAction(request, 'save'));
    const popupOpened = page.waitForEvent('popup');
    await page.getByRole('button', { name: 'Preview' }).click();
    const popup = await popupOpened;
    const popupRequests: string[] = [];
    popup.on('request', (request) => popupRequests.push(request.url()));
    await saveSent;
    await page.getByLabel('Link').fill('https://');
    slow.release();

    await expect(notice(page)).toContainText('Fix or revert the Button link before previewing');
    await expect.poll(() => popup.isClosed()).toBe(true);
    expect(popupRequests.filter((url) => url.includes('/preview/'))).toEqual([]);
    await expect(status(page)).toHaveText('1 invalid field not saved');
    await expect(page.getByLabel('Link')).toHaveValue('https://');
    await slow.stop();

    // Reverted: the preview opens normally and shows the saved draft.
    await page.getByRole('button', { name: 'Revert link' }).click();
    await expect(status(page)).toHaveText('Draft saved');
    const next = page.waitForEvent('popup');
    await page.getByRole('button', { name: 'Preview' }).click();
    const preview = await next;
    await expect(preview).toHaveURL(new RegExp(`/preview/${id}$`));
    await expect(preview.locator('a.ak-btn2')).toHaveText('Get in touch');
    await expect(preview.locator('a.ak-btn2')).toHaveAttribute('href', '/contact');
    await preview.close();
});

test('image widths set with the design controls match in the canvas, preview and live page', async ({ page }) => {
    const id = await createPage('/image-sizes', 'Image sizes');
    await page.goto(`/admin/editor/${id}`);
    await openLayers(page);
    await addColumns(page);
    await page.getByRole('tab', { name: 'Properties' }).click();
    await page.getByRole('button', { name: 'Remove last column' }).click(); // one full-width column
    await openLayers(page);
    await layer(page, 'column').getByRole('button').first().click();
    for (let i = 0; i < 3; i++) await page.getByRole('button', { name: 'Add Image' }).click();

    const widths = ['', '66%', '40%'];
    for (const [i, width] of widths.entries()) {
        await openLayers(page);
        await layer(page, 'image', i).getByRole('button').first().click();
        await page.getByRole('tab', { name: 'Properties' }).click();
        if (i === 0) await page.getByLabel('Upload image').setInputFiles({ name: 'dot.png', mimeType: 'image/png', buffer: PNG_1X1 });
        else {
            await page.getByRole('button', { name: 'Choose from library' }).click();
            await page.getByTestId('media-library').getByRole('button').first().click();
        }
        await page.getByLabel(/Alternative text/).fill(`Dot ${i}`);
        if (width) {
            // The block's width (not the image's): the Image block part has its own size controls.
            await page.getByTestId('part-root').click();
            await page.getByTestId('block-sizing').getByLabel('Block width', { exact: true }).fill(width);
        }
    }

    // Width of each image block as a percentage of its column.
    const percentages = (root: Page | FrameLocator) =>
        root
            .locator('.ak-col > .ak-img2')
            .evaluateAll((els) => els.map((el) => Math.round((el.getBoundingClientRect().width / el.parentElement!.getBoundingClientRect().width) * 100)));
    const expectSizes = async (root: Page | FrameLocator) => {
        await expect.poll(async () => (await percentages(root)).join(',')).toBe('100,66,40');
    };
    await expectSizes(canvas(page));

    await page.getByRole('button', { name: 'Save draft' }).click();
    await expect(status(page)).toHaveText('Draft saved');
    const preview = await page.context().newPage();
    await preview.goto(`/preview/${id}`);
    await expectSizes(preview);

    await page.getByRole('button', { name: 'Publish' }).click();
    await expect(notice(page)).toContainText('Published');
    await preview.goto('/image-sizes');
    await expectSizes(preview);
    const live = await publicHtml(page, '/image-sizes');
    expect(live.html).toMatch(/\.ak-s[0-9a-f]{10}\{width:40%\}/);
    await preview.close();
});

test('a Columns block never gives away its last column, but columns move between blocks that keep one', async ({ page }) => {
    const id = await createPage('/columns-transfer', 'Columns transfer');
    await page.goto(`/admin/editor/${id}`);
    await openLayers(page);
    await addColumns(page); // block 1, selected
    await page.getByRole('tab', { name: 'Properties' }).click();
    await page.getByRole('button', { name: 'Remove last column' }).click(); // block 1 has one column
    await openLayers(page);
    await addColumns(page); // block 2, two columns
    const start = ['hero<page', 'columns<page', 'column<columns', 'columns<page', 'column<columns', 'column<columns'];
    expect(await outline(page)).toEqual(start);

    // Block 1's only column is not offered as a drop into block 2: nothing changes, no error.
    await drop(page, layer(page, 'column', 0), layer(page, 'column', 1), 'after');
    expect(await outline(page)).toEqual(start);
    await expect(notice(page)).toHaveCount(0);

    // Block 2 has two: one of them may move into block 1.
    await drop(page, layer(page, 'column', 2), layer(page, 'column', 0), 'after');
    expect(await outline(page)).toEqual(['hero<page', 'columns<page', 'column<columns', 'column<columns', 'columns<page', 'column<columns']);
    await expect(notice(page)).toHaveCount(0);
    await page.getByRole('button', { name: 'Save draft' }).click();
    await expect(status(page)).toHaveText('Draft saved');
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
        await addColumns(page);
        await page.getByRole('button', { name: 'Save draft' }).click();
        await expect(status(page)).toHaveText('Draft saved');
        await expect(page.getByRole('button', { name: 'Publish' })).toBeDisabled();
        expect((await publicHtml(page, '/builder-editor')).status).toBe(404);
    });
});
