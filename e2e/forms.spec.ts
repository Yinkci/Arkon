import { expect, test } from '@playwright/test';
import { db } from './support';
import { APP_ORIGIN, SERVER_URL, E2E_HOST, PORT } from './env';

test('an uncertain form save locks editing and retries the same immutable request', async ({ page }) => {
    await page.goto('/admin/forms');
    await page.getByRole('button', { name: '+ New form', exact: true }).click();
    await page.getByLabel('Form name', { exact: true }).fill('Retry-safe browser form');
    let dropped = false;
    const keys: string[] = [];
    await page.route('**/admin/api/forms/save', async (route) => {
        keys.push(route.request().postDataJSON().requestKey);
        if (!dropped) {
            dropped = true;
            const response = await route.fetch({
                url: route.request().url().replace(APP_ORIGIN, SERVER_URL),
                headers: { ...(await route.request().allHeaders()), host: `${E2E_HOST}:${PORT}` },
            });
            expect(response.ok()).toBe(true);
            await route.abort();
        } else await route.continue();
    });
    await page.getByRole('button', { name: 'Save draft', exact: true }).click();
    await expect(page.getByRole('status')).toContainText('Retry Save before editing');
    await expect(page.getByLabel('Form name', { exact: true })).toBeDisabled();
    await expect(page.getByRole('button', { name: '+ New form', exact: true })).toBeDisabled();
    await page.getByRole('button', { name: 'Save draft', exact: true }).click();
    await expect(page.getByRole('status')).toContainText('Form draft saved');
    await expect(page.getByLabel('Form name', { exact: true })).toBeEnabled();
    expect(keys).toHaveLength(2);
    expect(keys[0]).toBe(keys[1]);
    const { rows } = await db.query("SELECT version FROM site_forms WHERE name='Retry-safe browser form'");
    expect(rows).toEqual([{ version: 1 }]);
});
