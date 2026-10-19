<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../api/lib/profile.php';
require_once __DIR__ . '/../api/lib/image-moderation.php';
require_once __DIR__ . '/ApiHelpers.php';

// Profil alanı kuralları (saf) ve HTTP katmanındaki kayıt davranışı (çalışan sunucu ve MongoDB gerekir, bkz. ApiTest).
final class ProfileTest extends TestCase
{
    use ApiHelpers;

    public function testBioAcceptsPlainTextAndEmpty(): void
    {
        $this->assertNull(profileBioError('Film ve kitap tutkunu. Favorim: Bilim Kurgu'));
        $this->assertNull(profileBioError(''));
    }

    public function testBioRejectsLinksIbanAndProfanity(): void
    {
        $this->assertNotNull(profileBioError('Beni takip et: https://spam.example'));
        $this->assertNotNull(profileBioError('iletisim: ben@mail.com'));
        $this->assertNotNull(profileBioError('TR33 0006 1005 1978 6457 8413 26'));
        $this->assertNotNull(profileBioError('Sen tam bir aptal birisin'));
    }

    public function testBioLengthLimitCountsCharactersNotBytes(): void
    {
        $this->assertNull(profileBioError(str_repeat('ş', PROFILE_BIO_MAX_LENGTH)));
        $this->assertNotNull(profileBioError(str_repeat('ş', PROFILE_BIO_MAX_LENGTH + 1)));
    }

    public function testAvatarKinds(): void
    {
        $this->assertSame('url', profileAvatarKind('https://example.com/a.png'));
        $this->assertSame('emoji', profileAvatarKind('🧔'));
        // ZWJ ile birleşen ve varyasyon seçicili emojiler
        $this->assertSame('emoji', profileAvatarKind('👨‍🦱'));
        $this->assertSame('emoji', profileAvatarKind('👨‍⚕️'));
        $this->assertSame('emoji', profileAvatarKind('🧑‍💻'));
    }

    public function testAvatarRejectsDangerousOrPlainValues(): void
    {
        foreach (['', 'javascript:alert(1)', 'data:text/html;base64,AAAA', '<img src=x onerror=alert(1)>', 'merhaba', '😀<script>', '/uploads/avatars/x.png', 'ftp://example.com/a.png'] as $value) {
            $this->assertNull(profileAvatarKind($value), "kabul edilmemeli: $value");
        }
        $this->assertNull(profileAvatarKind(str_repeat('😀', PROFILE_AVATAR_MAX_EMOJI + 1)));
    }

    public function testUploadedAvatarPathOnlyForOwnFiles(): void
    {
        $name = str_repeat('a1', 16) . '.jpg';
        $this->assertNotNull(profileUploadedAvatarPath("/uploads/avatars/$name"));
        $this->assertNull(profileUploadedAvatarPath('/uploads/avatars/../../config.php'));
        $this->assertNull(profileUploadedAvatarPath('https://example.com/uploads/avatars/' . $name));
        $this->assertNull(profileUploadedAvatarPath(null));
        $this->assertNull(profileUploadedAvatarPath('🧔'));
    }

    public function testBioAndEmojiAvatarAreSavedAndReadBack(): void
    {
        [$client, $id] = $this->newUser('phpunit_profile');

        $saved = $client->post('/api/user-profile.php', ['bio' => '  Bilim kurgu ve polisiye  ', 'avatar' => '🧔']);
        $this->assertTrue($saved['data']['success'] ?? false, json_encode($saved['data']));
        $this->assertSame('Bilim kurgu ve polisiye', $saved['data']['bio']);

        $read = $client->get('/api/user-profile.php?id=' . $id);
        $this->assertSame('Bilim kurgu ve polisiye', $read['data']['user']['bio']);
        $this->assertSame('🧔', $read['data']['user']['avatar_url']);

        $status = $client->get('/api/auth-status.php');
        $this->assertSame('Bilim kurgu ve polisiye', $status['data']['user']['bio']);
    }

