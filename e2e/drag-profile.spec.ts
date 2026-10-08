// Drag profile on a realistically populated page (about 200 blocks), not a regression test:
// runs only with EDITOR_PERF=1 and prints what the browser measured while dragging:
// - preview latency: from a pointer event to the first frame that shows the preview there;
// - destination latency: from the latest pointer event to the status naming a new destination;
// - frame intervals and long tasks (> 50 ms) during the drag;
// - messages between the editor and the canvas iframe, per second.
// Local, unthrottled conditions; numbers vary by machine, so only scrolling progress is asserted.
import { expect, test, type Page } from '@playwright/test';
import { populatedPage } from './populated';

test.skip(process.env.EDITOR_PERF !== '1', 'Set EDITOR_PERF=1 to profile dragging');

interface DragLog {
    moves: { t: number; x: number; y: number }[];
    previewLatency: number[];
    statusLatency: number[];
    frames: number[];
    longTasks: number[];
    fromCanvas: number;
    recording: boolean;
}

/** Installed before the page loads, in the editor and in the canvas iframe. */
async function instrument(page: Page) {
    await page.addInitScript(() => {
        const w = window as unknown as { __received: number; __drag: DragLog };
        if (window !== window.top) {
            // In the canvas: messages received from the editor.
            w.__received = 0;
            addEventListener('message', (event) => {
                if ((event.data as { source?: string })?.source === 'arkon-editor') w.__received++;
            });
            return;
        }
        const log: DragLog = { moves: [], previewLatency: [], statusLatency: [], frames: [], longTasks: [], fromCanvas: 0, recording: false };
        w.__drag = log;
        addEventListener('message', (event) => {
            if (log.recording && (event.data as { source?: string })?.source === 'arkon-canvas') log.fromCanvas++;
        });
        addEventListener(
            'pointermove',
            (event) => {
                if (event.isTrusted) (window as unknown as { __pointerId: number }).__pointerId = event.pointerId;
                if (log.recording) log.moves.push({ t: event.timeStamp, x: event.clientX, y: event.clientY });
            },
            true,
        );
        new PerformanceObserver((list) => {
            for (const entry of list.getEntries()) if (log.recording) log.longTasks.push(entry.duration);
        }).observe({ type: 'longtask', buffered: false } as PerformanceObserverInit);
        let lastFrame = 0;
        let measured = 0;
        const frame = (now: number) => {
            if (log.recording) {
                if (lastFrame) log.frames.push(now - lastFrame);
                // Which pointer position the preview shows now; each position's latency is counted once.
                const shown = document.querySelector<HTMLElement>('[data-testid="drag-preview"]')?.style.transform;
                for (let k = log.moves.length - 1; k >= measured; k--) {
                    const move = log.moves[k]!;
                    if (shown === `translate(${Math.round(move.x + 14)}px, ${Math.round(move.y + 16)}px)`) {
                        log.previewLatency.push(performance.now() - move.t);
                        measured = k + 1;
                        break;
                    }
                }
            }
            lastFrame = now;
            requestAnimationFrame(frame);
        };
        requestAnimationFrame(frame);
        let lastStatus = '';
        const statusObserver = new MutationObserver(() => {
            const status = document.querySelector('[data-testid="drag-status"]')?.textContent ?? '';
            const last = log.moves.at(-1);
            if (log.recording && last && status !== lastStatus) log.statusLatency.push(performance.now() - last.t);
            lastStatus = status;
        });
        addEventListener('DOMContentLoaded', () => statusObserver.observe(document.body, { subtree: true, characterData: true, childList: true }));
    });
}

const stats = (values: number[]) => {
    const sorted = [...values].sort((a, b) => a - b);
    const q = (p: number) => (sorted.length ? Math.round(sorted[Math.min(sorted.length - 1, Math.floor(p * sorted.length))]! * 10) / 10 : 0);
    return `n=${sorted.length} p50=${q(0.5)} p95=${q(0.95)} max=${q(1)}`;
};

