import { expect, test, type Page } from '@playwright/test';
import { createPage, db } from './support';
import { PNG_1X1 } from './fixtures';
import { APP_ORIGIN, SERVER_URL } from './env';

const image = { name: 'new-background.png', mimeType: 'image/png', buffer: PNG_1X1 };
const background = (page: Page) => page.getByTestId('style-backgroundImage');
async function select(page: Page, id: string) {
    await page.getByRole('tab', { name: 'Layers', exact: true }).click();
    await page.locator(`[data-testid=layer][data-node-id="${id}"]`).click();
    await page.getByRole('tab', { name: 'Properties', exact: true }).click();
    if (!(await background(page).getByRole('button', { name: 'Upload a new image', exact: true }).isVisible()))
        await page.getByText('Background', { exact: true }).last().click();
    await expect(background(page).getByRole('button', { name: 'Upload a new image', exact: true })).toBeVisible();
}
async function fixture(mode: 'page' | 'component') {
    const pageId = await createPage('/background-' + mode + '-' + crypto.randomUUID().slice(0, 8), 'Background uploads');
    const row = (await db.query('select site_id,document from page_drafts where page_id=$1', [pageId])).rows[0];
    const doc = row.document;
    const root = doc.root;
    doc.nodes[root].children.push('group00001', 'group00002');
    for (const id of ['group00001', 'group00002'])
        doc.nodes[id] = { id, type: 'group', version: 5, props: { style: { root: { base: { minHeight: '120px' } } } }, children: [] };
    if (mode === 'page') {
        await db.query('update page_drafts set document=$1 where page_id=$2', [JSON.stringify(doc), pageId]);
        return { id: pageId, url: '/admin/editor/' + pageId };
    }
    doc.nodes[root] = { id: root, type: 'fragment', version: 1, props: {}, children: ['group00001', 'group00002'] };
    for (const [id, node] of Object.entries(doc.nodes)) if ((node as { type: string }).type === 'hero') delete doc.nodes[id];
    const id = crypto.randomUUID();
    await db.query('insert into reusable_components(id,site_id,name,draft) values($1,$2,$3,$4)', [
        id,
        row.site_id,
        'Background component',
        JSON.stringify(doc),
    ]);
    return { id, url: '/admin/components/' + id };
}
for (const mode of ['page', 'component'] as const)
    test(`${mode}: background upload, failure recovery, undo and reload`, async ({ page, playwright }) => {
        const f = await fixture(mode);
        await page.goto(f.url);
        await select(page, 'group00001');
        if (mode === 'component') {
            await page.route('**/admin/api/media', (route) => route.abort('connectionreset'), { times: 1 });
            await background(page).getByLabel('Upload image').setInputFiles(image);
            await expect(background(page).getByRole('alert')).toContainText('could not be confirmed');
            await expect(background(page).getByRole('button', { name: 'Upload a new image', exact: true })).toBeEnabled();
        }
        await background(page)
            .getByLabel('Upload image')
            .setInputFiles({ name: 'broken.png', mimeType: 'image/png', buffer: Buffer.from('not an image') });
        await expect(background(page).getByRole('alert')).toContainText('supported');
        await expect(background(page).getByRole('button', { name: 'Upload a new image', exact: true })).toBeEnabled();
        await background(page).getByLabel('Upload image').setInputFiles(image);
        await expect(background(page).getByText('new-background.png', { exact: true })).toBeVisible();
        const painted = page.frameLocator('[data-testid=canvas]').locator('[data-ak-id="group00001"]');
        await expect.poll(() => painted.evaluate((el) => getComputedStyle(el).backgroundImage)).toContain('/media/');
        await page.getByRole('button', { name: 'Undo', exact: true }).click();
        await expect(background(page).getByText('No image', { exact: true })).toHaveText('No image');
        await expect.poll(() => painted.evaluate((el) => getComputedStyle(el).backgroundImage)).toBe('none');
        await page.getByRole('button', { name: 'Redo', exact: true }).click();
        await expect.poll(() => painted.evaluate((el) => getComputedStyle(el).backgroundImage)).toContain('/media/');
        await page.getByRole('button', { name: 'Save draft', exact: true }).click();
        await expect(page.getByTestId(mode === 'page' ? 'save-status' : 'component-save-status')).toContainText('Draft saved');
        await page.reload();
        await select(page, 'group00001');
        await expect(background(page).getByText('new-background.png', { exact: true })).toBeVisible();
        let anonymous: Awaited<ReturnType<typeof playwright.request.newContext>> | undefined;
        let mediaUrl = '';
        if (mode === 'page') {
            const doc = (await db.query('select document from page_drafts where page_id=$1', [f.id])).rows[0].document;
            const assetId = doc.nodes.group00001.props.style.root.base.backgroundImage.assetId;
            mediaUrl = '/media/' + (await db.query('select storage_key from media_assets where id=$1', [assetId])).rows[0].storage_key;
            anonymous = await playwright.request.newContext({ baseURL: SERVER_URL, extraHTTPHeaders: { Host: new URL(APP_ORIGIN).host } });
            expect((await anonymous.get(mediaUrl)).status()).toBe(404);
        }
        await page.getByRole('button', { name: mode === 'page' ? 'Publish' : 'Publish component', exact: true }).click();
        if (anonymous) {
            await expect.poll(async () => (await anonymous!.get(mediaUrl)).status()).toBe(200);
            await anonymous.dispose();
        }
        await expect(page.getByTestId('notice')).toContainText('Published');
    });
test('upload finishing after selection changes stays in the library without changing either block', async ({ page }) => {
    const f = await fixture('page');
    await page.goto(f.url);
    await select(page, 'group00001');
    let release!: () => void;
    const gate = new Promise<void>((resolve) => (release = resolve));
    let arrived!: () => void;
    const ready = new Promise<void>((resolve) => (arrived = resolve));
    await page.route('**/admin/api/media', async (route) => {
        arrived();
        await gate;
        await route.continue();
    });
    await background(page).getByLabel('Upload image').setInputFiles(image);
    await ready;
    await expect(background(page).getByRole('status')).toBeVisible();
    await select(page, 'group00002');
    const finished = page.waitForResponse((response) => response.url().endsWith('/admin/api/media') && response.request().method() === 'POST');
    release();
    await finished;
    await expect
        .poll(async () => (await db.query("select count(*)::int n from media_assets where original_name='new-background.png'")).rows[0].n)
        .toBeGreaterThan(0);
    await background(page).getByRole('button', { name: 'Choose from library', exact: true }).click();
    await expect(background(page).getByRole('button', { name: 'Use new-background.png (1 × 1)', exact: true }).first()).toBeVisible();
    await select(page, 'group00001');
    await expect(background(page).getByText('No image', { exact: true })).toBeVisible();
    await select(page, 'group00002');
    await expect(background(page).getByText('No image', { exact: true })).toBeVisible();
});
