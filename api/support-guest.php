<?php
// Giriş yapmamış kişinin destek talebi (ör. doğrulama kodu e-postası gelmeyen ya da giriş yapamayan biri).
//   GET  -> form belirteci ve konu listesi (belirteç açılış zamanını imzalar; sunucuda durum tutulmaz)
//   POST -> { email, category, message, token, website?, page? }
// Çerez/oturum kullanılmaz (SK_NO_SESSION); bu yüzden CSRF belirteci yoktur: istek kimlik doğrulamasına dayanmaz, bir tarayıcının
// ortam yetkisini kullanamaz. Kötüye kullanıma karşı: bal küpü alanı, en az 3 sn doldurma süresi, e-posta başına günlük ve genel saatlik
// sınır, bağlantı sayısı sınırı. Yazılan adrese kendiliğinden e-posta GİTMEZ; yalnızca yönetici yanıtı gider (bkz. lib/support-notify.php).
define('SK_NO_SESSION', true);
require_once 'config.php';
require_once __DIR__ . '/lib/support.php';
require_once __DIR__ . '/lib/support-notify.php';

header('Cache-Control: no-store');
$method = $_SERVER['REQUEST_METHOD'];
$secret = supportGuestSecret($db);

if ($method === 'GET') {
    jsonResponse([
        'success' => true,
        'token' => supportGuestToken($secret, time()),
        'categories' => supportCategoryList(),
        'limits' => ['message_min' => SUPPORT_MESSAGE_MIN, 'message_max' => SUPPORT_MESSAGE_MAX],
    ]);
}
if ($method !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Geçersiz istek yöntemi'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);
$clean = supportCleanGuestTicket($input);
if (isset($clean['spam'])) {
    // Bot: başarılı gibi yanıt verilir, hiçbir şey kaydedilmez.
    jsonResponse(['success' => true, 'message' => 'Talebin alındı.'], 201);
}
if (isset($clean['error'])) {
    jsonResponse(['success' => false, 'message' => $clean['error']], 422);
}
$tokenError = supportGuestTokenError($secret, $input['token'] ?? null, time());
if ($tokenError !== null) {
    jsonResponse(['success' => false, 'message' => $tokenError], 422);
}

if (rateLimitHit($db, 'support-guest-email:' . sha1($clean['email']), SUPPORT_GUEST_EMAIL_DAILY_LIMIT, 86400)) {
    jsonResponse(['success' => false, 'message' => 'Bu e-posta adresinden bugün çok fazla talep geldi. Yarın tekrar dene.'], 429);
}
if (rateLimitHit($db, 'support-guest-global', SUPPORT_GUEST_GLOBAL_HOURLY_LIMIT, 3600)) {
    jsonResponse(['success' => false, 'message' => 'Destek formu şu an çok yoğun. Biraz sonra tekrar dene.'], 429);
}

$now = new MongoDB\BSON\UTCDateTime();
$ticket = [
    '_id' => nextSequence($db, 'support_tickets'),
    'user_id' => null,
    'contact_email' => $clean['email'],
    'category' => $clean['category'],
    'message' => $clean['message'],
    'page' => $clean['page'],
    'status' => 'open',
    'admin_note' => null,
    'replies' => [],
    'created_at' => $now,
    'updated_at' => $now,
];
$db->support_tickets->insertOne($ticket);
supportNotifyAdmins($db, $ticket, 'new', 'Misafir: ' . $clean['email'], null);

jsonResponse(['success' => true, 'message' => 'Talebin alındı.'], 201);
