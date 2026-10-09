// Lab performance check of published pages (docs/PERFORMANCE.md).
//
//   npm run build && node scripts/perf.mjs
//
// Resets the isolated e2e database, publishes three fixture pages (arkon:perf-fixtures), serves
// the app with production settings (APP_ENV=production, APP_DEBUG=false) from PHP's built-in
// server, and runs Lighthouse (mobile, simulated throttling, Playwright's Chromium) three times
// per page. Prints the medians and writes the raw reports to storage/perf/. Lab numbers only:
// field Core Web Vitals need real-user data after deployment.
import { execFileSync, spawn } from 'node:child_process';
import { existsSync, mkdirSync, readFileSync, readdirSync, realpathSync, rmSync, writeFileSync } from 'node:fs';
import { homedir } from 'node:os';
import { dirname, join, resolve, sep } from 'node:path';
import { chromium as playwright } from '@playwright/test';
import { E2E_ENV, PHP } from '../e2e/env.ts';

const PORT = 8101;
const HOST = `127.0.0.1:${PORT}`;
// Each page without and with entrance animations (PERF_PAGES=/a,/b to measure fewer).
const PAGES = process.env.PERF_PAGES?.split(',') ?? [
    '/perf-contact',
    '/perf-hero',
    '/perf-hero-motion',
    '/perf-images',
    '/perf-images-motion',
    '/perf-nested',
    '/perf-nested-motion',
    '/perf-intro-motion',
];
// Where reports and the summary go (PERF_OUT=storage/perf-baseline keeps a baseline apart).
const OUT = process.env.PERF_OUT ?? 'storage/perf';
// Output cleanup must never target the repository root or an arbitrary directory.
// These bounded inputs also stay safe when npx.cmd needs a Windows shell.
if (PAGES.length > 16 || PAGES.some((path) => !/^\/(?:[a-z0-9-]+(?:\/[a-z0-9-]+)*)?$/.test(path))) {
    throw new Error('PERF_PAGES must contain at most 16 plain site paths.');
}
if (!/^storage[\/][A-Za-z0-9_/-]+$/.test(OUT) || !resolve(OUT).startsWith(resolve('storage') + sep)) {
    throw new Error('PERF_OUT must be a named report directory inside storage/.');
}
let existingOutputParent = resolve(OUT);
while (!existsSync(existingOutputParent)) existingOutputParent = dirname(existingOutputParent);
const storageRoot = realpathSync('storage');
const realOutputParent = realpathSync(existingOutputParent);
if (realOutputParent !== storageRoot && !realOutputParent.startsWith(storageRoot + sep)) {
    throw new Error('PERF_OUT cannot traverse a link outside storage/.');
}
// Optional already-installed CLI: run Node directly, avoiding Windows cmd/npx spawning.
const LIGHTHOUSE_CLI = process.env.PERF_LIGHTHOUSE_CLI ? resolve(process.env.PERF_LIGHTHOUSE_CLI) : null;
if (LIGHTHOUSE_CLI && !existsSync(LIGHTHOUSE_CLI)) throw new Error('PERF_LIGHTHOUSE_CLI does not exist.');
if (LIGHTHOUSE_CLI && JSON.parse(readFileSync(resolve(dirname(LIGHTHOUSE_CLI), '../package.json'), 'utf8')).version !== '12.8.2') {
    throw new Error('PERF_LIGHTHOUSE_CLI must be Lighthouse 12.8.2 for a comparable benchmark.');
}
const RUNS = 3;
const OWNER = { email: 'perf-owner@e2e.test', name: 'Perf Owner', password: 'perf-owner-password-123' };
const env = {
    ...process.env,
    ...E2E_ENV,
    APP_ENV: 'production',
    APP_DEBUG: 'false',
    APP_URL: `http://${HOST}`,
    ARKON_MEDIA_ROOT: `${OUT}/media`,
    LOG_CHANNEL: 'stderr',
};
const artisan = (args, extra = {}) => execFileSync(PHP, ['artisan', ...args], { env: { ...env, ...extra }, encoding: 'utf8' });

