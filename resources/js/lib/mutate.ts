// After a change on the server, the screen must show it without a manual browser reload. This reloads
// the page's props (only the named ones, when given) and resolves once Inertia has applied them, so
// a caller can close its dialog, clear its selection and announce the result on the updated screen.
import { router } from '@inertiajs/react';
import { api } from './api';

export function reloadProps(only?: string[]): Promise<void> {
    return new Promise((resolve) => router.reload({ ...(only ? { only } : {}), onFinish: () => resolve() }));
}

export type BulkAction = 'trash' | 'restore' | 'purge';
export type BulkResult = { done: string[]; failed: { id: string; message: string }[] };

/**
 * Trash, Restore or Delete permanently for list items (`/admin/api/<resource>/bulk`), followed by a
 * props reload. Throws only when the outcome is unknown (network); item failures come back in
 * `failed` with the server's reason.
 */
export async function bulk(resource: 'pages' | 'forms' | 'media', action: BulkAction, items: { id: string; version?: number }[], only?: string[]) {
    const result = await api<BulkResult>(`/${resource}/bulk`, { body: { action, items } });
    if (!result.ok) return { done: [], failed: items.map((item) => ({ id: item.id, message: result.message })) } satisfies BulkResult;
    await reloadProps(only);
    return result.data;
}

/** "1 page", "3 pages". */
export const plural = (count: number, one: string, many = `${one}s`) => `${count} ${count === 1 ? one : many}`;
