// Yönetim konsolu: yetki, her sekme, silme/geri yükleme, şikâyet çözme, askıya alma, susturma ve işlem günlüğü.
// Testin kendi geçici kullanıcılarıyla (yönetici, yazar, şikâyetçi) çalışır; veri testler arasında paylaşılır (serial).
const { test, expect } = require('@playwright/test');
const { createUser, deleteUsers, loginAs, api } = require('./helpers');

test.describe.configure({ mode: 'serial' });

let admin;
let author;
let reporter;
let spoilerComment;
let plainComment;

test.beforeAll(() => {
    admin = createUser('adm', { admin: true });
    author = createUser('author');
    reporter = createUser('reporter');
});
test.afterAll(() => { deleteUsers(admin, author, reporter); });
test.beforeEach(async ({}, testInfo) => { test.skip(testInfo.project.name !== 'chromium', 'Yalnızca varsayılan projede çalışır'); });

const MOVIE_ID = 800000 + Math.floor(Math.random() * 99999);
// Her çalıştırmada benzersiz metin: aynı veritabanında önceki çalıştırmaların yorumlarıyla karışmaz.
const TOKEN = Math.random().toString(36).slice(2, 8);

async function adminPage(browser) {
    const context = await browser.newContext({ viewport: { width: 1290, height: 900 } });
    const page = await context.newPage();
    await loginAs(page, admin);
    return page;
}

test('veri hazırlanır; yönetici olmayan konsola giremez', async ({ browser }) => {
    const authorPage = await (await browser.newContext()).newPage();
    const reporterPage = await (await browser.newContext()).newPage();
    await loginAs(authorPage, author);
    await loginAs(reporterPage, reporter);

    spoilerComment = await api(authorPage, 'POST', '/api/comments.php', { content_type: 'movie', content_id: MOVIE_ID, comment_text: `GİZLİ-BULGU ${TOKEN} Neo sonunda ölüyor`, has_spoiler: true });
    plainComment = await api(authorPage, 'POST', '/api/comments.php', { content_type: 'movie', content_id: MOVIE_ID, comment_text: `İlk metin: ${TOKEN} sıradan bir yorum` });
    await api(authorPage, 'PUT', '/api/comments.php', { comment_id: plainComment.id, comment_text: `Düzenlenmiş metin: ${TOKEN} sıradan bir yorum` });
    await api(authorPage, 'POST', '/api/comments.php', { content_type: 'movie', content_id: MOVIE_ID, comment_text: 'Bu bir yanıt', parent_comment_id: spoilerComment.id });
    await api(reporterPage, 'POST', '/api/report-comment.php', { comment_id: plainComment.id, reason: 'Hakaret içeriyor (deneme)' });
    await api(reporterPage, 'POST', '/api/report-comment.php', { comment_id: spoilerComment.id, reason: 'Spoiler uyarısı yok (deneme)' });
    expect(spoilerComment.success && plainComment.success).toBeTruthy();

    // Yönetici olmayan: konsol açılmaz, menüde bağlantı yok
    await authorPage.goto('/admin.html');
    await expect(authorPage.locator('#adminGate.empty')).toContainText('erişimin yok');
    await expect(authorPage.locator('#navAdminLink')).toBeHidden();
    await authorPage.context().close();
    await reporterPage.context().close();
});

test('genel bakış: sayaçlar, 14 günlük grafikler ve bekleyen şikâyet göstergesi', async ({ browser }) => {
    const page = await adminPage(browser);
    await page.goto('/admin.html');
    await expect(page.locator('#navAdminLink')).toBeVisible();
    await expect(page.locator('.stat-grid--admin .stat').nth(9)).toBeVisible();
    await expect(page.locator('.mini-bars')).toHaveCount(3);
    await expect(page.locator('.mini-bar')).toHaveCount(42);
    await expect(page.locator('#count-reports')).not.toHaveText('');
    await page.context().close();
});

test('kullanıcılar: arama, ayrıntı (e-posta, spoiler dahil yorumlar, girişler) ve Escape', async ({ browser }) => {
    const page = await adminPage(browser);
    await page.goto('/admin.html#users');
    await page.fill('#userQuery', author.username);
    await expect(page.locator('tr[data-act=open-user]')).toHaveCount(1);
    await page.click('tr button[data-act=open-user]');
    await expect(page.locator('.modal-box .detail-grid')).toBeVisible();
    await expect(page.locator('.modal-box')).toContainText(author.email);
    await expect(page.locator('.modal-box')).toContainText(`GİZLİ-BULGU ${TOKEN}`);
    await expect(page.locator('.modal-box')).toContainText('Son girişler');
    await page.keyboard.press('Escape');
    await expect(page.locator('.modal-box')).toHaveCount(0);
    await page.context().close();
});

