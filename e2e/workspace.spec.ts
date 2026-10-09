// The builder's workspace on wide screens (1536 px and wider): the outline (Layers, History, AI)
// on the left, the canvas in the middle and Properties always on the right, so selecting never
// swaps panels. Narrower screens keep the single tabbed sidebar (covered by the other specs).
import { expect, test } from '@playwright/test';
import { createPage } from './support';

const canvas = (page: import('@playwright/test').Page) => page.frameLocator('[data-testid="canvas"]');

test('wide screens show the outline and the inspector beside the canvas', async ({ page }) => {
    await page.setViewportSize({ width: 1680, height: 1000 });
    const id = await createPage('/workspace-wide', 'A calm workspace');
    await page.goto(`/admin/editor/${id}`);
    // Start from the default (outline shown), whatever an earlier run remembered.
    await page.evaluate(() => localStorage.removeItem('arkon.editor.outline'));
    await page.reload();

    const outline = page.getByRole('complementary', { name: 'Outline' });
    const sidebar = page.getByRole('complementary', { name: 'Sidebar' });
    await expect(outline.getByRole('tab', { name: 'Layers' })).toHaveAttribute('aria-selected', 'true');
    await expect(sidebar.getByRole('tab', { name: 'Properties' })).toHaveAttribute('aria-selected', 'true');
    // Properties is never a tab of the outline on wide screens, and the canvas keeps its desktop width.
    await expect(outline.getByRole('tab', { name: 'Properties' })).toHaveCount(0);
    expect((await page.getByTestId('canvas-frame').boundingBox())!.width).toBeGreaterThanOrEqual(960);

    // Selecting in Layers edits it in Properties, with Layers still open.
    await outline
        .getByTestId('layer')
        .filter({ hasText: 'A calm workspace' })
        .getByRole('button', { name: /A calm workspace/ })
        .click();
    await expect(sidebar.getByTestId('inspector-target')).toHaveText('Hero section');
    await expect(outline.getByRole('tab', { name: 'Layers' })).toHaveAttribute('aria-selected', 'true');

    // Selecting on the canvas does not move the outline off Layers either.
    await canvas(page).locator('h1').click();
    await expect(outline.getByRole('tab', { name: 'Layers' })).toHaveAttribute('aria-selected', 'true');
    await expect(sidebar.getByTestId('inspector-target')).toBeVisible();

    // History and AI live in the outline; Properties stays.
    await outline.getByRole('tab', { name: 'History' }).click();
    await expect(outline.getByRole('tab', { name: 'History' })).toHaveAttribute('aria-selected', 'true');
    await expect(sidebar.getByTestId('inspector-target')).toBeVisible();

    // The outline can be hidden for more canvas room; the choice is remembered.
    await page.getByRole('button', { name: 'Hide outline' }).click();
    await expect(outline).toHaveCount(0);
    await page.reload();
    await expect(page.getByRole('complementary', { name: 'Outline' })).toHaveCount(0);
    await page.getByRole('button', { name: 'Show outline' }).click();
    await expect(page.getByRole('complementary', { name: 'Outline' })).toBeVisible();
});

test('an empty page offers a first block on the canvas', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    const id = await createPage('/workspace-empty', 'Soon removed');
    await page.goto(`/admin/editor/${id}`);
    await page.getByRole('tab', { name: 'Layers' }).click();
    await page.getByRole('button', { name: 'Remove Hero', exact: true }).click();
    const empty = page.getByTestId('canvas-empty');
    await expect(empty).toContainText('Start building your page');
    await empty.getByRole('button', { name: 'Add section' }).click();
    await expect(empty).toHaveCount(0);
    await expect(page.getByTestId('layer').first()).toHaveAttribute('data-node-type', 'section');
});
