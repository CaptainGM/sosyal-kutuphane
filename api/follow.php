<?php
require_once 'config.php';
requireLogin();
requireCsrf();

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);
$currentUserId = (int)getCurrentUserId();
$targetUserId = isset($input['target_user_id']) ? (int)$input['target_user_id'] : null;

if (!$targetUserId) {
    jsonResponse(['success' => false, 'message' => 'Kullanıcı ID gereklidir'], 400);
}

if ($currentUserId === $targetUserId) {
    jsonResponse(['success' => false, 'message' => 'Kendinizi takip edemezsiniz'], 400);
}

if ($method === 'POST') {
    $blocked = $db->blocks->findOne(['$or' => [
        ['blocker_id' => $currentUserId, 'blocked_id' => $targetUserId],
        ['blocker_id' => $targetUserId, 'blocked_id' => $currentUserId],
    ]]);
    if ($blocked) {
        jsonResponse(['success' => false, 'message' => 'Bu kullanıcıyı takip edemezsiniz'], 403);
    }

    $db->follows->updateOne(
        ['follower_id' => $currentUserId, 'following_id' => $targetUserId],
        ['$setOnInsert' => [
            '_id' => nextSequence($db, 'follows'),
            'follower_id' => $currentUserId,
            'following_id' => $targetUserId,
            'created_at' => new MongoDB\BSON\UTCDateTime(),
        ]],
        ['upsert' => true]
    );

    $db->notifications->insertOne([
        '_id' => nextSequence($db, 'notifications'),
        'user_id' => $targetUserId,
        'actor_id' => $currentUserId,
        'type' => 'follow',
        'content_type' => 'user',
        'content_id' => null,
        'is_read' => false,
        'created_at' => new MongoDB\BSON\UTCDateTime(),
    ]);

    jsonResponse(['success' => true, 'message' => 'Kullanıcı takip edildi']);
} elseif ($method === 'DELETE') {
    $db->follows->deleteOne(['follower_id' => $currentUserId, 'following_id' => $targetUserId]);

    jsonResponse(['success' => true, 'message' => 'Takip kaldırıldı']);
}
?>
