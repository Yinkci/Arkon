// Entrance animations, checked on what the browser actually does: animation events and progress
// on the canvas and on published pages, not only classes. Editor: honest status (plays, off on this
// screen, reduced motion, protected content with the image or heading responsible), automatic
// previews after the render that shows the new setting (latest wins, cancelled by other edits and
// selection changes), both editors. Live (motion-3): content on screen at load plays its entrance
// from the first paint, below-the-fold content plays once when it comes into view, the likely LCP
// never animates, keyboard focus shows every entrance around it at once (any trigger, delay or progress),
// and reduced motion, phones and JavaScript-disabled all show content. Moving an entrance away from
// protected content never lands on protected blocks.
import { expect, test, type Browser, type Frame, type Page } from '@playwright/test';
import { APP_ORIGIN, E2E_HOST, PORT } from './env';
import { PNG_1X1 } from './fixtures';
import { db } from './support';

type Spec = Record<string, unknown>;
let counter = 0;
const nid = (prefix: string) => `${prefix}${String(++counter).padStart(10 - prefix.length, '0')}`;
const text = (label: string, extra: Spec = {}): Spec => ({ type: 'text', version: 3, props: { text: label, element: 'p', style: {}, ...extra } });
const button = (label: string, href = '/contact', style: Spec = {}): Spec => ({
    type: 'button',
    version: 3,
    props: { label, href, variant: 'primary', size: 'medium', newTab: false, style },
});
const image = (): Spec => ({ type: 'image', version: 4, props: { image: null, caption: '', loading: 'auto', style: {} } });
const section = (children: Spec[], style: Spec = {}): Spec => ({
    type: 'section',
    version: 2,
    props: { element: 'section', contentWidth: 'default', style },
    children,
});
const columns = (cols: Spec[][]): Spec => ({
    type: 'columns',
    version: 3,
    props: { style: { root: { mobile: { columns: '1' } } } },
    children: cols.map((c) => ({ type: 'column', version: 3, props: { style: {} }, children: c })),
});
const spacer = (): Spec => text('Spacer', { style: { root: { base: { minHeight: '1400px' } } } });
const entrance = (animation: string, trigger: 'load' | 'view', extra: Spec = {}) => ({ root: { base: { animation, animationTrigger: trigger }, ...extra } });
/** The longest entrance allowed: a 2 s delay, then 2 s of animation. */
const slow = (animation: string, trigger: 'load' | 'view') => ({
    root: { base: { animation, animationTrigger: trigger, animationDelay: '2000ms', animationDuration: '2000ms' } },
});

function documentOf(blocks: Spec[], rootType = 'page', rootVersion = 3) {
    const nodes: Record<string, Spec> = {};
    const ids: string[] = [];
    const add = (spec: Spec): string => {
        const id = nid(String(spec.type).slice(0, 3));
        ids.push(id);
        const children = (spec.children as Spec[] | undefined)?.map(add);
        nodes[id] = { id, type: spec.type, version: spec.version, props: spec.props, ...(children ? { children } : {}) };
        return id;
    };
    const root = nid('root');
    nodes[root] = { id: root, type: rootType, version: rootVersion, props: {}, children: blocks.map(add) };
    return { schemaVersion: 1, root, nodes, seo: {} };
}

async function siteId() {
    return (await db.query<{ site_id: string }>('select site_id from site_domains where hostname = $1', [`${E2E_HOST}:${PORT}`])).rows[0]!.site_id;
}

async function openPage(page: Page, blocks: Spec[]) {
    const id = crypto.randomUUID();
    const path = `/motion-${id.slice(0, 8)}`;
    const site = await siteId();
    const document = documentOf(blocks);
    await db.query('insert into pages (id, site_id, path, title) values ($1, $2, $3, $4)', [id, site, path, 'Motion']);
    await db.query('insert into page_drafts (page_id, site_id, document, version) values ($1, $2, $3, 1)', [id, site, JSON.stringify(document)]);
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto(`/admin/editor/${id}`);
    await expect(page.frameLocator('[data-testid="canvas"]').locator('main')).toBeVisible();
    return { id, path, document };
}

const canvas = (page: Page) => page.frameLocator('[data-testid="canvas"]');
const status = (page: Page) => page.getByTestId('animation-status');
const layer = (page: Page, type: string) => page.locator(`[data-testid="layer"][data-node-type="${type}"]`);

