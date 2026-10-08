import { expect, type Page, type Request, type Route } from '@playwright/test';
import pg from 'pg';
import { APP_ORIGIN, E2E_HOST, PORT, SERVER_URL, runtimeDatabase } from './env';
import { E2E_OWNER } from './fixtures';

/** For anonymous, Node-side requests (no browser): the same site, reached directly. */
export const BASE_URL = SERVER_URL;

/** The e2e database as the restricted runtime role (fixtures only). */
export const db = new pg.Pool({ ...runtimeDatabase(), max: 2 });

export async function login(page: Page) {
    await page.goto('/login');
    await page.getByLabel('Email').fill(E2E_OWNER.email);
    await page.getByLabel('Password').fill(E2E_OWNER.password);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page.getByRole('heading', { name: `Welcome, ${E2E_OWNER.name}` })).toBeVisible();
}

/** A fresh, unpublished page with one hero, so each test starts from a known state. */
export async function createPage(path: string, heading: string): Promise<string> {
    const { rows } = await db.query<{ site_id: string }>('select site_id from site_domains where hostname = $1', [`${E2E_HOST}:${PORT}`]);
    const siteId = rows[0]!.site_id;
    const id = crypto.randomUUID();
    const document = {
        schemaVersion: 1,
        root: 'e2eRoot001',
        nodes: {
            e2eRoot001: { id: 'e2eRoot001', type: 'page', version: 3, props: {}, children: ['e2eHero001'] },
            e2eHero001: { id: 'e2eHero001', type: 'hero', version: 3, props: { heading, headingLevel: 'h1', text: '', image: null }, children: [] },
        },
        seo: {},
    };
    await db.query('insert into pages (id, site_id, path, title) values ($1, $2, $3, $4)', [id, siteId, path, path.slice(1)]);
    await db.query('insert into page_drafts (page_id, site_id, document, version) values ($1, $2, $3, 1)', [id, siteId, JSON.stringify(document)]);
    return id;
}

/** Revisions the server has committed for a page (the source of truth, not the editor's list). */
export async function revisionCount(pageId: string): Promise<number> {
    const { rows } = await db.query<{ count: string }>('select count(*) from page_revisions where page_id = $1', [pageId]);
    return Number(rows[0]!.count);
}

export async function publicationCount(pageId: string): Promise<number> {
    const { rows } = await db.query<{ count: string }>('select count(*) from publications where page_id = $1', [pageId]);
    return Number(rows[0]!.count);
}

/** Editor requests are JSON POSTs to /admin/api/pages/<id>/<action>. */
export type ActionMarker = 'save' | 'publish' | 'restore';

export function isAction(request: Request, marker: ActionMarker): boolean {
    return request.method() === 'POST' && new RegExp(`/admin/api/pages/[^/]+/${marker}$`).test(new URL(request.url()).pathname);
}

export type Interference = 'delay' | 'abort-before-server' | 'drop-response-after-commit';

/**
 * Interferes with the next matching request only.
 * - delay: holds the request until `release()` is called.
 * - abort-before-server: the request never reaches the server.
 * - drop-response-after-commit: the server handles it fully, the browser sees a network error.
 */
export async function interceptNext(page: Page, marker: ActionMarker, mode: Interference) {
    let release: () => void = () => {};
    const gate = new Promise<void>((resolve) => (release = resolve));
    let done = false;
    const handler = async (route: Route) => {
        if (done || !isAction(route.request(), marker)) return route.continue();
        done = true;
        if (mode === 'delay') {
            await gate;
            return route.continue();
        }
        if (mode === 'abort-before-server') return route.abort('connectionreset');
        // Reaches the server and commits. Sent from Node, which cannot resolve the browser-only host name,
        // so it goes to the server address with the browser's own headers (cookies, CSRF token, Host).
        const request = route.request();
        await route.fetch({ url: request.url().replace(APP_ORIGIN, SERVER_URL), headers: { ...(await request.allHeaders()), host: `${E2E_HOST}:${PORT}` } });
        return route.abort('connectionreset');
    };
    await page.route('**/admin/api/pages/**', handler);
    return { release, stop: () => page.unroute('**/admin/api/pages/**', handler) };
}

export async function typeIntoHeading(page: Page, text: string, mode: 'replace' | 'append') {
    const heading = page.frameLocator('[data-testid="canvas"]').locator('h1');
    await heading.click();
    await expect(heading).toHaveAttribute('contenteditable', 'plaintext-only');
    if (mode === 'replace') await page.keyboard.press('ControlOrMeta+A');
    else await page.keyboard.press('End');
    await page.keyboard.type(text);
}
