// Detay sayfası düğme davranışları ve çıkmamış yapım kuralları. TMDB yanıtı sahtelenir (ağ ve anahtar gerekmez);
// kullanıcı tarafı geçici "e2e_" kullanıcısıyla çalışır. Uydurma kimlikler 987000-987999 aralığındadır
// (scripts/e2e-user.php kullanıcıyı silerken bu aralıktaki içerik kayıtlarını da temizler).
const { test, expect } = require('@playwright/test');
const { createUser, deleteUsers, loginAs } = require('./helpers');

function fakeMovie(overrides = {}) {
    return {
        id: 987001,
        title: 'Deneme Filmi',
        poster_path: null,
        backdrop_path: null,
        overview: 'Test için uydurulmuş bir film.',
        release_date: '2001-05-05',
        runtime: 101,
        genres: [{ id: 18, name: 'Dram' }],
        vote_average: 7.4,
        vote_count: 1200,
        credits: { crew: [] },
        videos: { results: [{ site: 'YouTube', type: 'Trailer', key: 'dQw4w9WgXcQ' }] },
        'watch/providers': { results: {} },
        ...overrides,
    };
}

async function mockDetail(page, movie) {
    await page.route(/\/api\/tmdb-proxy\.php\?.*endpoint=detail/, route => route.fulfill({ json: movie }));
    // Trailer iframe'i gerçek YouTube'a gitmesin.
    await page.route('https://www.youtube.com/**', route => route.fulfill({ body: '<html></html>', contentType: 'text/html' }));
}

test.describe('detay sayfası düğmeleri', () => {
    let user;
    test.beforeAll(() => { user = createUser('detail'); });
    test.afterAll(() => deleteUsers(user));

    test('"İzlenecekler" ikinci basışta eski haline döner', async ({ page }) => {
        await loginAs(page, user);
        await mockDetail(page, fakeMovie());
        await page.goto('/detail.php?type=movie&id=987001');
        const button = page.locator('#watchlistBtn');
        await expect(button).toBeVisible();
        await expect(button).not.toHaveClass(/active/);

        await button.click();
        await expect(button).toHaveClass(/active/);
        await expect(page.locator('#watchlistBtnText')).toContainText('✓');

        await button.click();
        await expect(button).not.toHaveClass(/active/);
        await expect(page.locator('#watchlistBtnText')).toHaveText('İzlenecekler');

        // Sunucuda da kayıt kalmamış olmalı.
        const status = await page.evaluate(async () => {
            const response = await fetch('/api/user-status.php?content_type=movie&content_id=987001', { credentials: 'include' });
            return response.json();
        });
        expect(status.data.status).toBeNull();
    });

    test('"Fragmanı İzle" açılıp kapanınca düğme mor (etkin) görünümde kalmaz', async ({ page }) => {
        await mockDetail(page, fakeMovie());
        await page.goto('/detail.php?type=movie&id=987001');
        const trailer = page.locator('#watchTrailerBtn');
        await expect(trailer).toBeVisible();
        const readBackground = () => trailer.evaluate(el => getComputedStyle(el).backgroundImage);
        expect(await readBackground()).toBe('none');

        await trailer.click();
        await expect(page.locator('#trailerModal')).toHaveClass(/active/);
        await page.keyboard.press('Escape');
        await expect(page.locator('#trailerModal')).not.toHaveClass(/active/);
        await page.mouse.move(2, 2);
        await expect.poll(readBackground).toBe('none');
    });

    test('çıkmamış yapımda puan, "izledim" ve yorum kapalı; izlenecekler açık', async ({ page }) => {
        await loginAs(page, user);
        await mockDetail(page, fakeMovie({ id: 987002, title: 'Gelecek Film', release_date: '2099-12-18', vote_average: 0, vote_count: 0 }));
        await page.goto('/detail.php?type=movie&id=987002');
        await expect(page.locator('#contentTitle')).toHaveText('Gelecek Film');

        await expect(page.locator('#contentMeta')).toContainText('Yakında');
        await expect(page.locator('#rateBtn')).toBeDisabled();
        await expect(page.locator('#watchBtn')).toBeDisabled();
        await expect(page.locator('#watchlistBtn')).toBeEnabled();
        await expect(page.locator('#unreleasedNotice')).toBeVisible();
        await expect(page.locator('#commentsPane .comment-form')).toBeHidden();
    });

    test('yayınlanmış yapımda yorum formu görünür', async ({ page }) => {
        await loginAs(page, user);
        await mockDetail(page, fakeMovie());
        await page.goto('/detail.php?type=movie&id=987001');
        await expect(page.locator('#contentTitle')).toHaveText('Deneme Filmi');
        await expect(page.locator('#commentsPane .comment-form')).toBeVisible();
        await expect(page.locator('#unreleasedNotice')).toHaveCount(0);
        await expect(page.locator('#rateBtn')).toBeEnabled();
    });

    test('hikaye kartı: afişli kart PNG olarak indirilir (tuval CORS yüzünden kirlenmez)', async ({ page }) => {
        const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');
        // TMDB görselleri gerçekte CORS başlığıyla gelir; sahte yanıt da aynısını yapar.
        await page.route('https://image.tmdb.org/**', route => route.fulfill({ body: png, contentType: 'image/png', headers: { 'access-control-allow-origin': '*' } }));
        await mockDetail(page, fakeMovie({ poster_path: '/mock.png' }));
        await page.goto('/detail.php?type=movie&id=987001');
        await expect(page.locator('#contentTitle')).toHaveText('Deneme Filmi');
        await page.click('#storyCardBtn');
        await expect(page.locator('#storyModal')).toHaveClass(/active/);
        // Kart çizilene kadar bekle (afiş yüklenmesi asenkron).
        await page.waitForTimeout(600);

        const [download] = await Promise.all([
            page.waitForEvent('download'),
            page.click('#storyModal .btn-primary'),
        ]);
        expect(download.suggestedFilename()).toBe('sosyal-kutuphane-deneme-filmi.png');
        const path = await download.path();
        const header = require('node:fs').readFileSync(path).subarray(0, 8);
        expect([...header]).toEqual([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]);

        // Paylaş: desteklenmeyen tarayıcıda görsel indirilir ya da bağlantı kopyalanır; hata vermez.
        const errors = [];
        page.on('pageerror', (error) => errors.push(error.message));
        await page.click('#storyModal .btn-secondary');
        await expect(page.locator('#toastContainer .toast').last()).toBeVisible();
        expect(errors).toEqual([]);
    });

    test('demo hesap: bilgi şeridi görünür, yorum formu ve puan verme kapalı', async ({ page }) => {
        await page.goto('/index.html');
        await page.fill('#loginEmail', 'ahmet@test.com');
        await page.fill('#loginPassword', '123456');
        await page.click('#loginSubmitBtn');
        await page.waitForURL('**/search.html');
        await mockDetail(page, fakeMovie());
        await page.goto('/detail.php?type=movie&id=987001');
        await expect(page.locator('.demo-notice')).toBeVisible();
        await expect(page.locator('#commentsPane .comment-form')).toBeHidden();
        await expect(page.locator('#rateBtn')).toBeDisabled();
    });
});
