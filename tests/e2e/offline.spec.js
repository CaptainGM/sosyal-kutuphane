// Servis çalışanı: çevrimdışı sayfası yalnızca sunucuya gerçekten ulaşılamadığında gösterilir.
const { test, expect } = require('@playwright/test');

test.use({ serviceWorkers: 'allow' });

async function waitForControl(page) {
    await page.goto('/index.html');
    await page.evaluate(() => navigator.serviceWorker.ready);
    // İlk yüklemede sayfa henüz denetlenmez; yenileyince servis çalışanı devreye girer.
    await page.reload();
    await expect.poll(() => page.evaluate(() => !!navigator.serviceWorker.controller)).toBe(true);
}

test('çevrimiçiyken sayfalar normal açılır, çevrimdışı sayfası görünmez', async ({ page }) => {
    await waitForControl(page);
    await page.goto('/search.html');
    await expect(page.locator('#offlineTitle')).toHaveCount(0);
    await expect(page.locator('.nav-link').first()).toBeVisible();
});

test('bağlantı kopunca dürüst bir çevrimdışı sayfası gelir ve bağlantı dönünce asıl sayfa açılır', async ({ page, context }) => {
    await waitForControl(page);
    await context.setOffline(true);
    await page.goto('/search.html').catch(() => {});
    await expect(page.locator('#offlineTitle')).toHaveText('Çevrimdışısın');

    await context.setOffline(false);
    // Sayfa 'online' olayında kendiliğinden yenilenir.
    await expect(page.locator('#offlineTitle')).toHaveCount(0, { timeout: 15000 });
    await expect(page).toHaveURL(/search\.html/);
});
