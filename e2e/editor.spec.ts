import { expect, test, type APIRequestContext, type Page } from '@playwright/test';
import { E2E_OWNER, PNG_1X1 } from './fixtures';
import { BASE_URL } from './support';

// This file exercises the login flow itself, so it starts signed out.
test.use({ storageState: { cookies: [], origins: [] } });

async function login(page: Page) {
    await page.goto('/admin');
    await expect(page).toHaveURL(/\/login\?next=%2Fadmin/);
    await page.getByLabel('Email').fill(E2E_OWNER.email);
    await page.getByLabel('Password').fill(E2E_OWNER.password);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page.getByRole('heading', { name: `Welcome, ${E2E_OWNER.name}` })).toBeVisible();
}

/** The live home page as an anonymous visitor sees it. */
async function live(request: APIRequestContext) {
    const response = await request.get('/', { headers: { cookie: '' } });
    return { status: response.status(), html: await response.text(), headers: response.headers() };
}

test('draft, preview, history and publish keep the live page safe', async ({ page, playwright }) => {
    const anonymous = await playwright.request.newContext({ baseURL: BASE_URL });

    await login(page);
    // The conditions of http://arkonlaravel.test: an insecure context without crypto.randomUUID.
    // Every write below (save, publish, upload, restore) generates request keys here.
    expect(await page.evaluate(() => ({ secure: window.isSecureContext, randomUUID: typeof crypto.randomUUID }))).toEqual({
        secure: false,
        randomUUID: 'undefined',
    });
    const homeRow = page.getByTestId('page-row').filter({ has: page.getByText('Home', { exact: true }) });
    await expect(homeRow.getByText('Not published', { exact: true })).toBeVisible();
    expect((await live(anonymous)).status).toBe(404);

    // ── Open the editor and edit the hero heading inline in the iframe canvas ──
    await homeRow.getByRole('link', { name: 'Edit' }).click();
    const canvas = page.frameLocator('[data-testid="canvas"]');
    const heading = canvas.locator('h1');
    await expect(heading).toHaveText('Build visually. Describe the rest.');

    await heading.click();
    await expect(heading).toHaveAttribute('contenteditable', 'plaintext-only');
    await page.keyboard.press('ControlOrMeta+A');
    await page.keyboard.type('Hello from the canvas');
    await expect(page.getByLabel('Heading', { exact: true })).toHaveValue('Hello from the canvas');
    await expect(page.getByTestId('save-status')).toHaveText('Unsaved changes');

    await page.getByRole('button', { name: 'Save draft' }).click();
    await expect(page.getByTestId('save-status')).toHaveText('Draft saved');
    // Saving a draft never publishes.
    expect((await live(anonymous)).status).toBe(404);

    // ── Publish ──
    await page.getByRole('button', { name: 'Publish' }).click();
    await expect(page.getByTestId('live-status')).toContainText('Live: revision #2');
    const published = await live(anonymous);
    expect(published.status).toBe(200);
    expect(published.html).toContain('<h1 class="ak-hero3__heading">Hello from the canvas</h1>');
    // No editor attributes, scripts, framework bundles or editor assets on the public page.
    expect(published.html).not.toContain('data-ak-');
    expect(published.html).not.toMatch(/<script/i);
    expect(published.html).not.toContain('/build/');
    expect(published.html).not.toContain('data-page');
    expect(published.html).not.toContain('contenteditable');
    expect(published.headers['content-security-policy']).toContain("script-src 'none'");

    // ── Unpublished edits do not reach the live page; preview shows them ──
    await page.getByLabel('Heading', { exact: true }).fill('Unpublished heading');
    await page.getByRole('button', { name: 'Save draft' }).click();
    await expect(page.getByTestId('save-status')).toHaveText('Draft saved');
    const afterDraft = await live(anonymous);
    expect(afterDraft.html).toBe(published.html);

    // Fetched by the signed-in browser itself (same origin, same cookies).
    const previewPath = new URL(page.url()).pathname.replace(/\/admin\/editor\//, '/preview/');
    const preview = await page.evaluate(async (path) => {
        const response = await fetch(path);
        return { status: response.status, robots: response.headers.get('x-robots-tag'), html: await response.text() };
    }, previewPath);
    expect(preview.status).toBe(200);
    expect(preview.robots).toContain('noindex');
    expect(preview.html).toContain('Unpublished heading');
    expect(preview.html).not.toContain('data-ak-');

    // Preview is for signed-in members only.
    const anonymousPreview = await anonymous.get(previewPath, { maxRedirects: 0 });
    expect(anonymousPreview.status()).toBe(302);

    // ── Revision history and restore ──
    await page.getByRole('tab', { name: 'History' }).click();
    const revisions = page.getByTestId('revision');
    await expect(revisions).toHaveCount(3);
    await expect(revisions.first()).toContainText('#3 Saved draft');
    await expect(revisions.nth(1)).toContainText('Live now');
    await revisions.nth(1).getByRole('button', { name: 'Restore' }).click();
    await expect(page.getByTestId('notice')).toContainText('Restored revision #2');
    await expect(heading).toHaveText('Hello from the canvas');
    await expect(revisions.first()).toContainText('#4 Restored from #2');
    expect((await live(anonymous)).html).toBe(published.html);

    // ── Image: upload, alt text is required to publish ──
    await page.getByRole('tab', { name: 'Properties' }).click();
    await page.getByTestId('part-media').click();
    await page.getByLabel('Upload image').setInputFiles({ name: 'dot.png', mimeType: 'image/png', buffer: PNG_1X1 });
    await expect(canvas.locator('img.ak-hero3__media')).toBeVisible();
    // Unpublished uploads are private: the sandboxed canvas loads them through a signed URL,
    // anonymous visitors get 404.
    const canvasSrc = await canvas.locator('img.ak-hero3__media').getAttribute('src');
    expect(canvasSrc).toMatch(/^\/media\/[0-9a-f-]+\.png\?t=\d+\./);
    await expect.poll(() => canvas.locator('img.ak-hero3__media').evaluate((img: HTMLImageElement) => img.naturalWidth)).toBe(1);
    expect((await anonymous.get(canvasSrc!.split('?')[0]!)).status()).toBe(404);
    await page.getByRole('button', { name: 'Publish' }).click();
    await expect(page.getByTestId('notice')).toContainText('Hero image needs alternative text');
    expect((await live(anonymous)).html).toBe(published.html);

    await page.getByLabel(/Alternative text/).fill('A single pixel');
    await page.getByRole('button', { name: 'Publish' }).click();
    await expect(page.getByTestId('notice')).toContainText('Published');
    const withImage = await live(anonymous);
    expect(withImage.html).toMatch(/<img class="ak-hero3__media" src="\/media\/[0-9a-f-]+\.png" alt="A single pixel" width="1" height="1"/);
    const imageUrl = withImage.html.match(/src="(\/media\/[^"]+)"/)![1]!;
    const image = await anonymous.get(imageUrl);
    expect(image.headers()['content-type']).toBe('image/png');

    await anonymous.dispose();
});

test('reserved paths are not public pages, unknown paths return 404 and there is no sign-up', async ({ playwright }) => {
    const anonymous = await playwright.request.newContext({ baseURL: BASE_URL });
    expect((await anonymous.get('/does-not-exist')).status()).toBe(404);
    const admin = await anonymous.get('/admin', { maxRedirects: 0 });
    expect(admin.status()).toBe(302);
    expect(admin.headers()['location']).toContain('/login?next=%2Fadmin');
    expect((await anonymous.get('/register')).status()).toBe(404);
    expect((await anonymous.post('/register', { data: { email: 'x@e2e.test', password: 'x'.repeat(16) } })).status()).toBeGreaterThanOrEqual(404);
    // Public 404s set no cookies and load no admin assets.
    const missing = await anonymous.get('/nothing-here');
    expect(missing.headers()['set-cookie']).toBeUndefined();
    expect(await missing.text()).not.toContain('/build/');
    await anonymous.dispose();
});
