import { expect, test } from '@playwright/test';

const email = process.env.HERD_EMAIL ?? '';
const password = process.env.HERD_PASSWORD ?? '';

test('the admin works through Herd and public pages stay clean', async ({ page, playwright, baseURL }) => {
    test.skip(!email || !password, 'Set HERD_EMAIL and HERD_PASSWORD (an existing account) to run this smoke test.');
    const anonymous = await playwright.request.newContext({ baseURL });

    // Admin is behind sign-in.
    const admin = await anonymous.get('/admin', { maxRedirects: 0 });
    expect(admin.status()).toBe(302);
    expect(admin.headers()['location']).toContain('/login?next=%2Fadmin');

    // Public pages: no session cookie, no admin assets, stored HTML or a plain 404.
    const home = await anonymous.get('/', { maxRedirects: 0 });
    expect([200, 301, 404]).toContain(home.status());
    expect(home.headers()['set-cookie']).toBeUndefined();
    const homeHtml = await home.text();
    expect(homeHtml).not.toContain('/build/');
    expect(homeHtml).not.toMatch(/<script/i);

    // Sign in through the real form.
    await page.goto('/admin');
    await expect(page).toHaveURL(/\/login\?next=%2Fadmin/);
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(password);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page.getByRole('heading', { name: /^Welcome, / })).toBeVisible();
    await expect(page.getByTestId('page-row').first()).toBeVisible();

    // Herd serves plain http on a .test name: an insecure context without crypto.randomUUID.
    expect(await page.evaluate(() => ({ secure: window.isSecureContext, randomUUID: typeof crypto.randomUUID }))).toEqual({
        secure: false,
        randomUUID: 'undefined',
    });
    // A write request that generates a fresh request key here, refused by the server before anything is
    // written (reserved URL). Before the request-key fix this threw in the browser and never reached the server.
    await page.goto('/admin/pages');
    const form = page.getByRole('form', { name: 'New page' });
    await form.getByLabel('Title').fill('Herd smoke check');
    await form.getByLabel('URL path').fill('/admin');
    const createRequest = page.waitForRequest((r) => r.method() === 'POST' && r.url().endsWith('/admin/api/pages'));
    await form.getByRole('button', { name: 'Create page' }).click();
    expect((await createRequest).postDataJSON().requestKey).toMatch(/^[0-9a-f]{32}$/);
    await expect(form.getByRole('alert')).toContainText('reserved');
    await page.goto('/admin');

    // The editor: sandboxed canvas rendered by the server's renderer, inspector, history.
    await page.getByTestId('page-row').first().getByRole('link', { name: 'Edit' }).click();
    const canvas = page.frameLocator('[data-testid="canvas"]');
    await expect(canvas.locator('[data-ak-type="hero"]').first()).toBeVisible();
    await expect(page.getByTestId('save-status')).toHaveText('Draft saved');
    await expect(page.locator('iframe[data-testid="canvas"]')).toHaveAttribute('sandbox', 'allow-scripts');
    await page.getByRole('tab', { name: 'History' }).click();
    await expect(page.getByTestId('revision').first()).toBeVisible();

    // Preview: the draft rendered for members only.
    const editorUrl = page.url();
    const preview = await page.request.get(editorUrl.replace('/admin/editor/', '/preview/'));
    expect(preview.status()).toBe(200);
    expect(preview.headers()['x-robots-tag']).toContain('noindex');
    expect(await preview.text()).not.toContain('data-ak-');
    expect((await anonymous.get(new URL(editorUrl.replace('/admin/editor/', '/preview/')).pathname, { maxRedirects: 0 })).status()).toBe(302);

    await anonymous.dispose();
});
