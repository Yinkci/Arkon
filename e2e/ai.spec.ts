// The AI workflow in the browser, through both entry points:
//  - the AI panel: request → the local helper (started by global setup) runs the FAKE Claude Code
//    CLI (e2e/fake-claude.mjs) → proposal → preview → apply/discard;
//  - Claude Code in VS Code: `php artisan arkon:mcp` driven over stdio → proposal waits for review.
// Mocked replies only: no real Claude Code, login or subscription usage.
import { spawn } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { expect, test, type Page } from '@playwright/test';
import { E2E_EDITOR } from './fixtures';
import { E2E_ENV, E2E_MCP_TOKEN_FILE, FAKE_CLAUDE_LOG, PHP } from './env';
import { BASE_URL, createPage, db, greeting, publicationCount, revisionCount } from './support';

const LANDSCAPING = 'Build a homepage for a landscaping business, with a hero, services, about section and contact button.';

const status = (page: Page) => page.getByTestId('save-status');
const notice = (page: Page) => page.getByTestId('notice');
const canvas = (page: Page) => page.frameLocator('[data-testid="canvas"]');
const proposal = (page: Page) => page.getByTestId('ai-proposal');
const requestState = (page: Page) => page.getByTestId('ai-request');

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

async function draftVersion(pageId: string): Promise<number> {
    return Number((await db.query<{ version: number }>('select version from page_drafts where page_id = $1', [pageId])).rows[0]!.version);
}

async function requestStatus(pageId: string): Promise<string | undefined> {
    return (await db.query<{ status: string }>('select status from ai_proposals where page_id = $1 order by created_at desc limit 1', [pageId])).rows[0]
        ?.status;
}

/** Asks in the panel. Unless told otherwise, waits until the request is no longer queued or running. */
async function ask(page: Page, prompt: string, options: { wait?: boolean } = {}) {
    await page.getByRole('tab', { name: 'AI' }).click();
    await page.getByLabel('Ask AI to change this page').fill(prompt);
    await page.getByRole('button', { name: 'Generate proposal' }).click();
    if (options.wait !== false) {
        await expect(page.locator('[data-testid="ai-request"][data-status="queued"], [data-testid="ai-request"][data-status="running"]')).toHaveCount(0, {
            timeout: 30_000,
        });
    }
}

function fakeClaudeRuns(): { args: string[]; env: string[]; stdinBytes: number }[] {
    try {
        return readFileSync(FAKE_CLAUDE_LOG, 'utf8')
            .trim()
            .split('\n')
            .filter(Boolean)
            .map((line) => JSON.parse(line));
    } catch {
        return [];
    }
}

/** Drives `php artisan arkon:mcp` like Claude Code does: newline-delimited JSON-RPC over stdio. */
// eslint-disable-next-line @typescript-eslint/no-explicit-any
async function mcp(calls: { name: string; arguments: Record<string, unknown> }[]): Promise<any[]> {
    const token = readFileSync(E2E_MCP_TOKEN_FILE, 'utf8').trim();
    const child = spawn(PHP, ['artisan', 'arkon:mcp'], { env: { ...process.env, ...E2E_ENV, ARKON_MCP_TOKEN: token } });
    let output = '';
    child.stdout.on('data', (chunk) => (output += chunk));
    const lines = [
        { jsonrpc: '2.0', id: 0, method: 'initialize', params: { protocolVersion: '2025-06-18', capabilities: {}, clientInfo: { name: 'e2e' } } },
        { jsonrpc: '2.0', method: 'notifications/initialized' },
        ...calls.map((call, i) => ({ jsonrpc: '2.0', id: i + 1, method: 'tools/call', params: call })),
    ];
    child.stdin.end(lines.map((line) => JSON.stringify(line)).join('\n') + '\n');
    await new Promise((done) => child.on('close', done));
    const responses = output
        .trim()
        .split('\n')
        .map((line) => JSON.parse(line));
    return responses.slice(1).map((response) => JSON.parse(response.result.content[0].text));
}

