// Review screenshots of the admin (not a regression test): runs only with SCREENSHOTS=<label>, e.g.
//   $env:SCREENSHOTS='after'; npx playwright test e2e/screenshots.spec.ts
// and writes PNGs to storage/screenshots/<label>/ (git-ignored). The e2e database holds fixture data
// only (no real accounts or credentials), and the AI panel uses the fake Claude Code CLI.
import { expect, test, type Page } from '@playwright/test';
import { AUTH_STATE, E2E_HOST } from './env';
import { createPage, db } from './support';

const LABEL = process.env.SCREENSHOTS ?? '';
// Headless Chromium hides scrollbars; show them, as people see them.
test.use({ launchOptions: { args: [`--host-resolver-rules=MAP ${E2E_HOST} 127.0.0.1`], ignoreDefaultArgs: ['--hide-scrollbars'] } });
test.skip(LABEL === '', 'Set SCREENSHOTS=<label> to capture review screenshots');

const ACCEPTANCE =
    'Keep the hero text on the left. Put its image on the right, make the image 500px tall with cover cropping, and stack the image below the text on mobile.';
const dir = `storage/screenshots/${LABEL}`;
const canvas = (page: Page) => page.frameLocator('[data-testid="canvas"]');

/** A photo-like PNG made by the browser itself (a gradient landscape), so the screenshots show a real image. */
async function landscape(page: Page): Promise<Buffer> {
    const scratch = await page.context().newPage();
    await scratch.setViewportSize({ width: 1200, height: 800 });
    await scratch.setContent(
        `<body style="margin:0"><div style="width:1200px;height:800px;background:
        radial-gradient(circle at 75% 28%, #fff7d6 0 6%, transparent 7%),
        linear-gradient(180deg, #9cc9e8 0%, #d9ecf2 46%, #6f9c5a 47%, #3f6b3a 70%, #2c4a2b 100%)"></div></body>`,
    );
    const buffer = await scratch.screenshot();
    await scratch.close();
    return buffer;
}

async function shot(page: Page, name: string) {
    await page.waitForTimeout(400);
    await page.screenshot({ path: `${dir}/${name}.png` });
}

for (const theme of ['light', 'dark'] as const) {
    test(`review screenshots (${theme})`, async ({ page }) => {
        test.setTimeout(180_000);
        await page.setViewportSize({ width: 1440, height: 900 });
        await page.addInitScript((value) => localStorage.setItem('arkon.theme', value), theme);
        const suffix = theme === 'light' ? '' : '-dark';

        const id = await createPage(`/spring-${theme}`, 'Gardens designed for every season');
        await createPage(`/journal-${theme}`, 'Journal');
        await page.goto(`/admin/editor/${id}`);
        await page.getByTestId('edit-hero-image').click();
        await page.getByLabel('Upload image').setInputFiles({ name: 'garden.png', mimeType: 'image/png', buffer: await landscape(page) });
        await page.getByLabel(/Alternative text/).fill('A walled garden in spring');
        await page.getByRole('button', { name: 'Save draft' }).click();
        await expect(page.getByTestId('save-status')).toHaveText('Draft saved');
        await page.getByRole('button', { name: 'Publish' }).click();
        await expect(page.getByTestId('notice')).toContainText('Published');

        await page.goto('/admin');
        await shot(page, `dashboard${suffix}`);

        await page.goto(`/admin/editor/${id}`);
        await canvas(page)
            .locator('section')
            .first()
            .click({ position: { x: 8, y: 8 } });
        await shot(page, `builder-properties${suffix}`);

        // The image as the editing target (or, before the redesign, the closest equivalent: the hero's image fields).
        await canvas(page).locator('img').first().click();
        const imageControls = page.getByTestId('image-sizing');
        if (await imageControls.count()) await imageControls.scrollIntoViewIfNeeded();
        else {
            const slot = page.getByTestId('design-slot');
            if (await slot.count()) await slot.selectOption('media');
            await page.getByTestId('design-panel').scrollIntoViewIfNeeded();
        }
        await shot(page, `image-selected${suffix}`);

        // The hero section itself, with its own sizing.
        const parent = page.getByTestId('inspector-target-parent');
        if (await parent.count()) await parent.click();
        else {
            const slot = page.getByTestId('design-slot');
            if (await slot.count()) await slot.selectOption('root');
        }
        const size = page.getByTestId('section-sizing');
        if (await size.count()) await size.scrollIntoViewIfNeeded();
        else await page.getByTestId('design-panel').scrollIntoViewIfNeeded();
        await shot(page, `section-selected${suffix}`);

        await page.getByRole('tab', { name: 'AI' }).click();
        await page.getByLabel('Ask AI to change this page').fill(ACCEPTANCE);
        await page.getByRole('button', { name: 'Generate proposal' }).click();
        await expect(page.getByTestId('ai-proposal')).toBeVisible({ timeout: 30_000 });
        await shot(page, `ai-proposal${suffix}`);
        await page.getByTestId('ai-proposal').getByRole('button', { name: 'Discard' }).click();

        await page.goto('/admin/design');
        await shot(page, `design${suffix}`);

        await page.goto('/admin/pages');
        await shot(page, `pages${suffix}`);

        for (const [path, name] of [
            ['/admin/media', 'media'],
            ['/admin/forms', 'forms'],
            ['/admin/navigation', 'navigation'],
            ['/admin/design/components', 'components'],
            ['/admin/themes', 'themes'],
            ['/admin/website', 'website'],
            ['/admin/settings', 'settings'],
        ] as const) {
            await page.goto(path);
            await shot(page, `${name}${suffix}`);
        }

        // Signed out: the login screen.
        const anonymous = await page
            .context()
            .browser()!
            .newContext({ storageState: { cookies: [], origins: [] }, viewport: { width: 1440, height: 900 } });
        const login = await anonymous.newPage();
        await login.addInitScript((value) => localStorage.setItem('arkon.theme', value), theme);
        await login.goto('/login');
        await shot(login, `login${suffix}`);
        await anonymous.close();
    });
}

