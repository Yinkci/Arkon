// JSON calls to /admin/api. Errors come back as { ok: false, code, message } and
// are returned, not thrown. A thrown error means the outcome is unknown (network
// problem): callers keep their request keys and retry the same request safely.
import type { Issue } from '@/arkon/rules';

export type ApiResult<T> = { ok: true; data: T } | { ok: false; code: string; message: string; issues?: Issue[]; currentVersion?: number };

function xsrfToken(): string | null {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]!) : null;
}

export async function api<T>(path: string, init: { method?: 'GET' | 'POST'; body?: unknown; form?: FormData } = {}): Promise<ApiResult<T>> {
    const headers: Record<string, string> = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    const token = xsrfToken();
    if (token) headers['X-XSRF-TOKEN'] = token;
    let body: BodyInit | undefined;
    if (init.form) {
        body = init.form;
    } else if (init.body !== undefined) {
        headers['Content-Type'] = 'application/json';
        body = JSON.stringify(init.body);
    }
    const response = await fetch(`/admin/api${path}`, { method: init.method ?? (body ? 'POST' : 'GET'), headers, body, credentials: 'same-origin' });
    try {
        return (await response.json()) as ApiResult<T>;
    } catch {
        // Not our JSON envelope (proxy error page, upload too large for the server, …).
        if (response.status === 413)
            return {
                ok: false,
                code: 'REQUEST_TOO_LARGE',
                message: 'The server rejected the request body. Ask the administrator to check PHP and proxy upload limits.',
            };
        return { ok: false, code: response.status >= 500 ? 'INTERNAL' : 'BAD_REQUEST', message: 'Something went wrong. Please try again.' };
    }
}

export { newRequestKey } from './request-key';
