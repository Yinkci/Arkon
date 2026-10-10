import { defineConfig, devices } from '@playwright/test';

// Smoke test of the running app through Laravel Herd (http://arkonlaravel.test, dev database).
// Read-only: signs in and looks around, never saves or publishes. Kept apart from the main suite
// (playwright.config.ts at the project root), which starts its own server and resets the e2e database.
// Needs an existing account:
//   $env:HERD_EMAIL="..."; $env:HERD_PASSWORD="..."; npm run test:herd
export default defineConfig({
    testDir: '.',
    outputDir: '../../storage/playwright',
    workers: 1,
    retries: 0,
    reporter: [['list']],
    use: { ...devices['Desktop Chrome'], baseURL: process.env.HERD_URL ?? 'http://arkonlaravel.test', trace: 'retain-on-failure' },
});
