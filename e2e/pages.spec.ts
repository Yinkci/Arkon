import { expect, test, type Browser, type Page } from '@playwright/test';
import { E2E_EDITOR } from './fixtures';
import { BASE_URL, createPage } from './support';

const notice = (page: Page) => page.getByTestId('notice');
const row = (page: Page, path: string) => page.locator(`[data-testid="page-row"][data-path="${path}"]`);

/** An anonymous visitor: no cookies, redirects not followed. */
async function visit(browser: Browser, path: string) {
    const context = await browser.newContext({ baseURL: BASE_URL, storageState: { cookies: [], origins: [] } });
    try {
        const response = await context.request.get(path, { maxRedirects: 0 });
        const html = await response.text();
        return { status: response.status(), location: response.headers()['location'], title: html.match(/<title>(.*?)<\/title>/)?.[1] ?? null };
    } finally {
        await context.close();
    }
}

async function createViaForm(page: Page, title: string, path?: string) {
    await page.goto('/admin/pages');
    const form = page.getByRole('form', { name: 'New page' });
    await form.getByLabel('Title').fill(title);
    if (path !== undefined) await form.getByLabel('URL path').fill(path);
    await form.getByRole('button', { name: 'Create page' }).click();
}

async function openPageSettings(page: Page) {
    await page.getByRole('button', { name: '← Page settings' }).click();
    return page.getByRole('form', { name: 'Title and URL' });
}