test('continuous dragging and stationary edge scrolling with about 200 blocks', async ({ page }) => {
    test.setTimeout(180_000);
    await instrument(page);
    await page.setViewportSize({ width: 1440, height: 900 });
    const id = await populatedPage();
    await page.goto(`/admin/editor/${id}`);
    // DRAG_THROTTLE=4 slows the CPU fourfold (Chrome DevTools emulation), like a low-end laptop.
    const rate = Number(process.env.DRAG_THROTTLE ?? 1);
    if (rate > 1) await (await page.context().newCDPSession(page)).send('Emulation.setCPUThrottlingRate', { rate });
    const canvas = page.frameLocator('[data-testid="canvas"]');
    await expect(canvas.locator('h2').first()).toHaveText('Section 1');
    await canvas.locator('h2').first().click();
    await page.keyboard.press('Escape');
    const handle = (await page.getByTestId('canvas-drag-handle').boundingBox())!;
    const frame = (await page.getByTestId('canvas').boundingBox())!;
    const read = () => page.evaluate(() => (window as unknown as { __drag: DragLog }).__drag);
    const reset = () =>
        page.evaluate(() =>
            Object.assign((window as unknown as { __drag: DragLog }).__drag, {
                moves: [],
                previewLatency: [],
                statusLatency: [],
                frames: [],
                longTasks: [],
                fromCanvas: 0,
                recording: false,
            }),
        );
    const record = () => page.evaluate(() => ((window as unknown as { __drag: DragLog }).__drag.recording = true));
    const received = () => canvas.locator('body').evaluate(() => (window as unknown as { __received: number }).__received);

    // 1. Continuous dragging: 3 s of movement over the canvas, a pointer event about every 8 ms.
    await page.mouse.move(handle.x + handle.width / 2, handle.y + handle.height / 2);
    await page.mouse.down();
    await page.mouse.move(frame.x + 300, frame.y + 200, { steps: 4 });
    await page.waitForTimeout(300);
    await record();
    const before = await received();
    const start = Date.now();
    // Playwright's mouse delivers only a few moves per second, so the 120 Hz stream of a real
    // mouse is generated in the page (the same pointer as the held button: pointerId 1).
    await page.evaluate(
        ({ x, y }) =>
            new Promise<void>((resolve) => {
                let i = 0;
                const timer = setInterval(() => {
                    const t = i++ / 40;
                    window.dispatchEvent(
                        new PointerEvent('pointermove', {
                            pointerId: (window as unknown as { __pointerId: number }).__pointerId,
                            pointerType: 'mouse',
                            isPrimary: true,
                            buttons: 1,
                            bubbles: true,
                            clientX: x + Math.sin(t) * 300,
                            clientY: y + Math.sin(t * 1.7) * 300,
                        }),
                    );
                    if (i >= 375) {
                        clearInterval(timer);
                        resolve();
                    }
                }, 8);
            }),
        { x: frame.x + 400, y: frame.y + 450 },
    );
    let log = await read();
    let seconds = (Date.now() - start) / 1000;
    let toCanvas = (await received()) - before;
    console.log(`continuous drag: ${log.moves.length} pointer events in ${seconds.toFixed(1)} s`);
    console.log(`  preview latency ms (event → frame showing it): ${stats(log.previewLatency)}`);
    console.log(`  destination latency ms (event → status change): ${stats(log.statusLatency)}`);
    console.log(`  frame interval ms: ${stats(log.frames)}; long tasks: ${log.longTasks.length} ${log.longTasks.length ? stats(log.longTasks) : ''}`);
    console.log(
        `  messages: editor → canvas ${toCanvas} (${(toCanvas / seconds).toFixed(1)}/s), canvas → editor ${log.fromCanvas} (${(log.fromCanvas / seconds).toFixed(1)}/s)`,
    );
    await page.keyboard.press('Escape');
    await page.mouse.up();

    // 2. Stationary edge scrolling: the pointer held still 10 px above the canvas bottom for 2 s.
    await reset();
    const scrollY = () => canvas.locator('body').evaluate(() => window.scrollY);
    await canvas.locator('body').evaluate(() => window.scrollTo(0, 0));
    await page.mouse.move(handle.x + handle.width / 2, handle.y + handle.height / 2);
    await page.mouse.down();
    await page.mouse.move(frame.x + frame.width / 2, frame.y + frame.height - 10, { steps: 6 });
    await page.waitForTimeout(100);
    await record();
    const scrollStart = await scrollY();
    const before2 = await received();
    await page.waitForTimeout(2000);
    log = await read();
    seconds = 2;
    const scrolled = (await scrollY()) - scrollStart;
    toCanvas = (await received()) - before2;
    console.log(`stationary edge scrolling: ${scrolled} px in 2 s (${Math.round(scrolled / 2)} px/s, pointer still)`);
    console.log(`  frame interval ms: ${stats(log.frames)}; long tasks: ${log.longTasks.length} ${log.longTasks.length ? stats(log.longTasks) : ''}`);
    console.log(
        `  messages: editor → canvas ${toCanvas} (${(toCanvas / seconds).toFixed(1)}/s), canvas → editor ${log.fromCanvas} (${(log.fromCanvas / seconds).toFixed(1)}/s)`,
    );
    await page.keyboard.press('Escape');
    await page.mouse.up();
    expect(scrolled).toBeGreaterThan(500);
});
