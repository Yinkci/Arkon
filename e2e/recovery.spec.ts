import { expect, test, type Page } from '@playwright/test';
import { PNG_1X1 } from './fixtures';
import { BASE_URL, createPage, db } from './support';
import { E2E_HOST, PORT } from './env';

const status = (page: Page) => page.getByTestId('save-status');
const notice = (page: Page) => page.getByTestId('notice');
const canvas = (page: Page) => page.frameLocator('[data-testid="canvas"]');

async function publicHtml(page: Page, path: string) {
    const context = await page
        .context()
        .browser()!
        .newContext({ baseURL: BASE_URL, storageState: { cookies: [], origins: [] } });
    try {
        const response = await context.request.get(path);
        return { status: response.status(), html: await response.text() };
    } finally {
        await context.close();
    }
}

/**
 * A draft as code before the backslash correction stored it: a hero with an image, one
 * button at the top level and one inside a column, written straight to the database.
 */
async function olderDraft(path: string, assetId: string, hrefs: [string, string]): Promise<string> {
    const { rows } = await db.query<{ site_id: string }>('select site_id from site_domains where hostname = $1', [`${E2E_HOST}:${PORT}`]);
    const siteId = rows[0]!.site_id;
    const id = crypto.randomUUID();
    const document = {
        schemaVersion: 1,
        root: 'oldRoot001',
        nodes: {
            oldRoot001: { id: 'oldRoot001', type: 'page', version: 2, props: {}, children: ['oldHero001', 'oldButn001', 'oldCols001'] },
            oldHero001: {
                id: 'oldHero001',
                type: 'hero',
                version: 1,
                props: { heading: 'Older page', headingLevel: 'h1', text: '', image: { assetId, alt: 'A dot' } },
            },
            oldButn001: { id: 'oldButn001', type: 'button', version: 1, props: { label: 'Contact', href: hrefs[0] } },
            oldCols001: { id: 'oldCols001', type: 'columns', version: 1, props: {}, children: ['oldColu001'] },
            oldColu001: { id: 'oldColu001', type: 'column', version: 1, props: {}, children: ['oldButn002'] },
            oldButn002: { id: 'oldButn002', type: 'button', version: 1, props: { label: 'Partner', href: hrefs[1] } },
        },
        seo: {},
    };
    await db.query('insert into pages (id, site_id, path, title) values ($1, $2, $3, $4)', [id, siteId, path, 'Older']);
    await db.query('insert into page_drafts (page_id, site_id, document, version) values ($1, $2, $3, 1)', [id, siteId, JSON.stringify(document)]);
    return id;
}