    public function testBioWithLinkIsRejectedAndNotSaved(): void
    {
        [$client, $id] = $this->newUser('phpunit_profile');

        $res = $client->post('/api/user-profile.php', ['bio' => 'Gel: www.spam.example']);
        $this->assertSame(422, $res['status']);

        $read = $client->get('/api/user-profile.php?id=' . $id);
        $this->assertEmpty($read['data']['user']['bio'] ?? null);
    }

    public function testBadAvatarAndEmptyBodyAreRejected(): void
    {
        [$client] = $this->newUser('phpunit_profile');

        $this->assertSame(400, $client->post('/api/user-profile.php', ['avatar' => 'javascript:alert(1)'])['status']);
        $this->assertSame(400, $client->post('/api/user-profile.php', ['avatar' => ['x']])['status']);
        $this->assertSame(400, $client->post('/api/user-profile.php', [])['status']);
        $this->assertSame(400, $client->post('/api/user-profile.php', ['bio' => ['x']])['status']);
    }

    public function testExternalImageUrlAvatarIsRejected(): void
    {
        [$client, $id] = $this->newUser('phpunit_profile');
        $client->post('/api/user-profile.php', ['avatar' => '🧔']);

        $res = $client->post('/api/user-profile.php', ['avatar' => 'https://example.com/baska-site.jpg']);
        $this->assertSame(400, $res['status'], 'Başka sitedeki görsel yüklenirken denetlenemez, bu yüzden avatar olamaz');
        $this->assertStringContainsString('emoji', $res['data']['message']);

        $read = $client->get('/api/user-profile.php?id=' . $id);
        $this->assertSame('🧔', $read['data']['user']['avatar_url']);
    }

    // --- Fotoğraf yükleme. Yapay zekâ kontrolü açıksa gerçek ağ isteği yapılacağından bu testler atlanır (CI'da kapalıdır). ---

