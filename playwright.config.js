// Playwright ayarları. CI'de sunucu zaten ayakta (E2E_BASE_URL verilir); yerelde php -S otomatik başlar.
// Yerelde Edge ile çalıştırmak için: PW_CHANNEL=msedge npx playwright test
const { defineConfig, devices } = require('@playwright/test');

const baseURL = process.env.E2E_BASE_URL || 'http://127.0.0.1:8000';

module.exports = defineConfig({
    testDir: './tests/e2e',
    timeout: 45000,
    workers: 1,
    outputDir: 'test-results',
    reporter: [['list'], ['html', { open: 'never', outputFolder: 'playwright-report' }]],
    use: {
        baseURL,
        trace: 'retain-on-failure',
        channel: process.env.PW_CHANNEL || undefined,
    },
    projects: [
        { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    ],
    webServer: process.env.E2E_BASE_URL ? undefined : {
        command: 'php -S 127.0.0.1:8000 -t .',
        url: baseURL,
        reuseExistingServer: true,
        timeout: 20000,
    },
});
