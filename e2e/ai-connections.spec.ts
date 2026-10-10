import { spawn } from 'node:child_process';
import { resolve } from 'node:path';
import { E2E_ENV, PHP } from './env';
import { E2E_OWNER } from './fixtures';
import { createPage, db } from './support';
import { expect, test } from '@playwright/test';
test('provider overview hides technical details and guides setup with copy, focus and health checks', async ({ page }) => {
    await page.goto('/admin/settings/ai-connections');
    await expect(page.getByRole('heading', { name: 'AI Connections', exact: true })).toBeVisible();
    await expect(page.locator('main pre')).toHaveCount(0);
    await expect(page.locator('main')).not.toContainText(E2E_OWNER.email);
    const claude = page.getByTestId('provider-claude-code');
    await expect(claude).toContainText('Connected');
    await expect(page.getByRole('button', { name: 'Set as default' })).toHaveCount(0);
    const manage = claude.getByRole('button', { name: 'Manage', exact: true });
    await manage.click();
    const dialog = page.getByRole('dialog', { name: 'Manage Claude Code', exact: true });
    await expect(dialog).toBeVisible();
    await expect(dialog.getByRole('heading', { name: 'Connection health' })).toBeVisible();
    await expect(dialog.locator('pre')).not.toBeVisible();
    await dialog.getByRole('button', { name: 'Check again', exact: true }).click();
    await expect(dialog.getByRole('button', { name: 'Check again', exact: true })).toBeEnabled({ timeout: 15000 });
    await dialog.getByRole('button', { name: 'Disconnect', exact: true }).click();
    const confirmation = page.getByRole('dialog', { name: 'Disconnect Claude Code?', exact: true });
    await expect(confirmation).toBeVisible();
    await confirmation.getByRole('button', { name: 'Cancel', exact: true }).click();
    await expect(confirmation).not.toBeVisible();
    await expect(dialog).toBeVisible();
    for (let i = 0; i < 10; i++) {
        await page.keyboard.press('Tab');
        expect(await page.evaluate(() => !!document.activeElement?.closest('dialog[open]'))).toBe(true);
    }
    await page.keyboard.press('Escape');
    await expect(dialog).not.toBeVisible();
    await expect(manage).toBeFocused();
    await page.getByTestId('provider-codex').getByRole('button', { name: 'Connect', exact: true }).click();
    const setup = page.getByRole('dialog', { name: 'Connect Codex', exact: true });
    await expect(setup.getByRole('heading', { name: 'Install Codex', exact: true })).toBeVisible();
    await expect(setup.locator('pre')).toHaveCount(0);
    await setup.getByRole('button', { name: 'Continue', exact: true }).click();
    await expect(setup.locator('pre')).toHaveText('codex login');
    await setup.getByRole('button', { name: 'Continue', exact: true }).click();
    await expect(setup.locator('pre')).toContainText('--provider=codex');
    await setup.getByRole('button', { name: 'Copy pairing command' }).click();
    await expect(setup.getByRole('button', { name: 'Copy pairing command' })).toHaveText('Copied');
    await setup.getByRole('button', { name: 'Continue', exact: true }).click();
    await expect(setup.getByRole('button', { name: 'Check connection', exact: true })).toBeVisible();
    await expect(setup).not.toContainText('Connection successful');
    await setup.getByRole('button', { name: 'Close provider details' }).click();
    await page.setViewportSize({ width: 390, height: 844 });
    await expect(page.getByTestId('provider-codex').getByRole('button', { name: 'Connect', exact: true })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth)).toBe(false);
});