test('panel: prompt → helper → preview → apply → undo/redo → follow-up → explicit publish', async ({ page }) => {
    const id = await createPage('/ai-landscaping', 'Welcome');
    const runsBefore = fakeClaudeRuns().length;
    await page.goto(`/admin/editor/${id}`);
    await page.getByRole('tab', { name: 'AI' }).click();
    await expect(page.getByTestId('ai-connection')).toHaveAttribute('data-ready', 'true');
    await expect(page.getByTestId('ai-connection')).toContainText('Claude Code 2.1.292, signed in with a Claude Pro subscription');

    await ask(page, LANDSCAPING);

    // The finished request opens for review: what will change, what blocks publishing, the AI's notes.
    await expect(proposal(page)).toBeVisible();
    await expect(page.getByTestId('ai-changes').getByRole('listitem')).toHaveText([
        'Change Hero “Welcome”: heading, text',
        'Add Text “Our services”',
        'Add Columns with 3 columns (6 blocks inside)',
        'Add Text “About us”',
        'Add Text “We are a local team that looks after gar…”',
        'Add Button “Contact us”',
    ]);
    await expect(proposal(page)).toContainText('Button uses a placeholder destination (#)');
    await expect(page.getByTestId('ai-notes')).toContainText('Set where the Contact us button links to');
    await expect(page.getByTestId('proposal-banner')).toContainText('Nothing has changed yet');
    await expect(canvas(page).locator('h2')).toHaveText(['Our services', 'About us']);
    await expect(status(page)).toHaveText('Previewing AI proposal');
    for (const name of ['Publish', 'Save draft', 'Undo']) await expect(page.getByRole('button', { name, exact: true })).toBeDisabled();
    expect(await draftVersion(id)).toBe(1);
    expect(await revisionCount(id)).toBe(0);

    // The fake CLI was run the way claude.exe is: print mode, structured output, no tools, prompt on stdin, a clean environment.
    const run = fakeClaudeRuns().slice(runsBefore)[0]!;
    for (const flag of ['-p', '--json-schema', '--tools', '--restricted', '--strict-mcp-config', '--no-session-persistence']) expect(run.args).toContain(flag);
    expect(run.args).not.toContain('--bare');
    expect(run.stdinBytes).toBeGreaterThan(100);
    expect(run.env.filter((name) => /^(DB_|ANTHROPIC|ARKON|APP_KEY)/i.test(name))).toEqual([]);

    // Apply: one edit, saved as the proposal (an AI revision), not published.
    await proposal(page).getByRole('button', { name: 'Apply to draft' }).click();
    await expect(notice(page)).toContainText('Applied to the draft and saved. Nothing is published');
    await expect(status(page)).toHaveText('Draft saved');
    await expect(canvas(page).locator('h1')).toHaveText('Gardens that grow with you');
    expect(await draftVersion(id)).toBe(2);
    const { rows } = await db.query<{ source: string; message: string }>(
        'select source, message from page_revisions where page_id = $1 order by number desc limit 1',
        [id],
    );
    expect(rows[0]).toEqual({ source: 'ai', message: expect.stringMatching(/^AI: A homepage for a landscaping business/) });
    expect(await publicationCount(id)).toBe(0);

    // Undo reverts the whole proposal in one step; redo brings it back.
    await page.getByRole('button', { name: 'Undo' }).click();
    await expect(canvas(page).locator('h1')).toHaveText('Welcome');
    await page.getByRole('button', { name: 'Redo' }).click();
    await expect(canvas(page).locator('h2')).toHaveText(['Our services', 'About us']);
    await page.getByRole('button', { name: 'Save draft' }).click();
    await expect(status(page)).toHaveText('Draft saved');

    // An unfinished field is never silently replaced: asking is refused until it is fixed or reverted.
    await page.getByRole('tab', { name: 'Layers' }).click();
    await page.locator('[data-testid="layer"][data-node-type="button"]').getByRole('button').first().click();
    await page.getByRole('tab', { name: 'Properties' }).click();
    await page.getByLabel('Link').fill('https://');
    await ask(page, 'Shorten the headline and add a services section.');
    await expect(notice(page)).toContainText('Fix or revert the Button link before asking the AI');
    await page.getByRole('tab', { name: 'Properties' }).click();
    await page.getByRole('button', { name: 'Revert link' }).click();

    // Follow-up: works on the current draft.
    await ask(page, 'Shorten the headline and add a services section.');
    await expect(page.getByTestId('ai-changes').getByRole('listitem')).toHaveText([
        'Change Hero “Gardens that grow with you”: heading',
        'Add Text “From first sketch to seasonal care, one …”',
    ]);
    await proposal(page).getByRole('button', { name: 'Apply to draft' }).click();
    await expect(status(page)).toHaveText('Draft saved');
    await expect(canvas(page).locator('h1')).toHaveText('Gardens that grow');

    // Publishing is explicit; placeholders work, and then a chosen destination replaces them.
    await page.getByRole('button', { name: 'Publish' }).click();
    await expect(notice(page)).toContainText('Published');
    await page.getByRole('tab', { name: 'Properties' }).click();
    const placeholder = await publicHtml(page, '/ai-landscaping');
    expect(placeholder.html).toContain('href="#"');
    await page.getByLabel('Link').fill('/contact');
    const committed = page.waitForResponse((r) => r.url().endsWith('/publish') && r.request().method() === 'POST');
    await page.getByRole('button', { name: 'Publish' }).click();
    expect((await committed).ok()).toBe(true);
    await expect(notice(page)).toContainText('Published');
    const live = await publicHtml(page, '/ai-landscaping');
    expect(live.html).toContain('<h1 class="ak-hero3__heading">Gardens that grow</h1>');
    expect(live.html).toContain('<a class="ak-btn3 ak-btn3--responsive ak-btn3--primary" href="/contact">Contact us</a>');
    expect(live.html).not.toMatch(/<script(?! type="application\/ld\+json")/i);
    for (const forbidden of ['data-ak-', 'contenteditable']) expect(live.html).not.toContain(forbidden);
});