function chromium() {
    const root = join(homedir(), 'AppData', 'Local', 'ms-playwright');
    const dir = readdirSync(root)
        .filter((d) => /^chromium-\d+$/.test(d))
        .sort()
        .pop();
    if (!dir) throw new Error('Playwright Chromium not found (npx playwright install chromium)');
    return join(root, dir, 'chrome-win64', 'chrome.exe');
}

// The browser check: the same production server and Chromium, a phone with 4× CPU slowdown and a
// slow 4G-like network (Lighthouse's mobile values), 3 runs per page. It loads the page, taps text
// five times, then scrolls to the end, and reports what the browser itself observed: LCP and its
// element (and whether that element is inside an animated block), CLS (also while scrolling),
// long tasks, the slowest tap (Event Timing, the INP source; synthetic, not field INP) and the
// entrance animations that started at load and while scrolling.
const BROWSER_PROFILE = {
    viewport: '412×823 @1.75, touch',
    cpuSlowdown: 4,
    network: { latencyMs: 150, downKbps: 1638, upKbps: 750 },
    runs: 3,
    taps: 5,
};
const OBSERVE = () => {
    const m = { lcp: 0, lcpElement: '', lcpAnimated: false, fcp: 0, cls: 0, longTasks: 0, taps: [], starts: [] };
    window.__perf = m;
    const watch = (type, fn) => {
        try {
            new PerformanceObserver((list) => list.getEntries().forEach(fn)).observe({
                type,
                buffered: true,
                ...(type === 'event' ? { durationThreshold: 16 } : {}),
            });
        } catch {}
    };
    watch('largest-contentful-paint', (e) => {
        m.lcp = Math.round(e.renderTime || e.loadTime || e.startTime);
        m.lcpElement = e.element ? `${e.element.tagName.toLowerCase()}.${String(e.element.className).split(' ')[0]}` : '';
        m.lcpAnimated = !!e.element?.closest('.ak-anim');
    });
    watch('paint', (e) => {
        if (e.name === 'first-contentful-paint') m.fcp = Math.round(e.startTime);
    });
    watch('layout-shift', (e) => {
        if (!e.hadRecentInput) m.cls += e.value;
    });
    watch('longtask', () => m.longTasks++);
    watch('event', (e) => {
        if (e.interactionId) m.taps.push(Math.round(e.duration));
    });
    // An entrance counts when it finishes (a paused one may fire animationstart without being seen).
    document.addEventListener('animationend', (e) => m.starts.push(Math.round(e.timeStamp)), true);
};

