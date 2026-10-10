import { expect, test } from '@playwright/test';
const PNG_1X1 = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAACAAAAAYCAIAAAAUMWhjAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAGElEQVRIie3BAQEAAACAkP6v7ggKAICqAQkYAAHO7iU+AAAAAElFTkSuQmCC',
    'base64',
);

test('a real 2.8 MiB multipart image is stored and receives WebP delivery copies', async ({ page }) => {
    await page.goto('/admin/media');
    const image = Buffer.concat([PNG_1X1, Buffer.alloc(Math.round(2.8 * 1024 * 1024) - PNG_1X1.length)]);
    const response = page.waitForResponse((r) => r.url().endsWith('/admin/api/media') && r.request().method() === 'POST');
    await page.getByLabel('Upload image file').setInputFiles({ name: 'real-multipart-2.8.png', mimeType: 'image/png', buffer: image });
    const result = await (await response).json();
    expect(result.ok, result.message).toBe(true);
    expect(result.data.bytes).toBe(image.length);
    const tile = page.getByRole('button', { name: 'Open real-multipart-2.8.png', exact: true });
    await expect(tile).toBeVisible();
    await tile.click();
    const dialog = page.getByRole('dialog', { name: 'Image details' });
    const webp = dialog.getByLabel('WebP URL (32px)', { exact: true });
    await expect(webp).toHaveValue(/-w32\.webp$/);
    const expected = await webp.inputValue();
    await page.evaluate(() => {
        (window as unknown as { copiedUrl: string }).copiedUrl = '';
        document.execCommand = (command: string) => {
            if (command !== 'copy') return false;
            (window as unknown as { copiedUrl: string }).copiedUrl = (document.activeElement as HTMLTextAreaElement).value;
            return true;
        };
    });
    const copy = dialog.getByRole('button', { name: 'Copy 32px WebP URL', exact: true });
    await copy.click();
    await expect(dialog.getByText('WebP link copied.', { exact: true })).toBeVisible();
    expect(await page.evaluate(() => (window as unknown as { copiedUrl: string }).copiedUrl)).toBe(expected);
    await expect(copy).toBeFocused();
    await expect(dialog.getByLabel('File URL', { exact: true })).toHaveValue(/\.png$/);
});

test('exact file boundary passes; bypassing client validation still rejects larger files accurately', async ({ page }) => {
    await page.goto('/admin/media');
    const image = Buffer.concat([PNG_1X1, Buffer.alloc(5242880 - PNG_1X1.length)]);
    const response = page.waitForResponse((r) => r.url().endsWith('/admin/api/media') && r.request().method() === 'POST');
    await page.getByLabel('Upload image file').setInputFiles({ name: 'exact-limit.png', mimeType: 'image/png', buffer: image });
    expect((await (await response).json()).ok).toBe(true);
    await expect(page.getByRole('button', { name: 'Open exact-limit.png', exact: true })).toBeVisible();
    for (const size of [5242881, 6 * 1024 ** 2, 11 * 1024 ** 2]) {
        const result = await page.evaluate(async (size) => {
            const data = new FormData();
            data.set('file', new File([new Uint8Array(size)], 'bypass.png', { type: 'image/png' }));
            const token = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/)?.[1];
            const response = await fetch('/admin/api/media', {
                method: 'POST',
                body: data,
                headers: { Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(token ?? '') },
            });
            return { status: response.status, body: await response.json() };
        }, size);
        expect(result.status).toBe(size > 10 * 1024 ** 2 ? 413 : 422);
        expect(result.body.message).toContain(size > 10 * 1024 ** 2 ? 'request body' : 'maximum image size is 5 MiB');
    }
});
