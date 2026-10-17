// Erişilebilirlik (oturum gerektiren sayfalar): WCAG 2 AA ciddi ve kritik ihlal olmamalı (axe-core).
// Demo hesapla (ahmet@test.com) giriş yapılır; yönetim konsolu için testin kendi yönetici hesabı
// (scripts/e2e-user.php) oluşturulur ve test bitince silinir. Giriş yaptığı için canlı duman testinde çalıştırılmaz.
const { test, expect } = require('@playwright/test');
const { createUser, deleteUsers, loginAs: loginUser } = require('./helpers');
const { expectNoSeriousViolations } = require('./a11y-helpers');

let admin;
test.beforeAll(() => { admin = createUser('a11y_admin', { admin: true }); });
test.afterAll(() => { deleteUsers(admin); });

const userPages = [
    { name: 'ne izlesem', path: '/tonight.html', ready: '#rollBtn' },
    { name: 'akış', path: '/feed.html', ready: '#feedContainer :is(.act-card, .empty, .error-message, .cv-list)' },
    { name: 'sosyal', path: '/social.html', ready: '#matchesContainer :is(.match-grid, .empty, .error-message)' },
    { name: 'profilim', path: '/profile.html', ready: '#profileContent:not([style*="none"])' },
    { name: 'mesajlar', path: '/messages.html', ready: '#convFilter' },
    { name: 'yıllık özet', path: '/wrapped.html', ready: '#wrappedContent:not([hidden])' },
];

async function loginAs(page, email, password) {
    await page.goto('/index.html');
    await page.fill('#loginEmail', email);
    await page.fill('#loginPassword', password);
    await page.click('#loginSubmitBtn');
    await page.waitForURL('**/search.html');
}

for (const scheme of ['light', 'dark']) {
    test.describe(`${scheme} tema`, () => {
        test.use({ colorScheme: scheme });

        for (const pageInfo of userPages) {
            test(`${pageInfo.name} (oturumlu): WCAG 2 AA ciddi ihlali yok`, async ({ page }) => {
                await loginAs(page, 'ahmet@test.com', '123456');
                await page.goto(pageInfo.path);
                await expect(page.locator(pageInfo.ready).first()).toBeVisible({ timeout: 20000 });
                await expectNoSeriousViolations(page);
            });
        }

        test('başka bir kullanıcının profili (oturumlu): WCAG 2 AA ciddi ihlali yok', async ({ page }) => {
            await loginAs(page, 'ahmet@test.com', '123456');
            const found = await page.evaluate(async () => {
                await app.initPromise;
                const response = await fetch('/api/search-users.php?q=ayse', { credentials: 'include' });
                const data = await response.json();
                return data.users && data.users[0] ? data.users[0].id : null;
            });
            test.skip(!found, 'Demo kullanıcı "ayse" bulunamadı');
            await page.goto(`/user-profile.html?id=${found}`);
            await expect(page.locator('#profileContent:not([hidden])')).toBeVisible({ timeout: 20000 });
            // Zevk uyumu raporu ağdan gelir; kontrol edilmeden önce görünür olmalı.
            await expect(page.locator('#compatSection:not([hidden])')).toBeVisible({ timeout: 20000 });
            await expectNoSeriousViolations(page);
        });

        test('yönetim konsolu (yönetici): WCAG 2 AA ciddi ihlali yok', async ({ page }) => {
            await loginUser(page, admin);
            await page.goto('/admin.html');
            await expect(page.locator('.stat-grid--admin')).toBeVisible({ timeout: 15000 });
            await expectNoSeriousViolations(page);

            // Kullanıcılar sekmesi ve bir ayrıntı penceresi de aynı kuralları sağlamalı
            await page.click('[data-tab=users]');
            await expect(page.locator('#listArea tbody tr').first()).toBeVisible({ timeout: 15000 });
            await expectNoSeriousViolations(page);
            await page.locator('#listArea tbody tr').first().click();
            await expect(page.locator('.modal-box .detail-grid')).toBeVisible({ timeout: 15000 });
            await expectNoSeriousViolations(page);
        });
    });
}