test('each new task requires a provider choice when both helpers are connected', async ({ page }) => {
    const env = {
        ...process.env,
        ...E2E_ENV,
        APP_ENV: 'testing',
        ARKON_CODEX_COMMAND: JSON.stringify([process.execPath, resolve('e2e/fake-codex.mjs')]),
        ARKON_CODEX_HELPER_TOKEN_FILE: resolve('storage/e2e/codex-helper.token'),
    };
    const paired = spawn(PHP, ['artisan', 'arkon:ai-pair', E2E_OWNER.email, '--helper', '--provider=codex'], { env, stdio: 'ignore' });
    const exit = await new Promise<number | null>((done) => paired.once('exit', done));
    expect(exit).toBe(0);
    const helper = spawn(PHP, ['artisan', 'arkon:ai-helper', '--provider=codex'], { env, stdio: 'ignore' });
    try {
        await page.goto('/admin/settings/ai-connections');
        const codex = page.locator('section').filter({ has: page.getByRole('heading', { name: /^Codex/ }) });
        await expect(codex).toContainText('Connected', { timeout: 15000 });
        const id = await createPage('/codex-routing', 'Welcome');
        await page.goto('/admin/editor/' + id);
        await page.getByRole('tab', { name: 'AI', exact: true }).click();
        await expect(page.getByRole('button', { name: 'Generate proposal' })).toBeDisabled();
        await page.getByLabel('AI provider for this task').selectOption('codex');
        await expect(page.getByTestId('ai-connection')).toContainText('Codex');
        await page.getByLabel('Ask AI to change this page').fill('Create a professional landscaping homepage');
        await page.getByRole('button', { name: 'Generate proposal' }).click();
        await expect(page.getByTestId('ai-proposal')).toBeVisible({ timeout: 30000 });
        expect((await db.query('SELECT provider FROM ai_proposals WHERE page_id=$1 ORDER BY created_at DESC LIMIT 1', [id])).rows[0].provider).toBe('codex');
        await page.getByTestId('ai-proposal').getByRole('button', { name: 'Apply to draft' }).click();
        await expect.poll(async () => (await db.query('SELECT version FROM page_drafts WHERE page_id=$1', [id])).rows[0].version).toBe(2);
        expect((await db.query('SELECT count(*) FROM publications WHERE page_id=$1', [id])).rows[0].count).toBe('0');
        await page.goto('/admin/editor/' + id);
        await page.getByRole('tab', { name: 'AI', exact: true }).click();
        await page.getByLabel('AI provider for this task').selectOption('claude-code');
        await expect(page.getByTestId('ai-connection')).toContainText('Claude Code');
        await page.getByLabel('Ask AI to change this page').fill('Create a professional landscaping homepage');
        await page.getByRole('button', { name: 'Generate proposal' }).click();
        await expect
            .poll(async () => (await db.query('SELECT provider FROM ai_proposals WHERE page_id=$1 ORDER BY created_at DESC LIMIT 1', [id])).rows[0].provider, {
                timeout: 30000,
            })
            .toBe('claude-code');
        await expect(page.getByTestId('ai-proposal')).toBeVisible({ timeout: 30000 });
        await page.getByTestId('ai-proposal').getByRole('button', { name: 'Apply to draft' }).click();
        await expect.poll(async () => (await db.query('SELECT version FROM page_drafts WHERE page_id=$1', [id])).rows[0].version).toBe(3);
    } finally {
        const stop = spawn(
            PHP,
            [
                'artisan',
                'arkon:ai-revoke',
                ...(await db.query("SELECT id FROM ai_connections WHERE provider='codex' AND revoked_at IS NULL LIMIT 1")).rows.map((r) => r.id),
            ],
            { env, stdio: 'ignore' },
        );
        await new Promise((done) => stop.once('exit', done));
        helper.kill();
        await new Promise((done) => {
            if (helper.exitCode !== null) done(null);
            else helper.once('exit', done);
        });
    }
});

test('refresh errors stay actionable without replacing the last known provider status', async ({ page }) => {
    await page.goto('/admin/settings/ai-connections');
    await page.route('**/admin/api/ai-connections', (route) =>
        route.fulfill({
            status: 503,
            contentType: 'application/json',
            body: JSON.stringify({ ok: false, code: 'OFFLINE', message: 'Status is temporarily unavailable.' }),
        }),
    );
    await page.getByRole('button', { name: 'Refresh status', exact: true }).click();
    await expect(page.getByRole('alert')).toContainText('Status is temporarily unavailable.');
    await expect(page.getByTestId('provider-claude-code')).toContainText('Last known: Connected');
    await expect(page.getByTestId('provider-claude-code')).toContainText('Connected');
    await expect(page.getByRole('button', { name: 'Refresh status', exact: true })).toBeEnabled();
});

