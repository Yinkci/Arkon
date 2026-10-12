import { expect, test } from '@playwright/test';
import { db, BASE_URL } from './support';
test('one brief produces four editable pages with shared navigation and a working contact form', async ({ page }) => {
    await page.goto('/admin/website');
    await page.getByLabel('What would you like to build?').fill('Create a professional landscaping website with Home, About, Services and Contact pages.');
    await page.getByRole('button', { name: 'Generate proposal' }).click();
    await expect(page.getByRole('region', { name: 'Current proposal' }).getByText('Four-page landscaping website', { exact: true })).toBeVisible({
        timeout: 30000,
    });
    await page.getByLabel('Page', { exact: true }).selectOption({ label: 'Website Contact' });
    const preview = page.frameLocator('iframe');
    await expect(preview.getByRole('heading', { name: 'Contact', exact: true })).toBeVisible();
    await expect(preview.getByRole('button', { name: 'Send enquiry' })).toBeDisabled();
    await page.getByRole('button', { name: 'Apply proposal' }).click();
    await expect(page.getByRole('link', { name: 'Edit page' })).toHaveAttribute('title', 'Edit Website Contact in the builder');
    // One button: it checks the website (a dry run of publishing) and shows the outcome in the dialog it opens.
    await page.getByRole('button', { name: 'Publish website' }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog.getByRole('heading', { name: /^Publish (website\?|with \d+ warnings?\?)$/ })).toBeVisible();
    await page.screenshot({ path: 'storage/screenshots/website/publish-dialog.png' });
    await dialog.getByRole('button', { name: /^Publish (website|anyway)$/ }).click();
    await expect(dialog).toBeHidden();
    await expect(page.getByRole('status').filter({ hasText: 'Website published' })).toBeVisible();
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
    let accepted = false;
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
            expect(route.request().postDataJSON().provider).toBe('claude-code');
            accepted = true;
            await route.fulfill({ json: { ok: true, data: proposal() } });
        } else {
            if (offline) {
                await route.abort();
                return;
            }
            await route.fulfill({
                json: {
                    ok: true,
                    data: {
                        requests: accepted ? [proposal()] : [],
                        connection: {
                            ready: true,
                            selectionState: 'automatic',
                            provider: 'claude-code',
                            providerName: 'Claude Code',
                            providers: [{ id: 'claude-code', name: 'Claude Code', ready: true }],
                            message: 'Helper connected',
                        },
                    },
                },
            });
        }
    });
    await page.goto('/admin/website');
    await page.getByLabel('What would you like to build?').fill('Digital marketing homepage');
    await page.getByRole('button', { name: 'Generate proposal' }).click();
    await expect(page.getByRole('list', { name: 'Stages' })).toBeVisible();
    await expect(page.getByText(/Elapsed: 1m/)).toBeVisible();
    await expect(page.getByText(/Helper responding/)).toBeVisible();
    // While it runs, nothing else can be sent: the composer is replaced by the progress.
    await expect(page.getByRole('button', { name: 'Generate proposal' })).toHaveCount(0);
    stage = 'checking';
    await expect(page.locator('[aria-label="Stages"] li[aria-current="step"]')).toHaveText(/Checking against builder rules/, { timeout: 10000 });
    offline = true;
    await expect(page.getByText(/Cannot refresh status/)).toBeVisible({ timeout: 10000 });
    offline = false;
    stage = 'failed';
    await expect(page.getByRole('alert').filter({ hasText: 'The generated proposal needs correction.' })).toBeVisible({ timeout: 10000 });
    await page.getByText('Details', { exact: true }).click();
    await expect(page.getByText('Page /: Change 3: unknown block missing-block')).toBeVisible();
    await expect(page.getByText(/kept privately for diagnosis/)).toBeVisible();
    await expect(page.getByRole('list', { name: 'Stages' })).toHaveCount(0);
    // The accepted brief is not repeated in the composer; a new one can be sent once the run has ended.
    await expect(page.getByLabel('What would you like to build?')).toHaveValue('');
    await page.getByLabel('What would you like to build?').fill('Digital marketing homepage');
    await expect(page.getByRole('button', { name: 'Generate proposal' })).toBeEnabled();
});
