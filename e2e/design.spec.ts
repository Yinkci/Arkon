// The visual builder's design features in the browser: the design acceptance request through the
// AI panel (fake Claude Code CLI), the shared design controls with responsive overrides, drag and
// drop on the canvas, site design tokens and reusable components with their published dependants.
import { expect, test, type FrameLocator, type Page } from '@playwright/test';
import { PNG_1X1 } from './fixtures';
import { BASE_URL, createPage, db } from './support';

const ACCEPTANCE =
    'Keep the hero text on the left. Put its image on the right, make the image 500px tall with cover cropping, and stack the image below the text on mobile.';

const status = (page: Page) => page.getByTestId('save-status');
const notice = (page: Page) => page.getByTestId('notice');
const canvas = (page: Page) => page.frameLocator('[data-testid="canvas"]');
const proposal = (page: Page) => page.getByTestId('ai-proposal');
const design = (page: Page) => page.getByTestId('design-panel');
const viewport = (page: Page, name: 'desktop' | 'tablet' | 'mobile') => page.getByRole('group', { name: 'Viewport' }).getByRole('button', { name });
const tokenInput = (page: Page, token: string) => page.getByTestId(token).locator('input:not([type=color])');

async function ask(page: Page, prompt: string) {
    await page.getByRole('tab', { name: 'AI' }).click();
    await page.getByLabel('Ask AI to change this page').fill(prompt);
    await page.getByRole('button', { name: 'Generate proposal' }).click();
    await expect(page.locator('[data-testid="ai-request"][data-status="queued"], [data-testid="ai-request"][data-status="running"]')).toHaveCount(0, {
        timeout: 30_000,
    });
}

/** A browser page on the public site (no session) at a given viewport width. */
async function livePage(page: Page, path: string, width: number) {
    const context = await page
        .context()
        .browser()!
        .newContext({ baseURL: BASE_URL, storageState: { cookies: [], origins: [] }, viewport: { width, height: 900 } });
    const live = await context.newPage();
    const response = await live.goto(path);
    return { live, response, close: () => context.close() };
}

/** Where the hero's image is relative to its text, and how it is sized. */
async function heroGeometry(root: Page | FrameLocator) {
    return root.locator('section.ak-hero3').evaluate((section) => {
        const content = section.querySelector('.ak-hero3__content')!.getBoundingClientRect();
        const img = section.querySelector('img')!;
        const image = img.getBoundingClientRect();
        return {
            imageRight: image.left >= content.right - 1,
            imageBelow: image.top >= content.bottom - 1,
            imageAbove: image.bottom <= content.top + 1,
            height: Math.round(image.height),
            fit: getComputedStyle(img).objectFit,
        };
    });
}

