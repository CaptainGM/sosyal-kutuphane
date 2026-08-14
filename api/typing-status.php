<?php
require_once 'config.php';
requireLogin();

// "Yazıyor..." göstergesi: WebSocket yerine kısa TTL'li bir zaman damgası,
// messages.html 2 saniyede bir yoklar. 4 saniyeden eski damga = yazmıyor.
$userId = (int) getCurrentUserId();
$method = $_SERVER['REQUEST_METHOD'];

function typingConversationOrFail($db, int $conversationId, int $userId) {
    $conversation = $db->conversations->findOne(['_id' => $conversationId]);
    if (!$conversation || ($conversation->participant_a !== $userId && $conversation->participant_b !== $userId)) {
        jsonResponse(['success' => false, 'message' => 'Konuşma bulunamadı'], 404);
    }
    return $conversation;
}

if ($method === 'POST') {
    requireCsrf();
    $input = json_decode(file_get_contents('php://input'), true);
    $conversationId = isset($input['conversation_id']) ? (int) $input['conversation_id'] : null;
    if (!$conversationId) {
        jsonResponse(['success' => false, 'message' => 'conversation_id gerekli'], 400);
    }
    typingConversationOrFail($db, $conversationId, $userId);

    $db->typing_status->updateOne(
        ['conversation_id' => $conversationId, 'user_id' => $userId],
        ['$set' => ['updated_at' => new MongoDB\BSON\UTCDateTime()]],
        ['upsert' => true]
    );
    jsonResponse(['success' => true]);

} elseif ($method === 'GET') {
    $conversationId = isset($_GET['conversation_id']) ? (int) $_GET['conversation_id'] : null;
    if (!$conversationId) {
        jsonResponse(['success' => false, 'message' => 'conversation_id gerekli'], 400);
    }
    $conversation = typingConversationOrFail($db, $conversationId, $userId);
    $otherId = $conversation->participant_a === $userId ? $conversation->participant_b : $conversation->participant_a;

    $cutoff = new MongoDB\BSON\UTCDateTime((time() - 4) * 1000);
    $typing = $db->typing_status->findOne([
        'conversation_id' => $conversationId,
        'user_id' => $otherId,
        'updated_at' => ['$gte' => $cutoff],
    ]);

    jsonResponse(['success' => true, 'typing' => $typing !== null]);

} else {
    jsonResponse(['success' => false, 'message' => 'Geçersiz istek yöntemi'], 405);
}
?>
