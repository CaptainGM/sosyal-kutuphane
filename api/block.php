<?php
require_once 'config.php';
requireLogin();

$userId = (int) getCurrentUserId();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    if (isset($_GET['target_user_id'])) {
        $targetUserId = (int) $_GET['target_user_id'];
        $block = $db->blocks->findOne(['blocker_id' => $userId, 'blocked_id' => $targetUserId]);
        jsonResponse(['success' => true, 'is_blocked' => $block !== null]);
    }

    $blocked = iterator_to_array($db->blocks->find(['blocker_id' => $userId]), false);
    $blockedIds = array_map(fn($b) => $b->blocked_id, $blocked);

    $usersById = [];
    if ($blockedIds) {
        foreach ($db->users->find(['_id' => ['$in' => $blockedIds]]) as $u) {
            $usersById[$u->_id] = $u;
        }
    }

    $rows = [];
    foreach ($blocked as $b) {
        $u = $usersById[$b->blocked_id] ?? null;
        if (!$u) {
            continue;
        }
        $rows[] = ['id' => $u->_id, 'username' => $u->username, 'avatar_url' => $u->avatar_url ?? null];
    }

    jsonResponse(['success' => true, 'blocked' => $rows]);

} elseif ($method === 'POST') {
    requireCsrf();
    $input = json_decode(file_get_contents('php://input'), true);
    $targetUserId = isset($input['target_user_id']) ? (int) $input['target_user_id'] : null;

    if (!$targetUserId || $targetUserId === $userId) {
        jsonResponse(['success' => false, 'message' => 'Geçersiz kullanıcı'], 400);
    }

    $db->blocks->updateOne(
        ['blocker_id' => $userId, 'blocked_id' => $targetUserId],
        ['$setOnInsert' => [
            '_id' => nextSequence($db, 'blocks'),
            'blocker_id' => $userId,
            'blocked_id' => $targetUserId,
            'created_at' => new MongoDB\BSON\UTCDateTime(),
        ]],
        ['upsert' => true]
    );

    // Engellemek iki yönlü takibi de kaldırır.
    $db->follows->deleteOne(['follower_id' => $userId, 'following_id' => $targetUserId]);
    $db->follows->deleteOne(['follower_id' => $targetUserId, 'following_id' => $userId]);

    jsonResponse(['success' => true, 'message' => 'Kullanıcı engellendi']);

} elseif ($method === 'DELETE') {
    requireCsrf();
    $input = json_decode(file_get_contents('php://input'), true);
    $targetUserId = isset($input['target_user_id']) ? (int) $input['target_user_id'] : null;

    if (!$targetUserId) {
        jsonResponse(['success' => false, 'message' => 'Geçersiz kullanıcı'], 400);
    }

    $db->blocks->deleteOne(['blocker_id' => $userId, 'blocked_id' => $targetUserId]);
    jsonResponse(['success' => true, 'message' => 'Engel kaldırıldı']);

} else {
    jsonResponse(['success' => false, 'message' => 'Geçersiz istek yöntemi'], 405);
}
?>
