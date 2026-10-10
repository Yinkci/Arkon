import { expect, test } from '@playwright/test';
import { PNG_1X1 } from './fixtures';
test('dashboard summarizes and all management routes have a clear home', async ({ page }) => {
    await page.goto('/admin');
    await page.screenshot({
        path: 'C:/Users/jacob/Documents/Codex/2026-10-06/referenced-chatgpt-conversation-this-is-an/outputs/arkon-admin-dashboard.png',
        fullPage: true,
    });
    await expect(page.getByRole('heading', { name: 'Recent content' })).toBeVisible();
    await expect(page.getByRole('table')).toHaveCount(0);
    await page.getByRole('link', { name: 'Edit homepage', exact: true }).click();
    await expect(page).toHaveURL(/\/admin\/editor\//);
    await page.goto('/admin');
    await page.getByRole('navigation', { name: 'Main' }).getByRole('link', { name: 'Navigation', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Navigation', exact: true })).toBeVisible();
    await page.goto('/admin/settings');
    await expect(page.getByRole('heading', { name: 'General', exact: true })).toBeVisible();
    await page.goto('/admin/performance');
    await expect(page.getByText('Not measured for this live website.', { exact: false })).toBeVisible();
    await page.goto('/admin/design/components');
    await expect(page.getByRole('heading', { name: 'Global styles', exact: true })).toHaveCount(0);
});
test('the top bar has the location and View site, without a search', async ({ page }) => {
    await page.goto('/admin');
    await expect(page.getByRole('link', { name: 'View site' }).first()).toBeVisible();
    await expect(page.getByRole('button', { name: /search/i })).toHaveCount(0);
    await expect(page.getByRole('searchbox')).toHaveCount(0);
    await page.keyboard.press('Control+k');
    await expect(page.getByRole('dialog')).toHaveCount(0);
});
test('media upload and page management work at mobile widths in both themes', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/admin/media');
    await expect(page.getByRole('button', { name: 'Upload image', exact: true })).toBeVisible();
    await page.getByLabel('Upload image file').setInputFiles({
        name: 'ux-image.png',
        mimeType: 'image/png',
        buffer: PNG_1X1,
    });
    await expect(page.getByText('ux-image.png', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Menu', exact: true }).click();
    await page.getByText('Dark', { exact: true }).click();
    await page.getByRole('navigation', { name: 'Main' }).getByRole('link', { name: 'Pages', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Pages', exact: true })).toBeVisible();
    await expect(page.getByRole('navigation', { name: 'Main' })).toBeHidden();
    await page.getByLabel('Search pages', { exact: true }).fill('not-existing');
    await expect(page.getByText('No matching pages', { exact: true })).toBeVisible();
    await page.screenshot({
        path: 'C:/Users/jacob/Documents/Codex/2026-10-06/referenced-chatgpt-conversation-this-is-an/outputs/arkon-admin-mobile-dark.png',
        fullPage: true,
    });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
});
