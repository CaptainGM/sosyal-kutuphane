// Erişilebilirlik: ana sayfalarda WCAG 2 AA ciddi ve kritik ihlal olmamalı (axe-core).
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;

const pages = [
    { name: 'giriş ekranı', path: '/index.html', ready: '#loginForm' },
    { name: 'arama sayfası', path: '/search.html', ready: '.search-box' },
    { name: 'yeni çıkanlar', path: '/upcoming.html', ready: '#upcomingStatus' },
];

for (const page of pages) {
    test(`${page.name}: WCAG 2 AA ciddi ihlali yok`, async ({ page: browserPage }) => {
        await browserPage.goto(page.path);
        await expect(browserPage.locator(page.ready)).toBeVisible();

        const results = await new AxeBuilder({ page: browserPage }).withTags(['wcag2a', 'wcag2aa']).analyze();
        const serious = results.violations.filter(v => ['serious', 'critical'].includes(v.impact));

        expect(serious.map(v => `${v.id}: ${v.help}`)).toEqual([]);
    });
}
