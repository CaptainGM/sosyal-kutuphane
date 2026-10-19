// Destek düğmesi (sol alt): talep oluşturma, Taleplerim, yönetici çözümü, bildirim ve erişilebilirlik.
// Testin kendi geçici kullanıcılarıyla (kullanıcı, yönetici) çalışır; veri testler arasında paylaşılır (serial).
const { test, expect } = require('@playwright/test');
const { createUser, deleteUsers } = require('./helpers');
const { expectNoSeriousViolations } = require('./a11y-helpers');

test.describe.configure({ mode: 'serial' });

let user;
let admin;
test.beforeAll(() => {
    user = createUser('destek');
    admin = createUser('destekadm', { admin: true });
});
test.afterAll(() => { deleteUsers(user, admin); });

// Giriş API'den yapılır ve hafif bir sayfaya gidilir: giriş sonrası açılan Keşfet sayfası onlarca TMDB isteği başlatır;
// yavaş bir ağda bunlar PHP sunucusunun süreçlerini tutar ve bu testin istekleri sıra bekler.
async function login(page, account, path = '/profile.html') {
    const response = await page.request.post('/api/login.php', { data: { email: account.email, password: account.password } });
    expect(response.ok()).toBeTruthy();
    await page.goto(path);
}

// Her çalıştırmada benzersiz metin: aynı veritabanında önceki çalıştırmalarla karışmaz.
const TOKEN = Math.random().toString(36).slice(2, 8);
const MESSAGE = `Detay sayfasında afiş yüklenmiyor (${TOKEN}), sayfayı yenilemek de işe yaramadı.`;

test('ziyaretçide ve yönetim sayfasında destek düğmesi yoktur', async ({ page, browser }, testInfo) => {
    await page.goto('/tonight.html');
    await page.evaluate(() => app.initPromise);
    await expect(page.locator('.support-fab')).toHaveCount(0);

    test.skip(testInfo.project.name !== 'chromium', 'Yönetici kısmı yalnızca varsayılan projede çalışır');
    const adminPage = await (await browser.newContext({ viewport: { width: 1290, height: 900 } })).newPage();
    await login(adminPage, admin);
    await adminPage.goto('/admin.html');
    await expect(adminPage.locator('.stat-grid--admin')).toBeVisible();
    await expect(adminPage.locator('.support-fab')).toHaveCount(0);
    await adminPage.context().close();
});

test('kullanıcı sol alttaki düğmeden talep oluşturur ve Taleplerim sekmesinde görür', async ({ page }) => {
    await login(page, user);
    const fab = page.locator('.support-fab');
    await expect(fab).toBeVisible();

    // Sol altta durur
    const box = await fab.boundingBox();
    const viewport = page.viewportSize();
    expect(box.x).toBeLessThan(40);
    expect(viewport.height - (box.y + box.height)).toBeLessThan(40);

    await fab.click();
    await expect(page.locator('.support-panel')).toBeVisible();
    await expect(fab).toHaveAttribute('aria-expanded', 'true');

    // Çok kısa mesaj gönderilmez
    await page.fill('#supportMessage', 'kısa');
    await page.click('.support-submit');
    await expect(page.locator('.support-error')).toContainText('en az 10 karakter');

    await page.click('.support-topic:has-text("Hesap veya giriş sorunu")');
    await page.fill('#supportMessage', MESSAGE);
    await expect(page.locator('.support-counter')).toContainText(`${MESSAGE.length} / 2000`);
    await page.click('.support-submit');
    await expect(page.locator('.support-done')).toBeVisible();

    await page.click('.support-done [data-go="list"]');
    const ticket = page.locator('.support-ticket', { hasText: TOKEN });
    await expect(ticket).toContainText('Çözülmedi');
    await expect(ticket).toContainText('Hesap veya giriş sorunu');
    await expect(page.locator('.support-count')).toHaveText('1');

    // Escape kapatır ve odağı düğmeye geri verir
    await page.keyboard.press('Escape');
    await expect(page.locator('.support-panel')).toBeHidden();
    await expect(fab).toBeFocused();
    await expect(fab).toHaveAttribute('aria-expanded', 'false');
});

test('yazılan taslak sayfa değişince kaybolmaz', async ({ page }) => {
    await login(page, user);
    await page.click('.support-fab');
    await page.fill('#supportMessage', `Yarım kalan mesaj ${TOKEN}`);
    await page.goto('/feed.html');
    await page.click('.support-fab');
    await expect(page.locator('#supportMessage')).toHaveValue(`Yarım kalan mesaj ${TOKEN}`);
    await page.fill('#supportMessage', '');
});

