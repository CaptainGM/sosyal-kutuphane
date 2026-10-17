<?php
// Tarayıcı bildirimi aboneliği. GET: anahtar bilgisi (herkese açık). POST: cihazı kaydet. DELETE: cihazı çıkar.
require_once 'config.php';
require_once __DIR__ . '/lib/push.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    jsonResponse(['success' => true, 'configured' => pushConfigured(), 'public_key' => pushPublicKey()]);
}

requireLogin();
requireCsrf();

$input = json_decode(file_get_contents('php://input'), true);
$input = is_array($input) ? $input : [];
$userId = (int) getCurrentUserId();

if ($method === 'POST') {
    if (!pushConfigured()) {
        jsonResponse(['success' => false, 'message' => 'Tarayıcı bildirimleri bu sunucuda açık değil'], 503);
    }
    $subscription = pushCleanSubscription($input['subscription'] ?? null);
    if ($subscription === null) {
        jsonResponse(['success' => false, 'message' => 'Geçersiz bildirim aboneliği'], 400);
    }
    $db->push_subscriptions->updateOne(
        ['endpoint' => $subscription['endpoint']],
        [
            '$set' => [
                'user_id' => $userId,
                'p256dh' => $subscription['p256dh'],
                'auth' => $subscription['auth'],
                'updated_at' => new MongoDB\BSON\UTCDateTime(),
            ],
            '$setOnInsert' => ['_id' => nextSequence($db, 'push_subscriptions'), 'created_at' => new MongoDB\BSON\UTCDateTime()],
        ],
        ['upsert' => true]
    );
    jsonResponse(['success' => true, 'message' => 'Bildirimler bu cihazda açıldı']);
}

if ($method === 'DELETE') {
    $endpoint = $input['endpoint'] ?? null;
    if (!is_string($endpoint) || !preg_match('~^https://\S{10,2000}$~', $endpoint)) {
        jsonResponse(['success' => false, 'message' => 'Geçersiz cihaz'], 400);
    }
    $db->push_subscriptions->deleteOne(['endpoint' => $endpoint, 'user_id' => $userId]);
    jsonResponse(['success' => true, 'message' => 'Bildirimler bu cihazda kapatıldı']);
}

jsonResponse(['success' => false, 'message' => 'Geçersiz istek'], 405);
