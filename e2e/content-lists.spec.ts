import { expect, test, type Page } from '@playwright/test';

// The shared admin list pattern on Forms and Media: Trash, Restore, Delete permanently, checkboxes,
// bulk actions and immediate updates (no reloads).

/** A JSON call to the admin API from the signed-in page (same origin, XSRF header). */
async function apiPost<T>(page: Page, path: string, body: unknown): Promise<T> {
    return page.evaluate(
        async ({ path, body }) => {
            const token = decodeURIComponent(document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/)?.[1] ?? '');
            const response = await fetch(`/admin/api${path}`, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': token },
                body: JSON.stringify(body),
            });
            return (await response.json()).data;
        },
        { path, body },
    );
}

/** Uploads canvas-drawn PNGs (wide enough to get WebP sizes) through the real upload endpoint. */
async function uploadImages(page: Page, names: string[], width = 900, height = 600) {
    await page.evaluate(
        async ({ names, width, height }) => {
            const token = decodeURIComponent(document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/)?.[1] ?? '');
            for (const [i, name] of names.entries()) {
                const canvas = document.createElement('canvas');
                canvas.width = width;
                canvas.height = height;
                const g = canvas.getContext('2d')!;
                g.fillStyle = `hsl(${(i * 47) % 360} 55% 55%)`;
                g.fillRect(0, 0, width, height);
                g.fillStyle = '#fff';
                g.fillRect(width / 4, height / 4, width / 2, height / 2);
                const blob = await new Promise<Blob>((resolve) => canvas.toBlob((b) => resolve(b!), 'image/png'));
                const form = new FormData();
                form.append('file', new File([blob], name, { type: 'image/png' }));
                const response = await fetch('/admin/api/media', {
                    method: 'POST',
                    headers: { Accept: 'application/json', 'X-XSRF-TOKEN': token },
                    body: form,
                });
                if (!response.ok) throw new Error(`Upload of ${name} failed: ${response.status}`);
            }
        },
        { names, width, height },
    );
}

const formsTab = (page: Page, name: string) => page.getByRole('group', { name: 'Filter forms' }).getByRole('button', { name: new RegExp(`^${name}`) });
const definition = (name: string) => ({
    name,
    submitLabel: 'Send',
    successMessage: 'Thanks',
    fields: [{ id: 'name', label: 'Name', type: 'text', required: true }],
});

test('forms move to the Trash one by one or together, restore, and delete permanently', async ({ page }) => {
    await page.goto('/admin/forms');
    const key = () => crypto.randomUUID().replace(/-/g, '');
    for (const name of ['Trash me', 'Bulk one', 'Bulk two'])
        await apiPost(page, '/forms/save', { baseVersion: 0, requestKey: key(), definition: definition(name) });
    await page.reload();
    const row = (name: string) => page.getByTestId('form-row').filter({ hasText: name });

    // One form: the More menu, a plain confirmation, gone at once.
    await page.getByRole('button', { name: 'More actions for Trash me' }).click();
    await page.getByRole('menuitem', { name: 'Move to Trash' }).click();
    const dialog = page.getByRole('dialog', { name: 'Move “Trash me” to Trash?' });
    await expect(dialog.getByRole('textbox')).toHaveCount(0);
    await dialog.getByRole('button', { name: 'Move to Trash' }).click();
    await expect(row('Trash me')).toHaveCount(0);
    await expect(page.getByTestId('toast').last()).toContainText('Form moved to Trash.');
    await expect(formsTab(page, 'Trash')).toHaveText(/1$/);

    // Two together.
    await page.getByRole('checkbox', { name: 'Select Bulk one' }).check();
    await page.getByRole('checkbox', { name: 'Select Bulk two' }).check();
    await page.getByTestId('bulk-bar').getByRole('button', { name: 'Move to Trash' }).click();
    await page.getByRole('dialog', { name: 'Move 2 forms to Trash?' }).getByRole('button', { name: 'Move to Trash' }).click();
    await expect(row('Bulk one')).toHaveCount(0);
    await expect(row('Bulk two')).toHaveCount(0);
    await expect(page.getByTestId('toast').last()).toContainText('2 forms moved to Trash.');
    await expect(formsTab(page, 'Trash')).toHaveText(/3$/);

    // The Trash: restore one, delete the rest permanently.
    await formsTab(page, 'Trash').click();
    await expect(page.getByTestId('form-row')).toHaveCount(3);
    await row('Trash me').getByRole('button', { name: 'Restore' }).click();
    await expect(page.getByTestId('toast').last()).toContainText('Form restored.');
    await expect(row('Trash me')).toHaveCount(0);
    await page.getByRole('checkbox', { name: 'Select all forms in the Trash' }).check();
    await page.getByTestId('bulk-bar').getByRole('button', { name: 'Delete permanently' }).click();
    const purge = page.getByRole('dialog', { name: 'Permanently delete 2 forms?' });
    await expect(purge).toContainText('cannot be undone');
    await expect(purge).toContainText('no entries');
    await purge.getByRole('button', { name: 'Delete permanently' }).click();
    await expect(page.getByText('Trash is empty')).toBeVisible();
    await formsTab(page, 'All').click();
    await expect(row('Trash me')).toBeVisible();
    await expect(page.getByText(/^\d+ forms?$/)).toBeVisible();
});

