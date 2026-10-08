import { expect, test } from '@playwright/test';
import { BASE_URL, createPage } from './support';
import { PNG_1X1 } from './fixtures';

test('theme component is editable, saved and published without theme JavaScript', async ({ page }) => {
    const id = await createPage('/theme-example', 'Theme example');
    await page.goto('/admin/themes');
    await page.getByRole('article', { name: 'MySite', exact: true }).getByRole('button', { name: 'Activate for editing', exact: true }).click();
    await expect(page.getByRole('status')).toContainText('Theme activated for editing');
    await page.getByRole('link', { name: 'Pages', exact: true }).click();
    await page.locator(`a[href="/admin/editor/${id}"]`).click();

    await page.getByRole('tab', { name: 'Layers' }).click();
    await page.getByRole('button', { name: 'Add Testimonial', exact: true }).click();
    const canvas = page.frameLocator('[data-testid="canvas"]');
    await expect(canvas.getByText('Alex Morgan')).toBeVisible();
    await page.getByRole('tab', { name: 'Properties' }).click();
    await page.getByLabel('Quote', { exact: true }).fill('A component created outside the CMS.');
    await page.getByLabel('Author', { exact: true }).fill('Jamie Rivera');
    await page.getByLabel('Alignment', { exact: true }).selectOption('center');
    await expect(canvas.getByText('Jamie Rivera', { exact: true })).toBeVisible();
    await page.getByLabel('Upload image').setInputFiles({ name: 'portrait.png', mimeType: 'image/png', buffer: PNG_1X1 });
    await expect(canvas.locator('figure.at-theme-mysite-testimonial-v1 img')).toBeVisible();
    await page.getByLabel('Author photo alternative text (required to publish)').fill('Portrait of Jamie');
    await page.getByTestId('part-quote').click();
    await page.getByText('Typography', { exact: true }).click();
    await page.getByLabel('Font size', { exact: true }).fill('32px');
    await expect.poll(() => canvas.locator('blockquote').evaluate((el) => getComputedStyle(el).fontSize)).toBe('32px');
    // The template's inline binding uses the existing canvas editing protocol.
    await canvas.locator('figcaption.author').dblclick();
    await canvas.locator('figcaption.author').press('ControlOrMeta+a');
    await page.keyboard.insertText('Jamie Rivera, designer');
    await page.keyboard.press('Escape');
    await expect(page.getByLabel('Author', { exact: true })).toHaveValue('Jamie Rivera, designer');
    await page.getByTestId('duplicate-block').click();
    await expect(canvas.getByText('Jamie Rivera, designer', { exact: true })).toHaveCount(2);
    await page.locator('header').getByRole('button', { name: 'Undo', exact: true }).click();
    await expect(canvas.getByText('Jamie Rivera, designer', { exact: true })).toHaveCount(1);
    await page.locator('header').getByRole('button', { name: 'Redo', exact: true }).click();
    await expect(canvas.getByText('Jamie Rivera, designer', { exact: true })).toHaveCount(2);
    await page.getByTestId('canvas-delete').click();
    await expect(canvas.getByText('Jamie Rivera, designer', { exact: true })).toHaveCount(1);
    await page.getByRole('button', { name: 'Save draft', exact: true }).click();
    await expect(page.getByTestId('save-status')).toHaveText('Draft saved');
    await page.reload();
    await expect(canvas.getByText('Jamie Rivera, designer', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Publish', exact: true }).click();
    await expect(page.getByTestId('notice')).toContainText('Published');
    const context = await page.context().browser()!.newContext({ baseURL: BASE_URL });
    try {
        const response = await context.request.get('/theme-example');
        const html = await response.text();
        expect(response.status()).toBe(200);
        expect(html).toContain('A component created outside the CMS.');
        expect(html).toContain('Jamie Rivera, designer');
        expect(html).toContain('alt="Portrait of Jamie"');
        expect(html).toContain('at-theme-mysite-testimonial-v1');
        expect(html).not.toMatch(/<script|data-ak-|data-field|\/build\/assets/);
    } finally {
        await context.close();
    }
});
