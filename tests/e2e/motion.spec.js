// Hareket sistemi: <html data-motion> kararı, kullanıcının sitedeki düğmeyle tercihi ve gerçekten hareket edip etmediği.
// Hareket, iki örnekte (≈1,5 sn arayla) transform karşılaştırılarak ölçülür; animasyon adına bakılmaz (süre 0 olsa bile
// adı görünür, bu yüzden yanıltıcıdır). Windows'ta "Animasyon efektleri" kapalıyken tarayıcı reduced-motion bildirir.
const { test, expect } = require('@playwright/test');

test.beforeEach(async ({}, testInfo) => { test.skip(testInfo.project.name !== 'chromium', 'Hareket kipleri testin içinde ayrı ayrı seçilir'); });

async function transformOf(page, selector) {
    return page.evaluate((sel) => {
        const el = document.querySelector(sel);
        return el ? getComputedStyle(el).transform : null;
    }, selector);
}

// Atmosfer katmanı TMDB verisine bağlıdır: oluşmadıysa (anahtar yok) ilgili doğrulamalar atlanır.
async function hasAtmosphere(page) {
    return page.locator('.atmo-track').first().waitFor({ state: 'attached', timeout: 8000 }).then(() => true).catch(() => false);
}

async function moves(page, selector, waitMs = 1500) {
    const first = await transformOf(page, selector);
    await page.waitForTimeout(waitMs);
    const second = await transformOf(page, selector);
    return first !== second;
}

test.describe('tam hareket (sistem tercihi yok)', () => {
    test.use({ reducedMotion: 'no-preference' });

    test('giriş: afiş halkası dönüyor, arka plan kareleri kayıyor', async ({ page }) => {
        await page.goto('/index.html');
        await expect(page.locator('html')).toHaveAttribute('data-motion', 'full');
        await page.waitForSelector('.ring .poster');
        await page.waitForTimeout(2000);
        expect(await moves(page, '.ring')).toBe(true);
        if (await hasAtmosphere(page)) {
            expect(await moves(page, '.atmo-track')).toBe(true);
            await expect(page.locator('.atmo-still').first()).toBeAttached();
        }
    });

    test('arama: vitrin şeritleri kayıyor ve ok düğmeleri gizli', async ({ page }) => {
        await page.goto('/search.html');
        const strip = page.locator('#topRatedMovies .strip-card').first();
        test.skip(!(await strip.waitFor({ timeout: 30000 }).then(() => true).catch(() => false)), 'TMDB yanıt vermedi (anahtar yok)');
        expect(await moves(page, '#topRatedMovies .strip-track')).toBe(true);
        await expect(page.locator('#topRatedMovies .strip-arrow').first()).toBeHidden();
    });

    test('sayfalar yatay taşmıyor (giriş ve arama)', async ({ page }) => {
        for (const path of ['/index.html', '/search.html']) {
            await page.goto(path);
            await page.waitForTimeout(1500);
            const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
            expect(overflow, path).toBeLessThanOrEqual(1);
        }
    });
});

test.describe('azaltılmış hareket (işletim sistemi tercihi)', () => {
    test.use({ reducedMotion: 'reduce' });

    test('giriş: halka dönmez ama tasarlanmış yelpaze görünür; "Animasyonları aç" düğmesi var', async ({ page }) => {
        await page.goto('/index.html');
        await expect(page.locator('html')).toHaveAttribute('data-motion', 'reduced');
        await page.waitForSelector('.ring .poster');
        await page.waitForTimeout(2000);
        expect(await moves(page, '.ring')).toBe(false);
        if (await hasAtmosphere(page)) {
            expect(await moves(page, '.atmo-track')).toBe(false);
        }
        await expect(page.locator('.ring .poster').first()).toBeVisible();
        await expect(page.locator('#motionCta')).toBeVisible();
        await expect(page.locator('#motionCta')).toContainText('Animasyon');
    });

    test('sayfa içi ipucu ve gezinme çubuğu düğmesi: sistem azaltılmış derken açıklama gösterilir', async ({ page }) => {
        await page.goto('/search.html');
        await expect(page.locator('html')).toHaveAttribute('data-motion', 'reduced');
        await expect(page.locator('.motion-hint')).toBeVisible();
        await expect(page.locator('.motion-hint')).toContainText('Animasyonlar kapalı');
        await expect(page.locator('.motion-toggle-btn')).toHaveAttribute('aria-pressed', 'false');
    });

    test('"Animasyonları aç" tercihi kaydeder, sayfa yenilenir ve halka dönmeye başlar', async ({ page }) => {
        await page.goto('/index.html');
        await page.waitForSelector('#motionCta');
        await page.click('#motionCta');
        await page.waitForLoadState('load');
        await expect(page.locator('html')).toHaveAttribute('data-motion', 'full');
        expect(await page.evaluate(() => localStorage.getItem('sk-motion'))).toBe('full');
        await page.waitForSelector('.ring .poster');
        await page.waitForTimeout(2000);
        expect(await moves(page, '.ring')).toBe(true);

        // Tercih sonraki sayfalarda da geçerli: navbar düğmesi basılı görünür
        await page.goto('/search.html');
        await expect(page.locator('html')).toHaveAttribute('data-motion', 'full');
        await expect(page.locator('.motion-toggle-btn')).toHaveAttribute('aria-pressed', 'true');
    });

    test('"Şimdi değil" ipucunu kapatır ve düzen azaltılmış kalır', async ({ page }) => {
        await page.goto('/search.html');
        await page.waitForSelector('.motion-hint');
        await page.click('.motion-hint [data-action="dismiss"]');
        await expect(page.locator('.motion-hint')).toHaveCount(0);
        await expect(page.locator('html')).toHaveAttribute('data-motion', 'reduced');
    });

    test('arama: şeritler durağan, ok düğmeleri görünür ve kaydırma tutamağı çalışır', async ({ page }) => {
        await page.goto('/search.html');
        const strip = page.locator('#topRatedMovies .strip-card').first();
        test.skip(!(await strip.waitFor({ timeout: 30000 }).then(() => true).catch(() => false)), 'TMDB yanıt vermedi (anahtar yok)');
        expect(await moves(page, '#topRatedMovies .strip-track')).toBe(false);
        await expect(page.locator('#topRatedMovies .strip-arrow').first()).toBeVisible();
    });
});

test.describe('kullanıcının seçimi sistem tercihini ezer', () => {
    test.use({ reducedMotion: 'reduce' });

    test('kayıtlı "full" tercihi varken sistem "azalt" dese bile tam hareket çalışır', async ({ page }) => {
        await page.addInitScript(() => localStorage.setItem('sk-motion', 'full'));
        await page.goto('/index.html');
        await expect(page.locator('html')).toHaveAttribute('data-motion', 'full');
        await expect(page.locator('html')).toHaveAttribute('data-motion-source', 'user');
        await page.waitForSelector('.ring .poster');
        await page.waitForTimeout(2000);
        expect(await moves(page, '.ring')).toBe(true);
    });
});
