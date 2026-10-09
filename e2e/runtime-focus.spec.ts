import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';

// Real browser focus/inert behavior cannot be verified by a mocked DOM.
for (const mode of ['pagination', 'none']) {
    test(`keyboard slide change retains focus when arrows are hidden: ${mode}`, async ({ page }) => {
        await page.setContent(`<style>.arrows,.hidden{display:none}</style>
            <section data-arkon-slider="test" data-autoplay="false" data-transition="none" aria-label="Examples">
              <div class="ak-slider__slides"><div data-active="true"><a href="#first">First slide action</a></div><div><a href="#second">Second slide action</a></div></div>
              <div class="ak-slider__controls"><button class="arrows" data-slide-step="-1">Previous</button><button class="arrows" data-slide-step="1">Next</button></div>
              <div class="ak-slider__pagination ${mode === 'none' ? 'hidden' : ''}"><button data-slide-index="0">One</button><button data-slide-index="1">Two</button></div>
              <span data-slide-status></span>
            </section>`);
        await page.addScriptTag({ content: readFileSync('public/_arkon/components-5.js', 'utf8') });
        await page.getByRole('link', { name: 'First slide action' }).focus();
        await page.keyboard.press('ArrowRight');
        await expect(page.getByRole('link', { name: 'Second slide action' })).toBeVisible();
        if (mode === 'pagination') await expect(page.getByRole('button', { name: 'One', exact: true })).toBeFocused();
        else await expect(page.locator('[data-arkon-slider]')).toBeFocused();
        expect(await page.evaluate(() => document.activeElement?.closest('[inert]') === null)).toBe(true);
    });
}
