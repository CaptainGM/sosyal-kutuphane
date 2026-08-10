<?php
require_once 'config.php';
requireLogin();

$userId = (int)getCurrentUserId();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $cursor = $db->notifications->find(
        ['user_id' => $userId],
        ['sort' => ['created_at' => -1], 'limit' => 50]
    );
    $rows = iterator_to_array($cursor, false);

    $actorIds = [];
    foreach ($rows as $row) {
        if (!empty($row->actor_id)) {
            $actorIds[] = $row->actor_id;
        }
    }
    $actorIds = array_values(array_unique($actorIds));

    $actors = [];
    if ($actorIds) {
        $actorDocs = $db->users->find(['_id' => ['$in' => $actorIds]]);
        foreach ($actorDocs as $actorDoc) {
            $actors[$actorDoc->_id] = $actorDoc;
        }
    }

    $notifications = [];
    foreach ($rows as $row) {
        $actorId = $row->actor_id ?? null;
        $actor = ($actorId !== null && isset($actors[$actorId])) ? $actors[$actorId] : null;
        $notifications[] = [
            'id' => $row->_id,
            'user_id' => $row->user_id,
            'actor_id' => $actorId,
            'type' => $row->type ?? null,
            'content_type' => $row->content_type ?? null,
            'content_id' => $row->content_id ?? null,
            'is_read' => $row->is_read ?? false,
            'created_at' => $row->created_at->toDateTime()->format('Y-m-d H:i:s'),
            'username' => $actor->username ?? null,
            'email' => $actor->email ?? null,
        ];
    }

    jsonResponse(['success' => true, 'notifications' => $notifications]);
} elseif ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    requireCsrf();
    $input = json_decode(file_get_contents('php://input'), true);
    $notificationId = isset($input['id']) ? (int)$input['id'] : null;

    if (!$notificationId) {
        jsonResponse(['success' => false, 'message' => 'id gereklidir'], 400);
    }

    $db->notifications->updateOne(
        ['_id' => $notificationId, 'user_id' => $userId],
        ['$set' => ['is_read' => true]]
    );

    jsonResponse(['success' => true]);
}
?>
