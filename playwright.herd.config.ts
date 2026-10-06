import { defineConfig, devices } from '@playwright/test';

// Smoke test of the running app through Laravel Herd (http://arkonlaravel.test, dev database).
// Read-only: signs in and looks around, never saves or publishes. Needs an account:
//   HERD_EMAIL=... HERD_PASSWORD=... npm run test:herd
export default defineConfig({
    testDir: './e2e-herd',
    workers: 1,
    retries: 0,
    reporter: [['list']],
    use: { ...devices['Desktop Chrome'], baseURL: process.env.HERD_URL ?? 'http://arkonlaravel.test', trace: 'retain-on-failure' },
});
