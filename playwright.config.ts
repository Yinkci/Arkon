import { defineConfig, devices } from '@playwright/test';
import { APP_ORIGIN, E2E_ENV, E2E_HOST, PHP, PORT, SERVER_URL } from './e2e/env';

// The browser tests run against their own database (arkonlaravel_e2e) and media
// directory, served by PHP's built-in server with the same Laravel app. Dev data is
// never touched. Only runtime settings reach the server: the schema-owner
// credentials stay in .migrate.env and are read by the setup command only.
export default defineConfig({
    testDir: './e2e',
    fullyParallel: false,
    workers: 1,
    retries: 0,
    timeout: 60_000,
    expect: { timeout: 10_000 },
    reporter: [['list']],
    globalSetup: './e2e/global-setup.ts',
    use: {
        // An insecure origin, like Herd's http://arkonlaravel.test (see e2e/env.ts).
        baseURL: APP_ORIGIN,
        launchOptions: { args: [`--host-resolver-rules=MAP ${E2E_HOST} 127.0.0.1`] },
        trace: 'retain-on-failure',
    },
    projects: [
        { name: 'setup', testMatch: /auth\.setup\.ts/ },
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'], storageState: 'test-results/.auth/owner.json' },
            dependencies: ['setup'],
        },
    ],
    webServer: {
        // Laravel's router script expects to run from public/ (like `artisan serve`).
        command: `"${PHP}" -S 127.0.0.1:${PORT} -t . ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php`,
        cwd: 'public',
        // A static file: the database is migrated by globalSetup, which runs after the server starts.
        url: `${SERVER_URL}/robots.txt`,
        reuseExistingServer: false,
        timeout: 120_000,
        env: E2E_ENV,
    },
});
