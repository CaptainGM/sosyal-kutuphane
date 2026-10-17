// Giriş ekranı: demo girişleri, hareket azaltma davranışı ve erişilebilirlik (veritabanı gerekmez).
const { test, expect } = require('@playwright/test');

test.describe('giriş ekranı', () => {
    test('demo girişleri: yönetici düğmesi yok, diğer demo hesaplar var', async ({ page }) => {
        await page.goto('/index.html');

        await expect(page.locator('.demo-chip')).toHaveCount(3);
        await expect(page.locator('.demo-chip', { hasText: 'Admin' })).toHaveCount(0);
        await expect(page.locator('.demo-chip', { hasText: 'Ahmet' })).toHaveCount(1);
    });

    test('sağ üstteki tema düğmesi temayı değiştirir ve hatırlar; hareket düğmesi eklenmez', async ({ page }) => {
        await page.goto('/index.html');
        const html = page.locator('html');
        const before = await html.evaluate(el => el.getAttribute('data-theme') || (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'));
        await page.click('.auth-corner .theme-toggle-btn');
        const expected = before === 'dark' ? 'light' : 'dark';
        await expect(html).toHaveAttribute('data-theme', expected);
        await page.reload();
        await expect(html).toHaveAttribute('data-theme', expected);
        await expect(page.locator('.auth-corner .motion-toggle-btn')).toHaveCount(0);
    });

    test('dar ekranda sayfa yatay kaymaz', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 800 });
        await page.goto('/index.html');
        await page.waitForSelector('.ring .poster');
        await page.waitForTimeout(800);
        expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(1);
    });


    test.describe('hareket azaltma tercihi açıkken', () => {
        test.use({ reducedMotion: 'reduce' });

        test('3D sahne oluşturulmaz, form yine görünür', async ({ page }) => {
            await page.goto('/index.html');

            await expect(page.locator('#loginForm')).toBeVisible();
            await expect(page.locator('#scene3d')).toHaveCount(0);
        });
    });
});