test('panel: cancelling stops a running request, edits made meanwhile are never replaced, discard changes nothing', async ({ page }) => {
    const id = await createPage('/ai-cancel', 'Welcome');
    await page.goto(`/admin/editor/${id}`);

    // Cancel while Claude Code is working: no proposal, ever.
    await ask(page, 'MOCK-SLOW landscaping', { wait: false });
    await expect(page.locator('[data-testid="ai-request"][data-status="running"]')).toBeVisible({ timeout: 15_000 });
    await requestState(page).getByRole('button', { name: 'Cancel' }).click();
    await expect(requestState(page)).toHaveAttribute('data-status', 'cancelled');
    await page.waitForTimeout(6000); // the fake CLI would have answered by now
    expect(await requestStatus(id)).toBe('cancelled');
    await expect(proposal(page)).toHaveCount(0);

    // Edit while the request runs: the proposal arrives but cannot replace the edit.
    await ask(page, 'MOCK-SLOW landscaping again', { wait: false });
    await page.getByRole('tab', { name: 'Properties' }).click();
    await page.getByRole('textbox', { name: 'Heading' }).fill('My own heading');
    await expect(status(page)).toHaveText('Unsaved changes');
    await expect(proposal(page)).toBeVisible({ timeout: 30_000 });
    await expect(proposal(page).getByRole('alert')).toContainText('You edited the page after asking');
    await expect(proposal(page).getByRole('button', { name: 'Apply to draft' })).toBeDisabled();
    await proposal(page).getByRole('button', { name: 'Discard' }).click();
    await expect(canvas(page).locator('h1')).toHaveText('My own heading');
    await page.getByRole('button', { name: 'Save draft' }).click();
    await expect(status(page)).toHaveText('Draft saved');
    await expect.poll(() => requestStatus(id)).toBe('discarded');
});

