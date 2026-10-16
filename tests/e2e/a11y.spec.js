// Erişilebilirlik: ana sayfalarda WCAG 2 AA ciddi ve kritik ihlal olmamalı (axe-core).
// Açık ve koyu tema ayrı ayrı çalışır. axe, gradyan üstü kontrastı "incomplete" sayar; bu yüzden
// bu sonuçlar tek başına okunaklılık kanıtı değildir (ekran görüntüleriyle ayrıca bakılır).
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;

const pages = [
    { name: 'giriş ekranı', path: '/index.html', ready: '#loginForm' },
    { name: 'arama sayfası', path: '/search.html', ready: '.search-box' },
    { name: 'yeni çıkanlar', path: '/upcoming.html', ready: '#upcomingStatus' },
];

for (const scheme of ['light', 'dark']) {
    test.describe(`${scheme} tema`, () => {
        test.use({ colorScheme: scheme });

        for (const pageInfo of pages) {
            test(`${pageInfo.name}: WCAG 2 AA ciddi ihlali yok`, async ({ page }) => {
                await page.goto(pageInfo.path);
                await expect(page.locator(pageInfo.ready)).toBeVisible();

                const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze();
                const serious = results.violations.filter(v => ['serious', 'critical'].includes(v.impact));

                expect(serious.map(v => `${v.id}: ${v.help}`)).toEqual([]);
            });
        }
    });
}
