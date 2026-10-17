<?php

use PHPUnit\Framework\TestCase;

// Sunucu yönlendiriciyle (router.php) çalışmalı: gizli dosyalar sunulmaz, statik dosyalar önbellek başlığı taşır,
// herkese açık API yanıtlarında oturum çerezi bulunmaz.
final class RouterTest extends TestCase
{
    /** @return array{status:int, headers:string, body:string} */
    private function fetch(string $path): array
    {
        $ch = curl_init(TEST_BASE_URL . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_PATH_AS_IS => true,
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