test('yorumlar: spoiler tam metin, düzenleme geçmişi, silme (gerekçeyle), arşiv ve geri yükleme', async ({ browser }) => {
    const page = await adminPage(browser);
    await page.goto('/admin.html#comments');
    await page.fill('#commentQuery', author.username);
    await page.click('[data-act=comment-filter][data-value=spoiler]');
    await expect(page.locator('tbody')).toContainText(`GİZLİ-BULGU ${TOKEN}`);

    await page.click('[data-act=comment-filter][data-value=edited]');
    await page.fill('#commentQuery', `Düzenlenmiş metin: ${TOKEN}`);
    // Eski liste (önceki süzgeç) tek satırlıysa sayım yanıltır: satırın beklenen yorum olduğu doğrulanır.
    await expect(page.locator('tbody tr')).toHaveCount(1);
    await expect(page.locator('tbody tr')).toContainText(`Düzenlenmiş metin: ${TOKEN}`);
    await page.click('tr button[data-act=open-comment]');
    await expect(page.locator('.version')).toHaveCount(2);
    await expect(page.locator('.versions')).toContainText(`İlk metin: ${TOKEN}`);
    await expect(page.locator('.modal-box')).toContainText('Hakaret içeriyor');
    await expect(page.locator('.modal-box')).toContainText(author.username);

    await page.click('.modal-box [data-act=delete-comment]');
    await page.fill('.sk-dialog textarea', 'Deneme gerekçesi');
    await page.click('.sk-dialog button[type=submit]');
    await expect(page.locator('.modal-box')).toHaveCount(0);

    await page.click('[data-tab=deleted]');
    await expect(page.locator('tbody')).toContainText('Deneme gerekçesi');
    await expect(page.locator('tbody')).toContainText(`Düzenlenmiş metin: ${TOKEN}`);
    await page.locator('[data-act=restore]').first().click();
    await page.click('.confirm-box .btn-danger');
    await expect(page.locator('.toast-success', { hasText: 'geri yüklendi' })).toBeVisible();

    const authorPage = await (await browser.newContext()).newPage();
    await loginAs(authorPage, author);
    const list = await api(authorPage, 'GET', `/api/comments.php?content_type=movie&content_id=${MOVIE_ID}`);
    expect(list.comments.some(c => c.comment_text.includes(`Düzenlenmiş metin: ${TOKEN}`))).toBeTruthy();
    await authorPage.context().close();
    await page.context().close();
});

test('şikâyetler: silme otomatik çözer, kalan şikâyet reddedilince çözülenlere geçer', async ({ browser }) => {
    const page = await adminPage(browser);
    await page.goto('/admin.html#reports');
    await expect(page.locator('.report-card')).toHaveCount(1);
    await expect(page.locator('.report-card')).toContainText('Spoiler uyarısı yok');
    await expect(page.locator('#listArea')).not.toContainText('Hakaret içeriyor');

    await page.click('[data-act=resolve-dismiss]');
    await expect(page.locator('.toast-success', { hasText: 'reddedildi' })).toBeVisible();
    await page.click('[data-act=report-filter][data-value=resolved]');
    await expect(page.locator('.report-card').first()).toContainText('Şikâyet reddedildi');
    await page.context().close();
});

test('askıya alma (gerekçe zorunlu), oturumun kapanması, susturma ve kendine işlem yasağı', async ({ browser }) => {
    const page = await adminPage(browser);
    const authorPage = await (await browser.newContext()).newPage();
    await loginAs(authorPage, author);

    await page.goto('/admin.html#users');
    await page.fill('#userQuery', author.username);
    await expect(page.locator('tr[data-act=open-user]')).toHaveCount(1);
    await expect(page.locator('tr[data-act=open-user]')).toContainText(author.username);
    await page.click('tr button[data-act=open-user]');
    await page.click('.modal-box [data-act=suspend]');
    await page.click('.sk-dialog button[type=submit]');
    await expect(page.locator('.sk-dialog-error')).toContainText('boş bırakılamaz');
    await page.fill('.sk-dialog textarea', 'Spam davranışı (deneme)');
    await page.click('.sk-dialog button[type=submit]');
    await expect(page.locator('.modal-box .notice--danger')).toContainText('Spam davranışı');

    const status = await authorPage.evaluate(async () => (await (await fetch('/api/auth-status.php', { credentials: 'include' })).json()));
    expect(status.user).toBeNull();
    expect(status.suspended).toBe(true);

    await page.click('.modal-box [data-act=unsuspend]');
    await expect(page.locator('.modal-box [data-act=suspend]')).toBeVisible();
    await page.click('.modal-box [data-act=mute]');
    await page.selectOption('.sk-dialog select', '6');
    await page.click('.sk-dialog button[type=submit]');
    await expect(page.locator('.modal-box .notice--warn')).toContainText('yorum yazamaz');
    await page.click('.modal-box [data-act=unmute]');
    await expect(page.locator('.modal-box [data-act=mute]')).toBeVisible();
    await page.keyboard.press('Escape');

    await page.fill('#userQuery', admin.username);
    await expect(page.locator('tr[data-act=open-user]')).toContainText(admin.username);
    await expect(page.locator('tr[data-act=open-user]')).toHaveCount(1);
    await page.click('tr button[data-act=open-user]');
    await expect(page.locator('.modal-box .detail-grid')).toBeVisible();
    await expect(page.locator('.modal-box [data-act=suspend], .modal-box [data-act=mute]')).toHaveCount(0);

    await authorPage.context().close();
    await page.context().close();
});

test('işlem günlüğü her yönetici işlemini gerekçesiyle kaydeder; mobilde yatay taşma yok', async ({ browser }) => {
    const page = await adminPage(browser);
    await page.goto('/admin.html#audit');
    await expect(page.locator('tbody tr').first()).toBeVisible();
    const text = await page.locator('tbody').innerText();
    for (const label of ['Yorumu sildi', 'Yorumu geri yükledi', 'Şikâyeti reddetti', 'Hesabı askıya aldı', 'Askıyı kaldırdı', 'Kullanıcıyı susturdu', 'Susturmayı kaldırdı']) {
        expect(text).toContain(label);
    }
    expect(text).toContain('Deneme gerekçesi');
    expect(text).toContain('Spam davranışı');

    await page.setViewportSize({ width: 390, height: 780 });
    for (const tab of ['users', 'comments', 'audit']) {
        await page.goto(`/admin.html#${tab}`);
        await page.waitForSelector('#listArea tbody tr');
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
        expect(overflow, `${tab} sekmesi mobilde yatay taşıyor`).toBeLessThanOrEqual(1);
    }
    await page.context().close();
});
