<?php
require_once 'config.php';

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET') {
    requireLogin();
}
$input = json_decode(file_get_contents('php://input'), true);
$userId = (int)getCurrentUserId();

if ($method === 'GET') {
    $contentType = $_GET['content_type'] ?? null;
    $contentId = $_GET['content_id'] ?? null;
    $allComments = isset($_GET['all']) && $_GET['all'] === 'true';
    $targetUserId = isset($_GET['user_id']) ? intval($_GET['user_id']) : null;

    $options = ['sort' => ['created_at' => -1]];
    if ($contentType && $contentId) {
        $filter = ['content_type' => $contentType, 'content_id' => (int)$contentId, 'parent_comment_id' => null];
    } elseif ($allComments) {
        $filter = ['parent_comment_id' => null];
        $options['limit'] = 100;
    } elseif ($targetUserId) {
        $filter = ['user_id' => $targetUserId, 'parent_comment_id' => null];
    } else {
        $filter = ['user_id' => $userId, 'parent_comment_id' => null];
    }

    $topComments = iterator_to_array($db->comments->find($filter, $options), false);

    $topIds = [];
    foreach ($topComments as $c) {
        $topIds[] = $c->_id;
    }

    $replies = [];
    if ($topIds) {
        $replies = iterator_to_array(
            $db->comments->find(['parent_comment_id' => ['$in' => $topIds]], ['sort' => ['created_at' => 1]]),
            false
        );
    }

    $repliesByParent = [];
    foreach ($replies as $r) {
        $repliesByParent[$r->parent_comment_id][] = $r;
    }

    $allRows = array_merge($topComments, $replies);
    $userIds = [];
    $commentIds = [];
    foreach ($allRows as $row) {
        $userIds[] = $row->user_id;
        $commentIds[] = $row->_id;
    }
    $userIds = array_values(array_unique($userIds));

    $usersById = [];
    if ($userIds) {
        foreach ($db->users->find(['_id' => ['$in' => $userIds]]) as $u) {
            $usersById[$u->_id] = $u;
        }
    }

    $likeCounts = [];
    $likedByMe = [];
    if ($commentIds) {
        foreach ($db->comment_likes->find(['comment_id' => ['$in' => $commentIds]]) as $like) {
            $cid = $like->comment_id;
            $likeCounts[$cid] = ($likeCounts[$cid] ?? 0) + 1;
            if ($like->user_id === $userId) {
                $likedByMe[$cid] = true;
            }
        }
    }

    $comments = [];
    foreach ($topComments as $c) {
        $u = $usersById[$c->user_id] ?? null;
        $row = [
            'id' => $c->_id,
            'user_id' => $c->user_id,
            'content_type' => $c->content_type,
            'content_id' => $c->content_id,
            'comment_text' => $c->comment_text,
            'parent_comment_id' => $c->parent_comment_id,
            'created_at' => $c->created_at->toDateTime()->format('Y-m-d H:i:s'),
            'updated_at' => $c->updated_at->toDateTime()->format('Y-m-d H:i:s'),
            'username' => $u->username ?? null,
            'avatar_url' => $u->avatar_url ?? null,
            'likes_count' => $likeCounts[$c->_id] ?? 0,
            'liked_by_me' => isset($likedByMe[$c->_id]) ? 1 : 0,
            'has_spoiler' => !empty($c->has_spoiler),
        ];

        $row['replies'] = [];
        foreach ($repliesByParent[$c->_id] ?? [] as $r) {
            $ru = $usersById[$r->user_id] ?? null;
            $row['replies'][] = [
                'id' => $r->_id,
                'user_id' => $r->user_id,
                'content_type' => $r->content_type,
                'content_id' => $r->content_id,
                'comment_text' => $r->comment_text,
                'parent_comment_id' => $r->parent_comment_id,
                'created_at' => $r->created_at->toDateTime()->format('Y-m-d H:i:s'),
                'updated_at' => $r->updated_at->toDateTime()->format('Y-m-d H:i:s'),
                'username' => $ru->username ?? null,
                'avatar_url' => $ru->avatar_url ?? null,
                'likes_count' => $likeCounts[$r->_id] ?? 0,
                'liked_by_me' => isset($likedByMe[$r->_id]) ? 1 : 0,
                'has_spoiler' => !empty($r->has_spoiler),
            ];
        }

        $comments[] = $row;
    }

    jsonResponse(['success' => true, 'comments' => $comments]);

} elseif ($method === 'POST') {
    requireCsrf();
    $contentType = $input['content_type'] ?? null;
    $contentId = $input['content_id'] ?? null;
    $commentText = $input['comment_text'] ?? null;
    $parentCommentId = $input['parent_comment_id'] ?? null;
    $hasSpoiler = !empty($input['has_spoiler']);

    if (!$contentType || !$contentId || !$commentText) {
        jsonResponse(['success' => false, 'message' => 'Gerekli alanlar eksik'], 400);
    }

    $commentId = nextSequence($db, 'comments');
    $now = new MongoDB\BSON\UTCDateTime();
    $db->comments->insertOne([
        '_id' => $commentId,
        'user_id' => $userId,
        'content_type' => $contentType,
        'content_id' => (int)$contentId,
        'comment_text' => $commentText,
        'parent_comment_id' => $parentCommentId !== null ? (int)$parentCommentId : null,
        'has_spoiler' => $hasSpoiler,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $user = $db->users->findOne(['_id' => $userId]);
    $newComment = [
        'id' => $commentId,
        'user_id' => $userId,
        'content_type' => $contentType,
        'content_id' => (int)$contentId,
        'comment_text' => $commentText,
        'parent_comment_id' => $parentCommentId !== null ? (int)$parentCommentId : null,
        'has_spoiler' => $hasSpoiler,
        'created_at' => $now->toDateTime()->format('Y-m-d H:i:s'),
        'updated_at' => $now->toDateTime()->format('Y-m-d H:i:s'),
        'username' => $user->username ?? null,
        'avatar_url' => $user->avatar_url ?? null,
        'likes_count' => 0,
        'liked_by_me' => 0,
    ];

    jsonResponse(['success' => true, 'comment' => $newComment, 'id' => $commentId]);

} elseif ($method === 'PUT') {
    requireCsrf();
    $commentId = $input['comment_id'] ?? null;
    $commentText = $input['comment_text'] ?? null;

    if (!$commentId || !$commentText) {
        jsonResponse(['success' => false, 'message' => 'Gerekli alanlar eksik'], 400);
    }
    $commentId = (int)$commentId;

    $comment = $db->comments->findOne(['_id' => $commentId]);

    if (!$comment) {
        jsonResponse(['success' => false, 'message' => 'Yorum bulunamadı'], 404);
    }

    if ($comment->user_id != $userId) {
        jsonResponse(['success' => false, 'message' => 'Bu yorumu düzenleme yetkiniz yok'], 403);
    }

    $db->comments->updateOne(
        ['_id' => $commentId],
        ['$set' => ['comment_text' => $commentText, 'updated_at' => new MongoDB\BSON\UTCDateTime()]]
    );

    jsonResponse(['success' => true, 'message' => 'Yorum düzenlendi']);

} elseif ($method === 'DELETE') {
    requireCsrf();
    $commentId = $input['comment_id'] ?? null;

    if (!$commentId) {
        jsonResponse(['success' => false, 'message' => 'comment_id gerekli'], 400);
    }
    $commentId = (int)$commentId;

    $comment = $db->comments->findOne(['_id' => $commentId]);

    if (!$comment) {
        jsonResponse(['success' => false, 'message' => 'Yorum bulunamadı'], 404);
    }

    if ($comment->user_id != $userId) {
        jsonResponse(['success' => false, 'message' => 'Bu yorumu silme yetkiniz yok'], 403);
    }

    // Eski MySQL şemasında ON DELETE CASCADE FK'si vardı, Mongo'da yok:
    // silinen yorum ve yanıtlarına ait beğenileri de elle temizlemek gerekiyor.
    $deletedIds = [$commentId];
    foreach ($db->comments->find(['parent_comment_id' => $commentId], ['projection' => ['_id' => 1]]) as $reply) {
        $deletedIds[] = $reply->_id;
    }

    $db->comments->deleteMany(['$or' => [['_id' => $commentId], ['parent_comment_id' => $commentId]]]);
    $db->comment_likes->deleteMany(['comment_id' => ['$in' => $deletedIds]]);

    jsonResponse(['success' => true, 'message' => 'Yorum silindi']);
}
?>
