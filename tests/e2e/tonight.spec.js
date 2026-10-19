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

    test('türler: aksiyon ve komedi var; diziye geçince dizide karşılığı olmayanlar gizlenir', async ({ page }) => {
        await page.goto('/tonight.html');
        const chips = page.locator('#moodGroup .chip');
        await expect(chips.filter({ hasText: /^Aksiyon$/ })).toHaveCount(1);
        await expect(chips.filter({ hasText: /^Komedi$/ })).toHaveCount(1);
        expect(await chips.count()).toBeGreaterThanOrEqual(15);

        await page.click('#moodGroup .chip[data-mood="romantik"]');
        await expect(page).toHaveURL(/moods=romantik/);
        await page.click('[data-kind="series"]');
        await expect(page.locator('#moodGroup .chip[data-mood="romantik"]')).toHaveCount(0);
        await expect(page.locator('#moodGroup .chip[data-mood="tarih"]')).toHaveCount(0);
        await expect(page.locator('#moodGroup .chip[data-mood="komedi"]')).toHaveCount(1);
        expect(new URL(page.url()).search).not.toContain('romantik');
    });

    test.describe('seri uyarısı', () => {
        test.use({ reducedMotion: 'reduce' });

        const hobbit = {
            id: 122917, type: 'movie', title: 'Hobbit: Beş Ordunun Savaşı', year: '2014', rating: 7.3,
            poster: 'img/no-poster.svg', url: 'detail.php?type=movie&id=122917', overview: 'Son savaş.', backdrop: null,
            runtime: 144, genres: ['Macera'], genre_ids: [12], poster_path: null, tagline: '',
        };
        const answer = (franchise) => ({ success: true, pick: hobbit, franchise, alternatives: [], reel: [], reason: '144 dk · 7.3 puan', seed: 1, candidate_count: 5 });

        test('serinin sonraki filmi çıkınca ilk film uyarısı görünür', async ({ page }) => {
            await page.route('**/api/evening-picker.php*', route => route.fulfill({ json: answer({
                collection: 'Hobbit Serisi', position: 3, total: 3, missing_count: 2, starts_at_first: true,
                first: { id: 49051, title: 'Hobbit: Beklenmedik Yolculuk', year: '2012', poster: null, poster_path: null, url: 'detail.php?type=movie&id=49051' },
                before: [
                    { id: 49051, title: 'Hobbit: Beklenmedik Yolculuk', year: '2012', poster: null, url: 'detail.php?type=movie&id=49051' },
                    { id: 57158, title: 'Hobbit: Smaug', year: '2013', poster: null, url: 'detail.php?type=movie&id=57158' },
                ],
            }) }));
            await page.goto('/tonight.html');
            await page.click('#rollBtn');

            const note = page.locator('.franchise-note');
            await expect(note).toBeVisible();
            await expect(note).toContainText('Bu film bir serinin 3. filmi (Hobbit Serisi)');
            await expect(note).toContainText('İlk filmi izlemeden bunu izlemen önerilir');
            await expect(note.locator('.franchise-text a')).toHaveAttribute('href', 'detail.php?type=movie&id=49051');
            await expect(note).toContainText('Toplam 2 önceki film var');
            await expect(note.locator('.franchise-poster')).toHaveCount(2);
            await expect(page.locator('#addFirstBtn')).toContainText('İzlenecek listeme ekle');
        });

        test('serinin ilk filmi ya da seri dışı filmde uyarı çıkmaz', async ({ page }) => {
            await page.route('**/api/evening-picker.php*', route => route.fulfill({ json: answer(null) }));
            await page.goto('/tonight.html');
            await page.click('#rollBtn');
            await expect(page.locator('.pick-result')).toBeVisible();
            await expect(page.locator('.franchise-note')).toHaveCount(0);
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