test('the design acceptance request: proposal, apply, reload, manual edit, previews and published output agree', async ({ page }) => {
    const id = await createPage('/design-hero', 'Fresh gardens');
    await page.goto(`/admin/editor/${id}`);
    // The hero gets its image (images on the page are what the AI may use).
    await page.getByTestId('edit-hero-image').click();
    await page.getByLabel('Upload image').setInputFiles({ name: 'garden.png', mimeType: 'image/png', buffer: PNG_1X1 });
    await page.getByLabel(/Alternative text/).fill('A garden in spring');
    await page.getByRole('button', { name: 'Save draft' }).click();
    await expect(status(page)).toHaveText('Draft saved');

    await ask(page, ACCEPTANCE);
    await expect(proposal(page)).toBeVisible();
    await expect(page.getByTestId('ai-changes')).toContainText('Change Hero “Fresh gardens”: style');
    // The preview shows the proposed layout before anything changes.
    await expect.poll(() => heroGeometry(canvas(page))).toEqual({ imageRight: true, imageBelow: false, imageAbove: false, height: 500, fit: 'cover' });
    const before = (await db.query<{ document: string }>('select document::text from page_drafts where page_id = $1', [id])).rows[0]!.document;
    expect(before).not.toContain('500px');

    // Applying is a normal save; it survives a reload.
    await proposal(page).getByRole('button', { name: 'Apply to draft' }).click();
    await expect(status(page)).toHaveText('Draft saved');
    await page.reload();
    await expect.poll(() => heroGeometry(canvas(page))).toEqual({ imageRight: true, imageBelow: false, imageAbove: false, height: 500, fit: 'cover' });

    // The settings are visible and editable in the inspector, per screen size.
    await page.getByRole('tab', { name: 'Properties' }).click();
    await expect(page.getByTestId('hero-image-position')).toHaveValue('row');
    await viewport(page, 'mobile').click();
    await expect(page.getByTestId('hero-image-position')).toHaveValue('column');
    await expect.poll(async () => (await heroGeometry(canvas(page))).imageBelow).toBe(true);
    expect((await heroGeometry(canvas(page))).height).toBe(500);
    // A manual change on mobile (image above the text), then undone.
    await page.getByTestId('hero-image-position').selectOption('column-reverse');
    await expect.poll(async () => (await heroGeometry(canvas(page))).imageAbove).toBe(true);
    await page.getByRole('button', { name: 'Undo' }).click();
    await expect.poll(async () => (await heroGeometry(canvas(page))).imageBelow).toBe(true);
    await expect(page.getByTestId('hero-image-position')).toHaveValue('column');
    // The image height is with the image's own controls, inherited on mobile from all screens.
    await page.getByTestId('part-media').click();
    await expect(page.getByTestId('image-sizing').getByTestId('style-height')).toContainText('From all screens: 500px');
    await viewport(page, 'desktop').click();

    // Publish: the live page matches on a wide and a narrow screen.
    await page.getByRole('button', { name: 'Publish' }).click();
    await expect(notice(page)).toContainText('Published');
    const wide = await livePage(page, '/design-hero', 1280);
    expect(await heroGeometry(wide.live)).toEqual({ imageRight: true, imageBelow: false, imageAbove: false, height: 500, fit: 'cover' });
    const html = await wide.response!.text();
    expect(html).not.toMatch(/<script(?! type="application\/ld\+json")/i);
    for (const forbidden of ['data-ak-', '/build/', 'contenteditable']) expect(html).not.toContain(forbidden);
    await wide.close();
    const narrow = await livePage(page, '/design-hero', 390);
    expect(await heroGeometry(narrow.live)).toMatchObject({ imageBelow: true, height: 500, fit: 'cover' });
    await narrow.close();
});

test('design controls: values per screen size, inherited on smaller screens until overridden, reset, undo', async ({ page }) => {
    const id = await createPage('/design-controls', 'Controls');
    await page.goto(`/admin/editor/${id}`);
    await page.getByRole('tab', { name: 'Layers' }).click();
    await page.getByRole('button', { name: 'Add Text' }).click();
    await page.getByRole('tab', { name: 'Properties' }).click();
    const paragraph = canvas(page).locator('p.ak-text2');
    const computed = (property: string) => paragraph.evaluate((el, p) => getComputedStyle(el).getPropertyValue(p), property);

    // All screens: a colour and a size.
    await design(page).getByText('Typography').click();
    await design(page).getByLabel('Text colour', { exact: true }).fill('#b91c1c');
    await design(page).getByLabel('Font size', { exact: true }).fill('24px');
    await expect.poll(() => computed('color')).toBe('rgb(185, 28, 28)');
    await expect.poll(() => computed('font-size')).toBe('24px');
    // An invalid value is refused and never applied.
    await design(page).getByLabel('Font size', { exact: true }).fill('24 px; color: red');
    await expect(design(page).getByRole('alert')).toContainText('Font size: expected a length');
    await design(page).getByLabel('Font size', { exact: true }).fill('24px');

    // Mobile inherits, then overrides only the size.
    await viewport(page, 'mobile').click();
    await expect(design(page).getByTestId('style-fontSize')).toContainText('From all screens: 24px');
    await design(page).getByLabel('Font size', { exact: true }).fill('18px');
    await expect.poll(() => computed('font-size')).toBe('18px');
    await expect.poll(() => computed('color')).toBe('rgb(185, 28, 28)');
    await viewport(page, 'desktop').click();
    await expect.poll(() => computed('font-size')).toBe('24px');
    await viewport(page, 'mobile').click();
    await design(page).getByRole('button', { name: 'Reset Font size on mobile' }).click();
    await expect.poll(() => computed('font-size')).toBe('24px');
    await page.getByRole('button', { name: 'Undo' }).click();
    await expect.poll(() => computed('font-size')).toBe('18px');

    await page.getByRole('button', { name: 'Save draft' }).click();
    await expect(status(page)).toHaveText('Draft saved');
    const stored = (
        await db.query<{ document: { nodes: Record<string, { type: string; props: { style?: unknown } }> } }>(
            'select document from page_drafts where page_id = $1',
            [id],
        )
    ).rows[0]!.document;
    const text = Object.values(stored.nodes).find((n) => n.type === 'text')!;
    expect(text.props.style).toEqual({ root: { base: { color: '#b91c1c', fontSize: '24px' }, mobile: { fontSize: '18px' } } });
});