    private function tinyPng(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'avatar_test_');
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
        return $path;
    }

    private function skipWhenImageCheckIsOn(): void
    {
        if (imageModerationConfig() !== null) {
            $this->markTestSkipped('Yapay zekâ görsel kontrolü açık: bu test gerçek bir ağ isteği yapardı.');
        }
    }

    public function testAvatarUploadSavesPhotoAndReplacesOldOne(): void
    {
        $this->skipWhenImageCheckIsOn();
        [$client, $id] = $this->newUser('phpunit_upload');
        $png = $this->tinyPng();

        $first = $client->upload('/api/upload-avatar.php', 'avatar', $png, 'image/png', 'a.png');
        $this->assertSame(200, $first['status'], json_encode($first['data']));
        $this->assertMatchesRegularExpression('~^/uploads/avatars/[a-f0-9]{32}\.png$~', $first['data']['avatar_url']);
        $firstFile = dirname(__DIR__) . $first['data']['avatar_url'];
        $this->assertFileExists($firstFile);

        $second = $client->upload('/api/upload-avatar.php', 'avatar', $png, 'image/png', 'b.png');
        $secondFile = dirname(__DIR__) . $second['data']['avatar_url'];
        $this->assertFileExists($secondFile);
        $this->assertFileDoesNotExist($firstFile, 'Önceki yüklenen fotoğraf diskte kalmamalı');
        $this->assertNull($this->mongo()->avatar_files->findOne(['_id' => basename($first['data']['avatar_url'])]), 'Eski fotoğrafın depodaki kopyası da silinir');
        $this->assertNotNull($this->mongo()->avatar_files->findOne(['_id' => basename($second['data']['avatar_url'])]));

        $read = $client->get('/api/user-profile.php?id=' . $id);
        $this->assertSame($second['data']['avatar_url'], $read['data']['user']['avatar_url']);

        @unlink($secondFile);
        @unlink($png);
    }

    private function rawGet(string $path): array
    {
        $ch = curl_init(TEST_BASE_URL . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true]);
        $raw = curl_exec($ch);
        $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $status, 'headers' => substr((string) $raw, 0, $size), 'body' => substr((string) $raw, $size)];
    }

    public function testUploadedPhotoIsStillServedAfterTheHostsDiskIsWiped(): void
    {
        $this->skipWhenImageCheckIsOn();
        [$client] = $this->newUser('phpunit_upload');
        $png = $this->tinyPng();
        $url = $client->upload('/api/upload-avatar.php', 'avatar', $png, 'image/png', 'a.png')['data']['avatar_url'];
        $this->assertNotNull($this->mongo()->avatar_files->findOne(['_id' => basename($url)]), 'Fotoğraf MongoDB\'de de saklanır');

        // Render gibi barındırıcılar diski her dağıtımda sıfırlar: disk kopyası gidince fotoğraf depodan sunulur.
        unlink(dirname(__DIR__) . $url);
        $res = $this->rawGet($url);
        $this->assertSame(200, $res['status']);
        $this->assertMatchesRegularExpression('/content-type: image\/png/i', $res['headers']);
        $this->assertMatchesRegularExpression('/cache-control: public, max-age=\d+, immutable/i', $res['headers']);
        $this->assertSame(file_get_contents($png), $res['body'], 'Baytlar yüklenenle aynı olmalı');

        $this->mongo()->avatar_files->deleteOne(['_id' => basename($url)]);
        @unlink($png);
    }

    public function testUnknownUploadedPhotoNamesAreNotFound(): void
    {
        $this->assertSame(404, $this->rawGet('/uploads/avatars/' . str_repeat('0', 32) . '.png')['status']);
        $this->assertSame(404, $this->rawGet('/uploads/avatars/' . str_repeat('0', 32) . '.gif')['status']);
        $this->assertSame(404, $this->rawGet('/uploads/avatars/gecersiz.png')['status']);
    }

    public function testSwitchingToAnEmojiRemovesTheStoredPhoto(): void
    {
        $this->skipWhenImageCheckIsOn();
        [$client] = $this->newUser('phpunit_upload');
        $png = $this->tinyPng();
        $url = $client->upload('/api/upload-avatar.php', 'avatar', $png, 'image/png', 'a.png')['data']['avatar_url'];

        $this->assertSame(200, $client->post('/api/user-profile.php', ['avatar' => '🧔'])['status']);
        $this->assertNull($this->mongo()->avatar_files->findOne(['_id' => basename($url)]), 'Emojiye geçilince fotoğraf depodan silinir');
        $this->assertFileDoesNotExist(dirname(__DIR__) . $url);
        @unlink($png);
    }

    public function testAvatarUploadRejectsNonImagesAndMissingFile(): void
    {
        [$client] = $this->newUser('phpunit_upload');
        $text = tempnam(sys_get_temp_dir(), 'avatar_test_');
        file_put_contents($text, 'bu bir resim degil');

        $res = $client->upload('/api/upload-avatar.php', 'avatar', $text, 'image/png', 'sahte.png');
        $this->assertSame(400, $res['status'], 'Uzantı ya da MIME başlığı değil, dosyanın içeriği denetlenir');
        $this->assertSame(400, $client->upload('/api/upload-avatar.php', 'baska_alan', $text, 'image/png', 'x.png')['status']);
        @unlink($text);
    }

    public function testAvatarUploadIsRateLimitedPerUser(): void
    {
        $this->skipWhenImageCheckIsOn();
        [$client] = $this->newUser('phpunit_upload');
        $png = $this->tinyPng();
        $files = [];

        for ($i = 1; $i <= 10; $i++) {
            $res = $client->upload('/api/upload-avatar.php', 'avatar', $png, 'image/png', "a$i.png");
            $this->assertSame(200, $res['status'], "$i. yükleme geçmeli");
            $files[] = dirname(__DIR__) . $res['data']['avatar_url'];
        }
        $blocked = $client->upload('/api/upload-avatar.php', 'avatar', $png, 'image/png', 'a11.png');
        $this->assertSame(429, $blocked['status'], 'Saatte 10 denemeden sonrası reddedilir');
        $this->assertStringContainsString('Çok fazla', $blocked['data']['message']);

        foreach ($files as $file) {
            @unlink($file);
        }
        @unlink($png);
    }

    public function testProfileUpdateRequiresLogin(): void
    {
        $anonymous = new ApiClient(TEST_BASE_URL);
        $this->assertSame(401, $anonymous->post('/api/user-profile.php', ['bio' => 'x'])['status']);
    }
}