async function canvasFrame(page: Page): Promise<Frame> {
    const handle = await page.getByTestId('canvas').elementHandle();
    return (await handle!.contentFrame())!;
}

/** Records every animation that starts in the canvas (its name and the block it belongs to). */
async function recordCanvas(page: Page) {
    const frame = await canvasFrame(page);
    await frame.evaluate(() => {
        const w = window as unknown as { __anims: { name: string; id: string | null }[] };
        w.__anims = [];
        document.addEventListener(
            'animationstart',
            (e) =>
                w.__anims.push({
                    name: (e as AnimationEvent).animationName,
                    id: (e.target as Element).closest('[data-ak-id]')?.getAttribute('data-ak-id') ?? null,
                }),
            true,
        );
    });
    return {
        all: () => frame.evaluate(() => (window as unknown as { __anims: { name: string; id: string | null }[] }).__anims),
        /** Animation progress of a block right now: its first animation's current time (ms), or -1. */
        progress: (id: string) =>
            frame.evaluate((nodeId) => {
                const a = document.querySelector(`[data-ak-id="${nodeId}"]`)?.getAnimations()[0];
                return a ? Number(a.currentTime ?? 0) : -1;
            }, id),
    };
}

async function selectLayer(page: Page, type: string, index = 0) {
    await page.getByRole('tab', { name: 'Layers' }).click();
    await layer(page, type).nth(index).locator('button').first().click();
    await page.getByRole('tab', { name: 'Properties' }).click();
}

const nodeIdOf = async (page: Page, type: string, index = 0) => (await layer(page, type).nth(index).getAttribute('data-node-id'))!;