test('create, rename as a draft, then publish: the old URL redirects to the new one', async ({ page, browser }) => {
    await createViaForm(page, 'Pricing Plans');
    await expect(page).toHaveURL(/\/admin\/editor\//);
    await expect(page.getByTestId('page-path')).toHaveText('/pricing-plans'); // derived from the title
    await page.getByRole('button', { name: 'Publish' }).click();
    await expect(notice(page)).toContainText('Published');
    expect(await visit(browser, '/pricing-plans')).toMatchObject({ status: 200, title: 'Pricing Plans · Arkon Demo' });

    // Draft rename: the live page keeps its title and URL.
    const settings = await openPageSettings(page);
    await settings.getByLabel('Page title').fill('Plans');
    await settings.getByLabel('URL path').fill('/plans');
    await expect(settings.getByText('/pricing-plans will redirect (301)')).toBeVisible();
    await settings.getByRole('button', { name: 'Update title & URL' }).click();
    await expect(notice(page)).toContainText('Title and URL updated in the draft');
    await expect(page.getByTestId('page-title')).toHaveText('Plans');
    await expect(page.getByTestId('live-meta')).toContainText('Live now as “Pricing Plans” at /pricing-plans');
    expect(await visit(browser, '/pricing-plans')).toMatchObject({ status: 200, title: 'Pricing Plans · Arkon Demo' });
    expect((await visit(browser, '/plans')).status).toBe(404);

    // Publish: new URL live, old URL is a permanent redirect.
    await page.getByRole('button', { name: 'Publish' }).click();
    await expect(notice(page)).toContainText('Published');
    await expect(page.getByTestId('live-meta')).toHaveCount(0);
    expect(await visit(browser, '/plans')).toMatchObject({ status: 200, title: 'Plans · Arkon Demo' });
    expect(await visit(browser, '/pricing-plans')).toMatchObject({ status: 301, location: '/plans' });
});

test('reserved and taken URLs are refused with a clear message', async ({ page }) => {
    await createViaForm(page, 'Admin area', '/admin');
    await expect(page.getByRole('form', { name: 'New page' }).getByRole('alert')).toContainText('reserved');
    await createViaForm(page, 'Taken', '/taken');
    await expect(page).toHaveURL(/\/admin\/editor\//);
    await createViaForm(page, 'Taken again', '/taken');
    await expect(page.getByRole('form', { name: 'New page' }).getByRole('alert')).toContainText('already used');
});

test('unpublish asks for confirmation and takes the page offline, keeping it in the admin', async ({ page, browser }) => {
    const id = await createPage('/offline-soon', 'Offline soon');
    await page.goto(`/admin/editor/${id}`);
    await page.getByRole('button', { name: 'Publish' }).click();
    await expect(notice(page)).toContainText('Published');

    await page.goto('/admin/pages');
    await row(page, '/offline-soon').getByRole('button', { name: 'Unpublish' }).click();
    const dialog = page.getByRole('dialog', { name: 'Unpublish “offline-soon”?' });
    await expect(dialog).toContainText('will get “page not found”');
    await dialog.getByRole('button', { name: 'Cancel' }).click();
    expect((await visit(browser, '/offline-soon')).status).toBe(200);

    await row(page, '/offline-soon').getByRole('button', { name: 'Unpublish' }).click();
    await dialog.getByRole('button', { name: 'Unpublish' }).click();
    await expect(row(page, '/offline-soon').getByText('Not published', { exact: true })).toBeVisible();
    expect((await visit(browser, '/offline-soon')).status).toBe(404);
});

test('delete requires typing the URL, takes a live page offline and frees the URL', async ({ page, browser }) => {
    const id = await createPage('/to-delete', 'To delete');
    await page.goto(`/admin/editor/${id}`);
    await page.getByRole('button', { name: 'Publish' }).click();
    await expect(notice(page)).toContainText('Published');

    await page.goto('/admin/pages');
    await row(page, '/to-delete').getByRole('button', { name: 'Delete' }).click();
    const dialog = page.getByRole('dialog', { name: 'Delete “to-delete”?' });
    await expect(dialog).toContainText('live at /to-delete');
    const confirm = dialog.getByRole('button', { name: 'Delete page' });
    await expect(confirm).toBeDisabled();
    await dialog.getByLabel(/Type/).fill('/to-delet');
    await expect(confirm).toBeDisabled();
    await dialog.getByLabel(/Type/).fill('/to-delete');
    await confirm.click();
    await expect(row(page, '/to-delete')).toHaveCount(0);
    expect((await visit(browser, '/to-delete')).status).toBe(404);

    // The URL can be used again.
    await createViaForm(page, 'Replacement', '/to-delete');
    await expect(page).toHaveURL(/\/admin\/editor\//);
});

test('a title/URL change based on an outdated draft is rejected', async ({ page, context }) => {
    const id = await createPage('/stale-settings', 'Stale settings');
    await page.goto(`/admin/editor/${id}`);
    const settings = await openPageSettings(page);

    // Someone else saves the draft in another tab.
    const other = await context.newPage();
    await other.goto(`/admin/editor/${id}`);
    await other.getByLabel('Heading', { exact: true }).fill('Changed elsewhere');
    await other.getByRole('button', { name: 'Save draft' }).click();
    await expect(other.getByTestId('save-status')).toHaveText('Draft saved');

    await settings.getByLabel('URL path').fill('/stale-renamed');
    await settings.getByRole('button', { name: 'Update title & URL' }).click();
    await expect(notice(page)).toContainText('changed since you loaded it');
    await expect(notice(page).getByRole('button', { name: 'Reload' })).toBeVisible();
    await page.reload();
    await expect(page.getByTestId('page-path')).toHaveText('/stale-settings');
});

test.describe('as an editor', () => {
    test.use({ storageState: { cookies: [], origins: [] } });

    test('can create and rename drafts but cannot publish, unpublish or delete', async ({ page }) => {
        await page.goto('/login');
        await page.getByLabel('Email').fill(E2E_EDITOR.email);
        await page.getByLabel('Password').fill(E2E_EDITOR.password);
        await page.getByRole('button', { name: 'Sign in' }).click();
        await expect(page.getByRole('heading', { name: `Welcome, ${E2E_EDITOR.name}` })).toBeVisible();

        await createViaForm(page, 'Editor draft');
        await expect(page).toHaveURL(/\/admin\/editor\//);
        await expect(page.getByRole('button', { name: 'Publish' })).toBeDisabled();
        const settings = await openPageSettings(page);
        await settings.getByLabel('URL path').fill('/editor-draft-renamed');
        await settings.getByRole('button', { name: 'Update title & URL' }).click();
        await expect(notice(page)).toContainText('Title and URL updated');

        await page.goto('/admin/pages');
        await expect(row(page, '/editor-draft-renamed')).toBeVisible();
        await expect(page.getByRole('button', { name: 'Delete' })).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Unpublish' })).toHaveCount(0);
    });
});
