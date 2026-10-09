import { expect, test } from '@playwright/test';
import { PNG_1X1 } from './fixtures';
import { APP_ORIGIN, SERVER_URL } from './env';
test('media details save metadata, search, preserve canonical URL, and remove safely', async ({ page }) => {
    await page.goto('/admin/media');
    await page.getByLabel('Upload image file').setInputFiles({ name: 'metadata-check.png', mimeType: 'image/png', buffer: PNG_1X1 });
    const tile = page.getByRole('button', { name: 'Open metadata-check.png', exact: true });
    await expect(tile).toHaveCSS('cursor', 'pointer');
    await expect(tile).toContainText('Original PNG');
    await expect(page.getByLabel('Search images', { exact: true })).toHaveCSS('cursor', 'text');
    await tile.click();
    const dialog = page.getByRole('dialog', { name: 'Image details' });
    await expect(dialog.getByLabel('Title', { exact: true })).toBeEnabled();
    const original = await dialog.getByLabel('File URL', { exact: true }).inputValue();
    expect(original).not.toContain('?');
    await dialog.getByLabel('Title', { exact: true }).fill('Team review photograph');
    await dialog.getByLabel('Alt text', { exact: true }).fill('Colleagues discussing a design');
    await dialog.getByLabel('Default caption', { exact: true }).fill('Library caption');
    await expect(dialog.getByRole('button', { name: 'Close', exact: true })).toBeDisabled();
    await expect(dialog.getByRole('button', { name: 'Close', exact: true })).toHaveCSS('cursor', 'not-allowed');
    await expect(dialog.getByRole('button', { name: 'Save details', exact: true })).toHaveCSS('cursor', 'pointer');
    await dialog.getByRole('button', { name: 'Save details', exact: true }).click();
    await expect(dialog.getByText('Details saved.', { exact: true })).toBeVisible();
    await expect(dialog.getByLabel('File URL', { exact: true })).toHaveValue(original);
    await dialog.getByRole('button', { name: 'Copy URL', exact: true }).click();
    await expect(dialog.getByText('Link copied.', { exact: true })).toBeVisible();
    await page.screenshot({ path: 'C:/Users/jacob/Documents/Codex/2026-10-06/referenced-chatgpt-conversation-this-is-an/outputs/arkon-media-details.png' });
    await page.keyboard.press('Escape');
    await expect(dialog).not.toBeVisible();
    await page.getByLabel('Search images', { exact: true }).fill('Colleagues discussing');
    await expect(page.getByRole('button', { name: 'Open Team review photograph', exact: true })).toBeVisible();
    await expect(page).toHaveURL(/q=Colleagues/);
    await page.reload();
    await page.getByRole('button', { name: 'Open Team review photograph', exact: true }).click();
    await expect(dialog.getByLabel('Alt text', { exact: true })).toHaveValue('Colleagues discussing a design');
    await dialog.getByText('Remove image', { exact: true }).click();
    await dialog.getByRole('button', { name: 'Remove from library', exact: true }).click();
    await dialog.getByRole('button', { name: 'Confirm removal', exact: true }).click();
    await expect(dialog).not.toBeVisible();
    await expect(page.getByText('No matching images', { exact: true })).toBeVisible();
});
test('a delayed metadata read cannot overwrite edits and unsaved fields stay in the dialog', async ({ page }) => {
    await page.goto('/admin/media');
    await page.getByLabel('Upload image file').setInputFiles({ name: 'detail-race.png', mimeType: 'image/png', buffer: PNG_1X1 });
    let release!: () => void;
    const held = new Promise<void>((resolve) => {
        release = resolve;
    });
    await page.route('**/admin/api/media/*', async (route) => {
        if (route.request().method() === 'GET') await held;
        await route.continue();
    });
    await page.getByRole('button', { name: 'Open detail-race.png', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: 'Image details' });
    await expect(dialog.getByLabel('Title', { exact: true })).toBeDisabled();
    release();
    await expect(dialog.getByLabel('Title', { exact: true })).toBeEnabled();
    await dialog.getByLabel('Title', { exact: true }).fill('Unsaved title');
    await page.keyboard.press('Escape');
    await expect(dialog).toBeVisible();
    await dialog.getByRole('button', { name: 'Discard changes', exact: true }).click();
    await page.keyboard.press('Escape');
    await expect(dialog).not.toBeVisible();
});

test('metadata response loss retries the same save intent once', async ({ page }) => {
    await page.goto('/admin/media');
    await page.getByLabel('Upload image file').setInputFiles({ name: 'uncertain-save.png', mimeType: 'image/png', buffer: PNG_1X1 });
    await page.getByRole('button', { name: 'Open uncertain-save.png', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: 'Image details' });
    await expect(dialog.getByLabel('Title', { exact: true })).toBeEnabled();
    await dialog.getByLabel('Title', { exact: true }).fill('Confirmed retry');
    const bodies: string[] = [];
    page.on('request', (request) => {
        if (request.url().endsWith('/metadata')) bodies.push(request.postData()!);
    });
    await page.route(
        '**/admin/api/media/*/metadata',
        async (route) => {
            await route.fetch({
                url: SERVER_URL + new URL(route.request().url()).pathname,
                headers: { ...(await route.request().allHeaders()), host: new URL(APP_ORIGIN).host },
            });
            await route.abort('connectionreset');
        },
        { times: 1 },
    );
    await dialog.getByRole('button', { name: 'Save details', exact: true }).click();
    await expect(dialog.getByRole('alert')).toContainText('unconfirmed');
    await expect(dialog.getByLabel('Title', { exact: true })).toBeDisabled();
    await dialog.getByRole('button', { name: 'Save details', exact: true }).click();
    await expect(dialog.getByText('Details saved.', { exact: true })).toBeVisible();
    expect(bodies).toHaveLength(2);
    expect(bodies[1]).toBe(bodies[0]);
    await dialog.getByRole('button', { name: 'Close', exact: true }).click();
    await page.reload();
    await page.getByRole('button', { name: 'Open Confirmed retry', exact: true }).click();
    await expect(dialog.getByLabel('Title', { exact: true })).toHaveValue('Confirmed retry');
});
test('media detail navigation and mobile dark layout stay keyboard accessible', async ({ page }) => {
    await page.addInitScript(() => localStorage.setItem('arkon.theme', 'dark'));
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/admin/media');
    await page.getByLabel('Upload image file').setInputFiles({ name: 'mobile-detail.png', mimeType: 'image/png', buffer: PNG_1X1 });
    const tile = page.getByRole('button', { name: 'Open mobile-detail.png', exact: true });
    await tile.focus();
    await page.keyboard.press('Enter');
    const dialog = page.getByRole('dialog', { name: 'Image details' });
    await expect(dialog.getByLabel('Title', { exact: true })).toBeEnabled();

    expect(await dialog.evaluate((el) => el.scrollWidth <= el.clientWidth)).toBe(true);
    await page.screenshot({ path: 'C:/Users/jacob/Documents/Codex/2026-10-06/referenced-chatgpt-conversation-this-is-an/outputs/arkon-media-mobile-dark.png' });
    await dialog.getByRole('button', { name: 'Next image', exact: true }).click();
    await expect(dialog.getByLabel('Title', { exact: true })).not.toHaveValue('mobile-detail.png');
    await page.keyboard.press('Escape');
    await expect(tile).toBeFocused();
});
