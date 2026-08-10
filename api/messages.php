<?php
require_once 'config.php';
requireLogin();

$method = $_SERVER['REQUEST_METHOD'];
$userId = (int) getCurrentUserId();

function pairKey(int $a, int $b): array {
    return $a < $b ? [$a, $b] : [$b, $a];
}

if ($method === 'GET') {
    if (isset($_GET['conversation_id'])) {
        $conversationId = (int) $_GET['conversation_id'];
        $conversation = $db->conversations->findOne(['_id' => $conversationId]);
        if (!$conversation || ($conversation->participant_a !== $userId && $conversation->participant_b !== $userId)) {
            jsonResponse(['success' => false, 'message' => 'Konuşma bulunamadı'], 404);
        }

        $messages = iterator_to_array(
            $db->messages->find(['conversation_id' => $conversationId], ['sort' => ['created_at' => 1], 'limit' => 200]),
            false
        );

        $db->messages->updateMany(
            ['conversation_id' => $conversationId, 'sender_id' => ['$ne' => $userId], 'read' => false],
            ['$set' => ['read' => true]]
        );

        $rows = [];
        foreach ($messages as $m) {
            $rows[] = [
                'id' => $m->_id,
                'conversation_id' => $m->conversation_id,
                'sender_id' => $m->sender_id,
                'text' => $m->text,
                'created_at' => $m->created_at->toDateTime()->format('Y-m-d H:i:s'),
                'read' => $m->read,
            ];
        }
        jsonResponse(['success' => true, 'messages' => $rows]);

    } elseif (isset($_GET['with'])) {
        $otherId = (int) $_GET['with'];
        [$a, $b] = pairKey($userId, $otherId);
        $conversation = $db->conversations->findOne(['participant_a' => $a, 'participant_b' => $b]);
        jsonResponse(['success' => true, 'conversation_id' => $conversation ? $conversation->_id : null]);

    } else {
        $conversations = iterator_to_array(
            $db->conversations->find(
                ['$or' => [['participant_a' => $userId], ['participant_b' => $userId]]],
                ['sort' => ['last_message_at' => -1]]
            ),
            false
        );

        $otherIds = [];
        $convIds = [];
        foreach ($conversations as $c) {
            $otherIds[] = $c->participant_a === $userId ? $c->participant_b : $c->participant_a;
            $convIds[] = $c->_id;
        }
        $otherIds = array_values(array_unique($otherIds));

        $usersById = [];
        if ($otherIds) {
            foreach ($db->users->find(['_id' => ['$in' => $otherIds]]) as $u) {
                $usersById[$u->_id] = $u;
            }
        }

        $unreadCounts = [];
        if ($convIds) {
            foreach ($db->messages->find(['conversation_id' => ['$in' => $convIds], 'sender_id' => ['$ne' => $userId], 'read' => false]) as $m) {
                $unreadCounts[$m->conversation_id] = ($unreadCounts[$m->conversation_id] ?? 0) + 1;
            }
        }

        $rows = [];
        foreach ($conversations as $c) {
            $otherId = $c->participant_a === $userId ? $c->participant_b : $c->participant_a;
            $other = $usersById[$otherId] ?? null;
            $rows[] = [
                'conversation_id' => $c->_id,
                'other_user' => $other ? ['id' => $other->_id, 'username' => $other->username, 'avatar_url' => $other->avatar_url ?? null] : null,
                'last_message' => $c->last_message,
                'last_message_at' => $c->last_message_at->toDateTime()->format('Y-m-d H:i:s'),
                'unread_count' => $unreadCounts[$c->_id] ?? 0,
            ];
        }
        jsonResponse(['success' => true, 'conversations' => $rows]);
    }

} elseif ($method === 'POST') {
    requireCsrf();
    $input = json_decode(file_get_contents('php://input'), true);
    $text = trim($input['text'] ?? '');
    $conversationIdInput = isset($input['conversation_id']) ? (int) $input['conversation_id'] : null;
    $toUserId = isset($input['to_user_id']) ? (int) $input['to_user_id'] : null;

    if ($text === '') {
        jsonResponse(['success' => false, 'message' => 'Mesaj boş olamaz'], 400);
    }
    if (mb_strlen($text) > 2000) {
        jsonResponse(['success' => false, 'message' => 'Mesaj çok uzun (maks. 2000 karakter)'], 400);
    }

    if ($conversationIdInput) {
        $conversation = $db->conversations->findOne(['_id' => $conversationIdInput]);
        if (!$conversation || ($conversation->participant_a !== $userId && $conversation->participant_b !== $userId)) {
            jsonResponse(['success' => false, 'message' => 'Konuşma bulunamadı'], 404);
        }
        $conversationId = $conversation->_id;
        $toUserId = $conversation->participant_a === $userId ? $conversation->participant_b : $conversation->participant_a;
    } else {
        if (!$toUserId || $toUserId === $userId) {
            jsonResponse(['success' => false, 'message' => 'Geçersiz alıcı'], 400);
        }
        if (!$db->users->findOne(['_id' => $toUserId])) {
            jsonResponse(['success' => false, 'message' => 'Kullanıcı bulunamadı'], 404);
        }
        [$a, $b] = pairKey($userId, $toUserId);
        $existing = $db->conversations->findOne(['participant_a' => $a, 'participant_b' => $b]);
        if ($existing) {
            $conversationId = $existing->_id;
        } else {
            $conversationId = nextSequence($db, 'conversations');
            $db->conversations->insertOne([
                '_id' => $conversationId,
                'participant_a' => $a,
                'participant_b' => $b,
                'last_message' => '',
                'last_message_at' => new MongoDB\BSON\UTCDateTime(),
                'created_at' => new MongoDB\BSON\UTCDateTime(),
            ]);
        }
    }

    $now = new MongoDB\BSON\UTCDateTime();
    $messageId = nextSequence($db, 'messages');
    $db->messages->insertOne([
        '_id' => $messageId,
        'conversation_id' => $conversationId,
        'sender_id' => $userId,
        'text' => $text,
        'created_at' => $now,
        'read' => false,
    ]);

    $db->conversations->updateOne(
        ['_id' => $conversationId],
        ['$set' => ['last_message' => mb_substr($text, 0, 100), 'last_message_at' => $now]]
    );

    $db->notifications->insertOne([
        '_id' => nextSequence($db, 'notifications'),
        'user_id' => $toUserId,
        'actor_id' => $userId,
        'type' => 'message',
        'content_type' => 'conversation',
        'content_id' => $conversationId,
        'is_read' => false,
        'created_at' => $now,
    ]);

    jsonResponse([
        'success' => true,
        'conversation_id' => $conversationId,
        'message' => [
            'id' => $messageId,
            'conversation_id' => $conversationId,
            'sender_id' => $userId,
            'text' => $text,
            'created_at' => $now->toDateTime()->format('Y-m-d H:i:s'),
            'read' => false,
        ],
    ]);

} else {
    jsonResponse(['success' => false, 'message' => 'Geçersiz istek yöntemi'], 405);
}
?>
