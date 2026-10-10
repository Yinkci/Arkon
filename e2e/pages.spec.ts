import { expect, test, type Browser, type Page } from '@playwright/test';
import { E2E_EDITOR } from './fixtures';
import { BASE_URL, createPage, greeting } from './support';

const notice = (page: Page) => page.getByTestId('notice');
const row = (page: Page, path: string) => page.locator(`[data-testid="page-row"][data-path="${path}"]`);
const more = (page: Page, title: string) => page.getByRole('button', { name: `More actions for ${title}` });
const tab = (page: Page, name: string) => page.getByRole('group', { name: 'Filter pages' }).getByRole('button', { name: new RegExp(`^${name}`) });

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
    await page.getByRole('button', { name: 'New page', exact: true }).click();
    const form = page.getByRole('form', { name: 'New page' });
    await form.getByLabel('Title').fill(title);
    if (path !== undefined) await form.getByLabel('URL path').fill(path);
    await form.getByRole('button', { name: 'Create page' }).click();
}

async function openPageSettings(page: Page) {
    await page.getByRole('button', { name: 'Page settings' }).click();
    return page.getByRole('form', { name: 'Title and URL' });
}

test('create, rename as a draft, then publish: the old URL redirects to the new one', async ({ page, browser }) => {
    await createViaForm(page, 'Pricing Plans');
    await expect(page).toHaveURL(/\/admin\/editor\//);
    await expect(page.getByTestId('page-path')).toHaveText('/pricing-plans'); // derived from the title
    await page.getByRole('button', { name: 'Publish' }).click();
    await expect(notice(page)).toContainText('Published');
    expect(await visit(browser, '/pricing-plans')).toMatchObject({ status: 200, title: 'Pricing Plans · Arkon' });

    // Draft rename: the live page keeps its title and URL.
    const settings = await openPageSettings(page);
    await settings.getByLabel('Page title').fill('Plans');
    await settings.getByLabel('URL path').fill('/plans');
    await expect(settings.getByText('/pricing-plans will redirect (301)')).toBeVisible();
    await settings.getByRole('button', { name: 'Update title & URL' }).click();
    await expect(notice(page)).toContainText('Title and URL updated in the draft');
    await expect(page.getByTestId('page-title')).toHaveText('Plans');
    await expect(page.getByTestId('live-meta')).toContainText('Live now as “Pricing Plans” at /pricing-plans');
    expect(await visit(browser, '/pricing-plans')).toMatchObject({ status: 200, title: 'Pricing Plans · Arkon' });
    expect((await visit(browser, '/plans')).status).toBe(404);

    // Publish: new URL live, old URL is a permanent redirect.
    await page.getByRole('button', { name: 'Publish' }).click();
    await expect(notice(page)).toContainText('Published');
    await expect(page.getByTestId('live-meta')).toHaveCount(0);
    expect(await visit(browser, '/plans')).toMatchObject({ status: 200, title: 'Plans · Arkon' });
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
    await more(page, 'offline-soon').click();
    await page.getByRole('menuitem', { name: 'Unpublish' }).click();
    const dialog = page.getByRole('dialog', { name: 'Unpublish “offline-soon”?' });
    await expect(dialog).toContainText('will get “page not found”');
    await dialog.getByRole('button', { name: 'Cancel' }).click();
    expect((await visit(browser, '/offline-soon')).status).toBe(200);

    await more(page, 'offline-soon').click();
    await page.getByRole('menuitem', { name: 'Unpublish' }).click();
    await dialog.getByRole('button', { name: 'Unpublish' }).click();
    await expect(row(page, '/offline-soon').getByText('Not published', { exact: true })).toBeVisible();
    expect((await visit(browser, '/offline-soon')).status).toBe(404);
});

test('Move to Trash takes a live page offline at once; the Trash restores it and deletes permanently', async ({ page, browser }) => {
    const id = await createPage('/to-trash', 'To trash');
    await page.goto(`/admin/editor/${id}`);
    await page.getByRole('button', { name: 'Publish' }).click();
    await expect(notice(page)).toContainText('Published');

    // The More menu closes on an outside click and on Escape; one menu at a time.
    await page.goto('/admin/pages');
    const menu = page.getByRole('menu', { name: 'More actions for to-trash' });
    await more(page, 'to-trash').click();
    await expect(menu).toBeVisible();
    await page.getByRole('heading', { name: 'Pages', exact: true }).click();
    await expect(menu).toHaveCount(0);
    await more(page, 'to-trash').click();
    await page.keyboard.press('Escape');
    await expect(menu).toHaveCount(0);
    await expect(more(page, 'to-trash')).toBeFocused();

    // A plain confirmation (no typing); the row leaves the list without a reload.
    await more(page, 'to-trash').click();
    await page.getByRole('menuitem', { name: 'Move to Trash' }).click();
    const dialog = page.getByRole('dialog', { name: 'Move “to-trash” to Trash?' });
    await expect(dialog).toContainText('live at /to-trash');
    await expect(dialog.getByRole('textbox')).toHaveCount(0);
    await dialog.getByRole('button', { name: 'Move to Trash' }).click();
    await expect(row(page, '/to-trash')).toHaveCount(0);
    await expect(page.getByTestId('toast')).toContainText('Page moved to Trash.');
    await expect(tab(page, 'Trash')).toHaveText(/1$/);
    expect((await visit(browser, '/to-trash')).status).toBe(404);

    // Restore: same page back, unpublished.
    await tab(page, 'Trash').click();
    await page.getByTestId('trash-row').filter({ hasText: '/to-trash' }).getByRole('button', { name: 'Restore' }).click();
    await expect(page.getByTestId('toast').last()).toContainText('Page restored.');
    await expect(page.getByText('Trash is empty')).toBeVisible();
    await tab(page, 'All').click();
    await expect(row(page, '/to-trash').getByText('Not published', { exact: true })).toBeVisible();

    // Back to the Trash, then Delete permanently.
    await more(page, 'to-trash').click();
    await page.getByRole('menuitem', { name: 'Move to Trash' }).click();
    await page.getByRole('dialog', { name: 'Move “to-trash” to Trash?' }).getByRole('button', { name: 'Move to Trash' }).click();
    await expect(row(page, '/to-trash')).toHaveCount(0);
    await tab(page, 'Trash').click();
    await page.getByTestId('trash-row').filter({ hasText: '/to-trash' }).getByRole('button', { name: 'Delete permanently' }).click();
    const purge = page.getByRole('dialog', { name: 'Delete “to-trash” permanently?' });
    await expect(purge).toContainText('cannot be undone');
    await purge.getByRole('button', { name: 'Delete permanently' }).click();
    await expect(page.getByText('Trash is empty')).toBeVisible();

    // The URL can be used again.
    await createViaForm(page, 'Replacement', '/to-trash');
    await expect(page).toHaveURL(/\/admin\/editor\//);
});

test('several pages move to the Trash together, and the Trash empties in one action', async ({ page }) => {
    for (const name of ['bulk-a', 'bulk-b', 'bulk-c']) await createPage(`/${name}`, name);
    await page.goto('/admin/pages');
    const before = Number((await tab(page, 'All').innerText()).match(/\d+$/)![0]);
    for (const name of ['bulk-a', 'bulk-b', 'bulk-c']) await page.getByRole('checkbox', { name: `Select ${name}` }).check();
    const bar = page.getByTestId('bulk-bar');
    await expect(bar).toContainText('3 pages selected');
    await bar.getByRole('button', { name: 'Move to Trash' }).click();
    await page.getByRole('dialog', { name: 'Move 3 pages to Trash?' }).getByRole('button', { name: 'Move to Trash' }).click();
    for (const name of ['bulk-a', 'bulk-b', 'bulk-c']) await expect(row(page, `/${name}`)).toHaveCount(0);
    await expect(page.getByTestId('toast').last()).toContainText('3 pages moved to Trash.');
    await expect(tab(page, 'All')).toHaveText(new RegExp(`${before - 3}$`));
    await expect(bar).toHaveCount(0);

    await tab(page, 'Trash').click();
    await page.getByRole('checkbox', { name: 'Select all pages in the Trash' }).check();
    await page.getByTestId('bulk-bar').getByRole('button', { name: 'Delete permanently' }).click();
    const purge = page.getByRole('dialog', { name: /^Permanently delete \d+ pages\?$/ });
    await expect(purge).toContainText('cannot be undone');
    await purge.getByRole('button', { name: 'Delete permanently' }).click();
    await expect(page.getByText('Trash is empty')).toBeVisible();
    await expect(tab(page, 'Trash')).toHaveText(/0$/);
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
        await expect(page.getByRole('heading', { name: greeting(E2E_EDITOR.name) })).toBeVisible();

        await createViaForm(page, 'Editor draft');
        await expect(page).toHaveURL(/\/admin\/editor\//);
        await expect(page.getByRole('button', { name: 'Publish' })).toBeDisabled();
        const settings = await openPageSettings(page);
        await settings.getByLabel('URL path').fill('/editor-draft-renamed');
        await settings.getByRole('button', { name: 'Update title & URL' }).click();
        await expect(notice(page)).toContainText('Title and URL updated');

        await page.goto('/admin/pages');
        await expect(row(page, '/editor-draft-renamed')).toBeVisible();
        // No Trash, no selection; the More menu offers only what editors can do.
        await expect(page.getByRole('checkbox')).toHaveCount(0);
        await expect(tab(page, 'Trash')).toHaveCount(0);
        await more(page, 'Editor draft').click();
        await expect(page.getByRole('menuitem', { name: 'Move to Trash' })).toHaveCount(0);
        await page.keyboard.press('Escape');
        await expect(page.getByRole('button', { name: 'Unpublish', exact: true })).toHaveCount(0);
    });
});
