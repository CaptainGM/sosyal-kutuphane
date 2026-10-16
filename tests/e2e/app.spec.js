// Oturum açmış kullanıcı akışları. Veritabanı gerektirir: CI'de MongoDB servisi ve demo hesaplar ile çalışır.
const { test, expect } = require('@playwright/test');

async function loginAs(page, email) {
    await page.goto('/index.html');
    await page.fill('#loginEmail', email);
    await page.fill('#loginPassword', '123456');
    await page.click('#loginSubmitBtn');
    await page.waitForURL('**/search.html');
}

test.describe('oturum açmış kullanıcı', () => {
    test('giriş sonrası arama sayfası ve bildirim zili açılır', async ({ page }) => {
        await loginAs(page, 'ahmet@test.com');

        await expect(page.locator('#notifBellBtn')).toBeVisible();
        await expect(page.locator('.logout-btn')).toHaveText('Çıkış Yap');
    });

    test('rozet API\'si oturum açmış kullanıcı için JSON döner', async ({ page }) => {
        await loginAs(page, 'ahmet@test.com');

        const response = await page.request.get('/api/badges.php');
        const body = await response.json();

        expect(response.ok()).toBeTruthy();
        expect(body.success).toBe(true);
        expect(body.badges.length).toBeGreaterThan(0);
        expect(body.badges[0]).toHaveProperty('unlocked');
    });

    test('canlı akış açıkken diğer istekler bloklanmaz', async ({ page, request }) => {
        // Yalnızca ENABLE_SSE=1 ve çok süreçli sunucuda anlamlıdır (CI bunu ayarlar).
        test.skip(process.env.E2E_EXPECT_SSE !== '1', 'SSE yalnızca çok süreçli CI sunucusunda test edilir');
        await loginAs(page, 'ahmet@test.com');

        const opened = await page.evaluate(async () => {
            const res = await fetch('/api/stream.php', { credentials: 'include' });
            if (!res.ok || !res.body) return { status: res.status, text: '' };
            const reader = res.body.getReader();
            const decoder = new TextDecoder();
            let text = '';
            // İlk parçada yalnızca "retry" satırı gelebilir; "counts" olayını görene kadar oku.
            for (let i = 0; i < 5 && !text.includes('event: counts'); i++) {
                const { value, done } = await reader.read();
                if (done) break;
                text += decoder.decode(value, { stream: true });
            }
            window.__streamReader = reader;
            return { status: res.status, text };
        });

        expect(opened.status).toBe(200);
        expect(opened.text).toContain('event: counts');

        // Akış hâlâ açıkken ayrı bir bağlantıyla sayfa 2 saniyeden kısa sürede dönmeli.
        const started = Date.now();
        const response = await request.get('/index.html');
        const elapsed = Date.now() - started;

        expect(response.status()).toBe(200);
        expect(elapsed).toBeLessThan(2000);

        await page.evaluate(() => window.__streamReader && window.__streamReader.cancel());
    });
});