test('media: compact grid with small WebP thumbnails, list view, selection and bulk Trash', async ({ page }) => {
    await page.goto('/admin/media');
    const names = Array.from({ length: 6 }, (_, i) => `bulk-image-${i}.png`);
    await uploadImages(page, names);

    // The grid requests only small WebP sizes, never the originals.
    const images: string[] = [];
    page.on('request', (request) => {
        const path = new URL(request.url()).pathname;
        if (request.resourceType() === 'image' && path.startsWith('/media/')) images.push(path);
    });
    await page.evaluate(() => localStorage.removeItem('arkon.media.view'));
    // Only this test's images (other specs upload to the same library).
    await page.goto('/admin/media?q=bulk-image');
    const grid = page.getByTestId('media-grid');
    await expect(grid.getByTestId('media-item')).toHaveCount(6);
    await expect.poll(() => images.length).toBeGreaterThanOrEqual(6);
    expect(
        images.every((path) => /-w(320|640)\.webp$/.test(path)),
        images.join(', '),
    ).toBe(true);

    // Grid → List → Grid, remembered across a reload.
    const view = page.getByRole('group', { name: 'View' });
    await view.getByRole('button', { name: 'List' }).click();
    await expect(page.getByTestId('media-list')).toBeVisible();
    await expect(page.getByTestId('media-list')).toContainText('bulk-image-0.png');
    await page.reload();
    await expect(page.getByTestId('media-list')).toBeVisible();
    await view.getByRole('button', { name: 'Grid' }).click();
    await expect(grid).toBeVisible();

    // Clicking an image still opens it; the checkbox only selects.
    await page.getByRole('button', { name: 'Open bulk-image-0.png' }).click();
    await expect(page.getByRole('dialog', { name: 'Image details' })).toBeVisible();
    await page.keyboard.press('Escape');
    for (const name of names.slice(0, 5)) await page.getByRole('checkbox', { name: `Select ${name}` }).check();
    await expect(page.getByRole('dialog', { name: 'Image details' })).toBeHidden();
    await expect(page.getByTestId('bulk-bar')).toContainText('5 images selected');
    await page.getByTestId('bulk-bar').getByRole('button', { name: 'Move to Trash' }).click();
    const dialog = page.getByRole('dialog', { name: 'Move 5 images to Trash?' });
    await expect(dialog).toContainText('not used on any page');
    await dialog.getByRole('button', { name: 'Move to Trash' }).click();
    await expect(grid.getByTestId('media-item')).toHaveCount(1);
    await expect(page.getByTestId('toast').last()).toContainText('5 images moved to Trash.');

    // And back from the Trash.
    await page
        .getByRole('group', { name: 'Library or Trash' })
        .getByRole('button', { name: /^Trash/ })
        .click();
    await expect(grid.getByTestId('media-item')).toHaveCount(5);
    await page.getByRole('checkbox', { name: 'Select all images on this page' }).check();
    await page.getByTestId('bulk-bar').getByRole('button', { name: 'Restore' }).click();
    await expect(page.getByTestId('toast').last()).toContainText('5 images restored.');
    await expect(page.getByTestId('media-item')).toHaveCount(0);
    await expect(page.getByTestId('list-empty')).toBeVisible();
});
