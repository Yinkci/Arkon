import { execFileSync } from 'node:child_process';
import { rmSync } from 'node:fs';
import { E2E_ENV, E2E_HOST, PHP, PORT } from './env';
import { E2E_EDITOR, E2E_OWNER } from './fixtures';

function artisan(args: string[], extraEnv: Record<string, string> = {}) {
    execFileSync(PHP, ['artisan', ...args], { stdio: 'inherit', env: { ...process.env, ...E2E_ENV, ...extraEnv } });
}

export default function globalSetup() {
    rmSync('storage/e2e', { recursive: true, force: true });
    // Migrates and empties arkonlaravel_e2e (as the schema owner, from .migrate.env).
    artisan(['arkon:reset-test-database', '--target=e2e']);
    artisan(['arkon:seed', `--host=${E2E_HOST}:${PORT}`, `--host=127.0.0.1:${PORT}`, `--host=localhost:${PORT}`]);
    artisan(['arkon:owner-create', `--email=${E2E_OWNER.email}`, `--name=${E2E_OWNER.name}`, '--password-env=E2E_PASSWORD'], {
        E2E_PASSWORD: E2E_OWNER.password,
    });
    artisan(['arkon:member-create', `--email=${E2E_EDITOR.email}`, `--name=${E2E_EDITOR.name}`, '--role=editor', '--password-env=E2E_PASSWORD'], {
        E2E_PASSWORD: E2E_EDITOR.password,
    });
}
