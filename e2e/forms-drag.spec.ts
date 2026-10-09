import { expect, test } from '@playwright/test';

async function create(page: import('@playwright/test').Page, name: string) {
    await page.setViewportSize({ width: 1600, height: 1000 });
    await page.goto('/admin/forms');
    await page.getByRole('button', { name: 'New form', exact: true }).click();
    await page.getByLabel('Form name', { exact: true }).fill(name);
    await page.getByRole('button', { name: 'Create form', exact: true }).click();
    await expect(page.getByRole('heading', { name, exact: true })).toBeVisible();
}
test('clicks select; a handle drag lifts, tracks, indicates and commits only on release', async ({ page }) => {
    await create(page, 'Tactile drag test');
    const cards = page.locator('[data-form-field]'),
        email = page.getByRole('button', { name: 'Move Email', exact: true });
    const ids = await cards.evaluateAll((elements) => elements.map((e) => e.getAttribute('data-form-field')));
    const point = await email.boundingBox();
    expect(point).toBeTruthy();
    const x = point!.x + point!.width / 2,
        y = point!.y + point!.height / 2;
    await page.mouse.move(x, y);
    await page.mouse.down();
    await page.mouse.move(x + 2, y + 1);
    await page.mouse.up();
    await expect(page.getByLabel('Label', { exact: true })).toHaveValue('Email');
    expect(await cards.evaluateAll((es) => es.map((e) => e.getAttribute('data-form-field')))).toEqual(ids);
    const refreshed = await email.boundingBox(),
        card = await cards.nth(1).boundingBox();
    const px = refreshed!.x + refreshed!.width / 2,
        py = refreshed!.y + refreshed!.height / 2;
    await page.mouse.move(px, py);
    await page.mouse.down();
    await page.mouse.move(px - 40, py + 30, { steps: 8 });
    const ghost = page.getByTestId('form-drag-preview');
    await expect(ghost).toBeVisible();
    const lifted = await ghost.boundingBox();
    expect(Math.abs(lifted!.x - (card!.x - 40))).toBeLessThan(3);
    expect(Math.abs(lifted!.y - (card!.y + 30))).toBeLessThan(3);
    const first = await page.locator('[data-form-row]').first().boundingBox();
    await page.mouse.move(first!.x + first!.width - 40, first!.y + first!.height / 2, { steps: 20 });
    await expect(page.getByTestId('form-drop-marker')).toBeVisible();
    await expect(page.getByTestId('form-drop-marker')).toHaveAttribute('data-axis', 'x');
    expect(await cards.evaluateAll((es) => es.map((e) => e.getAttribute('data-form-field')))).toEqual(ids);
    await page.screenshot({ path: 'C:/Users/jacob/Documents/Codex/2026-10-06/referenced-chatgpt-conversation-this-is-an/outputs/forms-drag-active.png' });
    await page.mouse.up();
    await expect(page.locator('[data-form-row]')).toHaveCount(2);
    await expect(ghost).toHaveCount(0);
    expect((await cards.evaluateAll((es) => es.map((e) => e.getAttribute('data-form-field')))).sort()).toEqual([...ids].sort());
    await expect(page.getByLabel('Label', { exact: true })).toHaveValue('Email');
    // Cancellation restores the original placeholder without changing the document.
    const handle = await email.boundingBox();
    await page.mouse.move(handle!.x + 10, handle!.y + 10);
    await page.mouse.down();
    await page.mouse.move(handle!.x + 30, handle!.y + 40);
    await expect(ghost).toBeVisible();
    await page.keyboard.press('Escape');
    await page.mouse.up();
    await expect(ghost).toHaveCount(0);
    await expect(page.locator('[data-form-row]')).toHaveCount(2);
});
test('a stationary pointer scrolls long forms, then a fast drop preserves identity', async ({ page }) => {
    await create(page, 'Long form drag test');
    for (const name of ['Dropdown', 'Radio buttons', 'Checkboxes', 'Consent', 'Section', 'Divider', ...Array(10).fill('Paragraph')])
        await page.getByRole('button', { name, exact: true }).click();
    const canvas = page.locator('[data-form-row]').first().locator('..').locator('..');
    await canvas.evaluate((e) => {
        e.scrollTop = 0;
    });
    const original = await page.locator('[data-form-field]').first().getAttribute('data-form-field');
    const handle = await page.getByRole('button', { name: 'Move Your name', exact: true }).boundingBox(),
        bounds = await canvas.boundingBox();
    await page.mouse.move(handle!.x + 10, handle!.y + 10);
    await page.mouse.down();
    await page.mouse.move(bounds!.x + bounds!.width / 2, bounds!.y + bounds!.height - 5, { steps: 12 });
    await expect.poll(() => canvas.evaluate((e) => e.scrollTop)).toBeGreaterThan(150);
    await expect(page.getByTestId('form-drag-preview')).toBeVisible();
    // Move above the current first visible row and release rapidly.
    await page.mouse.move(bounds!.x + 50, bounds!.y + 40);
    await page.mouse.up();
    await expect(page.getByTestId('form-drag-preview')).toHaveCount(0);
    expect(await page.locator('[data-form-field]').evaluateAll((es) => es.filter((e) => e.getAttribute('data-form-field') === null).length)).toBe(0);
    expect(await page.locator('[data-form-field]').evaluateAll((es, id) => es.filter((e) => e.getAttribute('data-form-field') === id).length, original)).toBe(
        1,
    );
    await expect(page.getByLabel('Label', { exact: true })).toHaveValue('Your name');
});
