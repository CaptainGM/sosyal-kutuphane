// Erişilebilirlik: herkese açık sayfalarda WCAG 2 AA ciddi ve kritik ihlal olmamalı (axe-core).
// Açık ve koyu tema ayrı ayrı çalışır; Playwright projeleri ayrıca tam hareket ve azaltılmış hareket düzenlerini dener
// (playwright.config.js). axe, gradyan üstü kontrastı "incomplete" sayar; bu yüzden bu sonuçlar tek başına
// okunaklılık kanıtı değildir (ekran görüntüleriyle ayrıca bakılır).
//
// Bu dosya salt okunurdur (giriş yapmaz, veri yazmaz); canlı duman testi de bunu çalıştırır.
// Oturum gerektiren sayfalar a11y-oturumlu.spec.js içindedir.
const { test, expect } = require('@playwright/test');
const { expectNoSeriousViolations } = require('./a11y-helpers');

const publicPages = [
    { name: 'giriş ekranı', path: '/index.html', ready: '#loginForm' },
    { name: 'arama sayfası', path: '/search.html', ready: '.search-panel' },
    { name: 'yeni çıkanlar', path: '/upcoming.html', ready: '#upcomingStatus' },
    { name: 'şifre sıfırlama', path: '/reset-password.html?token=deneme', ready: '#resetForm' },
    { name: 'çevrimdışı sayfası', path: '/offline.html', ready: '#retryBtn' },
    { name: 'gizlilik ve veri kullanımı', path: '/gizlilik.html', ready: '#veriler' },
    { name: '404 sayfası', path: '/yok-boyle-bir-sayfa.html', ready: '.notfound-code' },
    { name: 'paylaşılan liste (bulunamadı durumu)', path: '/shared-list.html?id=999999999', ready: '#listError:not([hidden])' },
];

for (const scheme of ['light', 'dark']) {
    test.describe(`${scheme} tema`, () => {
        test.use({ colorScheme: scheme });

        for (const pageInfo of publicPages) {
            test(`${pageInfo.name}: WCAG 2 AA ciddi ihlali yok`, async ({ page }) => {
                await page.goto(pageInfo.path);
                await expect(page.locator(pageInfo.ready).first()).toBeVisible();
                await expectNoSeriousViolations(page);
            });
        }
    });
}
