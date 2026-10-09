import { expect, test } from '@playwright/test';
import { db, createPage } from './support';
import { APP_ORIGIN, SERVER_URL, E2E_HOST, PORT } from './env';

test('an uncertain creation freezes its intent and retries without duplicating the form', async ({ page }) => {
    await page.goto('/admin/forms');
    await page.getByRole('button', { name: 'New form', exact: true }).click();
    await page.getByLabel('Form name', { exact: true }).fill('Retry-safe browser form');
    let dropped = false;
    const keys: string[] = [];
    await page.route('**/admin/api/forms/save', async (route) => {
        keys.push(route.request().postDataJSON().requestKey);
        if (!dropped) {
            dropped = true;
            const response = await route.fetch({
                url: route.request().url().replace(APP_ORIGIN, SERVER_URL),
                headers: { ...(await route.request().allHeaders()), host: `${E2E_HOST}:${PORT}` },
            });
            expect(response.ok()).toBe(true);
            await route.abort();
        } else await route.continue();
    });
    await page.getByRole('button', { name: 'Create form', exact: true }).click();
    await expect(page.getByText('Creation could not be confirmed. Retry Create form with the same request.')).toBeVisible();
    await expect(page.getByLabel('Form name', { exact: true })).toBeDisabled();
    await expect(page.getByRole('button', { name: 'New form', exact: true })).toBeDisabled();
    await page.getByRole('button', { name: 'Retry Create form', exact: true }).click();
    await expect(page).toHaveURL(/\/admin\/forms\/[a-f0-9-]+$/);
    expect(keys).toHaveLength(2);
    expect(keys[0]).toBe(keys[1]);
    expect((await db.query("SELECT version FROM site_forms WHERE name='Retry-safe browser form'")).rows).toEqual([{ version: 1 }]);
});

test('visual builder supports columns, duplicate, delete, undo and a safe draft save', async ({ page }) => {
    await page.setViewportSize({ width: 1600, height: 1100 });
    await page.goto('/admin/forms');
    await page.getByRole('button', { name: 'New form', exact: true }).click();
    await page.getByLabel('Form name', { exact: true }).fill('Visual form test');
    await page.getByLabel('Start with').selectOption('blank');
    await page.getByRole('button', { name: 'Create form', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Visual form test', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Single line text', exact: true }).click();
    await page.getByLabel('Label', { exact: true }).fill('Your name');
    await page.getByRole('button', { name: 'Email', exact: true }).click();
    await page.getByLabel('Label', { exact: true }).fill('Email address');
    const source = await page.getByRole('button', { name: 'Move Email address', exact: true }).boundingBox();
    const target = await page.locator('[data-form-row]').first().boundingBox();
    expect(source).toBeTruthy();
    expect(target).toBeTruthy();
    await page.mouse.move(source!.x + source!.width / 2, source!.y + source!.height / 2);
    await page.mouse.down();
    await page.mouse.move(target!.x + target!.width - 30, target!.y + target!.height / 2, { steps: 18 });
    await page.mouse.up();
    await expect(page.locator('[data-form-row]')).toHaveCount(1);
    await expect(page.locator('[data-form-field]')).toHaveCount(2);
    await page.getByRole('button', { name: 'Duplicate', exact: true }).last().click();
    await expect(page.locator('[data-form-field]')).toHaveCount(3);
    await page.getByRole('button', { name: 'Delete', exact: true }).last().click();
    await page.getByRole('button', { name: 'Delete field', exact: true }).click();
    await expect(page.locator('[data-form-field]')).toHaveCount(2);
    await page.getByRole('button', { name: 'Undo', exact: true }).click();
    await expect(page.locator('[data-form-field]')).toHaveCount(3);
    await page.getByRole('button', { name: 'Redo', exact: true }).click();
    await expect(page.locator('[data-form-field]')).toHaveCount(2);
    await page.getByRole('button', { name: 'Save draft', exact: true }).click();
    await expect(page.getByText('Draft saved. Publish when ready.')).toBeVisible();
    await page.reload();
    await expect(page.locator('[data-form-row]')).toHaveCount(1);
    await page.screenshot({
        path: 'C:/Users/jacob/Documents/Codex/2026-10-06/referenced-chatgpt-conversation-this-is-an/outputs/forms-builder-desktop.png',
        fullPage: true,
    });
    await page.evaluate(() => localStorage.setItem('arkon.theme', 'dark'));
    await page.reload();
    await page.screenshot({
        path: 'C:/Users/jacob/Documents/Codex/2026-10-06/referenced-chatgpt-conversation-this-is-an/outputs/forms-builder-dark.png',
        fullPage: true,
    });
    await page.evaluate(() => localStorage.setItem('arkon.theme', 'light'));
    await page.reload();
    const id = new URL(page.url()).pathname.split('/').at(-1)!;
    const saved = (await db.query('SELECT draft FROM site_forms WHERE id=$1', [id])).rows[0].draft;
    expect(saved.fields.map((f: { width: number }) => f.width)).toEqual([6, 6]);
});