test('review connection screen and setup in light, dark and mobile layouts', async ({ page }) => {
    const output = process.env.AI_UI_SCREENSHOTS;
    test.skip(!output, 'Set AI_UI_SCREENSHOTS to save visual review captures');
    for (const theme of ['light', 'dark']) {
        await page.setViewportSize({ width: 1280, height: 900 });
        await page.addInitScript((value) => localStorage.setItem('arkon.theme', value), theme);
        await page.goto('/admin/settings/ai-connections');
        await expect(page.getByTestId('provider-codex')).toBeVisible();
        await page.mouse.move(0, 0);
        await page.screenshot({ path: output + '/AI Connections ' + theme + '.png', fullPage: true });
        await page.getByTestId('provider-codex').getByRole('button', { name: 'Connect', exact: true }).click();
        await page.getByRole('dialog', { name: 'Connect Codex', exact: true }).getByRole('button', { name: 'Continue', exact: true }).click();
        await page.mouse.move(0, 0);
        await page.screenshot({ path: output + '/AI setup ' + theme + '.png' });
        await page.keyboard.press('Escape');
        await page.setViewportSize({ width: 390, height: 844 });
        expect(await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth)).toBe(false);
        await page.mouse.move(0, 0);
        await page.screenshot({ path: output + '/AI Connections mobile ' + theme + '.png', fullPage: true });
        await page.getByTestId('provider-codex').getByRole('button', { name: 'Connect', exact: true }).click();
        await page.getByRole('dialog', { name: 'Connect Codex', exact: true }).getByRole('button', { name: 'Continue', exact: true }).click();
        const bounds = await page.getByRole('dialog', { name: 'Connect Codex', exact: true }).boundingBox();
        expect(bounds?.width).toBeLessThanOrEqual(390);
        await page.mouse.move(0, 0);
        await page.screenshot({ path: output + '/AI setup mobile ' + theme + '.png' });
        await page.keyboard.press('Escape');
    }
});

test('website override updates status and SEO shares the editor provider selection', async ({ page }) => {
    const connection = {
        provider: null,
        selectionState: 'choice_required',
        providerName: null,
        ready: false,
        message: 'Choose a connected AI for this task.',
        claudeVersion: null,
        lastSeenAt: null,
        providers: [
            { id: 'claude-code', name: 'Claude Code', ready: true, message: 'Claude Code is offline' },
            { id: 'codex', name: 'Codex', ready: true, message: 'Codex is connected' },
        ],
    };
    await page.route('**/admin/api/website/requests', (route) => route.fulfill({ json: { ok: true, data: { requests: [], connection } } }));
    await page.goto('/admin/website');
    await page.getByLabel('website brief', { exact: false }).fill('Create a contact page');
    await expect(page.getByRole('button', { name: 'Prepare website proposal' })).toBeDisabled({ timeout: 15000 });
    await page.getByLabel('AI provider for this task').selectOption('codex');
    await expect(page.getByRole('button', { name: 'Prepare website proposal' })).toBeEnabled();
    await expect(page.locator('main')).toContainText('Codex is connected');
    let websiteProvider: string | undefined;
    await page.route('**/admin/api/website/requests', async (route) => {
        if (route.request().method() === 'POST') {
            websiteProvider = route.request().postDataJSON().provider;
            await route.fulfill({ json: { ok: false, code: 'TEST', message: 'Test stopped before generation.' } });
        } else await route.fulfill({ json: { ok: true, data: { requests: [], connection } } });
    });
    await page.getByRole('button', { name: 'Prepare website proposal' }).click();
    await expect.poll(() => websiteProvider).toBe('codex');
    const id = await createPage('/provider-seo-regression', 'Provider SEO regression');
    await page.route('**/admin/api/pages/' + id + '/ai/requests', async (route) => {
        if (route.request().method() === 'GET') await route.fulfill({ json: { ok: true, data: { requests: [], connection } } });
        else await route.continue();
    });
    await page.goto('/admin/editor/' + id);
    await page.getByRole('tab', { name: 'AI', exact: true }).click();
    await page.getByLabel('AI provider for this task').selectOption('codex');
    await expect(page.getByTestId('ai-connection')).toContainText('Codex');
    await page.getByRole('tab', { name: 'SEO', exact: true }).click();
    await expect(page.getByLabel('AI provider for this task')).toHaveValue('codex');
    await expect(page.getByTestId('seo-panel')).toContainText('Uses Codex.');
    let seoRequest: { provider?: string; prompt?: string } | undefined;
    await page.route('**/admin/api/pages/' + id + '/ai/requests', async (route) => {
        if (route.request().method() === 'POST') {
            seoRequest = route.request().postDataJSON();
            await route.fulfill({ json: { ok: false, code: 'TEST', message: 'Test stopped before generation.' } });
        } else await route.fulfill({ json: { ok: true, data: { requests: [], connection } } });
    });
    await page.getByRole('button', { name: 'Improve SEO with AI', exact: true }).click();
    await expect.poll(() => seoRequest?.provider).toBe('codex');
    expect(seoRequest?.prompt).toMatch(/^ARKON_SEO_METADATA_ONLY:/);
});

