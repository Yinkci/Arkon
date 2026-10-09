import { expect, test } from '@playwright/test';
import { APP_ORIGIN, SERVER_URL, E2E_HOST, PORT } from './env';
import { BASE_URL, createPage, db, publicationCount } from './support';

test('Generate title changes only reviewed title metadata', async ({ page }) => {
    const id = await createPage('/seo-title-only', 'Our services');
    await page.goto(`/admin/editor/${id}?panel=seo`);
    const input = page.getByLabel('SEO title', { exact: true });
    await input.locator('..').getByRole('button', { name: 'Generate', exact: true }).click();
    const review = page.getByTestId('ai-proposal');
    await expect(review).toContainText('Review accurate search metadata.', { timeout: 30000 });
    expect((await db.query('select document from page_drafts where page_id=$1', [id])).rows[0].document.seo.title).toBeUndefined();
    await review.getByRole('button', { name: /Apply.*draft/i }).click();
    await expect(page.getByTestId('save-status')).toHaveText('Draft saved');
    const seo = (await db.query('select document from page_drafts where page_id=$1', [id])).rows[0].document.seo;
    expect(seo).toEqual({ title: 'Services and enquiries' });
    expect(await publicationCount(id)).toBe(0);
});

test('lost defaults save response retries the same batch and publishing refreshes only live revisions', async ({ page }) => {
    const id = await createPage('/seo-defaults-retry', 'Published services');
    await page.goto(`/admin/editor/${id}`);
    await page.getByRole('button', { name: 'Publish', exact: true }).click();
    await expect(page.getByTestId('notice')).toContainText('Published.');
    await page.getByLabel('Heading', { exact: true }).fill('Draft heading stays private');
    await page.getByRole('button', { name: 'Save draft', exact: true }).click();
    await expect(page.getByTestId('save-status')).toHaveText('Draft saved');
    await page.goto('/admin/seo/defaults');
    let dropped = false;
    const bodies: unknown[] = [];
    await page.route('**/seo/defaults/save', async (route) => {
        bodies.push(route.request().postDataJSON());
        if (!dropped) {
            dropped = true;
            await route.fetch({
                url: route.request().url().replace(APP_ORIGIN, SERVER_URL),
                headers: { ...(await route.request().allHeaders()), host: `${E2E_HOST}:${PORT}` },
            });
            await route.abort();
        } else await route.continue();
    });
    await page.getByLabel('Default meta description', { exact: true }).fill('The published site-wide summary.');
    await page.getByRole('button', { name: 'Save defaults', exact: true }).click();
    await expect(page.getByText('Save not confirmed. Retry the same saved batch.', { exact: true })).toBeVisible();
    await expect(page.getByLabel('Default meta description', { exact: true })).toBeDisabled();
    await page.getByRole('button', { name: 'Save defaults', exact: true }).click();
    await expect(page.getByText('Defaults saved as a draft. Publish separately to refresh live pages.', { exact: true })).toBeVisible();
    expect(bodies).toHaveLength(2);
    expect(bodies[1]).toEqual(bodies[0]);
    expect(await (await page.request.get(BASE_URL + '/seo-defaults-retry')).text()).not.toContain('The published site-wide summary.');
    page.once('dialog', (d) => d.accept());
    await page.getByRole('button', { name: 'Publish saved defaults', exact: true }).click();
    await expect(page.getByText(/SEO defaults published\./)).toBeVisible();
    const html = await (await page.request.get(BASE_URL + '/seo-defaults-retry')).text();
    expect(html).toContain('The published site-wide summary.');
    expect(html).toContain('Published services');
    expect(html).not.toContain('Draft heading stays private');
});