test('a draft saved with backslash links opens in recovery and becomes a normal draft after an explicit repair', async ({ page }) => {
    // An image uploaded through the editor (on another page), for the older page to use.
    const scratch = await createPage('/recovery-upload', 'Upload');
    await page.goto(`/admin/editor/${scratch}`);
    await page.getByTestId('edit-hero-image').click();
    await page.getByLabel('Upload image').setInputFiles({ name: 'dot.png', mimeType: 'image/png', buffer: PNG_1X1 });
    await expect(canvas(page).locator('img')).toBeVisible();
    await page.getByRole('button', { name: 'Save draft' }).click();
    await expect(status(page)).toHaveText('Draft saved');
    const { rows } = await db.query<{ id: string }>('select id from media_assets order by created_at desc limit 1');
    const assetId = rows[0]!.id;

    const id = await olderDraft('/recovery', assetId, ['/\\example.com', 'https://\\evil.example']);
    const stored = (await db.query<{ document: unknown }>('select document from page_drafts where page_id = $1', [id])).rows[0]!.document;
    const response = await page.goto(`/admin/editor/${id}`);
    expect(response?.status()).toBe(200);

    // Recovery: the stored values exactly as saved, the page as stored (image included), nothing else editable.
    const panel = page.getByTestId('recovery');
    await expect(panel.getByRole('heading', { name: 'This draft needs repair' })).toBeVisible();
    await expect(panel.getByTestId('stored-value')).toHaveText(['/\\example.com', 'https://\\evil.example']);
    await expect(status(page)).toHaveText('Needs repair');
    for (const name of ['Save draft', 'Publish', 'Preview', 'Undo']) await expect(page.getByRole('button', { name, exact: true })).toBeDisabled();
    await expect(canvas(page).locator('img')).toHaveAttribute('alt', 'A dot');
    await expect(canvas(page).locator('a.ak-btn2')).toHaveCount(2);
    await canvas(page).locator('h1').click();
    await expect(canvas(page).locator('h1')).not.toHaveAttribute('contenteditable');
    const apply = panel.getByRole('button', { name: 'Apply repair' });
    await expect(apply).toBeDisabled();

    // The user decides each one: a new link must itself be valid; or the block goes.
    const [first, second] = [panel.getByTestId('recovery-item').nth(0), panel.getByTestId('recovery-item').nth(1)];
    await first.getByLabel('Correct the link').check();
    await first.getByLabel(/New link for/).fill('/\\still.example');
    await expect(first).toContainText('Use a link starting with');
    await expect(apply).toBeDisabled();
    await first.getByLabel(/New link for/).fill('/contact');
    await second.getByLabel('Remove this block').check();
    // Nothing was written while choosing.
    expect((await db.query<{ document: unknown }>('select document from page_drafts where page_id = $1', [id])).rows[0]!.document).toEqual(stored);

    await apply.click();
    await expect(panel).toHaveCount(0);
    await expect(notice(page)).toContainText('Repair applied');
    await expect(status(page)).toHaveText('Unsaved changes');
    await expect(canvas(page).locator('a.ak-btn2')).toHaveCount(1);
    await expect(canvas(page).locator('a.ak-btn2')).toHaveAttribute('href', '/contact');
    await expect(canvas(page).locator('img')).toHaveAttribute('alt', 'A dot');
    await expect(page.getByRole('button', { name: 'Undo' })).toBeDisabled(); // history starts after the repair

    // A normal draft again: it saves, reopens without recovery, and publishes with its image.
    await page.getByRole('button', { name: 'Save draft' }).click();
    await expect(status(page)).toHaveText('Draft saved');
    await page.reload();
    await expect(page.getByTestId('recovery')).toHaveCount(0);
    await expect(status(page)).toHaveText('Draft saved');
    await page.getByRole('button', { name: 'Publish' }).click();
    await expect(notice(page)).toContainText('Published');
    const live = await publicHtml(page, '/recovery');
    expect(live.status).toBe(200);
    expect(live.html).toContain('<a class="ak-btn2 ak-btn2--primary" href="/contact">Contact</a>');
    expect(live.html).toContain(`/media/${assetId}.png`);
    expect(live.html).not.toContain('\\');
    expect(live.html).not.toContain('Partner');
});

test('a single stored backslash link can be repaired by removing its block', async ({ page }) => {
    const scratch = await createPage('/recovery-upload-2', 'Upload');
    await page.goto(`/admin/editor/${scratch}`);
    await page.getByTestId('edit-hero-image').click();
    await page.getByLabel('Upload image').setInputFiles({ name: 'dot.png', mimeType: 'image/png', buffer: PNG_1X1 });
    await expect(canvas(page).locator('img')).toBeVisible();
    await page.getByRole('button', { name: 'Save draft' }).click();
    await expect(status(page)).toHaveText('Draft saved');
    const assetId = (await db.query<{ id: string }>('select id from media_assets order by created_at desc limit 1')).rows[0]!.id;
    const id = await olderDraft('/recovery-one', assetId, ['/\\example.com', '/partners']);

    await page.goto(`/admin/editor/${id}`);
    const panel = page.getByTestId('recovery');
    await expect(panel.getByTestId('recovery-item')).toHaveCount(1);
    await panel.getByLabel('Remove this block').check();
    await panel.getByRole('button', { name: 'Apply repair' }).click();
    await expect(canvas(page).locator('a.ak-btn2')).toHaveCount(1);
    await expect(canvas(page).locator('a.ak-btn2')).toHaveAttribute('href', '/partners');
    await page.getByRole('button', { name: 'Publish' }).click(); // saves first, then publishes
    await expect(notice(page)).toContainText('Published');
    expect((await publicHtml(page, '/recovery-one')).html).not.toContain('Contact');
});
