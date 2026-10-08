import { existsSync, readFileSync } from 'node:fs';
import { homedir } from 'node:os';
import { join, resolve } from 'node:path';

export const PORT = 8100;

/**
 * The browser opens the app at an insecure origin, like http://arkonlaravel.test under Herd:
 * plain http and not localhost, so Chromium has isSecureContext=false and no crypto.randomUUID.
 * (127.0.0.1 and localhost count as secure and would hide such bugs.) Chromium maps this
 * name to the e2e server; Node-side requests use SERVER_URL directly.
 */
export const E2E_HOST = 'arkon-e2e.test';
export const APP_ORIGIN = `http://${E2E_HOST}:${PORT}`;
export const SERVER_URL = `http://127.0.0.1:${PORT}`;

/** The fake Claude Code CLI the e2e helper runs instead of claude.exe (no login, no network). */
export const FAKE_CLAUDE = resolve('e2e/fake-claude.mjs');
export const FAKE_CLAUDE_LOG = resolve('storage/e2e/fake-claude.log');
/** Paired tokens written by global setup for the e2e owner (never real credentials). */
export const E2E_HELPER_TOKEN_FILE = resolve('storage/e2e/ai-helper.token');
export const E2E_MCP_TOKEN_FILE = resolve('storage/e2e/ai-mcp.token');

/** PHP with pdo_pgsql (Herd's). Override with PHP_BINARY. */
export const PHP = process.env.PHP_BINARY ?? [join(homedir(), '.config', 'herd', 'bin', 'php84', 'php.exe')].find((p) => existsSync(p)) ?? 'php';

/** Runtime settings of the e2e server and setup commands. Never the schema-owner credentials. */
export const E2E_ENV: Record<string, string> = {
    APP_ENV: 'local',
    APP_DEBUG: 'true',
    APP_URL: APP_ORIGIN,
    DB_DATABASE: 'arkonlaravel_e2e',
    ARKON_THEME_STORE: 'storage/e2e/theme-components',
    ARKON_MEDIA_ROOT: 'storage/e2e/media',
    SESSION_DRIVER: 'database',
    CACHE_STORE: 'array',
    LOG_CHANNEL: 'stderr',
    // AI: the helper runs the fake Claude Code CLI; tokens go to storage/e2e, never the dev helper's file.
    ARKON_CLAUDE_COMMAND: JSON.stringify(['node', FAKE_CLAUDE]),
    ARKON_HELPER_TOKEN_FILE: E2E_HELPER_TOKEN_FILE,
    ARKON_AI_PER_USER_PER_MINUTE: '60',
    ARKON_AI_MAX_ACTIVE_PER_SITE: '5',
};

/** Runtime database settings from .env (the restricted role), for test fixtures. */
export function runtimeDatabase() {
    const env = Object.fromEntries(
        readFileSync('.env', 'utf8')
            .split(/\r?\n/)
            .filter((line) => /^[A-Z_]+=/.test(line))
            .map((line) => {
                const [key, ...rest] = line.split('=');
                return [key!, rest.join('=').replace(/^"|"$/g, '')];
            }),
    );
    return {
        host: env.DB_HOST ?? '127.0.0.1',
        port: Number(env.DB_PORT ?? 5432),
        user: env.DB_USERNAME!,
        password: env.DB_PASSWORD!,
        database: E2E_ENV.DB_DATABASE!,
    };
}
