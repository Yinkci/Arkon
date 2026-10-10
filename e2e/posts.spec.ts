import { expect, test, type Browser, type Page } from '@playwright/test';
import { BASE_URL } from './support';

// Posts are pages of another kind: their own list, details in the builder (excerpt, featured
// image, categories, tags), categories and tags screens, and the public API reads them once
// published. Developer API tokens: created (shown once), used, revoked.

const notice = (page: Page) => page.getByTestId('notice');

/** The public API as an anonymous client or with a token: no cookies. */
async function apiGet(browser: Browser, path: string, token?: string) {
    const context = await browser.newContext({ baseURL: BASE_URL, storageState: { cookies: [], origins: [] } });
    try {
        const response = await context.request.get(`/api/v1${path}`, { headers: token ? { Authorization: `Bearer ${token}` } : {} });
        return { status: response.status(), body: await response.json() };
    } finally {
        await context.close();
    }
}

test('a post is written, categorized and published, and the public API returns it', async ({ page, browser }) => {
    const suffix = Date.now().toString(36);
    const title = `Notes from the field ${suffix}`;
    const slug = `notes-from-the-field-${suffix}`;

    await page.goto('/admin/posts');
    await expect(page.getByRole('heading', { name: 'Posts', level: 1 })).toBeVisible();
    await page.getByRole('button', { name: 'New post', exact: true }).click();
    const form = page.getByRole('form', { name: 'New post' });
    await form.getByLabel('Title').fill(title);
    await expect(form.getByLabel('URL path')).toHaveValue(`/blog/${slug}`);
    await form.getByRole('button', { name: 'Create post' }).click();
    await expect(page).toHaveURL(/\/admin\/editor\//);

    // Details live with the draft, next to Title and URL.
    await page.getByRole('button', { name: 'Page settings' }).click();
    const details = page.getByTestId('post-details');
    await details.getByLabel('Excerpt').fill('What we learned planting a hundred gardens.');
    await details.getByLabel('New categories').fill(`Field notes ${suffix}`);
    await details.getByRole('button', { name: 'Add' }).first().click();
    await expect(details.getByRole('checkbox', { name: `Field notes ${suffix}` })).toBeChecked();
    await details.getByRole('button', { name: 'Save post details' }).click();
    await expect(details.getByRole('status')).toContainText('Visitors see these details once you publish');

    // Not public until published.
    expect((await apiGet(browser, `/posts?slug=${slug}`)).body.data).toEqual([]);
    await page.getByRole('button', { name: 'Publish' }).click();
    await expect(notice(page)).toContainText('Published');

    const listed = await apiGet(browser, `/posts?slug=${slug}&include=content`);
    expect(listed.status).toBe(200);
    expect(listed.body.data).toHaveLength(1);
    expect(listed.body.data[0]).toMatchObject({ title, excerpt: 'What we learned planting a hundred gardens.', status: 'published', path: `/blog/${slug}` });
    expect(listed.body.data[0].categories.map((c: { name: string }) => c.name)).toEqual([`Field notes ${suffix}`]);
    expect(listed.body.data[0].content.rendered).toContain(title);

    // The category screen counts the published post; the Pages list does not show posts.
    await page.goto('/admin/posts/categories');
    const row = page.getByTestId('term-row').filter({ hasText: `Field notes ${suffix}` });
    await expect(row).toBeVisible();
    await expect(row.locator('td').nth(2)).toHaveText('1');
    await page.goto('/admin/pages');
    await expect(page.locator(`[data-testid="page-row"][data-path="/blog/${slug}"]`)).toHaveCount(0);
    await page.goto('/admin/posts');
    await expect(page.locator(`[data-testid="page-row"][data-path="/blog/${slug}"]`)).toBeVisible();
});

test('categories and tags are added, renamed and deleted', async ({ page }) => {
    const name = `Gardening ${Date.now().toString(36)}`;
    await page.goto('/admin/posts/tags');
    const add = page.getByRole('form', { name: 'Add a tag' });
    await add.getByLabel('Name').fill(name);
    await add.getByRole('button', { name: 'Add tag' }).click();
    const row = page.getByTestId('term-row').filter({ hasText: name });
    await expect(row).toBeVisible();

    await page.getByRole('button', { name: `Actions for ${name}` }).click();
    await page.getByRole('menuitem', { name: 'Edit' }).click();
    const edit = page.getByRole('form', { name: `Edit ${name}` });
    await edit.getByLabel('Name').fill(`${name} tips`);
    await edit.getByRole('button', { name: 'Save' }).click();
    await expect(page.getByTestId('term-row').filter({ hasText: `${name} tips` })).toBeVisible();

    await page.getByRole('button', { name: `Actions for ${name} tips` }).click();
    await page.getByRole('menuitem', { name: 'Delete' }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Delete' }).click();
    await expect(page.getByTestId('term-row').filter({ hasText: `${name} tips` })).toHaveCount(0);
});

test('an API token is shown once, works within its scope and stops working when revoked', async ({ page, browser }) => {
    const name = `Frontend ${Date.now().toString(36)}`;
    await page.goto('/admin/settings/developer');
    await expect(page.getByTestId('api-base-url')).toContainText('/api/v1');
    const form = page.getByRole('form', { name: 'New access token' });
    await form.getByLabel('Name', { exact: true }).fill(name);
    await form.getByRole('checkbox', { name: /^read:posts/ }).check();
    await form.getByRole('button', { name: 'Create token' }).click();
    const token = (await page.getByTestId('new-token').textContent())!.trim();
    expect(token).toMatch(/^arkon_pat_/);

    expect((await apiGet(browser, '/posts?status=draft', token)).status).toBe(200);
    expect((await apiGet(browser, '/pages?status=draft', token)).body.error.code).toBe('insufficient_scope');

    await page.reload();
    await expect(page.getByTestId('new-token')).toHaveCount(0); // never shown again
    const row = page.getByTestId('token-row').filter({ hasText: name });
    await row.getByRole('button', { name: 'Revoke' }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Revoke' }).click();
    await expect(row).toContainText('Revoked');
    expect((await apiGet(browser, '/posts?status=draft', token)).body.error.code).toBe('invalid_token');
});