test.describe('editor', () => {
    test('choosing an effect previews it on the canvas after its render; Preview animation plays it again', async ({ page }) => {
        await openPage(page, [text('About', { element: 'h1' }), section([text('Our team')])]);
        await page.getByRole('tab', { name: 'Layers' }).click();
        const sectionId = await nodeIdOf(page, 'section');
        await selectLayer(page, 'section');
        const anims = await recordCanvas(page);
        await page.getByTestId('animation-effect').selectOption('fade-up');
        await expect(status(page)).toHaveAttribute('data-status', 'plays');
        await expect(status(page)).toContainText('Plays when it comes into view');
        // A real animation of that block, in progress, then finished (the canvas returns to the final state).
        await expect.poll(anims.all).toContainEqual({ name: 'ak-a-up', id: sectionId });
        await expect.poll(() => anims.progress(sectionId)).toBeGreaterThan(0);
        await expect.poll(() => anims.progress(sectionId), { timeout: 3000 }).toBe(-1);
        expect(
            await canvas(page)
                .locator(`[data-ak-id="${sectionId}"]`)
                .evaluate((el) => getComputedStyle(el).opacity),
        ).toBe('1');
        await page.getByTestId('animation-preview').click();
        await expect.poll(async () => (await anims.all()).filter((a) => a.id === sectionId).length).toBe(2);
        // Other settings preview too (the duration).
        await page.getByTestId('animation-duration').fill('900');
        await expect.poll(async () => (await anims.all()).filter((a) => a.id === sectionId).length).toBe(3);

        // The maximum duration plus delay must finish naturally, beyond the old preview timeout.
        await page.getByTestId('animation-duration').fill('4000');
        await page.getByTestId('animation-delay').fill('2000');
        await expect.poll(() => anims.progress(sectionId), { timeout: 8000 }).toBeGreaterThan(4500);
        await expect.poll(() => anims.progress(sectionId), { timeout: 4000 }).toBe(-1);
        await page.keyboard.press('ControlOrMeta+s');
        await expect(page.getByTestId('save-status')).toHaveText('Draft saved');
        await page.reload();
        await selectLayer(page, 'section');
        await expect(page.getByTestId('animation-duration')).toHaveValue('4000');
        await expect(page.getByTestId('animation-delay')).toHaveValue('2000');
    });

    test('rapid changes with slow renders preview only the latest setting, once; other edits and selection changes cancel', async ({ page }) => {
        await openPage(page, [text('About', { element: 'h1' }), section([text('Our team')]), section([text('Another')])]);
        await page.getByRole('tab', { name: 'Layers' }).click();
        const sectionId = await nodeIdOf(page, 'section');
        await selectLayer(page, 'section');
        const anims = await recordCanvas(page);
        await page.route('**/admin/api/pages/*/canvas', async (route) => {
            await new Promise((resolve) => setTimeout(resolve, 700));
            await route.continue();
        });
        for (const effect of ['fade', 'zoom', 'fade-down']) await page.getByTestId('animation-effect').selectOption(effect);
        await expect.poll(anims.all, { timeout: 8000 }).toContainEqual({ name: 'ak-a-down', id: sectionId });
        await page.waitForTimeout(1500);
        expect(await anims.all()).toEqual([{ name: 'ak-a-down', id: sectionId }]);

        // Another block selected before the render arrives: no preview of the previous one.
        await page.getByTestId('animation-effect').selectOption('zoom');
        await selectLayer(page, 'section', 1);
        await page.waitForTimeout(2500);
        expect(await anims.all()).toHaveLength(1);

        // Typing right after a change (the same block stays selected): the preview is dropped.
        await canvas(page).getByText('Our team').click();
        await page.keyboard.press('Escape');
        await page.getByTestId('animation-effect').selectOption('fade-left');
        await page.getByLabel('Text', { exact: true }).fill('Our team members');
        await page.waitForTimeout(2500);
        expect(await anims.all()).toHaveLength(1);
        await page.unroute('**/admin/api/pages/*/canvas');
    });

    test('mobile overrides and reduced motion are shown and never previewed', async ({ page, browser }) => {
        await openPage(page, [text('About', { element: 'h1' }), section([text('Our team')], entrance('fade-up', 'view', { mobile: { animation: 'none' } }))]);
        await selectLayer(page, 'section');
        await expect(status(page)).toHaveAttribute('data-status', 'plays');
        await page.getByRole('group', { name: 'Viewport' }).getByRole('button', { name: 'Mobile' }).click();
        await expect(status(page)).toHaveAttribute('data-status', 'off-here');
        await expect(status(page)).toContainText('Off on phones');
        await expect(page.getByTestId('animation-preview')).toBeDisabled();

        const reduced = await browser.newContext({
            storageState: 'test-results/.auth/owner.json',
            reducedMotion: 'reduce',
            viewport: { width: 1440, height: 900 },
        });
        const other = await reduced.newPage();
        await other.goto(page.url());
        await selectLayer(other, 'section');
        await expect(status(other)).toHaveAttribute('data-status', 'reduced-motion');
        await expect(other.getByTestId('animation-reduced')).toContainText('reduced motion');
        await expect(other.getByTestId('animation-preview')).toBeDisabled();
        const anims = await recordCanvas(other);
        await other.getByTestId('animation-effect').selectOption('zoom');
        await other.waitForTimeout(1500);
        expect(await anims.all()).toEqual([]);
        await reduced.close();
    });

    test('protected content: the image and the heading say so and why; a container names its image and can hand its entrance to the other blocks', async ({
        page,
    }) => {
        const { id } = await openPage(page, [
            text('About us', { element: 'h1' }),
            section([columns([[image(), button('Meet us')], [text('Our office')]])], {
                root: { base: { animation: 'fade-up', animationTrigger: 'load', animationDelay: '700ms' } },
            }),
        ]);
        // The first image (it becomes the image fetched first, the likely LCP).
        await canvas(page).locator('.ak-image__empty').click();
        await page.getByTestId('part-media').click();
        await page.getByLabel('Upload image').setInputFiles({ name: 'team.png', mimeType: 'image/png', buffer: PNG_1X1 });
        await page.getByLabel('Alternative text (required to publish)').fill('Our team');
        await page.getByTestId('part-root').click();
        await expect(status(page)).toHaveAttribute('data-status', 'protected');
        await expect(status(page)).toContainText('is the image that loads first');
        await expect(page.getByTestId('animation-effect')).toBeDisabled();

        // The heading.
        await canvas(page).getByText('About us').click();
        await page.keyboard.press('Escape');
        await expect(status(page)).toHaveAttribute('data-status', 'protected');
        await expect(status(page)).toContainText('main heading (H1)');
        await expect(page.getByTestId('animation-effect')).toBeDisabled();

        // The section: it holds that image. Its stored entrance is kept, explained, and can be moved.
        await selectLayer(page, 'section');
        await expect(status(page)).toHaveAttribute('data-status', 'protected');
        await expect(status(page)).toContainText('holds the image that loads first');
        await expect(page.getByTestId('animation-cause')).toContainText('Image');
        await expect(page.getByTestId('animation-effect')).toHaveValue('fade-up');
        await page.getByTestId('animation-instead').click();
        await page.keyboard.press('ControlOrMeta+s');
        await expect(page.getByTestId('save-status')).toHaveText('Draft saved');
        const doc = (
            await db.query<{
                document: { nodes: Record<string, { type: string; props: { style?: { root?: { base?: Record<string, string> } }; label?: string } }> };
            }>('select document from page_drafts where page_id = $1', [id])
        ).rows[0]!.document;
        const animated = Object.values(doc.nodes)
            .filter((n) => n.props.style?.root?.base?.animation === 'fade-up')
            .map((n) => n.type)
            .sort();
        expect(animated).toEqual(['button', 'column']);
        expect(Object.values(doc.nodes).find((n) => n.type === 'image')!.props.style).toEqual({});
        // Show the image: selects it.
        await page.getByTestId('animation-show-cause').click();
        await expect(page.getByTestId('inspector-target')).toHaveText('Image');
    });

    test('moving an entrance away from protected content skips every protected block, plays on the page, and is one undo step', async ({ page, browser }) => {
        test.setTimeout(90_000);
        // The section holds the main heading AND the image that loads first; its status names the image.
        const { id, path } = await openPage(page, [
            section(
                [
                    text('About us', { element: 'h1' }),
                    image(),
                    button('Meet us'),
                    button('Own entrance', '/own', { root: { base: { animation: 'fade', animationTrigger: 'load' } } }),
                ],
                { root: { base: { animation: 'fade-up', animationTrigger: 'load', animationDelay: '700ms' } } },
            ),
            section([text('Later')]),
        ]);
        await canvas(page).locator('.ak-image__empty').click();
        await page.getByTestId('part-media').click();
        await page.getByLabel('Upload image').setInputFiles({ name: 'team.png', mimeType: 'image/png', buffer: PNG_1X1 });
        await page.getByLabel('Alternative text (required to publish)').fill('Our team');
        await selectLayer(page, 'section');
        await expect(status(page)).toHaveAttribute('data-status', 'protected');
        await expect(page.getByTestId('animation-cause')).toContainText('Image');
        // Only the button without its own entrance: not the heading (also protected), not the image.
        await expect(page.getByTestId('animation-section')).toContainText('1 block next to the protected content gets the entrance');
        await page.getByTestId('animation-instead').click();
        await page.keyboard.press('ControlOrMeta+s');
        await expect(page.getByTestId('save-status')).toHaveText('Draft saved');

        type Doc = {
            nodes: Record<string, { id: string; type: string; props: { style?: { root?: { base?: Record<string, string> } }; label?: string; text?: string } }>;
        };
        const draft = async () => (await db.query<{ document: Doc }>('select document from page_drafts where page_id = $1', [id])).rows[0]!.document;
        const animated = (doc: Doc) => Object.values(doc.nodes).filter((n) => (n.props.style?.root?.base?.animation ?? 'none') !== 'none');
        const withEntrance = (doc: Doc) =>
            animated(doc)
                .map((n) => `${n.type}:${n.props.label ?? n.props.text ?? ''}:${n.props.style!.root!.base!.animation}`)
                .sort();
        let doc = await draft();
        expect(withEntrance(doc)).toEqual(['button:Meet us:fade-up', 'button:Own entrance:fade']);
        // The page that results: every stored entrance really animates (none is left off by the renderer).
        const frame = await canvasFrame(page);
        for (const node of animated(doc)) await expect.poll(() => frame.locator(`[data-ak-id="${node.id}"]`).getAttribute('class')).toContain('ak-anim');
        expect(await frame.locator('h1').getAttribute('class')).not.toContain('ak-anim');
        await expect(status(page)).toHaveAttribute('data-status', 'protected');
        await expect(page.getByTestId('animation-instead')).toHaveCount(0);

        // One undo step brings the section's entrance back and takes the button's away.
        await page.keyboard.press('ControlOrMeta+z');
        await expect(page.getByTestId('animation-effect')).toHaveValue('fade-up');
        await expect(page.getByTestId('animation-section')).toContainText('1 block next to the protected content gets the entrance');
        await page.keyboard.press('ControlOrMeta+Shift+z');
        await expect(page.getByTestId('animation-instead')).toHaveCount(0);
        await page.keyboard.press('ControlOrMeta+s');
        await expect(page.getByTestId('save-status')).toHaveText('Draft saved');
        doc = await draft();
        expect(withEntrance(doc)).toEqual(['button:Meet us:fade-up', 'button:Own entrance:fade']);

        // Published: both buttons play their entrance; the heading and the image appear without one.
        await page.getByRole('button', { name: 'Publish', exact: true }).click();
        await expect(page.getByTestId('notice')).toContainText('Published');
        const live = await visit(browser, path);
        await expect
            .poll(async () => [...new Set((await events(live.page)).filter((e) => e.type === 'animationend').map((e) => e.text))].sort())
            .toEqual(['Meet us', 'Own entrance']);
        expect(await looks(live.page, 'h1')).toMatchObject({ opacity: '1', animations: 0 });
        expect(await looks(live.page, 'img')).toMatchObject({ opacity: '1', animations: 0 });
        await live.context.close();
    });

    test('a protected container with nothing else that could animate offers no move', async ({ page }) => {
        await openPage(page, [section([text('Welcome', { element: 'h1' })], entrance('fade-up', 'load')), section([text('Later')])]);
        await selectLayer(page, 'section');
        await expect(status(page)).toHaveAttribute('data-status', 'protected');
        await expect(status(page)).toContainText('main heading (H1)');
        await expect(page.getByTestId('animation-effect')).toHaveValue('fade-up');
        await expect(page.getByTestId('animation-instead')).toHaveCount(0);
        await expect(page.getByTestId('animation-reset')).toHaveText('Remove the stored entrance');
    });

    test('the reusable-component editor previews the same way', async ({ page }) => {
        const site = await siteId();
        const id = crypto.randomUUID();
        const document = documentOf([section([text('Card')])], 'fragment', 1);
        await db.query('insert into reusable_components (id, site_id, name, draft) values ($1, $2, $3, $4)', [
            id,
            site,
            `Motion ${id.slice(0, 6)}`,
            JSON.stringify(document),
        ]);
        await page.setViewportSize({ width: 1440, height: 900 });
        await page.goto(`/admin/components/${id}`);
        await page.getByRole('tab', { name: 'Layers' }).click();
        const sectionId = await nodeIdOf(page, 'section');
        await selectLayer(page, 'section');
        const anims = await recordCanvas(page);
        await page.getByTestId('animation-effect').selectOption('zoom');
        await expect.poll(anims.all).toContainEqual({ name: 'ak-a-zoom', id: sectionId });
        await expect(page.getByTestId('animation-section')).toContainText('On a page, the animation is left off if this block holds');
    });
});