test('form management, preview, conditional public submission and entries work together', async ({ page, context }) => {
    await page.goto('/admin/forms');
    await page.getByRole('button', { name: 'New form', exact: true }).click();
    await page.getByLabel('Form name', { exact: true }).fill('Managed contact form');
    await page.getByRole('button', { name: 'Create form', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Managed contact form', exact: true })).toBeVisible();
    const formId = new URL(page.url()).pathname.split('/').at(-1)!;
    await page.getByRole('button', { name: 'Dropdown', exact: true }).click();
    await page.getByLabel('Label', { exact: true }).fill('Service');
    await page.getByLabel('Choice 1 label').fill('Design');
    await page.getByLabel('Choice 2 label').fill('Marketing');
    await page.getByRole('button', { name: 'Select Message', exact: true }).click();
    await page.getByText('Conditional logic', { exact: true }).click();
    await page.getByLabel('Enable conditional logic').check();
    await page.getByRole('combobox', { name: 'Field', exact: true }).selectOption({ label: 'Service' });
    await page.getByLabel('Value', { exact: true }).fill('first');
    await page.getByRole('button', { name: 'Confirmations', exact: true }).click();
    await page.getByLabel('Confirmation message').fill('Thanks for contacting our team.');
    await page.getByRole('button', { name: 'Notifications', exact: true }).click();
    await page.getByRole('button', { name: 'Add notification', exact: true }).click();
    await page.getByLabel('Notification name').fill('Team notice');
    await page.getByLabel('Recipient', { exact: true }).fill('team@example.com');
    await page.getByRole('button', { name: 'Save draft', exact: true }).click();
    await expect(page.getByText('Draft saved. Publish when ready.')).toBeVisible();
    const popupPromise = page.waitForEvent('popup');
    await page.getByRole('link', { name: 'Preview', exact: true }).click();
    const preview = await popupPromise;
    await expect(preview.getByText('Draft preview. Test submissions are validated but never stored or emailed.')).toBeVisible();
    await preview.getByLabel('Your name', { exact: false }).fill('Preview visitor');
    await preview.getByLabel('Email', { exact: false }).fill('preview@example.com');
    await preview.getByLabel('Service', { exact: false }).selectOption('second');
    await preview.getByRole('button', { name: 'Send message' }).click();
    await expect(preview.getByRole('status')).toContainText('Preview validation passed');
    await preview.close();
    expect((await db.query('SELECT count(*)::int AS n FROM form_submissions WHERE form_id=$1', [formId])).rows[0].n).toBe(0);
    await page.getByRole('button', { name: 'Publish form', exact: true }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Publish form', exact: true }).click();
    await expect(page.getByText('Form published. Live pages now use this version.')).toBeVisible();
    const path = '/forms-redesign-browser',
        pageId = await createPage(path, 'Contact our team');
    const result = await page.evaluate(
        async ({ pageId, formId }) => {
            const token = decodeURIComponent(document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/)![1]!);
            const call = async (action: string, body: unknown) =>
                (
                    await fetch(`/admin/api/pages/${pageId}/${action}`, {
                        method: 'POST',
                        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': token },
                        body: JSON.stringify(body),
                    })
                ).json();
            const save = await call('save', {
                baseVersion: 1,
                saveKey: 'forms-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2),
                operations: [
                    {
                        op: 'insertNode',
                        parentId: 'e2eRoot001',
                        index: 1,
                        nodes: [{ id: 'e2eForm001', type: 'form', version: 4, props: { form: { id: formId }, style: {} } }],
                    },
                ],
            });
            if (!save.ok) return save;
            return call('publish', { expectedVersion: 2, idempotencyKey: 'forms-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2) });
        },
        { pageId, formId },
    );
    expect(result.ok).toBe(true);
    const publicPage = await context.newPage();
    await publicPage.goto(path);
    await publicPage.getByLabel('Your name', { exact: false }).fill('Real visitor');
    await publicPage.getByLabel('Email', { exact: false }).fill('real@example.com');
    await publicPage.getByLabel('Service', { exact: false }).selectOption('second');
    await expect(publicPage.getByLabel('Message', { exact: false })).toBeHidden();
    await publicPage.getByRole('button', { name: 'Send message' }).click();
    await expect(publicPage.getByRole('status')).toContainText('Thanks for contacting our team.');
    await publicPage.close();
    await page.getByRole('button', { name: 'Entries', exact: true }).click();
    await expect(page.getByRole('button', { name: 'real@example.com', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'real@example.com', exact: true }).click();
    await expect(page.getByRole('dialog').getByText('Real visitor', { exact: true })).toBeVisible();
    await expect(page.getByRole('dialog').getByText('Service', { exact: true })).toBeVisible();
    await page.getByRole('dialog').getByRole('button', { name: 'Close', exact: true }).click();
    await page.getByLabel('Search entries').fill('real@example.com');
    await expect(page.getByRole('button', { name: 'real@example.com', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Export CSV', exact: true }).click();
    await expect(page.getByRole('link', { name: 'Download CSV' })).toBeVisible();
    await page.screenshot({
        path: 'C:/Users/jacob/Documents/Codex/2026-10-06/referenced-chatgpt-conversation-this-is-an/outputs/forms-entries.png',
        fullPage: true,
    });
});

test('an unconfirmed draft save freezes editing and retries its immutable batch', async ({ page }) => {
    await page.goto('/admin/forms');
    await page.getByRole('link', { name: 'Visual form test', exact: true }).click();
    await page.getByRole('button', { name: 'Settings', exact: true }).click();
    await page.getByLabel('Form name', { exact: true }).fill('Visual form test renamed');
    const keys: string[] = [];
    let dropped = false;
    await page.route('**/admin/api/forms/save', async (route) => {
        keys.push(route.request().postDataJSON().requestKey);
        if (!dropped) {
            dropped = true;
            const response = await route.fetch({
                url: route.request().url().replace(APP_ORIGIN, SERVER_URL),
                headers: { ...(await route.request().allHeaders()), host: E2E_HOST + ':' + PORT },
            });
            expect(response.ok()).toBe(true);
            await route.abort();
        } else await route.continue();
    });
    await page.getByRole('button', { name: 'Save draft', exact: true }).click();
    await expect(page.getByText('Save could not be confirmed. Retry the same request before editing.')).toBeVisible();
    await expect(page.getByLabel('Form name', { exact: true })).toBeDisabled();
    await expect(page.getByRole('button', { name: 'Undo', exact: true })).toBeDisabled();
    await page.getByRole('button', { name: 'Retry Save', exact: true }).click();
    await expect(page.getByText('Draft saved. Publish when ready.')).toBeVisible();
    expect(keys).toHaveLength(2);
    expect(keys[0]).toBe(keys[1]);
    await page.reload();
    await expect(page.getByRole('heading', { name: 'Visual form test renamed', exact: true })).toBeVisible();
    const result = await db.query("SELECT version FROM site_forms WHERE name='Visual form test renamed'");
    expect(result.rows).toEqual([{ version: 3 }]);
});
test('conditional fields work without JavaScript through native validation recovery', async ({ page, browser }) => {
    const context = await browser.newContext({ javaScriptEnabled: false });
    const visitor = await context.newPage();
    await visitor.goto(APP_ORIGIN + '/forms-redesign-browser');
    await visitor.getByLabel('Your name', { exact: false }).fill('Native visitor');
    await visitor.getByLabel('Email', { exact: false }).fill('native@example.com');
    await visitor.getByRole('combobox', { name: 'Service', exact: false }).selectOption('first');
    await visitor.getByRole('button', { name: 'Send message' }).click();
    await expect(visitor.getByRole('heading')).toContainText('Message');
    await expect(visitor.getByLabel('Email', { exact: false })).toHaveValue('native@example.com');
    await visitor.getByLabel('Message', { exact: false }).fill('Native enquiry');
    await visitor.getByRole('button', { name: 'Send message' }).click();
    await expect(visitor.getByRole('heading')).toContainText('Thanks for contacting our team.');
    await context.close();
});

test('an uncertain public submission keeps its immutable values and records one entry', async ({ browser }) => {
    const context = await browser.newContext();
    const page = await context.newPage();
    await page.goto(APP_ORIGIN + '/forms-redesign-browser');
    await page.getByLabel('Your name', { exact: false }).fill('Retry visitor');
    await page.getByLabel('Email', { exact: false }).fill('retryvisitor@example.com');
    await page.getByRole('combobox', { name: 'Service', exact: false }).selectOption('second');
    let dropped = false;
    const bodies: string[] = [];
    await page.route('**/_arkon/forms/*/*', async (route) => {
        bodies.push(route.request().postData() ?? '');
        if (!dropped) {
            dropped = true;
            const response = await route.fetch({
                url: route.request().url().replace(APP_ORIGIN, SERVER_URL),
                headers: { ...(await route.request().allHeaders()), host: E2E_HOST + ':' + PORT },
            });
            expect(response.ok()).toBe(true);
            await route.abort();
        } else await route.continue();
    });
    await page.getByRole('button', { name: 'Send message' }).click();
    await expect(page.getByRole('status')).toContainText('could not be confirmed');
    await expect(page.getByLabel('Email', { exact: false })).toBeDisabled();
    await expect(page.getByLabel('Email', { exact: false })).toHaveValue('retryvisitor@example.com');
    await page.getByRole('button', { name: 'Retry submission' }).click();
    await expect(page.getByRole('status')).toContainText('Thanks for contacting our team.');
    expect(bodies).toHaveLength(2);
    const keys = bodies.map((body) => body.match(/name="requestKey"\r\n\r\n([^\r]+)/)?.[1]);
    expect(keys[0]).toBeTruthy();
    expect(keys[0]).toBe(keys[1]);
    const result = await db.query('SELECT count(*)::int AS count FROM form_submissions WHERE request_key=$1', [keys[0]]);
    expect(result.rows).toEqual([{ count: 1 }]);
    await context.close();
});
