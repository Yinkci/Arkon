// Clear inspector targets: a component's parts (here a hero's image) are edited as parts of
// that component, with their own clearly named controls, separately from the section around
// them. Sizes set on the image change the image only; the section has its own sizes; mobile
// overrides inherit and reset; canvas, preview and the published page agree.
import { expect, test, type FrameLocator, type Page } from '@playwright/test';
import { BASE_URL, createPage } from './support';

const canvas = (page: Page) => page.frameLocator('[data-testid="canvas"]');
const status = (page: Page) => page.getByTestId('save-status');
const target = (page: Page) => page.getByTestId('inspector-target');
const viewport = (page: Page, name: 'Desktop' | 'Tablet' | 'Mobile') => page.getByRole('group', { name: 'Viewport' }).getByRole('button', { name });

/** A photo-like image made by the browser (1200 × 800), so sizes and cropping are visible. */
async function photo(page: Page): Promise<Buffer> {
    const scratch = await page.context().newPage();
    await scratch.setViewportSize({ width: 1200, height: 800 });
    await scratch.setContent(
        '<body style="margin:0"><div style="width:1200px;height:800px;background:linear-gradient(180deg,#9cc9e8,#6f9c5a 55%,#2c4a2b)"></div></body>',
    );
    const buffer = await scratch.screenshot();
    await scratch.close();
    return buffer;
}

async function geometry(root: Page | FrameLocator) {
    return root.locator('section.ak-hero3').evaluate((section) => {
        const img = section.querySelector('img')!;
        const r = img.getBoundingClientRect();
        return {
            section: Math.round(section.getBoundingClientRect().width),
            width: Math.round(r.width),
            height: Math.round(r.height),
            fit: getComputedStyle(img).objectFit,
        };
    });
}

