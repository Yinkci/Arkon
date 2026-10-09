import { expect, test } from '@playwright/test';
import { db, BASE_URL } from './support';
test('one brief produces four editable pages with shared navigation and a working contact form', async ({ page }) => {
    await page.goto('/admin/website');
    await page.getByLabel('Website brief').fill('Create a professional landscaping website with Home, About, Services and Contact pages.');
    await page.getByRole('button', { name: 'Prepare website proposal' }).click();
    await expect(page.getByRole('heading', { name: 'Four-page landscaping website' })).toBeVisible({ timeout: 30000 });
    await page.getByRole('button', { name: 'Website Contact · /website-contact' }).click();
    const preview = page.frameLocator('iframe');
    await expect(preview.getByRole('heading', { name: 'Contact', exact: true })).toBeVisible();
    await expect(preview.getByRole('button', { name: 'Send enquiry' })).toBeDisabled();
    await page.getByRole('button', { name: 'Apply all website drafts' }).click();
    await expect(page.getByRole('link', { name: 'Edit Website Contact' })).toBeVisible();
    await page.getByRole('button', { name: 'Check publishing readiness' }).click();
    await expect(page.getByRole('heading', { name: 'Ready to publish' })).toBeVisible();
    page.once('dialog', (d) => d.accept());
    await page.getByRole('button', { name: 'Publish website' }).click();
    await expect(page.getByRole('status')).toContainText('Website published');
    const anonymous = await page
        .context()
        .browser()!
        .newContext({ baseURL: BASE_URL, storageState: { cookies: [], origins: [] } });
    try {
        const live = await anonymous.newPage();
        await live.goto('/website-contact');
        await expect(live.getByRole('heading', { name: 'Contact', exact: true })).toBeVisible();
        await expect(live.getByRole('navigation')).toBeVisible();
        await expect(live.getByRole('button', { name: 'Send enquiry' })).toBeEnabled();
        await live.getByLabel('Your name').fill('Browser visitor');
        await live.getByLabel('Email').fill('browser@example.com');
        await live.getByLabel('Message').fill('Please help plan my garden.');
        await live.getByRole('button', { name: 'Send enquiry' }).click();
        await expect(live.getByRole('heading', { name: 'Thank you. Your enquiry was received.' })).toBeVisible();
    } finally {
        await anonymous.close();
    }
    await page.goto('/admin/forms');
    await page.getByRole('link', { name: 'Entries', exact: true }).filter({ visible: true }).last().click();
    await expect(page.getByText('browser@example.com', { exact: true })).toBeVisible();
    const { rows } = await db.query("SELECT p.html FROM live_pages l JOIN publications p ON p.id=l.publication_id WHERE l.path='/website-contact'");
    expect(rows[0].html).not.toMatch(/data-ak-|<script(?! type="application\/ld\+json")|\/build\/assets/);
    expect(rows[0].html).toContain('rel="canonical"');
});

test('website generation displays elapsed time and heartbeat, blocks duplicates, and shows exact failures', async ({ page }) => {
    let stage = 'generating';
    let offline = false;
    const proposal = () => ({
        id: '01a00000-0000-7000-8000-000000000001',
        prompt: 'Digital marketing homepage',
        status: stage === 'failed' ? 'failed' : 'running',
        summary: null,
        error: stage === 'failed' ? 'The generated proposal needs correction.' : null,
        issues: stage === 'failed' ? [{ path: 'changes.2', message: 'Page /: Change 3: unknown block missing-block' }] : [],
        activity: stage,
        createdAt: new Date(Date.now() - 65000).toISOString(),
        startedAt: new Date(Date.now() - 65000).toISOString(),
        heartbeatAt: new Date().toISOString(),
        resolvedAt: null,
        candidateSaved: stage === 'failed',
        result: null,
        applied: null,
    });
    await page.route('**/website/requests', async (route) => {
        if (route.request().method() === 'POST') {
            expect(route.request().postDataJSON().allowRepair).toBe(false);
            await route.fulfill({ json: { ok: true, data: proposal() } });
        } else {
            if (offline) {
                await route.abort();
                return;
            }
            await route.fulfill({ json: { ok: true, data: { requests: [proposal()], connection: { ready: true, message: 'Helper connected' } } } });
        }
    });
    await page.goto('/admin/website');
    await page.getByLabel('Website brief').fill('Digital marketing homepage');
    await page.getByRole('button', { name: 'Prepare website proposal' }).click();
    await expect(page.getByLabel('Website preparation progress')).toBeVisible();
    await expect(page.getByText(/Elapsed: 1m/)).toBeVisible();
    await expect(page.getByText(/Helper responding/)).toBeVisible();
    await expect(page.getByRole('button', { name: 'Prepare website proposal' })).toBeDisabled();
    stage = 'checking';
    await expect(page.getByText('Checking the generated pages against builder rules…')).toBeVisible({ timeout: 10000 });
    offline = true;
    await expect(page.getByText(/Cannot refresh status/)).toBeVisible({ timeout: 10000 });
    offline = false;
    stage = 'failed';
    await expect(page.getByText('Page /: Change 3: unknown block missing-block')).toBeVisible({ timeout: 10000 });
    await expect(page.getByText(/retained privately for diagnosis/)).toBeVisible();
    await expect(page.getByLabel('Website preparation progress')).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Prepare website proposal' })).toBeEnabled();
});
