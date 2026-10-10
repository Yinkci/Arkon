// A recorded walkthrough of ordinary dragging (not a regression test): runs only with
// DRAG_DEMO=1 and writes a video and mid-drag screenshots to storage/screenshots/drag/.
// Quick drags without pauses: reorder sections, a button between text blocks, an image block
// between columns, into an empty group, and across a long page by holding at the edge;
// in the light and dark themes and the desktop, tablet and mobile canvas.
import { expect, test, type Page } from '@playwright/test';
import { AUTH_STATE, E2E_HOST, PORT } from './env';
import { db } from './support';

test.skip(process.env.DRAG_DEMO !== '1', 'Set DRAG_DEMO=1 to record the dragging walkthrough');

const dir = 'storage/screenshots/drag';
let n = 0;
const nid = (p: string) => `${p}${String(++n).padStart(10 - p.length, '0')}`;

function page_(): Record<string, unknown> {
    const nodes: Record<string, unknown> = {};
    const text = (t: string, el = 'p') => {
        const id = nid('txt');
        nodes[id] = { id, type: 'text', version: 2, props: { text: t, element: el, style: {} } };
        return id;
    };
    const container = (type: string, version: number, props: Record<string, unknown>, children: string[]) => {
        const id = nid(type.slice(0, 3));
        nodes[id] = { id, type, version, props, children };
        return id;
    };
    const buttonId = nid('btn');
    nodes[buttonId] = {
        id: buttonId,
        type: 'button',
        version: 2,
        props: { label: 'Book a visit', href: '/contact', variant: 'primary', size: 'medium', newTab: false, style: {} },
    };
    const imageId = nid('img');
    nodes[imageId] = { id: imageId, type: 'image', version: 3, props: { image: null, caption: 'Garden plan', loading: 'auto', style: {} } };
    const sections = [
        container('section', 1, { element: 'section', contentWidth: 'default', style: {} }, [
            text('Our services', 'h2'),
            text('Design, planting and care.'),
            text('Seasonal maintenance plans.'),
            buttonId,
        ]),
        container('section', 1, { element: 'section', contentWidth: 'default', style: {} }, [
            text('Projects', 'h2'),
            container('columns', 2, { style: { root: { mobile: { columns: '1' } } } }, [
                container('column', 2, { style: {} }, [text('Walled garden'), imageId]),
                container('column', 2, { style: {} }, [text('Roof terrace')]),
            ]),
        ]),
        container('section', 1, { element: 'section', contentWidth: 'default', style: {} }, [
            text('About us', 'h2'),
            container('group', 1, { element: 'div', style: { root: { base: { minHeight: '80px' } } } }, []),
        ]),
    ];
    for (let i = 1; i <= 14; i++)
        sections.push(
            container('section', 1, { element: 'section', contentWidth: 'default', style: {} }, [
                text(`Journal entry ${i}`, 'h2'),
                text('Notes from the garden this week.'),
            ]),
        );
    const root = nid('root');
    nodes[root] = { id: root, type: 'page', version: 3, props: {}, children: sections };
    return { schemaVersion: 1, root, nodes, seo: {} };
}

async function open(page: Page) {
    const site = (await db.query<{ site_id: string }>('select site_id from site_domains where hostname = $1', [`${E2E_HOST}:${PORT}`])).rows[0]!.site_id;
    const id = crypto.randomUUID();
    await db.query('insert into pages (id, site_id, path, title) values ($1, $2, $3, $4)', [id, site, `/drag-demo-${id.slice(0, 6)}`, 'Garden studio']);
    await db.query('insert into page_drafts (page_id, site_id, document, version) values ($1, $2, $3, 1)', [id, site, JSON.stringify(page_())]);
    await page.goto(`/admin/editor/${id}`);
    await expect(page.frameLocator('[data-testid="canvas"]').locator('h2').first()).toHaveText('Our services');
}

