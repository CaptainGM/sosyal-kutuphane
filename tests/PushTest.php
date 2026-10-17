<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ApiHelpers.php';
require_once __DIR__ . '/../api/lib/push.php';

// Tarayıcı bildirimleri: abonelik doğrulama, anahtar yokken sessiz pasiflik ve uçların davranışı.
final class PushTest extends TestCase
{
    use ApiHelpers;

    private function validSubscription(): array
    {
        return [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/' . str_repeat('a', 40),
            'keys' => [
                'p256dh' => str_repeat('B', 87),
                'auth' => str_repeat('c', 22),
            ],
        ];
    }

    public function testValidSubscriptionIsAccepted(): void
    {
        $clean = pushCleanSubscription($this->validSubscription());
        $this->assertSame(str_repeat('B', 87), $clean['p256dh']);
        $this->assertSame(str_repeat('c', 22), $clean['auth']);
    }

    public function testMalformedSubscriptionsAreRejected(): void
    {
        $http = $this->validSubscription();
        $http['endpoint'] = 'http://example.com/push/abc123456';
        $this->assertNull(pushCleanSubscription($http), 'Yalnızca https');

        $short = $this->validSubscription();
        $short['keys']['auth'] = 'kisa';
        $this->assertNull(pushCleanSubscription($short), 'Kısa anahtar');

        $injected = $this->validSubscription();
        $injected['keys']['p256dh'] = str_repeat('B', 80) . '; DROP';
        $this->assertNull(pushCleanSubscription($injected), 'Geçersiz karakter');

        $this->assertNull(pushCleanSubscription('düz metin'));
        $this->assertNull(pushCleanSubscription(null));
    }

    public function testWithoutKeysNothingIsSentAndNoDatabaseIsTouched(): void
    {
        putenv('VAPID_PUBLIC_KEY');
        putenv('VAPID_PRIVATE_KEY');
        $this->assertFalse(pushConfigured());
        $this->assertNull(pushPublicKey());
        // Anahtar yoksa veritabanına bakılmadan 0 döner (null veritabanıyla bile güvenli).
        $this->assertSame(0, pushSendToUser(null, 1, ['title' => 'x', 'body' => 'y']));
    }

    public function testConfigEndpointReportsWhetherPushIsOn(): void
    {
        $guest = new ApiClient(TEST_BASE_URL);
        $res = $guest->get('/api/push-subscribe.php');
        $this->assertSame(200, $res['status']);
        $this->assertArrayHasKey('configured', $res['data']);
        $this->assertSame(pushConfigured(), $res['data']['configured']);
    }

    public function testSubscribeRequiresLoginAndValidSubscription(): void
    {
        $guest = new ApiClient(TEST_BASE_URL);
        $this->assertSame(401, $guest->post('/api/push-subscribe.php', ['subscription' => $this->validSubscription()])['status']);

        [$client] = $this->newUser('pushuser');
        $res = $client->post('/api/push-subscribe.php', ['subscription' => ['endpoint' => 'kötü']]);
        if (pushConfigured()) {
            $this->assertSame(400, $res['status']);
        } else {
            $this->assertSame(503, $res['status'], 'Anahtar yoksa açıkça 503 döner');
        }
    }
}
