<?php
// Destek talepleri (kullanıcı tarafı). Yönetici tarafı api/admin.php içindedir (tickets, resolve_ticket, reopen_ticket).
//   GET  -> oturum açmış kullanıcının kendi talepleri (en yeni önce, 30 adet) ve konu listesi
//   POST -> yeni talep: { category, message, page? }
require_once 'config.php';
require_once __DIR__ . '/lib/demo.php';
require_once __DIR__ . '/lib/support.php';
require_once __DIR__ . '/lib/rate-limit.php';
requireLogin();

$userId = (int) getCurrentUserId();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $tickets = iterator_to_array($db->support_tickets->find(['user_id' => $userId], ['sort' => ['created_at' => -1], 'limit' => 30]), false);
    jsonResponse([
        'success' => true,
        'tickets' => array_map('supportTicketRow', $tickets),
        'categories' => supportCategoryList(),
        'limits' => ['message_min' => SUPPORT_MESSAGE_MIN, 'message_max' => SUPPORT_MESSAGE_MAX],
    ]);
}

if ($method !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Geçersiz istek yöntemi'], 405);
}

requireCsrf();
requireNotDemo();

$clean = supportCleanTicket(json_decode(file_get_contents('php://input'), true));
if (isset($clean['error'])) {
    jsonResponse(['success' => false, 'message' => $clean['error']], 422);
}

if ($db->support_tickets->countDocuments(['user_id' => $userId, 'status' => 'open']) >= SUPPORT_MAX_OPEN_PER_USER) {
    jsonResponse(['success' => false, 'message' => 'Çözülmemiş ' . SUPPORT_MAX_OPEN_PER_USER . ' talebin var. Yenisini açmadan önce biri çözülsün.'], 429);
}
if (rateLimitHit($db, "support-ticket:$userId", SUPPORT_HOURLY_LIMIT, 3600)) {
    jsonResponse(['success' => false, 'message' => 'Çok fazla talep gönderdin. Bir saat sonra tekrar dene.'], 429);
}

$now = new MongoDB\BSON\UTCDateTime();
$ticket = [
    '_id' => nextSequence($db, 'support_tickets'),
    'user_id' => $userId,
    'category' => $clean['category'],
    'message' => $clean['message'],
    'page' => $clean['page'],
    'status' => 'open',
    'admin_note' => null,
    'created_at' => $now,
    'updated_at' => $now,
];
$db->support_tickets->insertOne($ticket);

jsonResponse(['success' => true, 'message' => 'Talebin alındı.', 'ticket' => supportTicketRow($ticket)], 201);