test('panel: subscription limits, invalid output and unsupported requests are explained and change nothing', async ({ page }) => {
    const id = await createPage('/ai-errors', 'Welcome');
    await page.goto(`/admin/editor/${id}`);

    await ask(page, 'MOCK-LIMIT please');
    await expect(page.getByTestId('ai-request-failed')).toContainText('usage limit has been reached');

    await ask(page, 'MOCK-INVALID add a carousel');
    await expect(page.getByTestId('ai-request-failed')).toContainText("doesn't fit this page's rules");
    await expect(page.getByTestId('ai-request-failed')).toContainText('there is no "carousel" block');

    await ask(page, 'Add a contact form');
    await expect(proposal(page)).toContainText('No changes proposed');
    await expect(page.getByTestId('ai-notes')).toContainText('Arkon has no block for this yet');
    await expect(proposal(page).getByRole('button', { name: 'Apply to draft' })).toHaveCount(0);
    await proposal(page).getByRole('button', { name: 'Discard' }).click();

    expect(await draftVersion(id)).toBe(1);
    expect(await revisionCount(id)).toBe(0);
});

test('VS Code (MCP): a submitted proposal waits in the editor for visual review and is applied there', async ({ page }) => {
    const id = await createPage('/ai-mcp', 'Welcome');
    const [listed, read, format] = await mcp([
        { name: 'arkon_list_pages', arguments: {} },
        { name: 'arkon_get_page', arguments: { pageId: id } },
        { name: 'arkon_get_proposal_format', arguments: { pageId: id } },
    ]);
    expect(listed.pages.map((p: { id: string }) => p.id)).toContain(id);
    expect(format.schema.required).toEqual(['summary', 'notes', 'tokenChanges', 'changes']);
    const hero = read.blocks.find((b: { type: string }) => b.type === 'hero');
    const [submitted] = await mcp([
        {
            name: 'arkon_submit_proposal',
            arguments: {
                pageId: id,
                baseVersion: read.draftVersion,
                request: 'Make the hero about garden design',
                requestKey: 'e2e-vscode-submission-0001',
                proposal: {
                    summary: 'Hero about garden design.',
                    notes: [],
                    changes: [
                        {
                            action: 'update',
                            change: {
                                id: hero.id,
                                type: 'hero',
                                props: { heading: 'Garden design that lasts', headingLevel: null, text: null, image: null, style: null },
                            },
                        },
                    ],
                },
            },
        },
    ]);
    expect(submitted.status).toBe('proposed');
    expect(await draftVersion(id)).toBe(1); // nothing changed yet

    await page.goto(`/admin/editor/${id}`);
    await page.getByRole('tab', { name: 'AI' }).click();
    const waiting = page.getByTestId('ai-waiting');
    await expect(waiting).toContainText('From Claude Code in VS Code');
    await expect(waiting).toContainText('Make the hero about garden design');
    await waiting.getByRole('button', { name: 'Review' }).click();
    await expect(canvas(page).locator('h1')).toHaveText('Garden design that lasts');
    await expect(page.getByTestId('ai-changes')).toContainText('Change Hero “Welcome”: heading');
    await proposal(page).getByRole('button', { name: 'Apply to draft' }).click();
    await expect(status(page)).toHaveText('Draft saved');
    expect(await draftVersion(id)).toBe(2);
    const [after] = await mcp([{ name: 'arkon_get_proposal_status', arguments: { pageId: id, proposalId: submitted.proposalId } }]);
    expect(after.status).toBe('applied');
    expect(await publicationCount(id)).toBe(0);
});

test.describe('as an editor', () => {
    test.use({ storageState: { cookies: [], origins: [] } });

    test('can ask and apply, but publishing stays with publishers', async ({ page }) => {
        const id = await createPage('/ai-editor', 'Welcome');
        await page.goto('/login');
        await page.getByLabel('Email').fill(E2E_EDITOR.email);
        await page.getByLabel('Password').fill(E2E_EDITOR.password);
        await page.getByRole('button', { name: 'Sign in' }).click();
        await expect(page.getByRole('heading', { name: greeting(E2E_EDITOR.name) })).toBeVisible();
        await page.goto(`/admin/editor/${id}`);
        await ask(page, LANDSCAPING);
        await proposal(page).getByRole('button', { name: 'Apply to draft' }).click();
        await expect(status(page)).toHaveText('Draft saved');
        await expect(page.getByRole('button', { name: 'Publish' })).toBeDisabled();
        expect(await publicationCount(id)).toBe(0);
    });
});
