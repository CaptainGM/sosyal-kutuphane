// Playwright ayarları. CI'de sunucu zaten ayakta (E2E_BASE_URL verilir); yerelde php -S otomatik başlar.
// Yerelde Edge ile çalıştırmak için: PW_CHANNEL=msedge npx playwright test
//
// İki proje: sayfalar hem tam hareketle hem de işletim sistemi "hareketi azalt" derken (Windows'ta animasyon efektleri
// kapalıysa tarayıcı bunu böyle bildirir) denenir. Hareket düzeni <html data-motion> ile seçilir (js/motion.js).
const { defineConfig, devices } = require('@playwright/test');

const baseURL = process.env.E2E_BASE_URL || 'http://127.0.0.1:8000';

module.exports = defineConfig({
    testDir: './tests/e2e',
    timeout: 60000,
    // Tek süreçli geliştirme sunucusunda yavaş bir TMDB çağrısı diğer istekleri bekletebilir; beklemeler buna göre geniştir.
    expect: { timeout: 10000 },
    workers: 1,
    // CI'daki ortak sunucu zaman zaman istekleri geciktirebiliyor; başarısız test bir kez yeniden denenir. Yeniden denemede geçen test
    // raporda "flaky" olarak işaretlenir ve ilk denemenin izi (trace) saklanır, yani sorun gözden kaybolmaz.
    retries: process.env.CI ? 1 : 0,
    outputDir: 'test-results',
    reporter: [['list'], ['html', { open: 'never', outputFolder: 'playwright-report' }]],
    use: {
        baseURL,
        trace: 'retain-on-failure',
        channel: process.env.PW_CHANNEL || undefined,
    },
    projects: [
        { name: 'chromium', use: { ...devices['Desktop Chrome'], reducedMotion: 'no-preference' } },
        { name: 'chromium-azaltilmis-hareket', use: { ...devices['Desktop Chrome'], reducedMotion: 'reduce' } },
    ],
    webServer: process.env.E2E_BASE_URL ? undefined : {
        command: 'php -S 127.0.0.1:8000 -t . router.php',
        url: baseURL,
        reuseExistingServer: true,
        timeout: 20000,
    },
});