test('drag and drop on the canvas moves a block with placement feedback', async ({ page }) => {
    const id = await createPage('/design-dnd', 'Drag me');
    await page.goto(`/admin/editor/${id}`);
    await page.getByRole('tab', { name: 'Layers' }).click();
    await page.getByRole('button', { name: 'Add Text' }).click(); // after the hero, selected
    await expect(canvas(page).locator('p.ak-text2')).toBeVisible();
    const order = () => page.evaluate(() => [...document.querySelectorAll<HTMLElement>('[data-testid="layer"]')].map((row) => row.dataset.nodeType));
    expect(await order()).toEqual(['hero', 'text']);

    const handle = page.getByTestId('canvas-drag-handle');
    await expect(handle).toBeVisible();
    const from = (await handle.boundingBox())!;
    const hero = (await canvas(page).locator('section.ak-hero3').boundingBox())!;
    await page.mouse.move(from.x + from.width / 2, from.y + from.height / 2);
    await page.mouse.down();
    await page.mouse.move(hero.x + hero.width / 2, hero.y + 12, { steps: 8 });
    await expect(page.getByTestId('drop-indicator')).toHaveAttribute('data-position', 'between');
    await expect(page.getByTestId('drag-status')).toContainText('Before Hero');
    await page.mouse.up();
    await expect.poll(order).toEqual(['text', 'hero']);
    await expect(canvas(page).locator('main > *').first()).toHaveClass(/ak-text2/);

    // A palette item dragged onto the canvas lands where it is dropped.
    const groupButton = (await page.getByRole('button', { name: 'Add Group' }).boundingBox())!;
    const target = (await canvas(page).locator('section.ak-hero3').boundingBox())!;
    await page.mouse.move(groupButton.x + groupButton.width / 2, groupButton.y + groupButton.height / 2);
    await page.mouse.down();
    await page.mouse.move(target.x + 40, target.y + target.height - 10, { steps: 12 });
    await page.mouse.move(target.x + 44, target.y + target.height - 8, { steps: 4 });
    await expect(page.getByTestId('drop-indicator')).toHaveAttribute('data-position', 'between');
    await page.mouse.up();
    await expect.poll(order).toEqual(['text', 'hero', 'group']);
    await page.getByRole('button', { name: 'Undo' }).click();
    await page.getByRole('button', { name: 'Undo' }).click();
    await expect.poll(order).toEqual(['hero', 'text']);
});