/** A published page in a fresh context; animation starts and ends and layout shifts are recorded from the start. */
async function visit(
    browser: Browser,
    path: string,
    options: { javaScriptEnabled?: boolean; reducedMotion?: 'reduce' | 'no-preference'; width?: number } = {},
) {
    const context = await browser.newContext({
        baseURL: APP_ORIGIN,
        viewport: { width: options.width ?? 1440, height: 900 },
        javaScriptEnabled: options.javaScriptEnabled ?? true,
        reducedMotion: options.reducedMotion ?? 'no-preference',
    });
    const page = await context.newPage();
    const violations: string[] = [];
    page.on('console', (message) => {
        if (/Content Security Policy|Refused to/.test(message.text())) violations.push(message.text());
    });
    await page.addInitScript(() => {
        const w = window as unknown as { __events: { type: string; text: string; t: number }[]; __cls: number };
        w.__events = [];
        w.__cls = 0;
        for (const type of ['animationstart', 'animationend'])
            document.addEventListener(
                type,
                (e) => w.__events.push({ type, text: ((e.target as HTMLElement).textContent ?? '').trim().slice(0, 20), t: Math.round(e.timeStamp) }),
                true,
            );
        new PerformanceObserver((list) => list.getEntries().forEach((e) => (w.__cls += (e as unknown as { value: number }).value))).observe({
            type: 'layout-shift',
            buffered: true,
        });
    });
    const response = await page.goto(path);
    return { context, page, violations, response: response! };
}

