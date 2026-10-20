// Destek düğmesi (sol alt): talep, yönetici ile yazışma, misafir formu, bildirimler ve erişilebilirlik.
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
const GUEST_EMAIL = `ziyaretci_${TOKEN}@ornek.com`;
const GUEST_MESSAGE = `Doğrulama kodu e-postası gelmiyor (${TOKEN}), spam klasörüne de baktım.`;

test('ziyaretçi destek formunu açıp talep gönderir; yönetim sayfasında düğme yoktur', async ({ page, browser }, testInfo) => {
    await page.goto('/search.html');
    await page.evaluate(() => app.initPromise);
    const fab = page.locator('.support--guest .support-fab');
    await expect(fab).toBeVisible();
    await fab.click();
    await expect(page.locator('.support-panel')).toBeVisible();
    await expect(page.locator('#supportEmail')).toBeVisible();
    await expect(page.locator('.support-tabs')).toBeHidden(); // "Taleplerim" yalnızca üyeler için
    await expect(page.locator('.support-hp')).not.toBeInViewport(); // bal küpü insana görünmez
    await expect(page.locator('.support-privacy a')).toHaveAttribute('href', 'gizlilik.html');

    await page.click('.support-submit');
    await expect(page.locator('.support-error')).toContainText('e-posta');
    await page.fill('#supportEmail', GUEST_EMAIL);
    await page.click('.support-topic:has-text("Hesap veya giriş sorunu")');
    await page.fill('#supportMessage', GUEST_MESSAGE);
    // Sunucu formun en az 3 sn açık kalmasını ister (botları yavaşlatır).
    await page.waitForTimeout(3300);
    await page.click('.support-submit');
    await expect(page.locator('.support-done')).toBeVisible();
    await expect(page.locator('.support-done')).toContainText('e-posta');

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
    await expect(page.locator('#supportEmail')).toHaveCount(0); // üyede e-posta alanı yok

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

test('yönetici ile kullanıcı yazışır; çözülünce kullanıcı bildirimi görür; misafir talebine e-posta ile yanıt verilir', async ({ browser }, testInfo) => {
    test.skip(testInfo.project.name !== 'chromium', 'Yalnızca varsayılan projede çalışır');

    const adminPage = await (await browser.newContext({ viewport: { width: 1290, height: 900 } })).newPage();
    await login(adminPage, admin);
    await adminPage.goto('/admin.html#tickets');
    const card = adminPage.locator('.report-card', { hasText: TOKEN }).filter({ hasText: 'Hesap veya giriş sorunu' }).filter({ hasNotText: GUEST_EMAIL });
    await expect(card).toContainText('Çözülmedi');
    await expect(card).toContainText('Yanıt bekliyor'); // henüz kimse yanıtlamadı
    await expect(adminPage.locator('#count-tickets')).not.toHaveText('');

    // Yönetici yanıtlar (talep açık kalır)
    await card.locator('textarea').fill(`Hangi tarayıcıyı kullanıyorsun? ${TOKEN}`);
    await card.getByRole('button', { name: 'Yanıtla', exact: true }).click();
    await expect(adminPage.locator('.toast-success', { hasText: 'Yanıt gönderildi' })).toBeVisible();
    await expect(card.locator('.ticket-bubble--admin')).toContainText('Hangi tarayıcıyı kullanıyorsun?');
    await expect(card).not.toContainText('Yanıt bekliyor');

    // Kullanıcı: zilde "yanıt geldi", tıklayınca konuşma açılır, kullanıcı da yanıt yazar
    const userPage = await (await browser.newContext({ viewport: { width: 1290, height: 900 } })).newPage();
    await login(userPage, user);
    await expect(userPage.locator('#notifBadge')).toHaveClass(/visible/);
    await userPage.click('#notifBellBtn');
    const replyNotice = userPage.locator('.nav-panel-item[data-type="ticket_reply"]').first();
    await expect(replyNotice).toContainText('Destek talebine yanıt geldi');
    await replyNotice.click();
    await expect(userPage.locator('.support-panel')).toBeVisible();
    const mine = userPage.locator('.support-ticket', { hasText: TOKEN });
    await expect(mine.locator('.support-bubble--admin')).toContainText('Hangi tarayıcıyı kullanıyorsun?');
    await mine.getByRole('button', { name: 'Yanıt yaz' }).click();
    await mine.locator('.support-reply textarea').fill(`Edge kullanıyorum ${TOKEN}`);
    await mine.getByRole('button', { name: 'Gönder' }).click();
    await expect(mine.locator('.support-bubble--user')).toContainText('Edge kullanıyorum');

    // Yönetici: yeni yanıt gelince "Yanıt bekliyor" geri gelir, "Yanıtla ve çöz" ile kapatır
    await adminPage.reload();
    const again = adminPage.locator('.report-card', { hasText: TOKEN }).filter({ hasText: 'Hesap veya giriş sorunu' }).filter({ hasNotText: GUEST_EMAIL });
    await expect(again).toContainText('Yanıt bekliyor');
    await expect(again.locator('.ticket-bubble--user')).toContainText('Edge kullanıyorum');
    await again.locator('textarea').fill(`Sorun giderildi, tekrar dene ${TOKEN}`);
    await again.getByRole('button', { name: 'Yanıtla ve çöz' }).click();
    await expect(adminPage.locator('.toast-success', { hasText: 'talep çözüldü' })).toBeVisible();
    await expect(adminPage.locator('.report-card', { hasText: TOKEN }).filter({ hasText: 'Hesap veya giriş sorunu' }).filter({ hasNotText: GUEST_EMAIL })).toHaveCount(0); // "Çözülmeyenler"den düştü

    await adminPage.click('[data-act=ticket-filter][data-value=resolved]');
    const resolved = adminPage.locator('.report-card', { hasText: TOKEN }).filter({ hasText: 'Hesap veya giriş sorunu' }).filter({ hasNotText: GUEST_EMAIL });
    await expect(resolved).toContainText('Çözüldü');
    await expect(resolved).toContainText(`Sorun giderildi, tekrar dene ${TOKEN}`);

    // Kullanıcı: çözüldü bildirimi ve konuşma
    await userPage.reload();
    await userPage.click('#notifBellBtn');
    const resolvedNotice = userPage.locator('.nav-panel-item[data-type="ticket"]').first();
    await expect(resolvedNotice).toContainText('Destek talebin');
    await resolvedNotice.click();
    const mine2 = userPage.locator('.support-ticket', { hasText: TOKEN });
    await expect(mine2).toContainText('Çözüldü');
    await expect(mine2.locator('.support-bubble--admin').last()).toContainText('Sorun giderildi, tekrar dene');
    await expect(userPage.locator('.support-count')).toBeHidden(); // çözülmemiş talep kalmadı

    // Yönetici: yönetici bildirimi (zil) yeni talep ve yanıtlar için gelir
    await adminPage.reload();
    await adminPage.click('#notifBellBtn');
    await expect(adminPage.locator('.nav-panel-item[data-type="ticket_admin"]').first()).toContainText('Destek');

    // Misafir talebi: e-posta ve "Misafir" etiketi görünür; yanıt kaydedilir (e-posta ayarı olmayan ortamda gönderilemez)
    await adminPage.goto('/admin.html#tickets');
    const guestCard = adminPage.locator('.report-card', { hasText: GUEST_EMAIL });
    await expect(guestCard).toContainText('Misafir');
    await expect(guestCard).toContainText(GUEST_MESSAGE);
    await guestCard.locator('textarea').fill('Spam klasörüne baktınız mı? Yeni kod gönderdik.');
    await guestCard.getByRole('button', { name: 'Yanıtla', exact: true }).click();
    await expect(adminPage.locator('.toast', { hasText: /Yanıt (gönderildi|kaydedildi)/ })).toBeVisible();
    await expect(guestCard.locator('.ticket-bubble--admin')).toContainText('Spam klasörüne baktınız mı?');

    // Yönetici talebi tekrar "çözülmedi" yapar
    await adminPage.click('[data-act=ticket-filter][data-value=resolved]');
    await adminPage.locator('.report-card', { hasText: TOKEN }).filter({ hasText: 'Hesap veya giriş sorunu' }).filter({ hasNotText: GUEST_EMAIL }).locator('[data-act="ticket-reopen"]').click();
    await expect(adminPage.locator('.toast-success', { hasText: 'çözülmedi olarak işaretlendi' })).toBeVisible();

    await userPage.context().close();
    await adminPage.context().close();
});

for (const scheme of ['light', 'dark']) {
    test.describe(`${scheme} tema`, () => {
        test.use({ colorScheme: scheme });

        test(`destek paneli (form, konuşma ve misafir formu): WCAG 2 AA ciddi ihlali yok (${scheme})`, async ({ page }) => {
            await login(page, user);
            await page.click('.support-fab');
            await expect(page.locator('.support-panel')).toBeVisible();
            await expectNoSeriousViolations(page);

            await page.click('#supportTabList');
            await expect(page.locator('.support-ticket').first()).toBeVisible();
            await page.locator('.support-reply-toggle').first().click();
            await expectNoSeriousViolations(page);

            // Ziyaretçi formu
            const guestContext = await page.context().browser().newContext({ colorScheme: scheme });
            const guestPage = await guestContext.newPage();
            await guestPage.goto('/search.html');
            await guestPage.click('.support-fab');
            await expect(guestPage.locator('#supportEmail')).toBeVisible();
            await expectNoSeriousViolations(guestPage);
            await guestContext.close();
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
