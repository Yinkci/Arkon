import { execFileSync } from 'node:child_process';
import { expect, test } from '@playwright/test';
import { E2E_ENV, PHP, APP_ORIGIN } from './env';
import { E2E_OWNER } from './fixtures';
import { createPage, db } from './support';

test.beforeAll(() => {
    execFileSync(PHP, ['artisan', 'arkon:perf-fixtures', '--email=' + E2E_OWNER.email], { env: { ...process.env, ...E2E_ENV }, encoding: 'utf8' });
});
test.afterAll(async () => {
    await db.end();
});

test('reference page has accessible controls, stable slides, a sticky header and an exact script policy', async ({ page }) => {
    const errors: string[] = [];
    page.on('console', (m) => {
        if (m.type() === 'error') errors.push(m.text());
    });
    const response = await page.goto('/perf-reference');
    expect(response!.headers()['content-security-policy']).toContain(APP_ORIGIN + '/_arkon/components-5.js');
    const slider = page.getByRole('region', { name: 'Featured services' });
    const slides = slider.locator('.ak-slide');
    await expect(slides.nth(0)).toHaveAttribute('data-active', 'true');
    const height = (await slider.boundingBox())!.height;
    await slider.getByRole('button', { name: 'Next slide', exact: true }).click();
    await expect(slides.nth(1)).toHaveAttribute('data-active', 'true');
    await expect(slides.nth(0)).toHaveAttribute('inert', '');
    expect((await slider.boundingBox())!.height).toBe(height);
    await slider.getByRole('button', { name: 'Previous slide', exact: true }).focus();
    await page.keyboard.press('ArrowLeft');
    await expect(slides.nth(0)).toHaveAttribute('data-active', 'true');
    expect(await page.locator('header').evaluate((e) => getComputedStyle(e).position)).toBe('sticky');
    await page.setViewportSize({ width: 390, height: 844 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.screenshot({ path: 'storage/e2e/reference-mobile.png', fullPage: true });
    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.screenshot({ path: 'storage/e2e/reference-desktop.png', fullPage: true });
    expect(errors).toEqual([]);
});

test('reference works without JavaScript and with reduced motion; newsletter really records an entry', async ({ browser }) => {
    const nojs = await browser.newContext({ javaScriptEnabled: false });
    const page = await nojs.newPage();
    await page.goto(APP_ORIGIN + '/perf-reference');
    await expect(page.locator('.ak-slide').nth(0)).toBeVisible();
    await expect(page.locator('.ak-slide').nth(1)).toBeHidden();
    await expect(page.getByRole('button', { name: 'Next slide' })).toBeHidden();
    await page.getByLabel('Email address (required)').fill('newsletter-demo@example.invalid');
    await page.getByRole('button', { name: 'Subscribe', exact: true }).click();
    await expect(page.getByText('Your demo signup has been recorded.')).toBeVisible();
    await nojs.close();
    const reduced = await browser.newContext({ reducedMotion: 'reduce', viewport: { width: 390, height: 844 }, hasTouch: true });
    const mobile = await reduced.newPage();
    await mobile.goto(APP_ORIGIN + '/perf-reference');
    const slider = mobile.getByRole('region', { name: 'Featured services' });
    await slider.dispatchEvent('pointerdown', { pointerType: 'touch', clientX: 330, clientY: 150 });
    await slider.dispatchEvent('pointerup', { pointerType: 'touch', clientX: 100, clientY: 160 });
    await expect(slider.locator('.ak-slide').nth(1)).toHaveAttribute('data-active', 'true');
    await mobile.getByRole('link', { name: 'Back to top', exact: true }).click();
    await expect(mobile.locator('main')).toBeFocused();
    await reduced.close();
});

test('starting layouts insert ordinary editable blocks, save, undo and reload in the real builder', async ({ page }) => {
    const id = await createPage('/reference-editor', 'Reference editor');
    await page.goto('/admin/editor/' + id);
    await page.getByRole('tab', { name: 'Layers', exact: true }).click();
    await page.getByText('Starting layouts', { exact: true }).click();
    await page.getByRole('button', { name: 'Introduction and service cards', exact: true }).click();
    await expect(page.frameLocator('[data-testid="canvas"]').getByText('What we do for our clients')).toBeVisible();
    await page.getByRole('button', { name: 'Undo', exact: true }).click();
    await expect(page.frameLocator('[data-testid="canvas"]').getByText('What we do for our clients')).toHaveCount(0);
    await page.getByRole('button', { name: 'Redo', exact: true }).click();
    await page.getByRole('button', { name: 'Save draft', exact: true }).click();
    await expect(page.getByTestId('save-status').getByText('Draft saved', { exact: true })).toBeVisible();
    await page.reload();
    await expect(page.frameLocator('[data-testid="canvas"]').getByText('What we do for our clients')).toBeVisible();
});

test('autoplay pauses for hover, focus and reduced motion', async ({ page }) => {
    await page.clock.install();
    // A genuinely published autoplay fixture, served with its normal CSP.
    await page.goto('/perf-reference-autoplay');
    const slider = page.getByRole('region', { name: 'Featured services' });
    await expect(slider).toHaveAttribute('data-enhanced', 'true');
    const first = slider.locator('.ak-slide').nth(0);
    await page.mouse.move(0, 0);
    await page.clock.runFor(8000);
    await expect(first).toHaveAttribute('data-active', 'false');
    await slider.hover();
    await page.clock.runFor(11000);
    await expect(first).toHaveAttribute('data-active', 'false');
    await slider.getByRole('button', { name: 'Next slide', exact: true }).focus();
    await page.mouse.move(0, 0);
    await page.clock.runFor(11000);
    await expect(first).toHaveAttribute('data-active', 'false');
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await page.evaluate(() => (document.activeElement as HTMLElement).blur());
    await page.clock.runFor(11000);
    await expect(first).toHaveAttribute('data-active', 'false');
});

test('slider pagination defaults to reference dots with stable controls', async ({ page }) => {
    await page.goto('/perf-reference');
    const slider = page.getByRole('region', { name: 'Featured services' });
    await expect(slider).toHaveClass(/ak-slider--dots/);
    const active = slider.locator('[aria-current=true] .ak-slider__marker');
    expect(await active.evaluate((el) => getComputedStyle(el).fontSize)).toBe('0px');
    expect(await active.evaluate((el) => el.getBoundingClientRect().width)).toBe(24);
    const height = (await slider.boundingBox())!.height;
    await slider.getByRole('button', { name: 'Show slide 2', exact: true }).click();
    expect((await slider.boundingBox())!.height).toBe(height);
    await expect(slider.locator('.ak-slide').nth(1)).toHaveAttribute('data-active', 'true');
});

test('canvas shows one slide and reveals hidden content selected through Layers', async ({ page }) => {
    const id = await createPage('/slider-canvas-v2', 'Slider canvas');
    await page.goto('/admin/editor/' + id);
    await page.getByRole('tab', { name: 'Layers', exact: true }).click();
    await page.getByText('Starting layouts', { exact: true }).click();
    await page.getByRole('button', { name: 'Image hero slider', exact: true }).click();
    const canvas = page.frameLocator('[data-testid=canvas]');
    const slider = canvas.locator('[data-editor-slider]').first();
    await expect(slider.locator('.ak-slide').nth(0)).toBeVisible();
    await expect(slider.locator('.ak-slide').nth(1)).toBeHidden();
    await slider.getByRole('button', { name: 'Show slide 2', exact: true }).click();
    await expect(slider.locator('.ak-slide').nth(1)).toBeVisible();
    await expect(slider.locator('.ak-slide').nth(0)).toBeHidden();
    await page.getByRole('tab', { name: 'Layers', exact: true }).click();
    const layers = page.locator('[data-testid=layer][data-node-type=slide]');
    await layers.first().click();
    await expect(slider.locator('.ak-slide').nth(0)).toBeVisible();
    await layers.nth(1).click();
    await page.locator('[data-testid=layer][data-node-type=slider]').first().click();
    await page.getByRole('tab', { name: 'Properties', exact: true }).click();
    const label = page.getByLabel('Accessible slider label', { exact: true });
    await label.fill('Edited slider label');
    await label.blur();
    await expect(slider.locator('.ak-slide').nth(1)).toBeVisible();
    await page.getByLabel('Pagination style', { exact: true }).selectOption('bars');
    await expect(slider).toHaveClass(/ak-slider--bars/);
    await page.getByLabel('Automatically advance slides', { exact: true }).check();
    await page.getByLabel('Time between slides', { exact: true }).selectOption('1000');
    await expect(slider).toHaveAttribute('data-interval', '1000');
    await page.clock.install();
    await page.getByRole('button', { name: 'Play slideshow', exact: true }).click();
    await page.clock.runFor(1100);
    await expect(slider.locator('.ak-slide').nth(0)).toBeVisible();
    await page.getByRole('button', { name: 'Pause slideshow', exact: true }).click();
    await page.clock.resume();
    await page.getByRole('button', { name: 'Save draft', exact: true }).click();
    await expect(page.getByTestId('save-status').getByText('Draft saved', { exact: true })).toBeVisible();
    await page.reload();
    await page.getByRole('tab', { name: 'Layers', exact: true }).click();
    await page.locator('[data-testid=layer][data-node-type=slider]').first().click();
    await page.getByRole('tab', { name: 'Properties', exact: true }).click();
    await expect(page.getByLabel('Pagination style', { exact: true })).toHaveValue('bars');
    await expect(page.getByLabel('Automatically advance slides', { exact: true })).toBeChecked();
    await expect(page.getByLabel('Time between slides', { exact: true })).toHaveValue('1000');
    await expect(slider.locator('.ak-slide[data-active=true]')).toHaveCount(1);
});

test('slide transitions move both images without layout shift and keep quiet playback accessible', async ({ page }) => {
    await page.goto('/perf-reference');
    const slider = page.getByRole('region', { name: 'Featured services' });
    await expect(slider).toHaveAttribute('data-transition', 'slide');
    const height = (await slider.boundingBox())!.height;
    await slider.evaluate((root) => {
        (root.querySelector('[data-slide-step="1"]') as HTMLElement).click();
        root.querySelectorAll('.ak-slide').forEach((slide) =>
            slide.getAnimations().forEach((a) => {
                a.pause();
                a.currentTime = 200;
            }),
        );
    });
    const slides = slider.locator('.ak-slide');
    expect(await slides.nth(0).evaluate((el) => getComputedStyle(el).visibility)).toBe('visible');
    expect(await slides.nth(0).evaluate((el) => getComputedStyle(el).backgroundImage)).not.toBe('none');
    expect(await slides.nth(1).evaluate((el) => getComputedStyle(el).transform)).not.toBe('none');
    expect((await slider.boundingBox())!.height).toBe(height);
    await slider.evaluate((root) => root.querySelectorAll('.ak-slide').forEach((el) => el.getAnimations().forEach((a) => a.finish())));
    await expect(slides.nth(0)).toHaveAttribute('data-active', 'false');
    await slider.evaluate((root) => {
        const next = root.querySelector('[data-slide-step="1"]') as HTMLElement;
        next.click();
        next.click();
    });
    await expect(slider.locator('[data-exiting]')).toHaveCount(0);
    await expect(slider.locator('.ak-slide[data-active=true]')).toHaveCount(1);
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await slider.getByRole('button', { name: 'Next slide', exact: true }).click();
    expect(await slider.evaluate((root) => root.getAnimations({ subtree: true }).length)).toBe(0);
    await page.emulateMedia({ reducedMotion: 'no-preference' });
    for (const transition of ['fade', 'none']) {
        await slider.evaluate((root, mode) => {
            (root as HTMLElement).dataset.transition = mode;
            (root.querySelector('[data-slide-step="1"]') as HTMLElement).click();
        }, transition);
        const animations = await slider.evaluate((root) => root.getAnimations({ subtree: true }).map((a) => (a.effect as KeyframeEffect).getKeyframes()));
        if (transition === 'fade') expect(animations.some((frames) => frames.some((f) => 'opacity' in f))).toBe(true);
        else expect(animations).toHaveLength(0);
    }
    await page.goto('/perf-reference-autoplay');
    const autoplay = page.getByRole('region', { name: 'Featured services' });
    const pause = autoplay.locator('[data-slide-pause]');
    expect(await pause.evaluate((el) => getComputedStyle(el).clipPath)).toBe('inset(50%)');
    await pause.focus();
    expect(await pause.evaluate((el) => getComputedStyle(el).clipPath)).toBe('none');
    await page.keyboard.press('Enter');
    await expect(autoplay).toHaveAttribute('data-playback', 'paused');
});

test('edge arrows and no pagination work in the builder, survive saving and publish', async ({ page }) => {
    const id = await createPage('/slider-edge-layout', 'Edge slider');
    await page.goto('/admin/editor/' + id);
    await page.getByRole('tab', { name: 'Layers', exact: true }).click();
    await page.getByText('Starting layouts', { exact: true }).click();
    await page.getByRole('button', { name: 'Image hero slider', exact: true }).click();
    await page.locator('[data-testid=layer][data-node-type=slider]').first().click();
    await page.getByRole('tab', { name: 'Properties', exact: true }).click();
    await page.getByLabel('Arrow placement', { exact: true }).selectOption('grouped');
    await page.getByLabel('Horizontal position', { exact: true }).selectOption('center');
    await page.getByLabel('Arrow appearance', { exact: true }).selectOption('plain');
    await page.getByLabel('Arrow button size (px)', { exact: true }).selectOption('56');
    await page.getByLabel('Arrow icon size (px)', { exact: true }).selectOption('24');
    await page.getByLabel('Space between grouped arrows (px)', { exact: true }).selectOption('12');
    await page.getByLabel('Arrow distance from left/right edge (px)', { exact: true }).selectOption('24');
    await page.getByLabel('Arrow distance from top/bottom edge (px)', { exact: true }).selectOption('32');
    const designRoot = page.frameLocator('[data-testid=canvas]').locator('[data-editor-slider]').first();
    for (const position of ['top', 'middle', 'bottom']) {
        await page.getByLabel('Vertical position', { exact: true }).selectOption(position);
        await expect(designRoot).toHaveClass(new RegExp('ak-slider--groupedArrowPosition-' + position));
        const box = (await designRoot.boundingBox())!;
        const controls = (await designRoot.locator('.ak-slider__controls').boundingBox())!;
        expect(Math.abs(controls.x + controls.width / 2 - box.x - box.width / 2)).toBeLessThan(2);
        if (position === 'middle') expect(Math.abs(controls.y + controls.height / 2 - box.y - box.height / 2)).toBeLessThan(2);
        else if (position === 'top') expect(Math.abs(controls.y - box.y - 32)).toBeLessThan(2);
        else expect(Math.abs(box.y + box.height - controls.y - controls.height - 32)).toBeLessThan(2);
    }
    const sectionTitles = await page.locator('[data-testid=design-panel] section h3').allTextContents();
    expect(sectionTitles.indexOf('Arrows')).toBeLessThan(sectionTitles.indexOf('Pagination'));
    const arrow = designRoot.getByRole('button', { name: 'Next slide', exact: true });
    expect(await arrow.evaluate((el) => getComputedStyle(el).backgroundColor)).toBe('rgba(0, 0, 0, 0)');
    expect(await arrow.evaluate((el) => getComputedStyle(el).borderTopWidth)).toBe('0px');
    expect((await arrow.boundingBox())!.width).toBe(56);
    expect(await arrow.locator('svg').evaluate((el) => getComputedStyle(el).width)).toBe('24px');
    await page.getByLabel('Arrow background color', { exact: true }).fill('#225588');
    await page.getByLabel('Arrow background color', { exact: true }).blur();
    await expect(arrow).toHaveCSS('background-color', 'rgb(34, 85, 136)');
    await page.getByLabel('Arrow icon color', { exact: true }).fill('#ff0000');
    await page.getByLabel('Arrow icon color', { exact: true }).blur();
    await expect(arrow).toHaveCSS('color', 'rgb(255, 0, 0)');
    await page.getByRole('button', { name: 'Transparent background', exact: true }).click();
    await page.getByLabel('Arrow background color', { exact: true }).blur();
    await expect(arrow).toHaveCSS('background-color', 'rgba(0, 0, 0, 0)');
    await page.getByLabel('Show previous / next arrows', { exact: true }).uncheck();
    await expect(page.getByLabel('Arrow appearance', { exact: true })).toHaveCount(0);
    await expect(page.getByLabel('Arrow background color', { exact: true })).toHaveCount(0);
    await expect(designRoot.locator('.ak-slider__arrow').first()).toBeHidden();
    await page.getByLabel('Show previous / next arrows', { exact: true }).check();
    await page.getByLabel('Arrow placement', { exact: true }).selectOption('edges');
    await page.getByLabel('Pagination style', { exact: true }).selectOption('dots');
    const frame = page.frameLocator('[data-testid=canvas]');
    const target = frame.locator('[data-editor-slider]').first();
    await expect(target.locator('[data-editor-play]')).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Play slideshow', exact: true })).toBeVisible();
    for (const align of ['start', 'center', 'end']) {
        await page.getByLabel('Pagination alignment', { exact: true }).selectOption(align);
        await expect(target).toHaveClass(new RegExp('ak-slider--pagination-' + align));
        for (const position of ['top', 'bottom']) {
            await page.getByLabel('Pagination position', { exact: true }).selectOption(position);
            await expect(target).toHaveClass(new RegExp('ak-slider--pagination-' + position));
            const box = (await target.boundingBox())!;
            const rail = (await target.locator('.ak-slider__pagination').boundingBox())!;
            if (align === 'center') expect(Math.abs(rail.x + rail.width / 2 - box.x - box.width / 2)).toBeLessThan(2);
            else if (align === 'start') expect(rail.x + rail.width / 2).toBeLessThan(box.x + box.width / 2);
            else expect(rail.x + rail.width / 2).toBeGreaterThan(box.x + box.width / 2);
            if (position === 'top') expect(rail.y - box.y).toBeLessThan(25);
            else expect(box.y + box.height - rail.y - rail.height).toBeLessThan(25);
        }
    }
    await page.getByLabel('Pagination style', { exact: true }).selectOption('none');
    const canvas = page.frameLocator('[data-testid=canvas]');
    const slider = canvas.locator('[data-editor-slider]').first();
    await expect(slider).toHaveClass(/ak-slider--arrows-edges/);
    await expect(slider.locator('.ak-slider__pagination')).toBeHidden();
    await expect(page.getByLabel('Pagination alignment', { exact: true })).toHaveCount(0);
    await expect(page.getByLabel('Pagination position', { exact: true })).toHaveCount(0);
    async function checkEdges(root: typeof slider) {
        const box = (await root.boundingBox())!;
        const previous = (await root.getByRole('button', { name: 'Previous slide', exact: true }).boundingBox())!;
        const next = (await root.getByRole('button', { name: 'Next slide', exact: true }).boundingBox())!;
        expect(previous.x - box.x).toBeLessThan(25);
        expect(box.x + box.width - next.x - next.width).toBeLessThan(25);
        expect(Math.abs(previous.y + previous.height / 2 - box.y - box.height / 2)).toBeLessThan(2);
        expect(Math.abs(next.y + next.height / 2 - box.y - box.height / 2)).toBeLessThan(2);
    }
    await checkEdges(slider);
    await page.getByRole('button', { name: 'Save draft', exact: true }).click();
    await expect(page.getByTestId('save-status').getByText('Draft saved', { exact: true })).toBeVisible();
    await page.reload();
    await expect(slider).toHaveClass(/ak-slider--arrows-edges/);
    await checkEdges(slider);
    await page.getByRole('button', { name: 'Publish', exact: true }).click();
    await expect(page.getByText('Published. The live page now shows this version.', { exact: true })).toBeVisible();
    await page.goto('/slider-edge-layout');
    const live = page.locator('[data-arkon-slider]').first();
    await expect(live).toHaveAttribute('data-enhanced', 'true');
    await expect(live.getByRole('button', { name: 'Next slide', exact: true })).toHaveCSS('background-color', 'rgba(0, 0, 0, 0)');
    await expect(live.getByRole('button', { name: 'Next slide', exact: true })).toHaveCSS('color', 'rgb(255, 0, 0)');
    await checkEdges(live);
    await expect(live.locator('.ak-slider__pagination')).toBeHidden();
    await live.getByRole('button', { name: 'Next slide', exact: true }).click();
    await expect(live.locator('.ak-slide').nth(0)).toHaveAttribute('data-active', 'false');
    await page.setViewportSize({ width: 390, height: 844 });
    await checkEdges(live);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
});

test('slider screen overrides inherit, reset and survive publication and resizing', async ({ page }) => {
    const id = await createPage('/slider-responsive-layout', 'Responsive slider');
    await page.goto('/admin/editor/' + id);
    await page.getByRole('tab', { name: 'Layers', exact: true }).click();
    await page.getByText('Starting layouts', { exact: true }).click();
    await page.getByRole('button', { name: 'Image hero slider', exact: true }).click();
    await page.locator('[data-testid=layer][data-node-type=slider]').first().click();
    await page.getByRole('tab', { name: 'Properties', exact: true }).click();
    const screen = page.getByTestId('screen-bar').first();
    await page.getByLabel('Arrow placement', { exact: true }).selectOption('edges');
    await page.getByLabel('Pagination style', { exact: true }).selectOption('none');
    await screen.getByRole('button', { name: 'Tablet', exact: true }).click();
    await expect(page.getByLabel('Arrow placement', { exact: true })).toHaveValue('edges');
    await page.getByLabel('Arrow placement', { exact: true }).selectOption('grouped');
    await page.getByLabel('Horizontal position', { exact: true }).selectOption('center');
    await page.getByLabel('Arrow button size (px)', { exact: true }).selectOption('56');
    await screen.getByRole('button', { name: 'Mobile', exact: true }).click();
    await expect(page.getByLabel('Arrow placement', { exact: true })).toHaveValue('grouped');
    await page.getByLabel('Show previous / next arrows', { exact: true }).uncheck();
    await page.getByLabel('Pagination style', { exact: true }).selectOption('dots');
    await page.getByRole('button', { name: 'Reset Pagination style', exact: true }).click();
    await expect(page.getByLabel('Pagination style', { exact: true })).toHaveValue('none');
    await page.getByLabel('Pagination style', { exact: true }).selectOption('dots');
    await screen.getByRole('button', { name: 'All screens', exact: true }).click();
    await expect(page.getByLabel('Arrow placement', { exact: true })).toHaveValue('edges');
    await expect(page.getByLabel('Show previous / next arrows', { exact: true })).toBeChecked();
    await page.getByRole('button', { name: 'Save draft', exact: true }).click();
    await expect(page.getByTestId('save-status')).toBeVisible();
    await page.reload();
    await page.getByRole('button', { name: 'Publish', exact: true }).click();
    await expect(page.getByTestId('save-status')).toBeVisible();
    await page.goto('/slider-responsive-layout');
    const slider = page.locator('.ak-slider').first();
    await page.setViewportSize({ width: 1440, height: 1000 });
    await expect(slider).toHaveClass(/ak-slider--arrows-edges/);
    await expect(slider.locator('.ak-slider__arrow').first()).toBeVisible();
    await expect(slider.locator('.ak-slider__pagination')).toBeHidden();
    await page.setViewportSize({ width: 800, height: 1000 });
    await expect(slider).toHaveClass(/ak-slider--arrows-grouped/);
    await expect(slider.locator('.ak-slider__arrow').first()).toHaveCSS('width', '56px');
    await page.setViewportSize({ width: 390, height: 844 });
    await expect(slider.locator('.ak-slider__arrow').first()).toBeHidden();
    await expect(slider.locator('.ak-slider__pagination')).toBeVisible();
    await expect(slider).toHaveClass(/ak-slider--dots/);
    await page.setViewportSize({ width: 1440, height: 1000 });
    await expect(slider.locator('.ak-slider__arrow').first()).toBeVisible();
    await expect(slider).toHaveClass(/ak-slider--arrows-edges/);
});
