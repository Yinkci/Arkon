import { execFileSync } from 'node:child_process';
import { expect, test } from '@playwright/test';
import { PHP, E2E_ENV } from './env';
import { E2E_OWNER } from './fixtures';
import { createPage, db } from './support';
test.beforeAll(async () => {
    const existing = await db.query("select id from pages where path='/perf-hero' and deleted_at is null limit 1");
    if (!existing.rowCount)
        execFileSync(PHP, ['artisan', 'arkon:perf-fixtures', '--email=' + E2E_OWNER.email], { env: { ...process.env, ...E2E_ENV }, stdio: 'pipe' });
});
test.afterAll(() => db.end());
test('columns, buttons and images edit the selected screen and render without JavaScript', async ({ page, browser }) => {
    const id = await createPage('/responsive-blocks-demo', 'Responsive blocks');
    const asset = (await db.query('select id from media_assets order by created_at limit 1')).rows[0].id;
    const nodes: Record<string, unknown> = {
        root000001: { id: 'root000001', type: 'page', version: 6, props: {}, children: ['hero000001', 'cols000001'] },
        hero000001: {
            id: 'hero000001',
            type: 'hero',
            version: 4,
            props: { heading: 'Responsive blocks', headingLevel: 'h1', text: '', image: null },
            children: [],
        },
        cols000001: {
            id: 'cols000001',
            type: 'columns',
            version: 3,
            props: { style: { root: { mobile: { columns: '1' } } } },
            children: ['col0000001', 'col0000002'],
        },
        col0000001: { id: 'col0000001', type: 'column', version: 6, props: { style: {} }, children: ['btn0000001'] },
        col0000002: { id: 'col0000002', type: 'column', version: 6, props: { style: {} }, children: ['img0000001'] },
        btn0000001: {
            id: 'btn0000001',
            type: 'button',
            version: 6,
            props: { label: 'Contact us', href: '#', variant: 'primary', size: 'large', newTab: false, style: {} },
            children: undefined,
        },
        img0000001: {
            id: 'img0000001',
            type: 'image',
            version: 5,
            props: {
                image: { assetId: asset, alt: 'Example image' },
                caption: '',
                loading: 'lazy',
                style: { media: { base: { height: '300px', objectFit: 'cover' } } },
            },
        },
    };
    await db.query('update page_drafts set document=$1 where page_id=$2', [JSON.stringify({ schemaVersion: 1, root: 'root000001', nodes, seo: {} }), id]);
    await page.goto('/admin/editor/' + id);
    const select = async (type: string) => {
        await page.getByRole('tab', { name: 'Layers', exact: true }).click();
        await page
            .locator('[data-testid=layer][data-node-type=' + type + ']')
            .first()
            .click();
        await page.getByRole('tab', { name: 'Properties', exact: true }).click();
    };
    await select('columns');
    await page
        .getByTestId('screen-bar')
        .first()
        .getByRole('button', { name: /^Tablet/ })
        .click();
    await page.getByLabel('Custom column widths', { exact: true }).fill('1fr 2fr');
    await page.getByLabel('Custom column widths', { exact: true }).blur();
    await page
        .getByTestId('screen-bar')
        .first()
        .getByRole('button', { name: /^Mobile/ })
        .click();
    await page.getByTestId('columns-width-equal').click();
    await page.getByRole('button', { name: 'Reset column widths', exact: true }).click();
    await select('button');
    await page
        .getByTestId('screen-bar')
        .first()
        .getByRole('button', { name: /^Tablet/ })
        .click();
    await page.getByLabel('Size', { exact: true }).selectOption('small');
    await page.getByLabel('Appearance', { exact: true }).selectOption('secondary');
    await page
        .getByTestId('screen-bar')
        .first()
        .getByRole('button', { name: /^Mobile/ })
        .click();
    await expect(page.getByLabel('Size', { exact: true })).toHaveValue('inherit');
    await page.getByLabel('Size', { exact: true }).selectOption('medium');
    await page.getByRole('button', { name: 'Reset Size', exact: true }).click();
    await select('image');
    await page.getByLabel('Image height', { exact: true }).fill('150px');
    await page.getByLabel('Image height', { exact: true }).blur();
    await page.getByRole('button', { name: 'Save draft', exact: true }).click();
    await expect(page.getByTestId('save-status')).toContainText('Draft saved');
    await page.reload();
    await page.getByRole('button', { name: 'Publish', exact: true }).click();
    await expect(page.getByText('Published. The live page now shows this version.', { exact: true })).toBeVisible();
    const context = await browser.newContext({ javaScriptEnabled: false });
    const live = await context.newPage();
    await live.goto('http://' + new URL(page.url()).host + '/responsive-blocks-demo');
    const button = live.getByRole('link', { name: 'Contact us', exact: true });
    const image = live.getByAltText('Example image');
    await live.setViewportSize({ width: 1440, height: 900 });
    await expect(button).toHaveCSS('font-size', '18px');
    await expect(image).toHaveCSS('height', '300px');
    await live.setViewportSize({ width: 800, height: 900 });
    await expect(button).toHaveCSS('font-size', '14px');
    await expect(button).toHaveCSS('background-color', 'rgba(0, 0, 0, 0)');
    await live.setViewportSize({ width: 390, height: 844 });
    await expect(button).toHaveCSS('font-size', '14px');
    await expect(image).toHaveCSS('height', '150px');
    await expect(image).toHaveAttribute('sizes', /max-width: 899px/);
    await context.close();
    const row = (await db.query('select document from page_drafts where page_id=$1', [id])).rows[0].document;
    expect(row.nodes.cols000001.props.style.root.base?.columns).toBeUndefined();
    expect(row.nodes.cols000001.props.style.root.tablet.columns).toBe('1fr 2fr');
    expect(row.nodes.cols000001.props.style.root.mobile?.columns).toBeUndefined();
    expect(row.nodes.btn0000001.props.size).toBe('large');
});