async function browserCheck(chrome) {
    const browser = await playwright.launch({ executablePath: chrome, args: ['--no-sandbox'] });
    const rows = [];
    try {
        for (const path of PAGES) {
            const runs = [];
            for (let run = 1; run <= BROWSER_PROFILE.runs; run++) {
                const context = await browser.newContext({ viewport: { width: 412, height: 823 }, deviceScaleFactor: 1.75, isMobile: true, hasTouch: true });
                await context.addInitScript(OBSERVE);
                const page = await context.newPage();
                const cdp = await context.newCDPSession(page);
                await cdp.send('Emulation.setCPUThrottlingRate', { rate: BROWSER_PROFILE.cpuSlowdown });
                await cdp.send('Network.enable');
                await cdp.send('Network.emulateNetworkConditions', {
                    offline: false,
                    latency: BROWSER_PROFILE.network.latencyMs,
                    downloadThroughput: (BROWSER_PROFILE.network.downKbps * 1024) / 8,
                    uploadThroughput: (BROWSER_PROFILE.network.upKbps * 1024) / 8,
                });
                await page.goto(`http://${HOST}${path}`, { waitUntil: 'load' });
                await page.waitForTimeout(3000);
                const atLoad = await page.evaluate(() => ({
                    ...window.__perf,
                    starts: window.__perf.starts.length,
                    taps: [],
                    dom: (() => {
                        const elements = [...document.querySelectorAll('*')];
                        const depth = (element) => {
                            let value = 1;
                            while (element.parentElement) {
                                value++;
                                element = element.parentElement;
                            }
                            return value;
                        };
                        return {
                            elements: elements.length,
                            depth: Math.max(...elements.map(depth)),
                            maxChildren: Math.max(...elements.map((element) => element.children.length)),
                            editorAttributes: elements.reduce(
                                (count, element) => count + [...element.attributes].filter((a) => a.name.startsWith('data-ak-')).length,
                                0,
                            ),
                        };
                    })(),
                    // Animated blocks on screen that are still not fully shown 3 s after load.
                    hiddenInView: [...document.querySelectorAll('.ak-anim')].filter((el) => {
                        const r = el.getBoundingClientRect();
                        return r.bottom > 0 && r.top < innerHeight && parseFloat(getComputedStyle(el).opacity) < 0.99;
                    }).length,
                }));
                const text = page.locator('h1, h2, .ak-text2').first(); // text, never a link
                for (let i = 0; i < BROWSER_PROFILE.taps; i++) {
                    await text.tap({ force: true });
                    await page.waitForTimeout(200);
                }
                for (let i = 0; i < 14; i++) {
                    await page.evaluate(() => window.scrollBy(0, Math.round(window.innerHeight * 0.45)));
                    await page.waitForTimeout(250);
                }
                await page.waitForTimeout(2500);
                const end = await page.evaluate(() => window.__perf);
                runs.push({
                    dom: atLoad.dom,
                    lcp: atLoad.lcp,
                    fcp: atLoad.fcp,
                    lcpElement: atLoad.lcpElement,
                    lcpAnimated: atLoad.lcpAnimated,
                    clsLoad: atLoad.cls,
                    clsTotal: end.cls,
                    longTasks: end.longTasks,
                    tapMax: Math.max(0, ...end.taps),
                    startsAtLoad: atLoad.starts,
                    startsScrolling: end.starts.length - atLoad.starts,
                    hiddenInView: atLoad.hiddenInView,
                });
                await context.close();
            }
            const pick = (key) => runs.map((r) => r[key]);
            rows.push({
                path,
                dom: runs[0].dom,
                lcpMs: `${median(pick('lcp'))} (${pick('lcp').join('/')})`,
                fcpMs: median(pick('fcp')),
                lcpElement: runs[0].lcpElement,
                lcpInAnimatedBlock: pick('lcpAnimated').some(Boolean),
                clsLoad: Math.max(...pick('clsLoad')).toFixed(3),
                clsWithScroll: Math.max(...pick('clsTotal')).toFixed(3),
                longTasks: pick('longTasks').join('/'),
                slowestTapMs: pick('tapMax').join('/'),
                entrancesEndedAtLoad: pick('startsAtLoad').join('/'),
                entrancesEndedScrolling: pick('startsScrolling').join('/'),
                hiddenInViewAt3s: pick('hiddenInView').join('/'),
            });
        }
    } finally {
        await browser.close();
    }
    return rows;
}

const median = (values) => [...values].sort((a, b) => a - b)[Math.floor(values.length / 2)];

rmSync(OUT, { recursive: true, force: true });
mkdirSync(`${OUT}/reports`, { recursive: true });
process.stdout.write(artisan(['arkon:reset-test-database', '--target=e2e']));
artisan(['arkon:seed', `--host=${HOST}`]);
artisan(['arkon:owner-create', `--email=${OWNER.email}`, `--name=${OWNER.name}`, '--password-env=PERF_PASSWORD'], { PERF_PASSWORD: OWNER.password });
process.stdout.write(artisan(['arkon:perf-fixtures', `--email=${OWNER.email}`]));

