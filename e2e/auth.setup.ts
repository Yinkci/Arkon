import { test as setup } from '@playwright/test';
import { login } from './support';

// Signs in once and shares the session: sign-in is rate limited on purpose.
setup('sign in', async ({ page }) => {
    await login(page);
    await page.context().storageState({ path: 'test-results/.auth/owner.json' });
});
