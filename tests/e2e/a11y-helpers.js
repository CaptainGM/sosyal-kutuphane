// Erişilebilirlik testlerinin ortak yardımcıları (a11y.spec.js ve a11y-oturumlu.spec.js kullanır).
const { expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;

// Giriş animasyonları (belirme, yükselme) sürerken renkler yarı saydamdır ve axe sahte kontrast hatası bulur.
// Sonu olan animasyonlar bitene kadar beklenir; sonsuz olanlar (halka dönüşü, kayan şeritler) beklenmez.
async function settleAnimations(page) {
    await page.evaluate(async () => {
        const finite = document.getAnimations().filter(a => a.effect && a.effect.getComputedTiming().iterations !== Infinity);
        await Promise.all(finite.map(a => a.finished.catch(() => { })));
    });
}

async function expectNoSeriousViolations(page) {
    // Geç gelen içerik (ağdan dolan bölümler) kendi giriş animasyonunu sonradan başlatır: iki tur beklenir.
    await settleAnimations(page);
    await page.waitForTimeout(600);
    await settleAnimations(page);
    const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze();
    const serious = results.violations.filter(v => ['serious', 'critical'].includes(v.impact));
    expect(serious.map(v => `${v.id}: ${v.help} (${v.nodes.slice(0, 3).map(n => n.target.join(' ') + ' → ' + ((n.any[0] && n.any[0].message) || '')).join(' | ')})`)).toEqual([]);
}

module.exports = { settleAnimations, expectNoSeriousViolations };
