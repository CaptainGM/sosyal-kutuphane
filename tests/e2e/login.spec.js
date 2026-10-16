// Giriş ekranı: demo girişleri, hareket azaltma davranışı ve erişilebilirlik (veritabanı gerekmez).
const { test, expect } = require('@playwright/test');

test.describe('giriş ekranı', () => {
    test('demo girişleri: yönetici düğmesi yok, diğer demo hesaplar var', async ({ page }) => {
        await page.goto('/index.html');

        await expect(page.locator('.demo-chip')).toHaveCount(3);
        await expect(page.locator('.demo-chip', { hasText: 'Admin' })).toHaveCount(0);
        await expect(page.locator('.demo-chip', { hasText: 'Ahmet' })).toHaveCount(1);
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