const canvas = (page: Page) => page.frameLocator('[data-testid="canvas"]');
const centre = async (page: Page, testId: string) => {
    const b = (await page.getByTestId(testId).boundingBox())!;
    return { x: b.x + b.width / 2, y: b.y + b.height / 2 };
};
async function pick(page: Page, text: string) {
    await canvas(page).getByText(text, { exact: true }).first().click();
    await page.keyboard.press('Escape');
    await expect(page.getByTestId('canvas-drag-handle')).toBeVisible();
}
/** A quick drag, with a screenshot taken on the way (just before release). */
async function drag(page: Page, to: { x: number; y: number }, shot?: string) {
    const from = await centre(page, 'canvas-drag-handle');
    await page.mouse.move(from.x, from.y);
    await page.mouse.down();
    await page.mouse.move(to.x, to.y, { steps: 6 });
    if (shot) await page.screenshot({ path: `${dir}/${shot}.png` });
    await page.mouse.up();
}

for (const theme of ['light', 'dark'] as const) {
    test(`dragging walkthrough (${theme})`, async ({ browser }) => {
        test.setTimeout(240_000);
        const context = await browser.newContext({
            storageState: AUTH_STATE,
            viewport: { width: 1440, height: 900 },
            recordVideo: { dir: `${dir}/video-${theme}`, size: { width: 1440, height: 900 } },
        });
        await context.addInitScript((value) => localStorage.setItem('arkon.theme', value), theme);
        const page = await context.newPage();
        await open(page);
        const c = canvas(page);

        // Reorder sections: "Projects" above "Our services".
        await pick(page, 'Projects');
        await page.getByRole('button', { name: 'Select parent' }).click();
        const services = (await c.getByText('Our services').boundingBox())!;
        await drag(page, { x: services.x + 200, y: services.y - 30 }, `reorder-sections-${theme}`);
        await expect(c.locator('h2').first()).toHaveText('Projects');

        // A button between two text blocks.
        await pick(page, 'Book a visit');
        const design = (await c.getByText('Design, planting and care.').boundingBox())!;
        await drag(page, { x: design.x + 40, y: design.y + design.height + 4 }, `button-between-texts-${theme}`);

        // An image block from one column to the other.
        await c.locator('.ak-img2').click();
        const roof = (await c.getByText('Roof terrace').boundingBox())!;
        await drag(page, { x: roof.x + 40, y: roof.y + roof.height - 4 }, `image-between-columns-${theme}`);

        // Into an empty group.
        await pick(page, 'Seasonal maintenance plans.');
        await c.locator('.ak-group').evaluate((el) => el.scrollIntoView({ block: 'center' }));
        const group = (await c.locator('.ak-group').boundingBox())!;
        await drag(page, { x: group.x + group.width / 2, y: group.y + group.height / 2 }, `into-empty-group-${theme}`);

        // Across the long page: hold at the bottom edge, release at the end.
        await pick(page, 'Projects');
        await page.getByRole('button', { name: 'Select parent' }).click();
        const frame = (await page.getByTestId('canvas').boundingBox())!;
        const from = await centre(page, 'canvas-drag-handle');
        await page.mouse.move(from.x, from.y);
        await page.mouse.down();
        await page.mouse.move(frame.x + frame.width / 2, frame.y + frame.height - 10, { steps: 6 });
        await page.waitForTimeout(1200);
        await page.screenshot({ path: `${dir}/edge-scrolling-${theme}.png` });
        await page.waitForTimeout(1500);
        await page.mouse.up();

        // Tablet and mobile canvas: reorder at those widths.
        for (const viewport of ['Tablet', 'Mobile'] as const) {
            await page.getByRole('group', { name: 'Viewport' }).getByRole('button', { name: viewport }).click();
            await c.locator('body').evaluate(() => window.scrollTo(0, 0));
            await pick(page, 'Our services');
            await page.getByRole('button', { name: 'Select parent' }).click();
            const target = (await c.getByText(viewport === 'Tablet' ? 'About us' : 'Journal entry 2', { exact: true }).boundingBox())!;
            await drag(page, { x: target.x + 40, y: target.y - 24 }, `reorder-${viewport.toLowerCase()}-${theme}`);
        }
        await context.close();
    });
}