test('section widths, form layout and text button presets inherit through mobile', async ({ page, browser }) => {
    const id = await createPage('/responsive-presets-demo', 'Responsive presets');
    const formId = (await db.query('select id from site_forms order by created_at limit 1')).rows[0].id;
    const nodes = {
        root000001: { id: 'root000001', type: 'page', version: 6, props: {}, children: ['hero000001', 'sect000001'] },
        hero000001: {
            id: 'hero000001',
            type: 'hero',
            version: 4,
            props: { heading: 'Responsive presets', headingLevel: 'h1', text: '', image: null },
            children: [],
        },
        sect000001: {
            id: 'sect000001',
            type: 'section',
            version: 6,
            props: { contentWidth: 'wide', responsive: { tablet: { contentWidth: 'narrow' } } },
            children: ['form000001', 'btn0000001'],
        },
        form000001: {
            id: 'form000001',
            type: 'form',
            version: 3,
            props: { form: { id: formId }, layout: 'stacked', responsive: { tablet: { layout: 'inline' }, mobile: { layout: 'inherit' } } },
        },
        btn0000001: {
            id: 'btn0000001',
            type: 'button',
            version: 6,
            props: {
                label: 'Preset action',
                href: '#',
                variant: 'text',
                size: 'large',
                responsive: { tablet: { variant: 'primary', size: 'small' }, mobile: { variant: 'text' } },
            },
        },
    };
    await db.query('update page_drafts set document=$1 where page_id=$2', [JSON.stringify({ schemaVersion: 1, root: 'root000001', nodes, seo: {} }), id]);
    await page.goto('/admin/editor/' + id);
    await page.getByRole('button', { name: 'Publish', exact: true }).click();
    await expect(page.getByText('Published. The live page now shows this version.', { exact: true })).toBeVisible();
    const context = await browser.newContext({ javaScriptEnabled: false });
    const live = await context.newPage();
    await live.goto('http://' + new URL(page.url()).host + '/responsive-presets-demo');
    const form = live.locator('form.ak-form3');
    const button = live.getByRole('link', { name: 'Preset action', exact: true });
    const section = live.locator('.ak-section');
    await live.setViewportSize({ width: 1440, height: 900 });
    await expect(form).toHaveCSS('display', 'grid');
    await expect(button).toHaveCSS('padding-left', '0px');
    const desktopWidth = await section.evaluate((el) => getComputedStyle(el).getPropertyValue('--ak-content'));
    await live.setViewportSize({ width: 800, height: 900 });
    await expect(form).toHaveCSS('display', 'flex');
    await expect(button).toHaveCSS('padding-left', '14px');
    const tabletWidth = await section.evaluate((el) => getComputedStyle(el).getPropertyValue('--ak-content'));
    expect(tabletWidth).not.toBe(desktopWidth);
    await live.setViewportSize({ width: 390, height: 844 });
    await expect(form).toHaveCSS('display', 'flex');
    await expect(button).toHaveCSS('padding-left', '0px');
    expect(await section.evaluate((el) => getComputedStyle(el).getPropertyValue('--ak-content'))).toBe(tabletWidth);
    await context.close();
});
