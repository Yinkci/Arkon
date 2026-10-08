// Review screenshots of the admin (not a regression test): runs only with SCREENSHOTS=<label>, e.g.
//   $env:SCREENSHOTS='after'; npx playwright test e2e/screenshots.spec.ts
// and writes PNGs to storage/screenshots/<label>/ (git-ignored). The e2e database holds fixture data
// only (no real accounts or credentials), and the AI panel uses the fake Claude Code CLI.
import { expect, test, type Page } from '@playwright/test';
import { createPage } from './support';

const LABEL = process.env.SCREENSHOTS ?? '';
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

test('review screenshots (smaller screens)', async ({ page }) => {
    test.setTimeout(120_000);
    const id = await createPage('/spring-small', 'Gardens designed for every season');
    for (const [name, width, height] of [
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
