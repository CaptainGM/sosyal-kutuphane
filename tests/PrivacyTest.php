<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ApiHelpers.php';

// Gizlilik ve kötüye kullanım: e-posta adresleri başkasına görünmez; e-posta gönderen uçlar adres başına sınırlıdır.
// Çalışan bir sunucu ve MongoDB gerekir (bkz. ApiTest). Test ortamında e-posta ayarı yoktur, gönderim başarısız olur;
// sınırlar gönderimden önce sayıldığı için sonuç yine de sınanabilir.
final class PrivacyTest extends TestCase
{
    use ApiHelpers;

    public function testStatsOfAnotherUserNeverExposeTheirEmail(): void
    {
        [$owner, $ownerId, $ownerEmail] = $this->newUser('phpunit_priv');
        [$other] = $this->newUser('phpunit_priv');
        $anonymous = new ApiClient(TEST_BASE_URL);

        $self = $owner->get('/api/user-stats.php');
        $this->assertSame($ownerEmail, $self['data']['email'], 'Kendi istatistiğinde e-posta görünür');
        $this->assertSame($ownerEmail, $owner->get('/api/user-stats.php?user_id=' . $ownerId)['data']['email']);

        $this->assertNull($other->get('/api/user-stats.php?user_id=' . $ownerId)['data']['email'], 'Başka kullanıcı e-postayı göremez');
        $viaAnonymous = $anonymous->get('/api/user-stats.php?user_id=' . $ownerId);
        $this->assertSame(200, $viaAnonymous['status']);
        $this->assertNull($viaAnonymous['data']['email'], 'Giriş yapmamış biri kimlik numarası deneyerek e-posta toplayamaz');
        $this->assertStringNotContainsString($ownerEmail, json_encode($viaAnonymous['data']));
    }

    public function testNotificationsDoNotCarryTheActorsEmail(): void
    {
        [$follower, , $followerEmail] = $this->newUser('phpunit_priv');
        [$target, $targetId] = $this->newUser('phpunit_priv');
        $follower->post('/api/follow.php', ['target_user_id' => $targetId]);

        $notifications = $target->get('/api/notifications.php')['data']['notifications'];
        $follows = array_values(array_filter($notifications, static fn ($n) => $n['type'] === 'follow'));
        $this->assertCount(1, $follows);
        $this->assertNotEmpty($follows[0]['username'], 'Kullanıcı adı bildirimde kalır');
        $this->assertArrayNotHasKey('email', $follows[0]);
        $this->assertStringNotContainsString($followerEmail, json_encode($notifications));
    }

    public function testSessionIdChangesWhenLoggingIn(): void
    {
        [, , $email] = $this->newUser('phpunit_sess');
        $client = new ApiClient(TEST_BASE_URL);
        $client->get('/api/auth-status.php');
        $before = $client->sessionCookie();
        $this->assertNotNull($before, 'Ziyaretçiye oturum çerezi verilir');

        $login = $client->login($email, 'Sifre123456!');
        $this->assertTrue($login['data']['success']);
        $after = $client->sessionCookie();
        $this->assertNotNull($after);
        $this->assertNotSame($before, $after, 'Girişten önce bilinen oturum kimliği giriş sonrasında geçersiz olmalı (oturum sabitleme)');
        $this->assertNotEmpty($login['data']['csrf_token']);

        // Eski kimlikle gelen istek giriş yapmış sayılmaz
        $ch = curl_init(TEST_BASE_URL . '/api/auth-status.php');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Cookie: PHPSESSID=' . $before]]);
        $stale = json_decode((string) curl_exec($ch), true);
        curl_close($ch);
        $this->assertEmpty($stale['user'] ?? null, 'Eski oturum kimliği artık kullanıcıya bağlı değil');

        $this->assertNotEmpty($client->get('/api/auth-status.php')['data']['user'], 'Yeni kimlikle oturum açık');
    }

