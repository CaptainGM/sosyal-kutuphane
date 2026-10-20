// Profil sayfası: biyografi, avatar, listeler, şifre kuralları ve sekmeler. Testin kendi geçici kullanıcısıyla çalışır.
const { test, expect } = require('@playwright/test');
const { createUser, deleteUsers, loginAs } = require('./helpers');

test.describe.configure({ mode: 'serial' });

let user;
test.beforeAll(() => { user = createUser('profile'); });
test.afterAll(() => { deleteUsers(user); });
// Hareket düzeninden bağımsız: iki projede tekrar çalışmasına gerek yok.
test.beforeEach(async ({}, testInfo) => { test.skip(testInfo.project.name !== 'chromium', 'Yalnızca varsayılan projede çalışır'); });

test.describe('profilim', () => {
    test('boş profil: sahte biyografi yok, kütüphane boş durumları yönlendirir', async ({ page }) => {
        await loginAs(page, user);
        await page.goto('/profile.html');
        await expect(page.locator('#profileUsername')).toHaveText(user.username);
        await expect(page.locator('#profileBio.is-placeholder')).toBeVisible();
        await expect(page.locator('#profileBio')).not.toContainText('Harry Potter');
        await expect(page.locator('#watchedMovies .empty a[href="search.html"]')).toBeVisible();
        await expect(page.locator('#moviesCount')).toHaveText('0');
    });

    test('biyografi kaydolur, bağlantılı biyografi reddedilir, Escape pencereyi kapatır', async ({ page }) => {
        await loginAs(page, user);
        await page.goto('/profile.html');
        await expect(page.locator('#profileContent')).toBeVisible();

        await page.getByRole('button', { name: 'Profili Düzenle' }).click();
        await expect(page.locator('.sk-dialog textarea')).toBeFocused();
        await page.keyboard.press('Escape');
        await expect(page.locator('.sk-dialog')).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Profili Düzenle' })).toBeFocused();

        await page.getByRole('button', { name: 'Profili Düzenle' }).click();
        await page.fill('.sk-dialog textarea', 'Gel: www.spam.example');
        await page.click('.sk-dialog button[type=submit]');
        await expect(page.locator('.toast-error')).toContainText('bağlantı');
        await expect(page.locator('#profileBio.is-placeholder')).toBeVisible();

        await page.getByRole('button', { name: 'Profili Düzenle' }).click();
        await page.fill('.sk-dialog textarea', 'Bilim kurgu ve polisiye');
        await page.click('.sk-dialog button[type=submit]');
        await expect(page.locator('#profileBio')).toHaveText('Bilim kurgu ve polisiye');

        await page.reload();
        await expect(page.locator('#profileBio')).toHaveText('Bilim kurgu ve polisiye');
    });

    test('emoji avatar seçilir ve yenilemeden sonra kalıcıdır', async ({ page }) => {
        await loginAs(page, user);
        await page.goto('/profile.html');
        await page.click('#profileAvatar');
        const choice = (await page.locator('.avatar-pick').nth(8).textContent()).trim();
        await page.locator('.avatar-pick').nth(8).click();
        await expect(page.locator('.modal-box')).toHaveCount(0);
        await expect(page.locator('#profileAvatar .avatar--emoji')).toHaveText(choice);

        await page.reload();
        await expect(page.locator('#profileAvatar .avatar--emoji')).toHaveText(choice);
    });

    test('fotoğraf yüklenir ve kalıcıdır; resim olmayan dosya reddedilir', async ({ page }) => {
        await loginAs(page, user);
        await page.goto('/profile.html');
        await expect(page.locator('#profileContent')).toBeVisible();

        const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', 'base64');
        await page.setInputFiles('#avatarFileInput', { name: 'ben.png', mimeType: 'image/png', buffer: png });
        await expect(page.locator('.toast-success')).toContainText('Avatar güncellendi');
        await expect(page.locator('#profileAvatar img')).toHaveAttribute('src', /\/uploads\/avatars\/[a-f0-9]{32}\.png$/);

        await page.reload();
        const photo = page.locator('#profileAvatar img');
        await expect(photo).toHaveAttribute('src', /\/uploads\/avatars\//);
        await expect.poll(() => photo.evaluate((img) => img.complete && img.naturalWidth > 0)).toBe(true);

        await page.setInputFiles('#avatarFileInput', { name: 'sahte.png', mimeType: 'image/png', buffer: Buffer.from('bu bir resim degil') });
        await expect(page.locator('.toast-error')).toContainText('Geçersiz resim');
    });

    test('hesabı sil: yanlış şifre silmez, doğru şifre hesabı ve verileri siler', async ({ browser }) => {
        const victim = createUser('silinecek');
        try {
            const context = await browser.newContext();
            const page = await context.newPage();
            const login = await page.request.post('/api/login.php', { data: { email: victim.email, password: victim.password } });
            expect(login.ok()).toBeTruthy();
            await page.goto('/profile.html#settings');
            await expect(page.locator('#deleteAccountPanel')).toBeVisible();
            await expect(page.locator('#deleteAccountPanel a[href="gizlilik.html#saklama"]')).toBeVisible();

            // Onay kutusu işaretlenmeden gönderilmez
            await page.click('#deleteAccountBtn');
            await page.fill('.sk-dialog input[type=password]', 'Yanlis123!');
            await page.click('.sk-dialog button[type=submit]');
            await expect(page.locator('.sk-dialog-error')).toContainText('onay kutusunu');

            // Yanlış şifre: hesap silinmez
            await page.check('.sk-dialog input[type=checkbox]');
            await page.click('.sk-dialog button[type=submit]');
            await expect(page.locator('.toast-error')).toContainText('Şifre yanlış');
            await expect(page).toHaveURL(/profile\.html/);
            await page.reload();
            await expect(page.locator('#profileUsername')).toHaveText(victim.username);

            // Doğru şifre: hesap silinir, giriş sayfasına dönülür
            await page.click('#deleteAccountBtn');
            await page.fill('.sk-dialog input[type=password]', victim.password);
            await page.check('.sk-dialog input[type=checkbox]');
            await page.click('.sk-dialog button[type=submit]');
            await page.waitForURL('**/index.html');
            await expect(page.locator('.toast-success')).toContainText('silindi');
            const again = await context.request.post('/api/login.php', { data: { email: victim.email, password: victim.password } });
            expect(again.status()).toBe(401);
            await context.close();
        } finally {
            deleteUsers(victim);
        }
    });

    test('hesabı sil düğmesi yönetici hesabında kapalıdır ve nedeni yazar', async ({ browser }) => {
        const boss = createUser('yonetici', { admin: true });
        try {
            const context = await browser.newContext();
            const page = await context.newPage();
            await page.request.post('/api/login.php', { data: { email: boss.email, password: boss.password } });
            await page.goto('/profile.html#settings');
            await expect(page.locator('#deleteAccountBtn')).toBeDisabled();
            await expect(page.locator('#deleteAccountNote')).toContainText('Yönetici hesabı');
            await context.close();
        } finally {
            deleteUsers(boss);
        }
    });

    test('sekmeler adres çubuğuna yazılır, #settings doğrudan açılır', async ({ page }) => {
        await loginAs(page, user);
        await page.goto('/profile.html');
        await page.click('[data-tab=lists]');
        await expect(page).toHaveURL(/#lists$/);
        await expect(page.locator('[data-tab=lists]')).toHaveAttribute('aria-pressed', 'true');
        await page.click('[data-tab=diary]');
        await expect(page.locator('#diaryList .empty-title')).toContainText('henüz boş');

        await page.goto('/profile.html#settings');
        await expect(page.locator('#settingsTab')).toHaveClass(/active/);
    });

    test('liste oluşturma ve düzenleme penceresi; boş ad engellenir', async ({ page }) => {
        await loginAs(page, user);
        await page.goto('/profile.html#lists');
        await expect(page.locator('#customLists .empty')).toBeVisible();

        await page.getByRole('button', { name: '+ Yeni Liste Oluştur' }).click();
        await page.click('.sk-dialog button[type=submit]');
        await expect(page.locator('.sk-dialog-error')).toContainText('boş bırakılamaz');
        await page.fill('.sk-dialog input[name=name]', 'Hafta sonu');
        await page.fill('.sk-dialog textarea[name=description]', 'Cumartesi akşamı için');
        await page.click('.sk-dialog button[type=submit]');
        await expect(page.locator('#customLists .list-card-title')).toHaveText('Hafta sonu');

        await page.getByRole('button', { name: 'Görüntüle' }).click();
        await expect(page.locator('.modal-box-header h2')).toHaveText('Hafta sonu');
        await expect(page.locator('.modal-box .empty')).toBeVisible();
        await page.check('#listPublicToggle');
        await expect(page.locator('#copyListLinkBtn')).toBeVisible();
        await page.keyboard.press('Escape');
        await expect(page.locator('.modal-box')).toHaveCount(0);
    });

    test('şifre kuralları yazdıkça güncellenir', async ({ page }) => {
        await loginAs(page, user);
        await page.goto('/profile.html#settings');
        await page.fill('#newPasswordInput', 'abc');
        await expect(page.locator('#newPasswordRules li.is-met')).toHaveCount(1);
        await page.fill('#newPasswordInput', 'Abcdef1!');
        await expect(page.locator('#newPasswordRules li.is-met')).toHaveCount(4);
        await expect(page.locator('#adminLinkCard')).toBeHidden();
    });
});