const server = spawn(PHP, ['-S', HOST, '-t', '.', '../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'], {
    cwd: 'public',
    env,
    stdio: 'ignore',
});
try {
    for (let i = 0; i < 50; i++) {
        try {
            if ((await fetch(`http://${HOST}/favicon.ico`)).ok) break;
        } catch {}
        await new Promise((r) => setTimeout(r, 200));
    }
    const chrome = chromium();
    const rows = [];
    for (const path of PAGES) {
        // Warm up once (PHP opcache, file cache), then measure.
        await fetch(`http://${HOST}${path}`);
        const runs = [];
        for (let run = 1; run <= RUNS; run++) {
            const out = `${OUT}/reports/${path.slice(1)}-${run}.json`;
            execFileSync(
                LIGHTHOUSE_CLI ? process.execPath : process.platform === 'win32' ? 'npx.cmd' : 'npx',
                [
                    ...(LIGHTHOUSE_CLI ? [LIGHTHOUSE_CLI] : ['--yes', 'lighthouse@12.8.2']),
                    `http://${HOST}${path}`,
                    '--only-categories=performance,accessibility,seo',
                    '--output=json',
                    `--output-path=${out}`,
                    '--quiet',
                    '--chrome-flags=--headless=new --no-sandbox',
                ],
                { env: { ...process.env, CHROME_PATH: chrome }, stdio: 'inherit', shell: !LIGHTHOUSE_CLI && process.platform === 'win32' },
            );
            const report = JSON.parse(readFileSync(out, 'utf8'));
            const audit = (id) => report.audits[id]?.numericValue ?? NaN;
            const items = report.audits['network-requests']?.details?.items ?? [];
            const bytes = (type) => items.filter((i) => !type || i.resourceType === type).reduce((sum, i) => sum + (i.transferSize ?? 0), 0);
            runs.push({
                score: Math.round(report.categories.performance.score * 100),
                accessibility: Math.round(report.categories.accessibility.score * 100),
                seo: Math.round(report.categories.seo.score * 100),
                auditFindings: Object.entries(report.audits)
                    .filter(([, audit]) => audit.score !== null && audit.score < 1 && ['binary', 'numeric'].includes(audit.scoreDisplayMode))
                    .map(([id, audit]) => ({ id, title: audit.title, score: audit.score })),
                lcp: audit('largest-contentful-paint'),
                cls: audit('cumulative-layout-shift'),
                tbt: audit('total-blocking-time'),
                fcp: audit('first-contentful-paint'),
                si: audit('speed-index'),
                transfer: bytes(),
                images: bytes('Image'),
                document: bytes('Document'),
                requests: items.length,
                scripts: items.filter((i) => i.resourceType === 'Script').length,
                scriptBytes: bytes('Script'),
                lcpElement: report.audits['largest-contentful-paint-element']?.details?.items?.[0]?.items?.[0]?.node?.snippet ?? '',
                config: report.configSettings,
            });
        }
        const html = await (await fetch(`http://${HOST}${path}`)).text();
        const css = /<style>([\s\S]*?)<\/style>/.exec(html)?.[1] ?? '';
        const m = (key) => median(runs.map((r) => r[key]));
        rows.push({
            path,
            score: m('score'),
            accessibility: m('accessibility'),
            seo: m('seo'),
            auditFindings: runs[0].auditFindings,
            lcpMs: Math.round(m('lcp')),
            cls: Number(m('cls').toFixed(3)),
            tbtMs: Math.round(m('tbt')),
            fcpMs: Math.round(m('fcp')),
            siMs: Math.round(m('si')),
            transferKb: Number((m('transfer') / 1024).toFixed(1)),
            imagesKb: Number((m('images') / 1024).toFixed(1)),
            htmlKb: Number((m('document') / 1024).toFixed(1)),
            cssBytes: css.length,
            requests: m('requests'),
            scripts: m('scripts'),
            scriptBytes: m('scriptBytes'),
            scores: runs.map((r) => r.score).join('/'),
            lcpElement: runs[0].lcpElement,
        });
    }
    const conditions = rows.length ? JSON.parse(readFileSync(`${OUT}/reports/${PAGES[0].slice(1)}-1.json`, 'utf8')).configSettings : {};
    const browserRows = await browserCheck(chrome);
    const summary = {
        measuredAt: new Date().toISOString(),
        lighthouse: '12.8.2',
        chrome,
        conditions: {
            formFactor: conditions.formFactor,
            throttlingMethod: conditions.throttlingMethod,
            throttling: conditions.throttling,
            screenEmulation: conditions.screenEmulation,
        },
        server: 'PHP built-in server (php -S), APP_ENV=production, APP_DEBUG=false, same machine',
        runsPerPage: RUNS,
        rows,
        browser: {
            profile: BROWSER_PROFILE,
            rows: browserRows,
        },
    };
    writeFileSync(`${OUT}/summary.json`, JSON.stringify(summary, null, 2));
    console.table(browserRows);
    console.table(rows.map(({ lcpElement, ...r }) => r));
    for (const r of rows) console.log(`${r.path} LCP element: ${r.lcpElement}`);
} finally {
    if (process.platform === 'win32') execFileSync('taskkill', ['/PID', String(server.pid), '/T', '/F'], { stdio: 'ignore' });
    else server.kill();
}
