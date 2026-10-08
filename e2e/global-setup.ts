import { execFileSync, spawn } from 'node:child_process';
import { mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { E2E_ENV, E2E_HOST, E2E_MCP_TOKEN_FILE, PHP, PORT } from './env';
import { E2E_EDITOR, E2E_OWNER } from './fixtures';

function artisan(args: string[], extraEnv: Record<string, string> = {}): string {
    return execFileSync(PHP, ['artisan', ...args], { env: { ...process.env, ...E2E_ENV, ...extraEnv }, encoding: 'utf8' });
}

export default async function globalSetup() {
    rmSync('storage/e2e', { recursive: true, force: true });
    mkdirSync('storage/e2e', { recursive: true });
    // Migrates and empties arkonlaravel_e2e (as the schema owner, from .migrate.env).
    process.stdout.write(artisan(['arkon:reset-test-database', '--target=e2e']));
    artisan(['arkon:theme', 'install', process.cwd() + '/themes/mysite']);
    artisan(['arkon:seed', `--host=${E2E_HOST}:${PORT}`, `--host=127.0.0.1:${PORT}`, `--host=localhost:${PORT}`]);
    artisan(['arkon:owner-create', `--email=${E2E_OWNER.email}`, `--name=${E2E_OWNER.name}`, '--password-env=E2E_PASSWORD'], {
        E2E_PASSWORD: E2E_OWNER.password,
    });
    artisan(['arkon:member-create', `--email=${E2E_EDITOR.email}`, `--name=${E2E_EDITOR.name}`, '--role=editor', '--password-env=E2E_PASSWORD'], {
        E2E_PASSWORD: E2E_EDITOR.password,
    });

    // Pair the two local Claude Code connections for the e2e owner, exactly as a user would.
    artisan(['arkon:ai-pair', E2E_OWNER.email, '--helper']);
    const mcp = artisan(['arkon:ai-pair', E2E_OWNER.email, '--mcp']);
    writeFileSync(E2E_MCP_TOKEN_FILE, /ARKON_MCP_TOKEN=(\S+)/.exec(mcp)![1]!);

    // Start the helper (it runs e2e/fake-claude.mjs instead of claude.exe) and wait until it is ready.
    const helper = spawn(PHP, ['artisan', 'arkon:ai-helper'], { env: { ...process.env, ...E2E_ENV }, stdio: ['ignore', 'pipe', 'pipe'] });
    let output = '';
    helper.stdout.on('data', (chunk) => (output += chunk));
    helper.stderr.on('data', (chunk) => (output += chunk));
    const deadline = Date.now() + 60_000;
    while (!output.includes('signed in with a Claude')) {
        if (Date.now() > deadline || helper.exitCode !== null) throw new Error(`The AI helper did not start:\n${output}`);
        await new Promise((done) => setTimeout(done, 200));
    }

    return async () => {
        if (process.platform === 'win32') execFileSync('taskkill', ['/PID', String(helper.pid), '/T', '/F'], { stdio: 'ignore' });
        else helper.kill();
    };
}