test('the image is its own editing target: sizes, fit, a mobile override and the section size stay separate everywhere', async ({ page }) => {
    test.setTimeout(120_000);
    await page.setViewportSize({ width: 1440, height: 900 });
    const id = await createPage('/inspector-image', 'Gardens for every season');
    await page.goto(`/admin/editor/${id}`);
    await page.getByTestId('edit-hero-image').click();
    await page.getByLabel('Upload image').setInputFiles({ name: 'garden.png', mimeType: 'image/png', buffer: await photo(page) });
    await expect(page.getByTestId('media-picker')).toContainText('garden.png');
    await page.getByLabel(/Alternative text/).fill('A garden in spring');
    await page.getByRole('button', { name: 'Save draft' }).click();
    await expect(status(page)).toHaveText('Draft saved');

    // 1–2. Clicking the image on the canvas makes the image the target, named and highlighted.
    await page.getByTestId('inspector-target-parent').click();
    await expect(target(page)).toHaveText('Hero section');
    await canvas(page).locator('section.ak-hero3 img').click();
    await expect(target(page)).toHaveText('Image');
    await expect(page.getByTestId('inspector-target-header')).toContainText('Part of the hero section');
    await expect(page.getByTestId('part-media')).toHaveAttribute('aria-pressed', 'true');
    await expect(page.getByTestId('canvas-part-highlight')).toHaveAttribute('data-label', 'Image');
    const sizing = page.getByTestId('image-sizing');
    await expect(sizing.getByLabel('Image height', { exact: true })).toBeVisible();
    await expect(sizing.getByLabel('Image width', { exact: true })).toBeVisible();
    const before = await geometry(canvas(page));

    // 3. Image height 500 px with cover cropping.
    await sizing.getByLabel('Image height', { exact: true }).fill('500');
    await expect(sizing.getByLabel('Image height unit')).toHaveValue('px');
    await page.getByTestId('image-fit').getByLabel('Fit', { exact: true }).selectOption('cover');
    await expect.poll(() => geometry(canvas(page))).toMatchObject({ height: 500, fit: 'cover' });

    // 4. The image width changes the image only, not the section.
    await sizing.getByLabel('Image width', { exact: true }).fill('320');
    await expect.poll(async () => (await geometry(canvas(page))).width).toBe(320);
    expect((await geometry(canvas(page))).section).toBe(before.section);
    // Undo and redo the width (one step each).
    await page.getByRole('button', { name: 'Undo' }).click();
    await expect.poll(async () => (await geometry(canvas(page))).width).not.toBe(320);
    await expect(sizing.getByLabel('Image width', { exact: true })).toHaveValue('');
    await page.getByRole('button', { name: 'Redo' }).click();
    await expect.poll(async () => (await geometry(canvas(page))).width).toBe(320);

    // 5. The hero section has its own size, named for it.
    await page.getByTestId('inspector-target-parent').click();
    await expect(target(page)).toHaveText('Hero section');
    await page.getByTestId('section-sizing').getByLabel('Section width', { exact: true }).fill('900');
    await expect.poll(async () => (await geometry(canvas(page))).section).toBe(900);
    expect(await geometry(canvas(page))).toMatchObject({ width: 320, height: 500 });

    // 6. A mobile image override, shown as such, then reset back to the inherited value.
    await page.getByTestId('part-media').click();
    await viewport(page, 'Mobile').click();
    const height = page.getByTestId('image-sizing').getByTestId('style-height');
    await expect(height).toContainText('From all screens: 500px');
    await height.getByLabel('Image height', { exact: true }).fill('300');
    await expect(height).toContainText('Mobile override');
    await expect.poll(async () => (await geometry(canvas(page))).height).toBe(300);
    await height.getByRole('button', { name: 'Reset Image height on mobile' }).click();
    await expect(height).toContainText('From all screens: 500px');
    await expect.poll(async () => (await geometry(canvas(page))).height).toBe(500);
    await viewport(page, 'Desktop').click();

    // 8. Saved, and still there after a reload.
    await page.getByRole('button', { name: 'Save draft' }).click();
    await expect(status(page)).toHaveText('Draft saved');
    await page.reload();
    await canvas(page).locator('section.ak-hero3 img').click();
    await expect(page.getByTestId('image-sizing').getByLabel('Image height', { exact: true })).toHaveValue('500');
    await expect(page.getByTestId('image-sizing').getByLabel('Image width', { exact: true })).toHaveValue('320');
    await expect.poll(() => geometry(canvas(page))).toEqual({ section: 900, width: 320, height: 500, fit: 'cover' });

    // 7. The preview and the published page match the canvas.
    const popup = page.waitForEvent('popup');
    await page.getByRole('button', { name: 'Preview' }).click();
    const preview = await popup;
    await preview.setViewportSize({ width: 1440, height: 900 });
    await preview.waitForURL(/\/preview\//);
    expect(await geometry(preview)).toEqual({ section: 900, width: 320, height: 500, fit: 'cover' });
    await preview.close();
    await page.getByRole('button', { name: 'Publish' }).click();
    await expect(page.getByTestId('notice')).toContainText('Published');
    const context = await page
        .context()
        .browser()!
        .newContext({ baseURL: BASE_URL, storageState: { cookies: [], origins: [] }, viewport: { width: 1440, height: 900 } });
    const live = await context.newPage();
    await live.goto('/inspector-image');
    expect(await geometry(live)).toEqual({ section: 900, width: 320, height: 500, fit: 'cover' });
    await live.setViewportSize({ width: 390, height: 844 });
    expect(await geometry(live)).toMatchObject({ height: 500, fit: 'cover' });
    await context.close();
});

test('the inspector names every part and keeps text editing on the canvas', async ({ page }) => {
    const id = await createPage('/inspector-parts', 'Named parts');
    await page.goto(`/admin/editor/${id}`);
    const heading = canvas(page).locator('h1');
    await heading.click();
    // Clicking the heading selects the heading part and still edits text in place.
    await expect(target(page)).toHaveText('Heading');
    await expect(heading).toHaveAttribute('contenteditable', 'plaintext-only');
    await page.keyboard.press('End');
    await page.keyboard.type(' today');
    await expect(page.getByLabel('Heading', { exact: true })).toHaveValue('Named parts today');
    // Breadcrumb back to the component and to the page.
    await page.getByTestId('inspector-target-parent').click();
    await expect(target(page)).toHaveText('Hero section');
    await expect(page.getByRole('group', { name: 'Parts of the hero section' }).getByRole('button')).toHaveText([
        'Hero section',
        'Content area',
        'Heading',
        'Supporting text',
        'Buttons',
        'Image',
    ]);
    await page.getByRole('button', { name: 'Page settings' }).click();
    await expect(target(page)).toHaveText('Page settings');
});
