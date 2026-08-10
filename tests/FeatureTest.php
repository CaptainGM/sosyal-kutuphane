<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../api/totp.php';

final class FeatureTest extends TestCase
{
    private static MongoDB\Database $db;

    public static function setUpBeforeClass(): void
    {
        $client = new MongoDB\Client(TEST_MONGO_URI);
        self::$db = $client->selectDatabase(TEST_MONGO_DB);
    }

    private function uniqueSuffix(): string
    {
        return substr(bin2hex(random_bytes(4)), 0, 8);
    }

    public function testTwoFactorSetupLoginAndBackupCodeFlow(): void
    {
        $suffix = $this->uniqueSuffix();
        $email = "phpunit_2fa_$suffix@test.com";
        $password = 'sifre123456';

        $client = new ApiClient(TEST_BASE_URL);
        $client->register("phpunit_2fa_$suffix", $email, $password);

        $start = $client->post('/api/two-factor.php', ['action' => 'start']);
        $this->assertTrue($start['data']['success'] ?? false);
        $secret = str_replace(' ', '', $start['data']['secret']);

        $wrongConfirm = $client->post('/api/two-factor.php', ['action' => 'confirm', 'code' => '000000']);
        $this->assertFalse($wrongConfirm['data']['success'] ?? true);

        $confirm = $client->post('/api/two-factor.php', ['action' => 'confirm', 'code' => totpCodeAt($secret, time())]);
        $this->assertTrue($confirm['data']['success'] ?? false);
        $backupCodes = $confirm['data']['backup_codes'];
        $this->assertCount(8, $backupCodes);

        // Yeniden giriş: şifre doğru olsa da requires_2fa dönmeli, session hemen kurulmamalı.
        $freshClient = new ApiClient(TEST_BASE_URL);
        $login = $freshClient->login($email, $password);
        $this->assertTrue($login['data']['success'] ?? false);
        $this->assertTrue($login['data']['requires_2fa'] ?? false);
        $this->assertArrayNotHasKey('user', $login['data']);

        $wrongCode = $freshClient->post('/api/verify-2fa-login.php', ['code' => '000000']);
        $this->assertSame(401, $wrongCode['status']);

        $rightCode = $freshClient->post('/api/verify-2fa-login.php', ['code' => totpCodeAt($secret, time())]);
        $this->assertTrue($rightCode['data']['success'] ?? false);
        $this->assertSame($email, $rightCode['data']['user']['email'] ?? null);

        // Yedek kod ile giriş (authenticator'a erişilemediği senaryo) — tek kullanımlık olmalı.
        $backupLoginClient = new ApiClient(TEST_BASE_URL);
        $backupLoginClient->login($email, $password);
        $usedBackupCode = $backupCodes[0];

        $firstUse = $backupLoginClient->post('/api/verify-2fa-login.php', ['code' => $usedBackupCode]);
        $this->assertTrue($firstUse['data']['success'] ?? false);

        $secondLoginClient = new ApiClient(TEST_BASE_URL);
        $secondLoginClient->login($email, $password);
        $reuseAttempt = $secondLoginClient->post('/api/verify-2fa-login.php', ['code' => $usedBackupCode]);
        $this->assertFalse($reuseAttempt['data']['success'] ?? true, 'Kullanılmış yedek kod tekrar kabul edilmemeli');

        // Devre dışı bırakma yanlış şifreyle reddedilmeli, doğrusuyla çalışmalı (zaten girişli freshClient üzerinden).
        $disableWrong = $freshClient->post('/api/two-factor.php', ['action' => 'disable', 'current_password' => 'yanlis']);
        $this->assertSame(401, $disableWrong['status']);

        $disable = $freshClient->post('/api/two-factor.php', ['action' => 'disable', 'current_password' => $password]);
        $this->assertTrue($disable['data']['success'] ?? false);
    }

