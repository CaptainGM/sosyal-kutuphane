<?php

use PHPUnit\Framework\TestCase;

// Sunucu yönlendiriciyle (router.php) çalışmalı: gizli dosyalar sunulmaz, statik dosyalar önbellek başlığı taşır,
// herkese açık API yanıtlarında oturum çerezi bulunmaz.
final class RouterTest extends TestCase
{
    /** @return array{status:int, headers:string, body:string} */
    private function fetch(string $path, array $headers = []): array
    {
        $ch = curl_init(TEST_BASE_URL . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_PATH_AS_IS => true,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        return ['status' => $status, 'headers' => substr((string) $raw, 0, $size), 'body' => substr((string) $raw, $size)];
    }

    public function testHiddenFilesAndInternalFoldersAreNotServed(): void
    {
        foreach (['/.env', '/.git/config', '/.dockerignore', '/vendor/autoload.php', '/tests/ApiTest.php', '/api/lib/csv-import.php', '/scripts/make-vapid.php', '/partials/navbar.html', '/..%2Fcomposer.json', '/../.env'] as $path) {
            $res = $this->fetch($path);
            $this->assertSame(404, $res['status'], "$path sunulmamalı");
            $this->assertStringNotContainsString('MONGO', $res['body'], "$path içeriği sızmamalı");
        }
    }

    public function testUnknownPagesGetARealNotFoundPageInsteadOfTheLoginScreen(): void
    {
        foreach (['/yok-boyle-bir-sayfa.html', '/admin', '/a/b/c.html', '/uploads/', '/api'] as $path) {
            $res = $this->fetch($path);
            $this->assertSame(404, $res['status'], "$path 404 dönmeli (eskiden giriş sayfası 200 ile dönüyordu)");
            $this->assertMatchesRegularExpression('/content-type: text\/html/i', $res['headers']);
            $this->assertStringContainsString('Aradığın sayfa bulunamadı', $res['body']);
            $this->assertStringNotContainsString('loginEmail', $res['body'], "$path giriş formunu göstermemeli");
        }
    }

    public function testUnknownApiPathsReturnJsonNotFound(): void
    {
        $res = $this->fetch('/api/yok-boyle-bir-uc.php');
        $this->assertSame(404, $res['status']);
        $this->assertMatchesRegularExpression('/content-type: application\/json/i', $res['headers']);
        $this->assertFalse(json_decode($res['body'], true)['success']);
    }

    public function testExistingPagesRootAndRobotsStillWork(): void
    {
        $this->assertSame(200, $this->fetch('/')['status']);
        $this->assertSame(200, $this->fetch('/search.html')['status']);
        $this->assertSame(200, $this->fetch('/detail.php?type=movie&id=550')['status'], 'PHP sayfaları normal akışa döner');
        $this->assertSame(200, $this->fetch('/favicon.ico')['status']);
        $this->assertSame(200, $this->fetch('/img/logo.svg')['status']);

        $robots = $this->fetch('/robots.txt');
        $this->assertSame(200, $robots['status']);
        $this->assertMatchesRegularExpression('/content-type: text\/plain/i', $robots['headers']);
        $this->assertStringContainsString('Disallow: /api/', $robots['body']);
    }

    public function testSecurityHeadersAreSentOnEveryKindOfResponse(): void
    {
        foreach (['/css/ui.css', '/search.html', '/api/auth-status.php', '/yok-boyle-bir-sayfa.html', '/api/yok-boyle-bir-uc.php'] as $path) {
            $headers = $this->fetch($path)['headers'];
            $this->assertMatchesRegularExpression('/x-content-type-options: nosniff/i', $headers, $path);
            $this->assertMatchesRegularExpression('/x-frame-options: SAMEORIGIN/i', $headers, $path);
            $this->assertMatchesRegularExpression('/referrer-policy: strict-origin-when-cross-origin/i', $headers, $path);
            $this->assertDoesNotMatchRegularExpression('/strict-transport-security/i', $headers, "$path: düz HTTP'de HSTS gönderilmez");
        }
        $behindProxy = $this->fetch('/search.html', ['X-Forwarded-Proto: https'])['headers'];
        $this->assertMatchesRegularExpression('/strict-transport-security: max-age=\d+/i', $behindProxy, 'HTTPS vekilin ardında HSTS gönderilir');
    }

    public function testStaticFilesCarryCacheHeaders(): void
    {
        $res = $this->fetch('/css/ui.css');
        $this->assertSame(200, $res['status']);
        $this->assertMatchesRegularExpression('/cache-control: public, max-age=\d+/i', $res['headers']);
        $this->assertMatchesRegularExpression('/content-type: text\/css/i', $res['headers']);
    }

    public function testPublicApiResponseIsCacheableAndSetsNoSessionCookie(): void
    {
        $res = $this->fetch('/api/content-rating.php?content_type=movie&content_id=1');
        $this->assertSame(200, $res['status']);
        $this->assertMatchesRegularExpression('/cache-control: public, max-age=\d+/i', $res['headers']);
        $this->assertDoesNotMatchRegularExpression('/set-cookie:\s*PHPSESSID/i', $res['headers']);
    }

    public function testPersonalApiResponseIsNeverCached(): void
    {
        $res = $this->fetch('/api/auth-status.php');
        $this->assertSame(200, $res['status']);
        $this->assertMatchesRegularExpression('/cache-control: no-store/i', $res['headers']);
    }
}
