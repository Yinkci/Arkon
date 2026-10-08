// Editor responsiveness on a realistically populated page (not a regression test): runs only
// with EDITOR_PERF=1 and prints interaction timings measured by the browser's Event Timing API
// (the same source as INP): typing in the inspector, typing on the canvas, selecting blocks
// and parts, switching screens, and dragging a block with the Move handle.
import { expect, test, type Page } from '@playwright/test';
import { populatedPage } from './populated';

test.skip(process.env.EDITOR_PERF !== '1', 'Set EDITOR_PERF=1 to measure editor responsiveness');

/** Starts collecting interaction durations (Event Timing) in the editor's document. */
async function observe(page: Page) {
    await page.evaluate(() => {
        const w = window as unknown as { __events: number[] };
        w.__events = [];
        new PerformanceObserver((list) => {
            for (const entry of list.getEntries() as PerformanceEventTiming[]) if (entry.interactionId) w.__events.push(entry.duration);
        }).observe({ type: 'event', durationThreshold: 16, buffered: false } as PerformanceObserverInit);
    });
}

async function collect(page: Page, label: string) {
    await page.waitForTimeout(500);
    const events = await page.evaluate(() => {
        const w = window as unknown as { __events: number[] };
        const out = w.__events;
        w.__events = [];
        return out;
    });
    const sorted = [...events].sort((a, b) => a - b);
    const p = (q: number) => (sorted.length ? sorted[Math.min(sorted.length - 1, Math.floor(q * sorted.length))]! : 0);
    console.log(`${label}: ${events.length} slow-enough interactions (≥16 ms) · p50 ${p(0.5)} ms · p98 ${p(0.98)} ms · max ${sorted.at(-1) ?? 0} ms`);
    return sorted.at(-1) ?? 0;
}

test('editor responsiveness with about 200 blocks', async ({ page }) => {
    test.setTimeout(180_000);
    await page.setViewportSize({ width: 1440, height: 900 });
    const id = await populatedPage();
    const started = Date.now();
    await page.goto(`/admin/editor/${id}`);
    await expect(page.frameLocator('[data-testid="canvas"]').locator('h2').first()).toHaveText('Section 1');
    console.log(`open: ${Date.now() - started} ms until the canvas shows the page`);
    await observe(page);

    const heading = page.getByLabel('Heading', { exact: true });
    await heading.click();
    await page.keyboard.type(' with typing in the inspector', { delay: 30 });
    const inspectorMax = await collect(page, 'typing in the inspector (29 keys)');

    const canvasHeading = page.frameLocator('[data-testid="canvas"]').locator('h1');
    await canvasHeading.click();
    await page.keyboard.press('End');
    await page.keyboard.type(' and on the canvas', { delay: 30 });
    await collect(page, 'typing on the canvas (18 keys)');

    for (let i = 0; i < 6; i++) await page.frameLocator('[data-testid="canvas"]').locator('h2').nth(i).click();
    for (const part of ['part-root', 'part-heading', 'part-root']) {
        await page
            .frameLocator('[data-testid="canvas"]')
            .locator('h1')
            .click({ position: { x: 2, y: 2 } });
        if (await page.getByTestId(part).count()) await page.getByTestId(part).click();
    }
    await collect(page, 'selecting blocks and parts');

    for (const v of ['Mobile', 'Tablet', 'Desktop']) await page.getByRole('group', { name: 'Viewport' }).getByRole('button', { name: v }).click();
    await collect(page, 'switching screen sizes');

    await page.getByRole('tab', { name: 'Layers' }).click();
    await page.getByRole('tab', { name: 'Properties' }).click();
    await collect(page, 'switching sidebar tabs (Layers lists every block)');

    // Animation settings of a section (effect, then the duration slider), each a canvas render.
    await page.frameLocator('[data-testid="canvas"]').locator('h2').nth(2).click();
    await page.keyboard.press('Escape');
    await page.getByRole('button', { name: 'Select parent' }).click();
    for (const effect of ['fade-up', 'zoom', 'fade']) {
        await page.getByTestId('animation-effect').selectOption(effect);
        await page.waitForTimeout(300);
    }
    await page.getByTestId('animation-duration').fill('900');
    await collect(page, 'changing animation settings (3 effects, a duration)');

    // Drag the second section above the first with the canvas Move handle.
    await page.frameLocator('[data-testid="canvas"]').locator('h2').nth(1).click();
    await page.getByTestId('part-root').count();
    const handle = page.getByTestId('canvas-drag-handle');
    await handle.hover();
    const box = (await handle.boundingBox())!;
    await page.mouse.down();
    const frame = (await page.getByTestId('canvas').boundingBox())!;
    for (let step = 0; step <= 20; step++) await page.mouse.move(box.x + 10, box.y - (step * (box.y - frame.y - 40)) / 20, { steps: 2 });
    await page.mouse.up();
    await collect(page, 'dragging a block');
    expect(inspectorMax).toBeLessThan(500);
});
