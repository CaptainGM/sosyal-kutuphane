<?php
// Destek talepleri (kullanıcı tarafı). Yönetici tarafı api/admin.php içindedir (tickets, reply_ticket, resolve_ticket, reopen_ticket);
// giriş yapmamış kişilerin talebi api/support-guest.php'dedir.
//   GET  -> oturum açmış kullanıcının kendi talepleri (en yeni önce, 30 adet, yanıtlarıyla) ve konu listesi
//   POST -> yeni talep: { category, message, page? }
//   POST -> mevcut talebe yanıt: { action: 'reply', ticket_id, message } (çözülmüş talebe yazmak onu yeniden açar)
require_once 'config.php';
require_once __DIR__ . '/lib/demo.php';
require_once __DIR__ . '/lib/support.php';
require_once __DIR__ . '/lib/support-notify.php';
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

$input = json_decode(file_get_contents('php://input'), true);
$username = (string) ($_SESSION['username'] ?? 'Kullanıcı');

if (is_array($input) && ($input['action'] ?? '') === 'reply') {
    $ticketId = filter_var($input['ticket_id'] ?? null, FILTER_VALIDATE_INT);
    // Yalnızca kendi talebine yazabilir.
    $ticket = $ticketId ? $db->support_tickets->findOne(['_id' => $ticketId, 'user_id' => $userId]) : null;
    if (!$ticket) {
        jsonResponse(['success' => false, 'message' => 'Talep bulunamadı'], 404);
    }
    $reply = supportCleanReply($input['message'] ?? null);
    if (isset($reply['error'])) {
        jsonResponse(['success' => false, 'message' => $reply['error']], 422);
    }
    if (count((array) ($ticket['replies'] ?? [])) >= SUPPORT_REPLY_MAX_PER_TICKET) {
        jsonResponse(['success' => false, 'message' => 'Bu talepte yanıt sınırına ulaşıldı. Yeni bir talep aç.'], 429);
    }
    if (rateLimitHit($db, "support-reply:$userId", SUPPORT_REPLY_HOURLY_LIMIT, 3600)) {
        jsonResponse(['success' => false, 'message' => 'Çok fazla yanıt yazdın. Biraz sonra tekrar dene.'], 429);
    }

    $now = new MongoDB\BSON\UTCDateTime();
    // Kullanıcı yazınca çözülmüş talep yeniden açılır: yönetici yeni bir yanıt olduğunu görür.
    $db->support_tickets->updateOne(['_id' => $ticketId], [
        '$push' => ['replies' => ['by' => 'user', 'text' => $reply['message'], 'at' => $now]],
        '$set' => ['status' => 'open', 'updated_at' => $now],
        '$unset' => ['resolved_by' => '', 'resolved_at' => ''],
    ]);
    $fresh = $db->support_tickets->findOne(['_id' => $ticketId]);
    supportNotifyAdmins($db, $fresh, 'reply', $username, $userId);
    jsonResponse(['success' => true, 'message' => 'Yanıtın gönderildi.', 'ticket' => supportTicketRow($fresh)]);
}

$clean = supportCleanTicket($input);
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
    'replies' => [],
    'created_at' => $now,
    'updated_at' => $now,
];
$db->support_tickets->insertOne($ticket);
supportNotifyAdmins($db, $ticket, 'new', $username, $userId);

jsonResponse(['success' => true, 'message' => 'Talebin alındı.', 'ticket' => supportTicketRow($ticket)], 201);
