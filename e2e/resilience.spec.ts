import { expect, test, type Page } from '@playwright/test';
import { BASE_URL, createPage, interceptNext, isAction, publicationCount, revisionCount, typeIntoHeading } from './support';

const status = (page: Page) => page.getByTestId('save-status');
const notice = (page: Page) => page.getByTestId('notice');
const heading = (page: Page) => page.frameLocator('[data-testid="canvas"]').locator('h1');
const saveButton = (page: Page) => page.getByRole('button', { name: 'Save draft' });

async function openEditor(page: Page, pageId: string) {
    await page.goto(`/admin/editor/${pageId}`);
    await expect(heading(page)).toBeVisible();
}

async function liveHtml(page: Page, path: string) {
    const context = await page.context().browser()!.newContext({ baseURL: BASE_URL });
    try {
        const response = await context.request.get(path);
        return { status: response.status(), html: await response.text() };
    } finally {
        await context.close();
    }
}

test('typing in the same heading during a slow save is kept and saved', async ({ page }) => {
    const id = await createPage('/race-typing', 'Start');
    await openEditor(page, id);
    await typeIntoHeading(page, 'First', 'replace');

    const slow = await interceptNext(page, 'save', 'delay');
    await saveButton(page).click();
    await expect(status(page)).toHaveText('Saving…');
    await typeIntoHeading(page, ' and more', 'append');
    slow.release();

    // The saved batch did not include the later typing, so it must still be unsaved.
    await expect(status(page)).toHaveText('Unsaved changes');
    await expect(heading(page)).toHaveText('First and more');
    await saveButton(page).click();
    await expect(status(page)).toHaveText('Draft saved');

    await page.reload();
    await expect(heading(page)).toHaveText('First and more');
});

test('undo and SEO edits made during a slow save are saved in order', async ({ page }) => {
    const id = await createPage('/race-seo', 'SEO race');
    await openEditor(page, id);
    await page.getByRole('tab', { name: 'SEO', exact: true }).click();
    await page.getByLabel('SEO title').fill('Title A');

    const slow = await interceptNext(page, 'save', 'delay');
    await saveButton(page).click();
    await expect(status(page)).toHaveText('Saving…');
    await page.getByRole('button', { name: 'Undo' }).click(); // undoes the in-flight title change
    await expect(page.getByLabel('SEO title')).toHaveValue('');
    await page.getByLabel('Meta description').fill('Desc B');
    slow.release();

    await expect(status(page)).toHaveText('Unsaved changes');
    await saveButton(page).click();
    await expect(status(page)).toHaveText('Draft saved');

    await page.reload();
    await page.getByRole('tab', { name: 'SEO', exact: true }).click();
    await expect(page.getByLabel('SEO title')).toHaveValue('');
    await expect(page.getByLabel('Meta description')).toHaveValue('Desc B');
});

test('a failed save keeps edits and recovers; a lost response is not applied twice', async ({ page }) => {
    const id = await createPage('/save-recovery', 'Recovery');
    await openEditor(page, id);

    // 1. The request never reaches the server.
    let intercept = await interceptNext(page, 'save', 'abort-before-server');
    await typeIntoHeading(page, 'Aborted once', 'replace');
    await saveButton(page).click();
    await expect(notice(page)).toContainText("Couldn't confirm the save");
    await expect(status(page)).toHaveText('Save not confirmed');
    await expect(saveButton(page)).toBeEnabled();
    await typeIntoHeading(page, ' plus', 'append');
    await intercept.stop();
    await saveButton(page).click(); // resends the unconfirmed batch first
    await expect(status(page)).toHaveText('Unsaved changes');
    await saveButton(page).click();
    await expect(status(page)).toHaveText('Draft saved');

    // The aborted batch was resent once and the later edit saved once: exactly two revisions on the server.
    const before = await revisionCount(id);
    expect(before).toBe(2);

    // 2. The server commits but the response is lost.
    // The history list refreshes asynchronously after a save: wait until it shows the server's confirmed
    // history before relying on it.
    await page.getByRole('tab', { name: 'History' }).click();
    const revisions = page.getByTestId('revision');
    await expect(revisions).toHaveCount(before);
    await page.getByRole('tab', { name: 'Properties' }).click();
    intercept = await interceptNext(page, 'save', 'drop-response-after-commit');
    await typeIntoHeading(page, 'Committed', 'replace');
    await page.keyboard.press('ControlOrMeta+s');
    await page.keyboard.press('ControlOrMeta+s'); // repeated shortcut while saving
    await expect(status(page)).toHaveText('Save not confirmed');
    await intercept.stop();
    await saveButton(page).click(); // same key: the server recognises it
    await expect(status(page)).toHaveText('Draft saved');
    // The lost-response save and its replay added exactly one revision, on the server and in the editor.
    expect(await revisionCount(id)).toBe(before + 1);
    await page.getByRole('tab', { name: 'History' }).click();
    await expect(revisions).toHaveCount(before + 1);

    await page.reload();
    await expect(heading(page)).toHaveText('Committed');
});