const events = (page: Page) => page.evaluate(() => (window as unknown as { __events: { type: string; text: string; t: number }[] }).__events);
const looks = (page: Page, selector: string) =>
    page.locator(selector).evaluate((el) => ({ classes: el.className, opacity: getComputedStyle(el).opacity, animations: el.getAnimations().length }));

/** How visible an element really is (the focused one by default): its opacity times that of every block around it. */
const visibility = (page: Page, selector?: string) =>
    page.evaluate((s) => {
        let v = 1;
        for (let el = s ? document.querySelector(s) : document.activeElement; el; el = el.parentElement) v *= Number(getComputedStyle(el).opacity);
        return v;
    }, selector);
/** The entrance clock of a block: its first animation's current time (ms), -1 without one. */
const clock = (page: Page, selector: string) => page.locator(selector).evaluate((el) => Number(el.getAnimations()[0]?.currentTime ?? -1));

test('live: keyboard focus shows on-screen entrances and every animated block around them at once, for both triggers', async ({ page, browser }) => {
    test.setTimeout(120_000);
    // Both sections are on screen at load (nothing below the fold, so nothing is held back): a 2 s delay, then 2 s.
    const { path } = await openPage(page, [
        section(
            [
                {
                    type: 'columns',
                    version: 3,
                    props: { style: {} },
                    children: [
                        {
                            type: 'column',
                            version: 3,
                            props: { style: slow('fade', 'load') },
                            children: [button('First link', '#first', slow('zoom', 'load'))],
                        },
                        { type: 'column', version: 3, props: { style: {} }, children: [text('Beside it')] },
                    ],
                },
            ],
            slow('fade-up', 'load'),
        ),
        section([button('Second link', '#second')], slow('fade', 'view')),
    ]);
    await page.getByRole('button', { name: 'Publish', exact: true }).click();
    await expect(page.getByTestId('notice')).toContainText('Published');
    const first = 'a[href="#first"]';
    const second = 'a[href="#second"]';

    // During the delay: the focused link and the three animated blocks around it are fully visible at once.
    const live = await visit(browser, path);
    expect(live.response.headers()['content-security-policy']).toContain(`script-src ${APP_ORIGIN}/_arkon/motion-3.js;`);
    expect(await live.page.locator('.ak-wait').count()).toBe(0);
    await live.page.keyboard.press('Tab');
    await expect(live.page.locator(first)).toBeFocused();
    const during = await visibility(live.page);
    expect(await clock(live.page, 'section.ak-section >> nth=1')).toBeLessThan(2000); // still in the delay
    expect(during).toBe(1);
    // The view-triggered section, the same; the first stays shown after focus has left it.
    await live.page.keyboard.press('Tab');
    await expect(live.page.locator(second)).toBeFocused();
    expect(await visibility(live.page)).toBe(1);
    expect(await visibility(live.page, first)).toBe(1);
    // Keyboard activation works and nothing hides.
    await live.page.keyboard.press('Enter');
    await expect(live.page).toHaveURL(/#second$/);
    expect(await visibility(live.page)).toBe(1);
    expect(await visibility(live.page, first)).toBe(1);
    expect(live.violations).toEqual([]);
    await live.context.close();

    // While the entrance is running (past its delay, partly faded in): focus shows it at once.
    const running = await visit(browser, path);
    await expect.poll(() => clock(running.page, 'section.ak-section >> nth=0'), { intervals: [50] }).toBeGreaterThan(2200);
    expect(await visibility(running.page, first)).toBeLessThan(1);
    await running.page.keyboard.press('Tab');
    await expect(running.page.locator(first)).toBeFocused();
    expect(await visibility(running.page)).toBe(1);
    await running.context.close();

    // Reduced motion: nothing animates; focus finds everything shown.
    const reduced = await visit(browser, path, { reducedMotion: 'reduce' });
    await reduced.page.keyboard.press('Tab');
    expect(await visibility(reduced.page)).toBe(1);
    await reduced.context.close();

    // Without JavaScript: CSS alone shows the focused block and the blocks around it.
    const noJs = await visit(browser, path, { javaScriptEnabled: false });
    await noJs.page.keyboard.press('Tab');
    await expect(noJs.page.locator(first)).toBeFocused();
    expect(await clock(noJs.page, 'section.ak-section >> nth=1')).toBeLessThan(2000);
    expect(await visibility(noJs.page)).toBe(1);
    await noJs.context.close();
});

test('live: on-screen content plays at load, below the fold once in view, the LCP never; focus, reduced motion, phones and no JavaScript', async ({
    page,
    browser,
}) => {
    test.setTimeout(120_000);
    const { path } = await openPage(page, [
        text('Main heading', { element: 'h1', style: entrance('fade', 'load') }),
        text('Intro on screen', { style: entrance('fade-up', 'view') }),
        spacer(),
        section([text('Below the fold'), button('Book a visit')], entrance('fade-up', 'view')),
        spacer(),
        section([text('Not on phones')], entrance('zoom', 'view', { mobile: { animation: 'none' } })),
    ]);
    await page.getByRole('button', { name: 'Publish', exact: true }).click();
    await expect(page.getByTestId('notice')).toContainText('Published');

    const live = await visit(browser, path);
    expect(live.response.headers()['content-security-policy']).toContain(`script-src ${APP_ORIGIN}/_arkon/motion-3.js;`);
    // The heading (the likely LCP) is never animated, and shown at once.
    expect(await looks(live.page, 'h1')).toMatchObject({ opacity: '1', animations: 0 });
    // The intro is on screen at load: its entrance plays (it is not skipped) and ends shown.
    await expect
        .poll(async () => (await events(live.page)).filter((e) => e.text === 'Intro on screen').map((e) => e.type))
        .toEqual(['animationstart', 'animationend']);
    expect((await looks(live.page, '.ak-text2:not(h1) >> nth=0')).opacity).toBe('1');
    // Below the fold: held back (unseen), nothing played yet.
    const below = 'section.ak-section >> nth=0';
    expect(await looks(live.page, below)).toMatchObject({ opacity: '0', animations: 0 });
    expect((await looks(live.page, below)).classes).toContain('ak-wait');
    expect((await events(live.page)).filter((e) => e.text.startsWith('Below'))).toEqual([]);
    // Scrolled into view: it plays once, from the start, and ends shown.
    await live.page.locator(below).scrollIntoViewIfNeeded();
    await expect
        .poll(async () => (await events(live.page)).filter((e) => e.text.startsWith('Below')).map((e) => e.type))
        .toEqual(['animationstart', 'animationend']);
    expect((await looks(live.page, below)).opacity).toBe('1');
    await live.page.evaluate(() => window.scrollTo(0, 0));
    await live.page.waitForTimeout(300);
    await live.page.locator(below).scrollIntoViewIfNeeded();
    await live.page.waitForTimeout(1200);
    expect((await events(live.page)).filter((e) => e.text.startsWith('Below') && e.type === 'animationstart')).toHaveLength(1);
    expect(await live.page.evaluate(() => (window as unknown as { __cls: number }).__cls)).toBe(0);
    expect(live.violations).toEqual([]);
    await live.context.close();

    // Keyboard focus inside a held-back block shows it at once (no entrance).
    const keyboard = await visit(browser, path);
    await keyboard.page.keyboard.press('Tab');
    await expect(keyboard.page.getByRole('link', { name: 'Book a visit' })).toBeFocused();
    expect(await looks(keyboard.page, below)).toMatchObject({ opacity: '1', animations: 0 });
    expect((await looks(keyboard.page, below)).classes).toContain('ak-shown');
    await keyboard.context.close();

    // Without JavaScript nothing is held back: everything plays with the page (CSS) and ends shown.
    const noJs = await visit(browser, path, { javaScriptEnabled: false });
    await noJs.page.waitForTimeout(1500);
    for (const selector of ['h1', below, 'section.ak-section >> nth=1', '.ak-text2:not(h1) >> nth=0']) {
        const look = await looks(noJs.page, selector);
        expect(look.classes).not.toContain('ak-wait');
        expect(look.opacity).toBe('1');
    }
    await noJs.context.close();

    // Reduced motion: nothing animates, nothing is held back.
    const reduced = await visit(browser, path, { reducedMotion: 'reduce' });
    for (const selector of ['.ak-text2:not(h1) >> nth=0', below]) expect(await looks(reduced.page, selector)).toMatchObject({ opacity: '1', animations: 0 });
    await reduced.context.close();

    // Phones: the block turned off there is neither animated nor held back.
    const phone = await visit(browser, path, { width: 390 });
    expect(await looks(phone.page, 'section.ak-section >> nth=1')).toMatchObject({ opacity: '1', animations: 0 });
    expect((await looks(phone.page, 'section.ak-section >> nth=1')).classes).not.toContain('ak-wait');
    await phone.context.close();
});