test('review screenshots (sidebar at short heights)', async ({ page }) => {
    for (const theme of ['light', 'dark'] as const) {
        await page.addInitScript((value) => localStorage.setItem('arkon.theme', value), theme);
        for (const height of [768, 800, 900]) {
            await page.setViewportSize({ width: 1440, height });
            // The last destination: the rail scrolls it into view, while identity and account stay put.
            await page.goto('/admin/settings');
            const nav = page.getByRole('navigation', { name: 'Main' });
            await expect(nav.getByRole('link', { name: 'Site settings' })).toBeInViewport({ ratio: 1 });
            await expect(page.getByRole('button', { name: 'Sign out' })).toBeInViewport();
            await shot(page, `sidebar-${height}${theme === 'dark' ? '-dark' : ''}`);
        }
    }
});

test('review screenshots (SEO workspace with 3, 20 and 100 pages)', async ({ page }) => {
    test.setTimeout(600_000);
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/admin/seo');
    let made = 0;
    // Pages with varied metadata (so scores differ), published through the editor's API from the signed-in page.
    const addPages = async (upTo: number) => {
        for (; made < upTo; made++) {
            const id = await createPage(`/seo-${made}`, made % 4 === 0 ? `Services we offer ${made}` : `Page ${made}`);
            const seo = { ...(made % 3 === 0 ? { description: `A clear summary of page ${made} for search results and sharing.` } : {}) };
            await db.query(`update page_drafts set document = jsonb_set(document, '{seo}', $1::jsonb) where page_id = $2`, [JSON.stringify(seo), id]);
            // No main heading on some pages (a lower band), with or without a description.
            if (made % 3 === 1 || made % 7 === 0)
                await db.query(`update page_drafts set document = jsonb_set(document, '{nodes,e2eHero001,props,headingLevel}', '"h2"') where page_id = $1`, [
                    id,
                ]);
            if (made % 5 === 4) continue; // some stay unpublished
            const ok = await page.evaluate(async (pageId) => {
                const token = decodeURIComponent(document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/)?.[1] ?? '');
                const response = await fetch(`/admin/api/pages/${pageId}/publish`, {
                    method: 'POST',
                    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': token },
                    body: JSON.stringify({
                        expectedVersion: 1,
                        idempotencyKey: crypto.getRandomValues(new Uint32Array(4)).join('').slice(0, 32).padEnd(32, '0'),
                    }),
                });
                return response.ok;
            }, id);
            expect(ok).toBe(true);
        }
    };
    for (const count of [3, 20, 100]) {
        await addPages(count);
        for (const theme of ['light', 'dark'] as const) {
            await page.evaluate((value) => localStorage.setItem('arkon.theme', value), theme);
            const started = Date.now();
            await page.goto('/admin/seo');
            await expect(page.getByTestId('seo-overview')).toBeVisible();
            console.log(`SEO workspace, ${count} pages (${theme}): ${Date.now() - started} ms`);
            await page.screenshot({ path: `${dir}/seo-${count}${theme === 'dark' ? '-dark' : ''}.png`, fullPage: true });
        }
    }
    await page.evaluate(() => localStorage.setItem('arkon.theme', 'light'));
    for (const [name, width, height] of [
        ['1920', 1920, 1080],
        ['1366', 1366, 768],
        ['1024', 1024, 768],
        ['390', 390, 844],
    ] as const) {
        await page.setViewportSize({ width, height });
        await page.goto('/admin/seo');
        await expect(page.getByTestId('seo-overview')).toBeVisible();
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
        await page.screenshot({ path: `${dir}/seo-width-${name}.png`, fullPage: true });
        await page.screenshot({ path: `${dir}/seo-width-${name}-top.png` });
    }
    // A filter and a search.
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/admin/seo?status=unpublished');
    await expect(page.getByTestId('seo-page-row').first()).toBeVisible();
    await page.getByPlaceholder('Search pages…').fill('seo-14');
    await expect(page.getByTestId('seo-page-row')).toHaveCount(1);
    await expect(page.getByTestId('seo-page-row')).toContainText('seo-14');
    await expect(page).toHaveURL(/status=unpublished/);
    await page.screenshot({ path: `${dir}/seo-filtered.png`, fullPage: true });
    await page.getByPlaceholder('Search pages…').fill('no such page');
    await expect(page.getByText('No pages match')).toBeVisible();
    await expect(page.getByTestId('seo-page-row')).toHaveCount(0);
});