test('yönetici talebi çözüldü olarak işaretler; kullanıcı durumu, notu ve bildirimi görür', async ({ browser }, testInfo) => {
    test.skip(testInfo.project.name !== 'chromium', 'Yalnızca varsayılan projede çalışır');

    const adminPage = await (await browser.newContext({ viewport: { width: 1290, height: 900 } })).newPage();
    await login(adminPage, admin);
    await adminPage.goto('/admin.html#tickets');
    const card = adminPage.locator('.report-card', { hasText: TOKEN });
    await expect(card).toContainText('Çözülmedi');
    await expect(card).toContainText('Hesap veya giriş sorunu');
    await expect(adminPage.locator('#count-tickets')).not.toHaveText('');

    await card.locator('[data-act="ticket-resolve"]').click();
    await adminPage.fill('.sk-dialog textarea', `Düzeltildi ${TOKEN}`);
    await adminPage.click('.sk-dialog button[type=submit]');
    await expect(adminPage.locator('.toast-success', { hasText: 'çözüldü olarak işaretlendi' })).toBeVisible();
    await expect(adminPage.locator('.report-card', { hasText: TOKEN })).toHaveCount(0); // "Çözülmeyenler" listesinden düştü

    await adminPage.click('[data-act=ticket-filter][data-value=resolved]');
    const resolved = adminPage.locator('.report-card', { hasText: TOKEN });
    await expect(resolved).toContainText('Çözüldü');
    await expect(resolved).toContainText(`Düzeltildi ${TOKEN}`);

    // Kullanıcı: bildirim gelir, tıklayınca Taleplerim açılır, durum ve not görünür
    const userPage = await (await browser.newContext({ viewport: { width: 1290, height: 900 } })).newPage();
    await login(userPage, user);
    await expect(userPage.locator('#notifBadge')).toHaveClass(/visible/);
    await userPage.click('#notifBellBtn');
    const notification = userPage.locator('.nav-panel-item[data-type="ticket"]').first();
    await expect(notification).toContainText('Destek talebin');
    await notification.click();
    await expect(userPage.locator('.support-panel')).toBeVisible();
    await expect(userPage.locator('#supportTabList')).toHaveAttribute('aria-selected', 'true');
    const mine = userPage.locator('.support-ticket', { hasText: TOKEN });
    await expect(mine).toContainText('Çözüldü');
    await expect(mine).toContainText(`Yönetici notu: Düzeltildi ${TOKEN}`);
    await expect(userPage.locator('.support-count')).toBeHidden(); // çözülmemiş talep kalmadı

    // Yönetici tekrar "çözülmedi" yapar
    await adminPage.locator('.report-card', { hasText: TOKEN }).locator('[data-act="ticket-reopen"]').click();
    await expect(adminPage.locator('.toast-success', { hasText: 'çözülmedi olarak işaretlendi' })).toBeVisible();
    await adminPage.click('[data-act=ticket-filter][data-value=open]');
    await expect(adminPage.locator('.report-card', { hasText: TOKEN })).toContainText('Çözülmedi');

    await userPage.context().close();
    await adminPage.context().close();
});

for (const scheme of ['light', 'dark']) {
    test.describe(`${scheme} tema`, () => {
        test.use({ colorScheme: scheme });

        test(`destek paneli (form ve liste): WCAG 2 AA ciddi ihlali yok (${scheme})`, async ({ page }) => {
            await login(page, user);
            await page.click('.support-fab');
            await expect(page.locator('.support-panel')).toBeVisible();
            await expectNoSeriousViolations(page);

            await page.click('#supportTabList');
            await expect(page.locator('.support-ticket').first()).toBeVisible();
            await expectNoSeriousViolations(page);
        });
    });
}

test('telefonda panel ekrana sığar, yatay kaydırma yapmaz', async ({ browser }, testInfo) => {
    test.skip(testInfo.project.name !== 'chromium', 'Yalnızca varsayılan projede çalışır');
    const context = await browser.newContext({ viewport: { width: 390, height: 800 }, isMobile: true, hasTouch: true });
    const page = await context.newPage();
    await login(page, user);
    await page.click('.support-fab');
    const panel = page.locator('.support-panel');
    await expect(panel).toBeVisible();
    const box = await panel.boundingBox();
    expect(box.x).toBeGreaterThanOrEqual(0);
    expect(box.x + box.width).toBeLessThanOrEqual(390);
    expect(box.y).toBeGreaterThanOrEqual(0);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    await context.close();
});