test('design tokens: the draft stays private; publishing updates the live page', async ({ page }) => {
    const id = await createPage('/design-tokens', 'Tokens');
    await page.goto(`/admin/editor/${id}`);
    await page.getByRole('button', { name: 'Publish' }).click();
    await expect(notice(page)).toContainText('Published');
    const liveCss = async () => {
        const visit = await livePage(page, '/design-tokens', 1280);
        const html = await visit.response!.text();
        await visit.close();
        return html;
    };
    const initial = await liveCss();
    expect(initial).toMatch(/--ak-t-color-primary:#[0-9a-f]{6}/);

    await page.goto('/admin/design');
    const primary = tokenInput(page, 'token-color-primary');
    await primary.fill('#1d4ed8');
    await page.getByTestId('tokens-save').click();
    await expect(page.getByRole('status')).toContainText('Token draft saved');
    expect(await liveCss()).toBe(initial);

    await page.getByTestId('tokens-publish').click();
    await expect(page.getByRole('status')).toContainText('Published design tokens version');
    await expect(page.getByRole('status')).toContainText(/\d+ updated/);
    await expect(page.getByRole('status')).not.toContainText('failed');
    expect(await liveCss()).toContain('--ak-t-color-primary:#1d4ed8');
});

test('reusable components: made from a block, edited and published once, updating the page that uses it', async ({ page }) => {
    const id = await createPage('/design-components', 'Components');
    await page.goto(`/admin/editor/${id}`);
    await page.getByRole('tab', { name: 'Layers' }).click();
    await page.getByRole('button', { name: 'Add Text' }).click();
    await canvas(page).locator('p.ak-text2').click();
    await page.keyboard.press('ControlOrMeta+A');
    await page.keyboard.type('Free delivery on every order');
    await page.getByRole('tab', { name: 'Properties' }).click();
    await page.getByTestId('make-reusable').click();
    await page.getByLabel('Name of the reusable component').fill('Delivery banner');
    await page.getByRole('button', { name: 'Create', exact: true }).click();
    await expect(notice(page)).toContainText('is now a reusable component');
    await page.getByRole('tab', { name: 'Layers' }).click();
    await expect(page.locator('[data-testid="layer"][data-node-type="instance"]')).toHaveCount(1);
    await expect(canvas(page).locator('.ak-instance')).toContainText('Free delivery on every order');
    await page.getByRole('button', { name: 'Publish' }).click();
    await expect(notice(page)).toContainText('Published');

    // Edit the component on its own page and publish it: the live page follows.
    await page.goto('/admin/design/components');
    await page.getByRole('link', { name: 'Delivery banner' }).click();
    await expect(page.getByTestId('component-status')).toContainText('Pages show v1');
    await canvas(page).locator('p.ak-text2').click();
    await page.keyboard.press('ControlOrMeta+A');
    await page.keyboard.type('Free delivery and returns');
    await page.getByTestId('component-publish').click();
    await expect(page.getByRole('status')).toContainText('Published version 2. 1 live page uses it: 1 updated');
    const visit = await livePage(page, '/design-components', 1280);
    await expect(visit.live.locator('.ak-instance')).toHaveText('Free delivery and returns');
    await visit.close();
});

test('AI token changes are shown apart from page changes and only reach the token draft', async ({ page }) => {
    const id = await createPage('/design-ai-tokens', 'Brand');
    await page.goto(`/admin/editor/${id}`);
    await ask(page, 'MOCK-TOKENS make the brand teal');
    await expect(page.getByTestId('ai-token-changes')).toContainText('@color.primary');
    await expect(page.getByTestId('ai-token-changes')).toContainText('#0f766e');
    await expect(proposal(page).getByRole('button', { name: 'Apply to draft' })).toHaveCount(0);
    await page.getByTestId('ai-apply-tokens').click();
    await expect(page.getByTestId('ai-token-changes')).toContainText('Applied to the token draft');
    await page.goto('/admin/design');
    await expect(tokenInput(page, 'token-color-primary')).toHaveValue('#0f766e');
    await expect(page.getByText('The draft differs from what is live.')).toBeVisible();
});
