import { expect, test } from '@playwright/test';
import { db } from './support';
import { APP_ORIGIN, SERVER_URL, E2E_HOST, PORT } from './env';

test('menu drafts, dropdown ordering and retry after a lost response work in the dashboard', async ({ page }) => {
    await page.goto('/admin/navigation');
    await expect(page.getByRole('heading', { name: 'Navigation', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'New menu', exact: true }).click();
    await page.getByLabel('Menu name').fill('Browser menu');
    await page.getByRole('button', { name: 'Add menu item' }).click();
    await page.getByLabel('Label', { exact: true }).fill('Services');
    await page.getByLabel('Link', { exact: true }).fill('/services');
    await page.getByRole('button', { name: 'Add menu item' }).click();
    await page.getByLabel('Label', { exact: true }).nth(1).fill('Consulting');
    await page.getByLabel('Dropdown parent').nth(1).selectOption({ label: 'Services' });
    let dropped = false;
    await page.route('**/navigation/save', async (route) => {
        if (!dropped) {
            dropped = true;
            const response = await route.fetch({
                url: route.request().url().replace(APP_ORIGIN, SERVER_URL),
                headers: { ...(await route.request().allHeaders()), host: E2E_HOST + ':' + PORT },
            });
            expect(response.ok(), await response.text()).toBe(true);
            await route.abort();
        } else await route.continue();
    });
    await page.getByRole('button', { name: 'Save menu draft' }).click();
    await expect(page.getByText(/Outcome could not be confirmed/)).toBeVisible();
    await expect(page.getByLabel('Menu name')).toBeDisabled();
    await page.getByRole('button', { name: 'Confirm previous request' }).click();
    await expect(page.getByRole('status')).toContainText('Menu draft saved');
    const saved = await db.query("SELECT id,version,draft FROM site_menus WHERE name='Browser menu'");
    expect(saved.rows).toHaveLength(1);
    expect(saved.rows[0].version).toBe(1);
    expect(saved.rows[0].draft.items[1].parentId).toBe(saved.rows[0].draft.items[0].id);
    page.once('dialog', (d) => d.accept());
    await page.getByRole('button', { name: 'Publish menu', exact: true }).click();
    await expect(page.getByRole('status')).toContainText('Menu published');
    await page.getByLabel('Label', { exact: true }).nth(1).fill('Draft only');
    await expect(page.getByRole('button', { name: 'Publish menu', exact: true })).toBeDisabled();
    const published = await db.query('SELECT definition FROM site_menu_versions WHERE menu_id=$1', [saved.rows[0].id]);
    expect(published.rows[0].definition.items[1].label).toBe('Consulting');
});

test('local layout repair uses no helper and previews native keyboard and mobile navigation', async ({ page }) => {
    const m = await db.query("SELECT id,site_id FROM site_menus WHERE name='Browser menu'");
    await db.query('UPDATE site_website_settings SET header_id=NULL,footer_id=NULL,main_menu_id=$1 WHERE site_id=$2', [m.rows[0].id, m.rows[0].site_id]);
    await page.goto('/admin/navigation');
    // The first test leaves no local edits after this full navigation.
    await page.getByRole('button', { name: 'Prepare missing header and footer' }).click();
    await expect(page).toHaveURL(/\/admin\/website$/);
    await expect(page.getByRole('heading', { name: 'Add missing shared header, footer and navigation', exact: true })).toBeVisible();
    const frame = page.frameLocator('iframe');
    await expect(frame.getByRole('navigation', { name: 'Main navigation' })).toBeVisible();
    await expect(frame.getByRole('navigation').locator('.ak-navigation__desktop summary')).toHaveText('Services');
    await frame.getByRole('navigation').locator('.ak-navigation__desktop summary').focus();
    await page.keyboard.press('Enter');
    await expect(frame.getByRole('navigation').locator('.ak-navigation__desktop').getByRole('link', { name: 'Consulting' })).toBeVisible();
    await page.getByRole('button', { name: 'Mobile', exact: true }).click();
    await expect(frame.locator('.ak-navigation__desktop')).toBeHidden();
    await frame.getByText('Menu', { exact: true }).click();
    await expect(frame.locator('.ak-navigation__mobile[open]')).toBeVisible();
    const result = await db.query(
        "SELECT provider,status FROM ai_proposals WHERE summary='Add missing shared header, footer and navigation' ORDER BY created_at DESC LIMIT 1",
    );
    expect(result.rows[0]).toEqual({ provider: 'local', status: 'proposed' });
    await expect(page.getByRole('button', { name: 'Apply all website drafts' })).toBeVisible();
});
