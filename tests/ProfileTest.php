<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../api/lib/profile.php';
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

    public function testProfileUpdateRequiresLogin(): void
    {
        $anonymous = new ApiClient(TEST_BASE_URL);
        $this->assertSame(401, $anonymous->post('/api/user-profile.php', ['bio' => 'x'])['status']);
    }
}