test('publish retries are exact; an edit after an uncertain publish starts a new publication', async ({ page }) => {
    const id = await createPage('/publish-intent', 'V0');
    await openEditor(page, id);
    const publish = page.getByRole('button', { name: 'Publish' });

    // Response lost after the publication committed: retry returns it, no second publication.
    await typeIntoHeading(page, 'V1', 'replace');
    let intercept = await interceptNext(page, 'publish', 'drop-response-after-commit');
    await publish.click();
    await expect(notice(page)).toContainText("Couldn't confirm whether the page was published");
    expect(await publicationCount(id)).toBe(1);
    await intercept.stop();
    await publish.click();
    await expect(notice(page)).toContainText('Published');
    expect(await publicationCount(id)).toBe(1);
    expect((await liveHtml(page, '/publish-intent')).html).toContain('>V1</h1>');

    // Request lost before reaching the server: retry publishes once.
    await typeIntoHeading(page, 'V2', 'replace');
    intercept = await interceptNext(page, 'publish', 'abort-before-server');
    await publish.click();
    await expect(notice(page)).toContainText("Couldn't confirm");
    expect(await publicationCount(id)).toBe(1);
    await intercept.stop();
    await publish.click();
    await expect(notice(page)).toContainText('Published');
    expect(await publicationCount(id)).toBe(2);
    expect((await liveHtml(page, '/publish-intent')).html).toContain('>V2</h1>');

    // Uncertain publish of V3, then an edit: the next Publish is a new intent for V4, not a replay of V3.
    await typeIntoHeading(page, 'V3', 'replace');
    intercept = await interceptNext(page, 'publish', 'drop-response-after-commit');
    await publish.click();
    await expect(notice(page)).toContainText("Couldn't confirm");
    await intercept.stop();
    await typeIntoHeading(page, 'V4', 'replace');
    await publish.click();
    await expect(notice(page)).toContainText('Published');
    expect(await publicationCount(id)).toBe(4);
    expect((await liveHtml(page, '/publish-intent')).html).toContain('>V4</h1>');
});

test('Ctrl+S during a slow restore sends nothing, keeps fields locked and the restore succeeds', async ({ page }) => {
    const id = await createPage('/restore-lock', 'Fixture');
    await openEditor(page, id);
    // Fixture pages start without revisions: create #1 and #2.
    await typeIntoHeading(page, 'Revision one', 'replace');
    await saveButton(page).click();
    await expect(status(page)).toHaveText('Draft saved');
    await typeIntoHeading(page, 'Revision two', 'replace');
    await saveButton(page).click();
    await expect(status(page)).toHaveText('Draft saved');

    // Unsaved edit, then restore revision #1 (accepting the "discard unsaved changes" prompt).
    await page.getByLabel('Heading', { exact: true }).fill('Unsaved edit');
    page.once('dialog', (dialog) => void dialog.accept());
    const saveRequests: string[] = [];
    page.on('request', (request) => {
        if (isAction(request, 'save')) saveRequests.push(request.url());
    });
    const slowRestore = await interceptNext(page, 'restore', 'delay');
    await page.getByRole('tab', { name: 'History' }).click();
    await page.getByTestId('revision').filter({ hasText: '#1' }).getByRole('button', { name: 'Restore' }).click();
    await expect(status(page)).toHaveText('Restoring…');

    // Shortcut in the admin document…
    await page.getByRole('tab', { name: 'Properties' }).click();
    await page.locator('body').press('ControlOrMeta+s');
    // …and inside the canvas iframe (relayed by the bridge).
    await page
        .frameLocator('[data-testid="canvas"]')
        .locator('section')
        .click({ position: { x: 4, y: 4 } });
    await page.keyboard.press('ControlOrMeta+s');

    await expect(status(page)).toHaveText('Restoring…');
    await expect(page.getByLabel('Heading', { exact: true })).toBeDisabled();
    await expect(page.getByLabel('Text', { exact: true })).toBeDisabled();
    expect(saveRequests).toEqual([]);

    slowRestore.release();
    await expect(notice(page)).toContainText('Restored revision #1');
    await expect(status(page)).toHaveText('Draft saved');
    await expect(page.getByText('Out of date')).toHaveCount(0);
    await expect(page.getByLabel('Heading', { exact: true })).toBeEnabled();
    await expect(heading(page)).toHaveText('Revision one');
    expect(saveRequests).toEqual([]);

    // The restored draft is the server's current version: the next edit saves without conflict.
    await page.getByLabel('Heading', { exact: true }).fill('After restore');
    await saveButton(page).click();
    await expect(status(page)).toHaveText('Draft saved');
    await page.reload();
    await expect(heading(page)).toHaveText('After restore');
});
