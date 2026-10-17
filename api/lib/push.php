<?php
// Tarayıcı bildirimleri (Web Push). VAPID anahtarları ortam değişkenlerindedir (VAPID_PUBLIC_KEY, VAPID_PRIVATE_KEY);
// anahtarlar yoksa her şey sessizce pasiftir. Bildirim metni kısadır: mesaj içeriği ASLA gönderilmez, yalnızca "yeni mesaj" denir.
// Gönderim en iyi çabayla yapılır: bir hata kullanıcının işlemini (takip, mesaj, liste) bozmaz.
require_once __DIR__ . '/../../vendor/autoload.php';

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

const PUSH_MAX_DEVICES = 5;

function pushConfigured(): bool {
    return (string) getenv('VAPID_PUBLIC_KEY') !== '' && (string) getenv('VAPID_PRIVATE_KEY') !== '';
}

function pushPublicKey(): ?string {
    $key = (string) getenv('VAPID_PUBLIC_KEY');
    return $key !== '' ? $key : null;
}

// Tarayıcıdan gelen abonelik nesnesini doğrular. Geçersizse null.
function pushCleanSubscription($raw): ?array {
    if (!is_array($raw)) {
        return null;
    }
    $endpoint = $raw['endpoint'] ?? null;
    $p256dh = $raw['keys']['p256dh'] ?? null;
    $auth = $raw['keys']['auth'] ?? null;
    if (!is_string($endpoint) || !preg_match('~^https://\S{10,2000}$~', $endpoint)) {
        return null;
    }
    if (!is_string($p256dh) || !preg_match('~^[A-Za-z0-9_-]{60,120}$~', $p256dh)) {
        return null;
    }
    if (!is_string($auth) || !preg_match('~^[A-Za-z0-9_-]{16,40}$~', $auth)) {
        return null;
    }
    return ['endpoint' => $endpoint, 'p256dh' => $p256dh, 'auth' => $auth];
}

// Kullanıcının cihazlarına bildirim gönderir. Süresi dolmuş abonelikler veritabanından silinir. Gönderilen sayıyı döner.
function pushSendToUser($db, int $userId, array $payload): int {
    if (!pushConfigured() || $db === null) {
        return 0;
    }
    $devices = iterator_to_array($db->push_subscriptions->find(
        ['user_id' => $userId],
        ['sort' => ['_id' => -1], 'limit' => PUSH_MAX_DEVICES]
    ), false);
    if (!$devices) {
        return 0;
    }

    $webPush = new WebPush([
        'VAPID' => [
            'subject' => defined('SITE_URL') ? SITE_URL : 'https://localhost',
            'publicKey' => getenv('VAPID_PUBLIC_KEY'),
            'privateKey' => getenv('VAPID_PRIVATE_KEY'),
        ],
    ], [], 5);

    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $sent = 0;
    foreach ($devices as $device) {
        $subscription = Subscription::create([
            'endpoint' => $device['endpoint'],
            'publicKey' => $device['p256dh'],
            'authToken' => $device['auth'],
        ]);
        // Bir cihazın hatası (bozuk anahtar, ağ sorunu) diğer cihazların gönderimini durdurmaz.
        try {
            $report = $webPush->sendOneNotification($subscription, $body, ['TTL' => 3600]);
        } catch (Throwable $e) {
            error_log('Web Push cihazına gönderilemedi: ' . $e->getMessage());
            continue;
        }
        if ($report->isSuccess()) {
            $sent++;
        } elseif ($report->isSubscriptionExpired()) {
            $db->push_subscriptions->deleteOne(['endpoint' => $device['endpoint']]);
        }
    }
    return $sent;
}

// Çağıran akışları bozmadan bildirim gönderir (hatalar günlüğe yazılır, kullanıcıya yansımaz).
function pushNotifyUser($db, int $userId, string $body, string $url = 'search.html'): void {
    try {
        pushSendToUser($db, $userId, ['title' => 'Sosyal Kütüphane', 'body' => $body, 'url' => $url]);
    } catch (Throwable $e) {
        error_log('Web Push gönderilemedi: ' . $e->getMessage());
    }
}