test('sole Codex is automatic and an uncertain website request keeps its identity while disconnected', async ({ page }) => {
    let connection = {
        selectionState: 'automatic',
        provider: 'codex' as string | null,
        providerName: 'Codex' as string | null,
        ready: true,
        message: 'Using Codex.',
        providers: [{ id: 'codex', name: 'Codex', ready: true, message: 'Codex connected' }],
    };
    await page.route('**/admin/api/website/requests', async (route) => {
        if (route.request().method() === 'GET') await route.fulfill({ json: { ok: true, data: { requests: [], connection } } });
        else await route.continue();
    });
    await page.goto('/admin/website');
    await expect(page.locator('main')).toContainText('Using Codex.', { timeout: 15000 });
    await expect(page.getByLabel('AI provider for this task')).toHaveCount(0);
    await page.getByLabel('Website brief').fill('Make a contact page');
    const attempts: Record<string, unknown>[] = [];
    await page.route('**/admin/api/website/requests', async (route) => {
        if (route.request().method() === 'GET') {
            await route.fulfill({ json: { ok: true, data: { requests: [], connection } } });
            return;
        }
        attempts.push(route.request().postDataJSON());
        if (attempts.length === 1) {
            connection = {
                ...connection,
                selectionState: 'unavailable',
                provider: null,
                providerName: null,
                ready: false,
                message: 'No AI is connected and ready.',
                providers: [{ ...connection.providers[0]!, ready: false }],
            };
            await route.abort();
        } else await route.fulfill({ json: { ok: false, code: 'TEST', message: 'Confirmed original request in fixture.' } });
    });
    await page.getByRole('button', { name: 'Prepare website proposal' }).click();
    await expect(page.locator('main')).toContainText('Request could not be confirmed');
    await expect(page.getByRole('link', { name: 'Connect an AI' })).toBeVisible({ timeout: 15000 });
    await expect(page.getByRole('button', { name: 'Prepare website proposal' })).toBeEnabled();
    await page.getByRole('button', { name: 'Prepare website proposal' }).click();
    await expect.poll(() => attempts.length).toBe(2);
    expect(attempts[1]).toEqual(attempts[0]);
    expect(attempts[0]).toMatchObject({ provider: 'codex', selectionMode: 'automatic' });
    await expect(page.getByRole('button', { name: 'Prepare website proposal' })).toBeDisabled();
});