test('review screenshots (media library with 200 images)', async ({ page }) => {
    test.setTimeout(900_000);
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/admin/media');
    // 200 real uploads (900 × 600 PNGs), so every image has its WebP sizes like a real library.
    await page.evaluate(async () => {
        const token = decodeURIComponent(document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/)?.[1] ?? '');
        for (let i = 0; i < 200; i++) {
            const canvas = document.createElement('canvas');
            canvas.width = 900;
            canvas.height = 600;
            const g = canvas.getContext('2d')!;
            g.fillStyle = `hsl(${(i * 37) % 360} 50% 55%)`;
            g.fillRect(0, 0, 900, 600);
            g.fillStyle = `hsl(${(i * 91) % 360} 60% 80%)`;
            g.beginPath();
            g.arc(300 + (i % 7) * 40, 300, 160, 0, Math.PI * 2);
            g.fill();
            const blob = await new Promise<Blob>((resolve) => canvas.toBlob((b) => resolve(b!), 'image/png'));
            const form = new FormData();
            form.append('file', new File([blob], `library-${String(i).padStart(3, '0')}.png`, { type: 'image/png' }));
            await fetch('/admin/api/media', { method: 'POST', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': token }, body: form });
        }
    });
    const images: { path: string; bytes: number }[] = [];
    page.on('response', async (response) => {
        if (response.request().resourceType() !== 'image' || !new URL(response.url()).pathname.startsWith('/media/')) return;
        const body = await response.body().catch(() => Buffer.alloc(0));
        images.push({ path: new URL(response.url()).pathname, bytes: body.length });
    });
    const started = Date.now();
    await page.goto('/admin/media');
    await expect(page.getByTestId('media-item')).toHaveCount(48);
    const rendered = Date.now() - started;
    await page.waitForLoadState('networkidle');
    const loaded = Date.now() - started;
    const metrics = await page.evaluate(() => ({
        nodes: document.getElementsByTagName('*').length,
        heapMb: Math.round(((performance as unknown as { memory?: { usedJSHeapSize: number } }).memory?.usedJSHeapSize ?? 0) / 1048576),
    }));
    // Scroll to the end of the page; lazy thumbnails load as they come into view.
    await page.mouse.wheel(0, 20_000);
    await page.waitForLoadState('networkidle');
    const originals = images.filter((i) => !/-w\d+\.webp$/.test(i.path));
    const kb = Math.round(images.reduce((sum, i) => sum + i.bytes, 0) / 1024);
    console.log(
        `Media library, 200 images: ${rendered} ms to 48 items on screen, ${loaded} ms until every thumbnail loaded, ${metrics.nodes} DOM elements, ${metrics.heapMb} MB JS heap; ${images.length} thumbnails, ${kb} KB, ${originals.length} originals`,
    );
    expect(originals).toEqual([]);
    expect(page.getByText('Page 1 of 5')).toBeVisible();
    await page.screenshot({ path: `${dir}/media-200.png` });

    const timed = async (label: string, run: () => Promise<void>) => {
        const t = Date.now();
        await run();
        console.log(`Media library, ${label}: ${Date.now() - t} ms`);
    };
    await timed('search', async () => {
        await page.getByLabel('Search images').fill('library-19');
        await expect(page.getByTestId('media-item')).toHaveCount(10);
    });
    await timed('sort by name', async () => {
        await page.getByLabel('Search images').fill('');
        await page.getByLabel('Sort').selectOption('name');
        await expect(page.getByTestId('media-item').first()).toContainText('library-000');
    });
    await timed('next page', async () => {
        await page.getByRole('button', { name: 'Next' }).click();
        await expect(page.getByText('Page 2 of 5')).toBeVisible();
    });
    await page.getByRole('group', { name: 'View' }).getByRole('button', { name: 'List' }).click();
    await page.waitForLoadState('networkidle');
    await expect
        .poll(
            () =>
                page
                    .locator('[data-testid=media-list] img')
                    .evaluateAll((imgs) => imgs.slice(0, 10).every((img) => (img as HTMLImageElement).complete && (img as HTMLImageElement).naturalWidth > 0)),
            { timeout: 30_000 },
        )
        .toBe(true);
    await page.screenshot({ path: `${dir}/media-200-list.png` });
    await page.getByRole('group', { name: 'View' }).getByRole('button', { name: 'Grid' }).click();
    for (const [name, width] of [
        ['1920', 1920],
        ['1366', 1366],
        ['390', 390],
    ] as const) {
        await page.setViewportSize({ width, height: 900 });
        await page.waitForTimeout(300);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
        await page.screenshot({ path: `${dir}/media-200-${name}.png` });
    }
});

test('review screenshots (pages and forms lists)', async ({ page }) => {
    test.setTimeout(180_000);
    for (const [path, title] of [
        ['/about-us', 'About us'],
        ['/services', 'Services'],
        ['/contact', 'Contact'],
        ['/journal', 'Journal'],
    ] as const)
        await createPage(path, title);
    for (const theme of ['light', 'dark'] as const) {
        await page.addInitScript((value) => localStorage.setItem('arkon.theme', value), theme);
        const suffix = theme === 'dark' ? '-dark' : '';
        await page.setViewportSize({ width: 1440, height: 900 });
        await page.goto('/admin/pages');
        await page.getByRole('button', { name: 'More actions for services' }).click();
        await shot(page, `pages-menu${suffix}`);
        await page.keyboard.press('Escape');
        await page.getByRole('checkbox', { name: 'Select contact' }).check();
        await page.getByRole('checkbox', { name: 'Select journal' }).check();
        await shot(page, `pages-selected${suffix}`);
        await page.getByTestId('bulk-bar').getByRole('button', { name: 'Move to Trash' }).click();
        await shot(page, `pages-confirm${suffix}`);
        await page.getByRole('dialog').getByRole('button', { name: 'Move to Trash' }).click();
        await expect(page.getByTestId('toast').last()).toBeVisible();
        await shot(page, `pages-after-trash${suffix}`);
        await page
            .getByRole('group', { name: 'Filter pages' })
            .getByRole('button', { name: /^Trash/ })
            .click();
        await shot(page, `pages-trash${suffix}`);
        await page.getByRole('checkbox', { name: 'Select all pages in the Trash' }).check();
        await page.getByTestId('bulk-bar').getByRole('button', { name: 'Restore' }).click();
        await expect(page.getByText('Trash is empty')).toBeVisible();
        await shot(page, `pages-trash-empty${suffix}`);

        await page.goto('/admin/forms');
        if ((await page.getByTestId('form-row').count()) < 2) {
            for (const name of ['Contact form', 'Newsletter signup']) {
                await page.getByRole('button', { name: 'New form', exact: true }).click();
                await page.getByLabel('Form name', { exact: true }).fill(name);
                await page.getByRole('button', { name: 'Create form', exact: true }).click();
                await expect(page.getByRole('heading', { name, exact: true })).toBeVisible();
                await page.goto('/admin/forms');
            }
        }
        await page.getByRole('checkbox', { name: 'Select all forms' }).check();
        await shot(page, `forms-selected${suffix}`);
        await page.getByRole('checkbox', { name: 'Select all forms' }).uncheck();
        await page
            .getByTestId('form-row')
            .first()
            .getByRole('button', { name: /^More actions/ })
            .click();
        await shot(page, `forms-menu${suffix}`);
        await page.keyboard.press('Escape');
        for (const [name, width] of [
            ['1366', 1366],
            ['390', 390],
        ] as const) {
            await page.setViewportSize({ width, height: 900 });
            await page.goto('/admin/pages');
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
            await shot(page, `pages-${name}${suffix}`);
            await page.goto('/admin/forms');
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
            await shot(page, `forms-${name}${suffix}`);
        }
    }
});

test('review screenshots (branding)', async ({ browser }) => {
    test.setTimeout(180_000);
    const id = await createPage('/brand-review', 'Gardens designed for every season');
    for (const theme of ['light', 'dark'] as const) {
        const suffix = theme === 'dark' ? '-dark' : '';
        // A 2× display, to judge sharpness as on a high-DPI screen.
        const context = await browser.newContext({
            storageState: AUTH_STATE,
            viewport: { width: 1440, height: 900 },
            deviceScaleFactor: 2,
            ignoreHTTPSErrors: true,
        });
        const page = await context.newPage();
        await page.addInitScript((value) => localStorage.setItem('arkon.theme', value), theme);
        await page.goto('/admin');
        await shot(page, `brand-dashboard${suffix}`);
        await page.screenshot({ path: `${dir}/brand-rail${suffix}.png`, clip: { x: 0, y: 0, width: 300, height: 80 } });
        await page.getByRole('button', { name: /Collapse/ }).click();
        await page.waitForTimeout(300);
        await page.screenshot({ path: `${dir}/brand-rail-collapsed${suffix}.png`, clip: { x: 0, y: 0, width: 120, height: 220 } });
        await page.getByRole('button', { name: /Expand/ }).click();
        await page.goto(`/admin/editor/${id}`);
        await expect(page.getByTestId('save-status')).toHaveText('Draft saved');
        await page.screenshot({ path: `${dir}/brand-builder${suffix}.png`, clip: { x: 0, y: 0, width: 520, height: 64 } });
        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto('/admin/pages');
        await page.screenshot({ path: `${dir}/brand-mobile${suffix}.png`, clip: { x: 0, y: 0, width: 390, height: 140 } });
        await context.close();

        const anonymous = await browser.newContext({
            storageState: { cookies: [], origins: [] },
            viewport: { width: 1440, height: 900 },
            deviceScaleFactor: 2,
        });
        const login = await anonymous.newPage();
        await login.addInitScript((value) => localStorage.setItem('arkon.theme', value), theme);
        await login.goto('/login');
        await shot(login, `brand-login${suffix}`);
        await anonymous.close();
    }
});

test('review screenshots (smaller screens)', async ({ page }) => {
    test.setTimeout(120_000);
    const id = await createPage('/spring-small', 'Gardens designed for every season');
    for (const [name, width, height] of [
        ['desktop-1920', 1920, 1080],
        ['wide-1680', 1680, 1000],
        ['laptop-1366', 1366, 768],
        ['laptop-1280', 1280, 720],
        ['tablet-1024', 1024, 768],
        ['phone-390', 390, 844],
    ] as const) {
        await page.setViewportSize({ width, height });
        await page.goto('/admin');
        await shot(page, `dashboard-${name}`);
        await page.goto(`/admin/editor/${id}`);
        await expect(page.locator('[data-testid=save-status]:visible, [data-testid=save-status-compact]:visible').first()).toBeVisible();
        await shot(page, `builder-${name}`);
        // Every toolbar action is reachable (not clipped off-screen).
        for (const action of ['Preview', 'Save draft', 'Publish']) await expect(page.getByRole('button', { name: action, exact: true })).toBeInViewport();
    }
});