    public function testSessionIdChangesWhenRegistrationIsVerified(): void
    {
        $client = new ApiClient(TEST_BASE_URL);
        $client->get('/api/auth-status.php');
        $before = $client->sessionCookie();

        $suffix = $this->suffix();
        $client->register("phpunit_sess_$suffix", "phpunit_sess_$suffix@test.com", 'Sifre123456!');
        $this->assertNotSame($before, $client->sessionCookie(), 'Kayıt doğrulanıp oturum açılırken kimlik yenilenir');
        $this->assertSame("phpunit_sess_$suffix", $client->get('/api/auth-status.php')['data']['user']['username']);
    }

    public function testPasswordResetEmailsAreLimitedPerAddress(): void
    {
        [, , $email] = $this->newUser('phpunit_priv');
        $anonymous = new ApiClient(TEST_BASE_URL);
        $tokenOf = fn () => $this->mongo()->password_resets->findOne(['email' => $email])['token'] ?? null;

        $tokens = [];
        for ($i = 1; $i <= 3; $i++) {
            $res = $anonymous->post('/api/forgot-password.php', ['email' => $email]);
            $this->assertSame(200, $res['status']);
            $tokens[$i] = $tokenOf();
            $this->assertNotNull($tokens[$i], "$i. istek sıfırlama kaydı oluşturmalı");
            if ($i > 1) {
                $this->assertNotSame($tokens[$i - 1], $tokens[$i], 'Her istek yeni bağlantı üretir');
            }
        }

        $fourth = $anonymous->post('/api/forgot-password.php', ['email' => $email]);
        $this->assertSame(200, $fourth['status'], 'Sınır aşılınca da aynı başarılı yanıt döner (hesap var mı belli olmasın)');
        $this->assertSame($fourth['data']['message'], $anonymous->post('/api/forgot-password.php', ['email' => 'yok_' . $this->suffix() . '@test.com'])['data']['message']);
        $this->assertSame($tokens[3], $tokenOf(), 'Dördüncü istekte yeni bağlantı üretilmez, e-posta gönderilmez');
    }

    public function testRegistrationCodesAreLimitedPerAddress(): void
    {
        $suffix = $this->suffix();
        $email = "phpunit_reg_$suffix@test.com";
        $anonymous = new ApiClient(TEST_BASE_URL);
        $body = ['username' => "phpunit_reg_$suffix", 'email' => $email, 'password' => 'Sifre123456!'];

        for ($i = 1; $i <= 5; $i++) {
            $this->assertSame(200, $anonymous->post('/api/register.php', $body)['status'], "$i. kayıt denemesi geçmeli");
        }
        $sixth = $anonymous->post('/api/register.php', $body);
        $this->assertSame(429, $sixth['status']);
        $this->assertStringContainsString('çok fazla deneme', $sixth['data']['message']);
    }

    public function testRegistrationResendHasAnHourlyCapOnTopOfTheMinuteCooldown(): void
    {
        $suffix = $this->suffix();
        $email = "phpunit_resend_$suffix@test.com";
        $anonymous = new ApiClient(TEST_BASE_URL);
        $anonymous->post('/api/register.php', ['username' => "phpunit_resend_$suffix", 'email' => $email, 'password' => 'Sifre123456!']);

        $statuses = [];
        for ($i = 1; $i <= 11; $i++) {
            // Bir dakikalık bekleme süresini atlamak için kaydın zamanı geriye alınır.
            $this->mongo()->pending_registrations->updateOne(['email' => $email], ['$set' => ['created_at' => new MongoDB\BSON\UTCDateTime((time() - 120) * 1000)]]);
            $statuses[] = $anonymous->post('/api/register.php', ['action' => 'resend', 'email' => $email])['status'];
        }
        $this->assertNotContains(429, array_slice($statuses, 0, 10), 'İlk 10 istek sınırlanmaz (e-posta ayarı yoksa 503 dönebilir)');
        $this->assertSame(429, $statuses[10], 'Saatte 10 koddan sonrası reddedilir');
    }
}
