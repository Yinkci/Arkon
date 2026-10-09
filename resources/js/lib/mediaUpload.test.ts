import { afterEach, beforeEach, expect, test, vi } from 'vitest';
import { uploadMedia } from './mediaUpload';

class Request {
    static last: Request;
    upload: { onprogress?: (event: { lengthComputable: boolean; loaded: number; total: number }) => void } = {};
    onload!: () => void;
    onerror!: () => void;
    ontimeout!: () => void;
    status = 200;
    responseText = '';
    timeout = 0;
    headers: Record<string, string> = {};
    url = '';
    method = '';
    data?: FormData;
    constructor() {
        Request.last = this;
    }
    open(method: string, url: string) {
        this.method = method;
        this.url = url;
    }
    setRequestHeader(key: string, value: string) {
        this.headers[key] = value;
    }
    send(data: FormData) {
        this.data = data;
    }
}
beforeEach(() => {
    vi.stubGlobal('XMLHttpRequest', Request);
    vi.stubGlobal('document', { cookie: 'XSRF-TOKEN=encoded%20token' });
});
afterEach(() => vi.unstubAllGlobals());
const file = () => new File(['image-data'], 'photo.png', { type: 'image/png' });
test('uploads with CSRF and reports measured progress, then returns the signed asset', async () => {
    const progress = vi.fn();
    const promise = uploadMedia(file(), progress);
    const request = Request.last;
    expect(request.url).toBe('/admin/api/media');
    expect(request.method).toBe('POST');
    expect(request.headers['X-XSRF-TOKEN']).toBe('encoded token');
    expect(request.headers['Content-Type']).toBeUndefined();
    expect(request.data?.get('file')).toBeInstanceOf(File);
    request.upload.onprogress?.({ lengthComputable: true, loaded: 5, total: 10 });
    expect(progress).toHaveBeenCalledWith(50);
    request.upload.onprogress?.({ lengthComputable: false, loaded: 9, total: 0 });
    expect(progress).toHaveBeenCalledTimes(1);
    request.responseText = JSON.stringify({
        ok: true,
        data: { id: 'asset', url: '/media/asset.png?t=signed', originalName: 'photo.png', width: 100, height: 100 },
    });
    request.onload();
    await expect(promise).resolves.toMatchObject({ name: 'photo.png', url: '/media/asset.png?t=signed' });
});
test('server validation messages survive and oversized files are refused locally', async () => {
    const promise = uploadMedia(file());
    const failed = expect(promise).rejects.toThrow('Unsupported image');
    Request.last.status = 422;
    Request.last.responseText = JSON.stringify({ ok: false, message: 'Unsupported image' });
    Request.last.onload();
    await failed;
    await expect(uploadMedia(new File([new Uint8Array(5 * 1024 * 1024 + 1)], 'huge.png'))).rejects.toThrow('5 MiB');
    await expect(uploadMedia(new File([], 'empty.png'))).rejects.toThrow('empty');
});
test('network and timeout failures tell users the outcome is unconfirmed', async () => {
    let promise = uploadMedia(file());
    let failed = expect(promise).rejects.toThrow('could not be confirmed');
    Request.last.onerror();
    await failed;
    promise = uploadMedia(file());
    failed = expect(promise).rejects.toThrow('timed out');
    Request.last.ontimeout();
    await failed;
});
test('non-JSON and malformed responses never leave an upload pending', async () => {
    for (const body of ['<html>Error</html>', 'null', '{}']) {
        const promise = uploadMedia(file());
        const failed = expect(promise).rejects.toThrow('could not be confirmed');
        Request.last.responseText = body;
        Request.last.onload();
        await failed;
    }
});

test('an upstream HTML 413 is a server request limit error, not the image policy', async () => {
    const promise = uploadMedia(file());
    const failed = expect(promise).rejects.toThrow('server rejected the upload request');
    Request.last.status = 413;
    Request.last.responseText = '<html>Request too large</html>';
    Request.last.onload();
    await failed;
});
