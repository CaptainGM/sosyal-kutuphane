// "Ne izlesem?" sayfası: süzgeç çipleri, adres çubuğu eşitlemesi, boş sahnedeki rastgele afişler, dar ekran.
const { test, expect } = require('@playwright/test');

test.describe('ne izlesem', () => {
    test('varsayılan dönem "2000 ve sonrası"; Korece yok; seçimler adres çubuğuna yazılır ve geri yüklenir', async ({ page }) => {
        await page.goto('/tonight.html');
        await expect(page.locator('#eraGroup .chip[aria-pressed="true"]')).toHaveText('2000 ve sonrası');
        await expect(page.locator('#langGroup .chip')).toHaveText(['Hepsi', 'Türkçe', 'İngilizce']);

        await page.click('#eraGroup .chip[data-value="recent"]');
        await page.click('#ratingGroup .chip[data-value="8"]');
        await page.click('#langGroup .chip[data-value="tr"]');
        await expect(page).toHaveURL(/era=recent/);
        await expect(page).toHaveURL(/rating=8/);
        await expect(page).toHaveURL(/lang=tr/);

        await page.reload();
        await expect(page.locator('#eraGroup .chip[aria-pressed="true"]')).toHaveText('Son 5 yıl');
        await expect(page.locator('#ratingGroup .chip[aria-pressed="true"]')).toHaveText('8+');
        await expect(page.locator('#langGroup .chip[aria-pressed="true"]')).toHaveText('Türkçe');

        // Varsayılana dönünce adres çubuğu temizlenir.
        await page.click('#eraGroup .chip[data-value="modern"]');
        await page.click('#ratingGroup .chip[data-value="0"]');
        await page.click('#langGroup .chip[data-value=""]');
        expect(new URL(page.url()).search).toBe('');
    });

    test('istek parametreleri seçilen süzgeçlerle gider', async ({ page }) => {
        await page.route('**/api/evening-picker.php*', route => route.fulfill({ json: { success: true, pick: null, message: 'deneme' } }));
        await page.goto('/tonight.html?era=classic&rating=7&lang=en');
        const [request] = await Promise.all([
            page.waitForRequest(/evening-picker\.php/),
            page.click('#rollBtn'),
        ]);
        const query = new URL(request.url()).searchParams;
        expect(query.get('era')).toBe('classic');
        expect(query.get('rating')).toBe('7');
        expect(query.get('lang')).toBe('en');
    });

    test.describe('tam hareket', () => {
        test.use({ reducedMotion: 'no-preference' });

        test('boş sahnede rastgele afişler görünür ve birkaç saniyede biri değişir', async ({ page }) => {
            const posters = Array.from({ length: 12 }, (_, i) => ({ id: i + 1, title: `Film ${i + 1}`, poster_path: `/p${i + 1}.jpg` }));
            await page.route(/\/api\/tmdb-proxy\.php\?.*endpoint=list/, route => route.fulfill({ json: { results: posters } }));
            await page.route('https://image.tmdb.org/**', route => route.fulfill({
                body: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64'),
                contentType: 'image/png',
            }));
            await page.goto('/tonight.html');
            const images = page.locator('#teaser .teaser-card img');
            await expect(images).toHaveCount(5);
            const before = await images.evaluateAll(list => list.map(img => img.src));
            await expect.poll(async () => (await images.evaluateAll(list => list.map(img => img.src))).join('|'), { timeout: 12000 }).not.toBe(before.join('|'));
        });
    });

    test.describe('azaltılmış hareket', () => {
        test.use({ reducedMotion: 'reduce' });

        test('afişler görünür ama kendiliğinden değişmez', async ({ page }) => {
            const posters = Array.from({ length: 12 }, (_, i) => ({ id: i + 1, title: `Film ${i + 1}`, poster_path: `/p${i + 1}.jpg` }));
            await page.route(/\/api\/tmdb-proxy\.php\?.*endpoint=list/, route => route.fulfill({ json: { results: posters } }));
            await page.route('https://image.tmdb.org/**', route => route.abort());
            await page.goto('/tonight.html');
            const images = page.locator('#teaser .teaser-card img');
            await expect(images).toHaveCount(5);
            const before = await images.evaluateAll(list => list.map(img => img.src));
            await page.waitForTimeout(5000);
            expect(await images.evaluateAll(list => list.map(img => img.src))).toEqual(before);
        });
    });

    test('dar ekranda sayfa yatay kaymaz', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 800 });
        await page.route(/\/api\/tmdb-proxy\.php\?.*endpoint=list/, route => route.fulfill({ json: { results: Array.from({ length: 12 }, (_, i) => ({ id: i + 1, title: `F${i}`, poster_path: `/p${i}.jpg` })) } }));
        await page.goto('/tonight.html');
        await page.waitForSelector('#teaser .teaser-card');
        expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(1);
    });
});
