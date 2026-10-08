// The reusable-component editor's safety rules, the same as the page editor's: field input that
// can't be applied yet blocks publishing (also when typed while a save is pending), and a save
// whose response was lost is retried as the identical request, including a rename on its own.
import { expect, test, type Page, type Route } from '@playwright/test';
import { APP_ORIGIN, E2E_HOST, PORT, SERVER_URL } from './env';
import { db } from './support';

const canvas = (page: Page) => page.frameLocator('[data-testid="canvas"]');
const status = (page: Page) => page.getByTestId('component-status');
const linkField = (page: Page) => page.getByLabel('Link', { exact: true });

/** A draft reusable component with one button (never published). */
async function createComponent(name: string): Promise<string> {
    const { rows } = await db.query<{ site_id: string }>('select site_id from site_domains where hostname = $1', [`${E2E_HOST}:${PORT}`]);
    const id = crypto.randomUUID();
    const draft = {
        schemaVersion: 1,
        root: 'cmpRoot001',
        nodes: {
            cmpRoot001: { id: 'cmpRoot001', type: 'fragment', version: 1, props: {}, children: ['cmpBtn0001'] },
            cmpBtn0001: {
                id: 'cmpBtn0001',
                type: 'button',
                version: 2,
                props: { label: 'Contact us', href: '/', variant: 'primary', size: 'medium', newTab: false, style: {} },
            },
        },
        seo: {},
    };
    await db.query('insert into reusable_components (id, site_id, name, draft) values ($1, $2, $3, $4)', [id, rows[0]!.site_id, name, JSON.stringify(draft)]);
    return id;
}

async function component(id: string) {
    const { rows } = await db.query<{ name: string; version: number; published_version: number | null; draft: string }>(
        'select name, version, published_version, draft::text from reusable_components where id = $1',
        [id],
    );
    return rows[0]!;
}

async function publishedDocument(id: string): Promise<string | null> {
    const { rows } = await db.query<{ document: string }>(
        'select document::text from reusable_component_versions where component_id = $1 order by version desc limit 1',
        [id],
    );
    return rows[0]?.document ?? null;
}

/** Interferes with the component editor's next save request only. */
async function interceptSave(page: Page, mode: 'delay' | 'drop-response-after-commit') {
    let release: () => void = () => {};
    const gate = new Promise<void>((resolve) => (release = resolve));
    let done = false;
    const handler = async (route: Route) => {
        const request = route.request();
        if (done || request.method() !== 'POST' || !/\/admin\/api\/components\/[^/]+\/save$/.test(new URL(request.url()).pathname)) return route.continue();
        done = true;
        if (mode === 'delay') {
            await gate;
            return route.continue();
        }
        // Reaches the server and commits; the browser sees a network error.
        await route.fetch({ url: request.url().replace(APP_ORIGIN, SERVER_URL), headers: { ...(await request.allHeaders()), host: `${E2E_HOST}:${PORT}` } });
        return route.abort('connectionreset');
    };
    await page.route('**/admin/api/components/**', handler);
    return { release, stop: () => page.unroute('**/admin/api/components/**', handler) };
}

async function selectButton(page: Page) {
    await canvas(page).locator('.ak-btn2').first().click();
    await expect(linkField(page)).toBeVisible();
}

test('an invalid link already in the field blocks publishing until it is corrected or reverted', async ({ page }) => {
    const id = await createComponent('Contact button');
    await page.goto(`/admin/components/${id}`);
    await selectButton(page);
    await linkField(page).fill('/contact');
    await page.getByRole('button', { name: 'Save draft' }).click();
    await expect(status(page)).toContainText('Draft saved');

    await linkField(page).fill('https://');
    await expect.soft(status(page)).toContainText('1 invalid field not saved');
    // Somewhere else on the page first, so "brought into view" means something.
    await canvas(page)
        .locator('body')
        .click({ position: { x: 5, y: 5 } });
    await page.getByTestId('component-publish').click();
    await expect(page.getByTestId('notice')).toContainText('Fix or revert the Button link before publishing');
    await expect(linkField(page)).toBeInViewport();
    await expect(linkField(page)).toBeFocused();
    await expect(linkField(page)).toHaveValue('https://');
    expect((await component(id)).published_version).toBeNull();

    // Reverting restores the saved link, and publishing works normally.
    await page.getByRole('button', { name: 'Revert link' }).click();
    await expect(linkField(page)).toHaveValue('/contact');
    await page.getByTestId('component-publish').click();
    await expect(page.getByRole('status')).toContainText('Published version 1');
    expect(await publishedDocument(id)).toContain('"href": "/contact"');
});

test('a link made invalid while a save is pending blocks the publish that waited for that save', async ({ page }) => {
    const id = await createComponent('Pending button');
    await page.goto(`/admin/components/${id}`);
    await selectButton(page);
    const held = await interceptSave(page, 'delay');
    await linkField(page).fill('/contact');
    await page.getByRole('button', { name: 'Save draft' }).click();
    await expect.soft(status(page)).toContainText('Saving');
    await page.getByTestId('component-publish').click();
    await linkField(page).fill('https://');
    held.release();
    await expect(page.getByTestId('notice')).toContainText('Fix or revert the Button link before publishing');
    await held.stop();
    expect((await component(id)).published_version).toBeNull();
    expect((await component(id)).draft).toContain('"href": "/contact"');

    // Correcting it publishes the corrected link.
    await linkField(page).fill('https://example.com/contact');
    await page.getByTestId('component-publish').click();
    await expect(page.getByRole('status')).toContainText('Published version 1');
    expect(await publishedDocument(id)).toContain('"href": "https://example.com/contact"');
});

test('a rename-only save whose response is lost is confirmed by saving again, never "changed elsewhere"', async ({ page }) => {
    const id = await createComponent('Old name');
    await page.goto(`/admin/components/${id}`);
    await page.getByLabel('Component name').fill('New name');
    await expect(status(page)).toContainText('Unsaved changes');

    const dropped = await interceptSave(page, 'drop-response-after-commit');
    await page.getByRole('button', { name: 'Save draft' }).click();
    await expect(page.getByTestId('notice')).toContainText("Couldn't confirm the save");
    await expect(status(page)).toContainText('Save not confirmed');
    await dropped.stop();
    expect(await component(id)).toMatchObject({ name: 'New name', version: 2 });

    // A later name edit does not change the request being retried.
    await page.getByLabel('Component name').fill('Newer name');
    await page.keyboard.press('ControlOrMeta+S');
    await expect(page.getByRole('alert')).toHaveCount(0);
    expect(await component(id)).toMatchObject({ version: 2 });
    await expect(status(page)).toContainText('Unsaved changes');
    await page.keyboard.press('ControlOrMeta+S');
    await expect(status(page)).toContainText('Draft saved');
    expect(await component(id)).toMatchObject({ name: 'Newer name', version: 3 });
    await page.reload();
    await expect(page.getByLabel('Component name')).toHaveValue('Newer name');
});

test('an unsaved rename alone is protected when leaving the component editor', async ({ page }) => {
    const id = await createComponent('Guarded');
    await page.goto(`/admin/components/${id}`);
    await page.getByLabel('Component name').fill('Guarded, renamed');
    let asked = 0;
    page.on('dialog', (dialog) => {
        asked++;
        void dialog.dismiss();
    });
    await page
        .getByRole('link', { name: /Design/ })
        .first()
        .click();
    await expect(page.getByLabel('Component name')).toHaveValue('Guarded, renamed');
    expect(asked).toBe(1);
    expect(page.url()).toContain(`/admin/components/${id}`);
});