    public function testRecommendationsFavorSharedGenreOverUnwatchedContent(): void
    {
        $suffix = $this->uniqueSuffix();
        $genreId = 900000 + random_int(1, 9999); // gerçek TMDB tür kimlikleriyle çakışmasın diye ayrık bir aralık

        $userA = new ApiClient(TEST_BASE_URL);
        $userA->register("phpunit_reco_a_$suffix", "phpunit_reco_a_$suffix@test.com", 'sifre123456');

        $userB = new ApiClient(TEST_BASE_URL);
        $userB->register("phpunit_reco_b_$suffix", "phpunit_reco_b_$suffix@test.com", 'sifre123456');

        $movieAId = 800000 + random_int(1, 99999);
        $movieBId = 800000 + random_int(1, 99999);

        $userA->post('/api/user-status.php', [
            'content_type' => 'movie', 'content_id' => $movieAId, 'status' => 'watched', 'rating' => 9,
            'content_data' => ['title' => 'PHPUnit Reco A', 'genres' => [$genreId]],
        ]);
        $userB->post('/api/user-status.php', [
            'content_type' => 'movie', 'content_id' => $movieBId, 'status' => 'watched', 'rating' => 8,
            'content_data' => ['title' => 'PHPUnit Reco B', 'genres' => [$genreId]],
        ]);

        $recommendations = $userA->get('/api/recommendations.php');
        $this->assertTrue($recommendations['data']['success'] ?? false);

        $recommendedIds = array_column($recommendations['data']['movies'] ?? [], 'id');
        $this->assertContains($movieBId, $recommendedIds, 'Aynı türü yüksek puanlayan kullanıcının izlemediği film önerilmeli');
        $this->assertNotContains($movieAId, $recommendedIds, 'Kullanıcının zaten izlediği film önerilmemeli');
    }

    public function testDetailPageEmitsOpenGraphTagsForCachedContent(): void
    {
        $tmdbId = 700000 + random_int(1, 99999);
        self::$db->movies->insertOne([
            '_id' => $tmdbId + 1000000,
            'tmdb_id' => $tmdbId,
            'title' => 'PHPUnit OG Filmi',
            'poster_path' => '/phpunit-poster.jpg',
            'overview' => 'PHPUnit tarafından eklenen test açıklaması.',
            'created_at' => new MongoDB\BSON\UTCDateTime(),
        ]);

        $html = file_get_contents(TEST_BASE_URL . "/detail.php?type=movie&id=$tmdbId");

        $this->assertStringContainsString('<title>PHPUnit OG Filmi - Sosyal Kütüphane</title>', $html);
        $this->assertStringContainsString('property="og:title" content="PHPUnit OG Filmi"', $html);
        $this->assertStringContainsString('PHPUnit taraf', $html); // description'ın bir kısmı (mb_substr kesebilir)
        $this->assertStringContainsString('image.tmdb.org/t/p/w500/phpunit-poster.jpg', $html);
    }

    public function testDetailPageFallsBackToGenericPreviewForUncachedContent(): void
    {
        $html = file_get_contents(TEST_BASE_URL . '/detail.php?type=movie&id=999999999');
        $this->assertStringContainsString('og:title" content="Sosyal Kütüphane"', $html);
    }

    public function testExportLibraryReturnsExpectedStructure(): void
    {
        $suffix = $this->uniqueSuffix();
        $client = new ApiClient(TEST_BASE_URL);
        $client->register("phpunit_export_$suffix", "phpunit_export_$suffix@test.com", 'sifre123456');

        $movieId = 600000 + random_int(1, 99999);
        $client->post('/api/user-status.php', [
            'content_type' => 'movie', 'content_id' => $movieId, 'status' => 'watched', 'rating' => 7,
            'content_data' => ['title' => 'PHPUnit Export Filmi'],
        ]);

        $export = $client->get('/api/export-library.php');
        $this->assertArrayHasKey('account', $export['data']);
        $this->assertArrayHasKey('watched_movies', $export['data']);
        $this->assertArrayHasKey('read_books', $export['data']);
        $this->assertArrayHasKey('custom_lists', $export['data']);
        $this->assertArrayHasKey('comments', $export['data']);

        $titles = array_column($export['data']['watched_movies'], 'title');
        $this->assertContains('PHPUnit Export Filmi', $titles);
    }
}
