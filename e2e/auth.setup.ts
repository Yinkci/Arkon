import { test as setup } from '@playwright/test';
import { login } from './support';
import { AUTH_STATE } from './env';

// Signs in once and shares the session: sign-in is rate limited on purpose.
setup('sign in', async ({ page }) => {
    await login(page);
    await page.context().storageState({ path: AUTH_STATE });
});
