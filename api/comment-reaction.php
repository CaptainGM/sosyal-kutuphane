<?php
// Yoruma tepki ver (POST: comment_id, type) ya da geri al (DELETE: comment_id).
// Demo hesaplar tepki veremez; kendi yorumuna tepki verilmez.
require_once 'config.php';
require_once __DIR__ . '/lib/demo.php';
require_once __DIR__ . '/lib/reactions.php';

requireLogin();
requireCsrf();
requireNotDemo();

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);
$input = is_array($input) ? $input : [];

$commentId = filter_var($input['comment_id'] ?? null, FILTER_VALIDATE_INT);
if (!$commentId) {
    jsonResponse(['success' => false, 'message' => 'Geçersiz yorum'], 400);
}

$comment = $db->comments->findOne(['_id' => (int) $commentId], ['projection' => ['user_id' => 1]]);
if (!$comment) {
    jsonResponse(['success' => false, 'message' => 'Yorum bulunamadı'], 404);
}

$userId = (int) getCurrentUserId();
if ((int) $comment['user_id'] === $userId) {
    jsonResponse(['success' => false, 'message' => 'Kendi yorumuna tepki veremezsin'], 403);
}

if ($method === 'POST') {
    $type = $input['type'] ?? '';
    if (!is_string($type) || !isset(COMMENT_REACTIONS[$type])) {
        jsonResponse(['success' => false, 'message' => 'Geçersiz tepki'], 400);
    }
    $db->comment_reactions->updateOne(
        ['comment_id' => (int) $commentId, 'user_id' => $userId],
        [
            '$set' => ['type' => $type, 'updated_at' => new MongoDB\BSON\UTCDateTime()],
            '$setOnInsert' => ['_id' => nextSequence($db, 'comment_reactions'), 'created_at' => new MongoDB\BSON\UTCDateTime()],
        ],
        ['upsert' => true]
    );
    jsonResponse([
        'success' => true,
        'my_reaction' => $type,
        'reactions' => commentReactionCounts($db, (int) $commentId),
    ]);
}

if ($method === 'DELETE') {
    $db->comment_reactions->deleteOne(['comment_id' => (int) $commentId, 'user_id' => $userId]);
    jsonResponse([
        'success' => true,
        'my_reaction' => null,
        'reactions' => commentReactionCounts($db, (int) $commentId),
    ]);
}

jsonResponse(['success' => false, 'message' => 'Geçersiz istek'], 405);
