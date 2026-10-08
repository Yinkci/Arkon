// A screenshot walkthrough of duplicate, column layouts and entrance animations (not a regression
// test): runs only with FEATURES_DEMO=1 and writes to storage/screenshots/features/ (git-ignored).
import { expect, test, type Page } from '@playwright/test';
import { E2E_HOST, PORT } from './env';
import { db } from './support';

test.skip(process.env.FEATURES_DEMO !== '1', 'Set FEATURES_DEMO=1 to record the feature walkthrough');

const dir = 'storage/screenshots/features';
const canvas = (page: Page) => page.frameLocator('[data-testid="canvas"]');

async function open(page: Page) {
    const site = (await db.query<{ site_id: string }>('select site_id from site_domains where hostname = $1', [`${E2E_HOST}:${PORT}`])).rows[0]!.site_id;
    const id = crypto.randomUUID();
    const text = (t: string, element = 'p') => ({ type: 'text', version: 3, props: { text: t, element, style: {} } });
    const nodes: Record<string, unknown> = {};
    let n = 0;
    const add = (spec: Record<string, unknown>): string => {
        const nodeId = `demo${String(++n).padStart(6, '0')}`;
        const children = (spec.children as Record<string, unknown>[] | undefined)?.map(add);
        nodes[nodeId] = { id: nodeId, type: spec.type, version: spec.version, props: spec.props, ...(children ? { children } : {}) };
        return nodeId;
    };
    const top = [
        {
            type: 'hero',
            version: 4,
            props: { heading: 'Gardens that grow with you', headingLevel: 'h1', text: 'Design, planting and care.', image: null, style: {} },
            children: [
                {
                    type: 'button',
                    version: 3,
                    props: { label: 'Book a visit', href: '/contact', variant: 'primary', size: 'medium', newTab: false, style: {} },
                },
            ],
        },
        {
            type: 'section',
            version: 2,
            props: { element: 'section', contentWidth: 'default', style: {} },
            children: [
                text('How it works', 'h2'),
                {
                    type: 'columns',
                    version: 3,
                    props: { style: { root: { mobile: { columns: '1' } } } },
                    children: [
                        { type: 'column', version: 3, props: { style: {} }, children: [text('1. Visit'), text('We walk the garden with you.')] },
                        { type: 'column', version: 3, props: { style: {} }, children: [text('2. Plan'), text('A planting plan and a price.')] },
                        { type: 'column', version: 3, props: { style: {} }, children: [] },
                    ],
                },
            ],
        },
    ];
    nodes.demoroot01 = { id: 'demoroot01', type: 'page', version: 3, props: {}, children: top.map(add) };
    await db.query('insert into pages (id, site_id, path, title) values ($1, $2, $3, $4)', [id, site, `/demo-${id.slice(0, 6)}`, 'Garden studio']);
    await db.query('insert into page_drafts (page_id, site_id, document, version) values ($1, $2, $3, 1)', [
        id,
        site,
        JSON.stringify({ schemaVersion: 1, root: 'demoroot01', nodes, seo: {} }),
    ]);
    await page.goto(`/admin/editor/${id}`);
    await expect(canvas(page).locator('h1')).toBeVisible();
}

for (const theme of ['light', 'dark'] as const) {
    test(`feature walkthrough (${theme})`, async ({ browser }) => {
        const context = await browser.newContext({ storageState: 'test-results/.auth/owner.json', viewport: { width: 1440, height: 900 } });
        await context.addInitScript((value) => localStorage.setItem('arkon.theme', value), theme);
        const page = await context.newPage();
        await open(page);

        // 1. Duplicate: the selected block's controls on the canvas and in the toolbar.
        await canvas(page).getByText('Book a visit').click();
        await page.keyboard.press('Escape');
        await page.screenshot({ path: `${dir}/1-duplicate-controls-${theme}.png` });
        await page.getByTestId('canvas-duplicate').click();
        await page.screenshot({ path: `${dir}/2-duplicated-${theme}.png` });

        // 2. Columns: the picker, the inspector, an empty column's Add block, the confirmation.
        await page.getByRole('tab', { name: 'Layers' }).click();
        await page.getByTestId('add-columns').click();
        await page.screenshot({ path: `${dir}/3-columns-picker-${theme}.png` });
        await page.getByTestId('add-columns').click();
        await page.locator('[data-testid="layer"][data-node-type="columns"] button').first().click();
        await page.getByRole('tab', { name: 'Properties' }).click();
        await page.screenshot({ path: `${dir}/4-columns-inspector-${theme}.png` });
        await page.getByRole('group', { name: 'Number of columns' }).getByRole('button', { name: '1', exact: true }).click();
        await expect(page.getByTestId('reduce-columns-dialog')).toBeVisible();
        await page.screenshot({ path: `${dir}/5-reduce-columns-${theme}.png` });
        await page.getByTestId('reduce-cancel').click();
        await page.getByTestId('empty-column-add').first().click();
        await page.screenshot({ path: `${dir}/6-empty-column-add-${theme}.png` });
        await page.keyboard.press('Escape');

        // 3. Animation: the section, then the hero (left off: it holds the main heading).
        await page.getByRole('tab', { name: 'Layers' }).click();
        await page.locator('[data-testid="layer"][data-node-type="section"] button').first().click();
        await page.getByRole('tab', { name: 'Properties' }).click();
        await page.getByTestId('animation-effect').selectOption('fade-up');
        await page.getByTestId('animation-section').scrollIntoViewIfNeeded();
        await page.screenshot({ path: `${dir}/7-animation-${theme}.png` });
        await canvas(page).locator('h1').click();
        await page.keyboard.press('Escape');
        await page.getByTestId('part-root').click();
        await page.getByTestId('animation-effect').selectOption('fade');
        await expect(page.getByTestId('animation-status')).toHaveAttribute('data-status', 'protected');
        await page.getByTestId('animation-section').scrollIntoViewIfNeeded();
        await page.screenshot({ path: `${dir}/8-animation-lcp-${theme}.png` });
        await context.close();
    });
}
