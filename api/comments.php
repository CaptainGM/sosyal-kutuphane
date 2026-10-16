<?php
require_once 'config.php';
require_once __DIR__ . '/lib/moderation.php';
require_once __DIR__ . '/lib/content-snapshot.php';
require_once __DIR__ . '/lib/episodes.php';
require_once __DIR__ . '/lib/audit.php';
require_once __DIR__ . '/lib/social.php';

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET') {
    requireLogin();
}
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}
$userId = (int)getCurrentUserId();

// Yorumlar yalnızca tanınan içerik türlerine bağlanır. Film ve dizi kimlikleri sayı, kitap kimlikleri (Google Books) metindir.
function commentContentType($raw): ?string {
    return is_string($raw) && in_array($raw, ['movie', 'series', 'book'], true) ? $raw : null;
}

function commentContentId(string $type, $raw) {
    if ($type === 'book') {
        return is_string($raw) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $raw) ? $raw : null;
    }
    $id = filter_var($raw, FILTER_VALIDATE_INT);
    return ($id !== false && $id > 0) ? $id : null;
}

// Zamanlar UTC olarak ISO 8601 (+00:00 ile) gönderilir; tarayıcı yerel saat dilimine karışmaz.
function commentIso($utcDateTime): string {
    return $utcDateTime->toDateTime()->format(DATE_ATOM);
}

// Düzenleme, ilk yazımdan en az bir saniye sonraysa işaretlenir (aynı istekte yazılan yorumlar etiketlenmez).
function commentIsEdited($comment): bool {
    $created = $comment['created_at'];
    $updated = $comment['updated_at'] ?? $created;
    return (int)(string)$updated - (int)(string)$created >= 1000;
}

function commentPayload($comment, array $usersById, array $likeCounts, array $likedByMe): array {
    $id = $comment['_id'];
    $user = $usersById[$comment['user_id']] ?? null;
    $updated = $comment['updated_at'] ?? $comment['created_at'];
    return [
        'id' => $id,
        'user_id' => $comment['user_id'],
        'content_type' => $comment['content_type'],
        'content_id' => $comment['content_id'],
        'comment_text' => $comment['comment_text'],
        'parent_comment_id' => $comment['parent_comment_id'] ?? null,
        'season_number' => $comment['season_number'] ?? null,
        'episode_number' => $comment['episode_number'] ?? null,
        'created_at' => commentIso($comment['created_at']),
        'updated_at' => commentIso($updated),
        'edited' => commentIsEdited($comment),
        'username' => $user['username'] ?? null,
        'avatar_url' => $user['avatar_url'] ?? null,
        'likes_count' => $likeCounts[$id] ?? 0,
        'liked_by_me' => isset($likedByMe[$id]) ? 1 : 0,
        'has_spoiler' => !empty($comment['has_spoiler']),
    ];
}

// Yönetici bir kullanıcıyı susturmuşsa yorum yazamaz (okuyabilir, beğenebilir).
function commentMutedMessage($db, int $userId): ?string {
    $user = $db->users->findOne(['_id' => $userId], ['projection' => ['muted_until' => 1]]);
    $until = $user['muted_until'] ?? null;
    if (!$until) {
        return null;
    }
    $seconds = $until->toDateTime()->getTimestamp() - time();
    if ($seconds <= 0) {
        return null;
    }
    $hours = (int)ceil($seconds / 3600);
    return $hours >= 48
        ? 'Yorum yapman geçici olarak kısıtlandı (yaklaşık ' . (int)ceil($hours / 24) . ' gün).'
        : 'Yorum yapman geçici olarak kısıtlandı (yaklaşık ' . $hours . ' saat).';
}

if ($method === 'GET') {
    $hasContentParams = isset($_GET['content_type']) || isset($_GET['content_id']);
    $contentType = commentContentType($_GET['content_type'] ?? null);
    $contentId = $contentType ? commentContentId($contentType, $_GET['content_id'] ?? null) : null;
    $allComments = isset($_GET['all']) && $_GET['all'] === 'true';
    $followingOnly = isset($_GET['following']) && $_GET['following'] === 'true';
    $targetUserId = isset($_GET['user_id']) ? intval($_GET['user_id']) : null;
    $wantsThread = isset($_GET['season']) || isset($_GET['episode']);

    $options = ['sort' => ['created_at' => -1]];
    // Bölüm sohbeti yorumları (episode_number dolu) genel listelerde asla görünmez: spoiler sızmasın diye yalnızca
    // bölüm sohbeti uç noktasından, izlediğini işaretleyen kullanıcıya verilir.
    $general = ['parent_comment_id' => null, 'episode_number' => null];

    if ($hasContentParams) {
        if ($contentId === null) {
            jsonResponse(['success' => false, 'message' => 'Geçersiz içerik'], 400);
        }

        if ($wantsThread) {
            $ref = episodeRef($_GET['season'] ?? null, $_GET['episode'] ?? null);
            if ($ref === null || $contentType !== 'series') {
                jsonResponse(['success' => false, 'message' => 'Geçersiz bölüm'], 400);
            }
            requireLogin();
            $threadFilter = ['content_type' => 'series', 'content_id' => $contentId, 'season_number' => $ref['season'], 'episode_number' => $ref['episode'], 'parent_comment_id' => null];
            $watched = episodeWatchedMap($db, $userId, $contentId);
            if (!episodeThreadUnlocked($watched, $ref['season'], $ref['episode'])) {
                jsonResponse([
                    'success' => true,
                    'locked' => true,
                    'thread_count' => $db->comments->countDocuments($threadFilter),
                    'comments' => [],
                ]);
            }
            $filter = $threadFilter;
        } else {
            $filter = ['content_type' => $contentType, 'content_id' => $contentId] + $general;
        }
    } elseif ($followingOnly) {
        requireLogin();
        $filter = ['user_id' => ['$in' => followedUserIds($db, $userId)]] + $general;
        $options['limit'] = 60;
    } elseif ($allComments) {
        $filter = $general;
        $options['limit'] = 100;
    } elseif ($targetUserId) {
        $filter = ['user_id' => $targetUserId] + $general;
    } else {
        $filter = ['user_id' => $userId] + $general;
    }

    $topComments = iterator_to_array($db->comments->find($filter, $options), false);

    // İçerik kartı (başlık ve afiş) sosyal ve profil listelerinde gösterilir; içerik sayfasındaki liste bunu kullanmaz.
    // Eski yorumların özeti yoksa bu istekte en fazla 10 tanesi tamamlanır.
    $withContent = !$hasContentParams;
    $snapshots = [];
    if ($withContent) {
        $filled = 0;
        foreach ($topComments as $c) {
            $snapshot = ['title' => $c['content_title'] ?? null, 'poster' => $c['content_poster'] ?? null];
            if (!isset($c['content_title']) && $filled < 10) {
                $snapshot = commentContentSnapshot($db, $c['content_type'], $c['content_id']);
                $db->comments->updateOne(
                    ['_id' => $c['_id']],
                    ['$set' => ['content_title' => $snapshot['title'], 'content_poster' => $snapshot['poster']]]
                );
                $filled++;
            }
            $snapshots[$c['_id']] = $snapshot;
        }
    }

    $topIds = array_map(static fn ($c) => $c['_id'], $topComments);
    $replies = [];
    if ($topIds) {
        $replies = iterator_to_array(
            $db->comments->find(['parent_comment_id' => ['$in' => $topIds]], ['sort' => ['created_at' => 1]]),
            false
        );
    }

    $allRows = array_merge($topComments, $replies);
    $userIds = array_values(array_unique(array_map(static fn ($r) => $r['user_id'], $allRows)));
    $commentIds = array_map(static fn ($r) => $r['_id'], $allRows);

    $usersById = [];
    if ($userIds) {
        foreach ($db->users->find(['_id' => ['$in' => $userIds]]) as $u) {
            $usersById[$u['_id']] = $u;
        }
    }

    $likeCounts = [];
    $likedByMe = [];
    if ($commentIds) {
        foreach ($db->comment_likes->find(['comment_id' => ['$in' => $commentIds]]) as $like) {
            $cid = $like['comment_id'];
            $likeCounts[$cid] = ($likeCounts[$cid] ?? 0) + 1;
            if ($like['user_id'] === $userId) {
                $likedByMe[$cid] = true;
            }
        }
    }

    $repliesByParent = [];
    foreach ($replies as $r) {
        $repliesByParent[$r['parent_comment_id']][] = commentPayload($r, $usersById, $likeCounts, $likedByMe);
    }

    $comments = [];
    foreach ($topComments as $c) {
        $row = commentPayload($c, $usersById, $likeCounts, $likedByMe);
        if ($withContent) {
            $snapshot = $snapshots[$c['_id']] ?? ['title' => null, 'poster' => null];
            $row['content_title'] = $snapshot['title'];
            $row['content_poster'] = $snapshot['poster'];
            $row['content_url'] = 'detail.php?type=' . rawurlencode($c['content_type']) . '&id=' . rawurlencode((string)$c['content_id']);
        }
        $row['replies'] = $repliesByParent[$c['_id']] ?? [];
        $comments[] = $row;
    }

    jsonResponse(['success' => true, 'locked' => false, 'comments' => $comments]);

} elseif ($method === 'POST') {
    requireCsrf();
    $contentType = commentContentType($input['content_type'] ?? null);
    $contentId = $contentType ? commentContentId($contentType, $input['content_id'] ?? null) : null;
    $commentText = $input['comment_text'] ?? null;
    $parentCommentId = $input['parent_comment_id'] ?? null;
    $hasSpoiler = !empty($input['has_spoiler']);
    $seasonRaw = $input['season_number'] ?? null;
    $episodeRaw = $input['episode_number'] ?? null;

    if ($contentType === null || $contentId === null || !is_string($commentText) || trim($commentText) === '') {
        jsonResponse(['success' => false, 'message' => 'Gerekli alanlar eksik veya geçersiz'], 400);
    }
    $commentText = trim($commentText);
    if (mb_strlen($commentText, 'UTF-8') > COMMENT_MAX_LENGTH) {
        jsonResponse(['success' => false, 'message' => 'Yorum en fazla ' . COMMENT_MAX_LENGTH . ' karakter olabilir'], 400);
    }

    $mutedMessage = commentMutedMessage($db, $userId);
    if ($mutedMessage !== null) {
        jsonResponse(['success' => false, 'message' => $mutedMessage], 403);
    }

    $moderationError = commentModerationError($commentText);
    if ($moderationError !== null) {
        jsonResponse(['success' => false, 'message' => $moderationError], 422);
    }

    $episodeRef = null;
    if ($seasonRaw !== null || $episodeRaw !== null) {
        $episodeRef = episodeRef($seasonRaw, $episodeRaw);
        if ($episodeRef === null || $contentType !== 'series') {
            jsonResponse(['success' => false, 'message' => 'Geçersiz bölüm'], 400);
        }
    }

    $parentId = null;
    if ($parentCommentId !== null && $parentCommentId !== '') {
        $parentId = filter_var($parentCommentId, FILTER_VALIDATE_INT);
        $parent = $parentId ? $db->comments->findOne(['_id' => (int)$parentId]) : null;
        // Yanıtlar yalnızca aynı içeriğin üst düzey yorumlarına yazılır (tek seviye).
        if (!$parent
            || $parent['content_type'] !== $contentType
            || (string)$parent['content_id'] !== (string)$contentId
            || ($parent['parent_comment_id'] ?? null) !== null) {
            jsonResponse(['success' => false, 'message' => 'Yanıtlanacak yorum bulunamadı'], 404);
        }
        // Yanıt, üst yorumun bölüm sohbetini miras alır; istemci farklı bir bölüm gönderemez.
        $parentRef = isset($parent['episode_number']) && $parent['episode_number'] !== null
            ? ['season' => (int)$parent['season_number'], 'episode' => (int)$parent['episode_number']] : null;
        if ($episodeRef !== null && $episodeRef !== $parentRef) {
            jsonResponse(['success' => false, 'message' => 'Yanıt bölümü üst yorumla uyuşmuyor'], 400);
        }
        $episodeRef = $parentRef;
        $parentId = (int)$parentId;
    }

    // Bölüm sohbetine yalnızca o bölümü izlediğini işaretleyen kullanıcı yazabilir (spoiler güvenliği).
    if ($episodeRef !== null) {
        $watched = episodeWatchedMap($db, $userId, (int)$contentId);
        if (!episodeThreadUnlocked($watched, $episodeRef['season'], $episodeRef['episode'])) {
            jsonResponse(['success' => false, 'message' => 'Bu bölümün sohbetine yazmak için önce bölümü izlediğini işaretle.'], 403);
        }
    }

    $now = time();
    $rateError = commentRateError(commentRecentActivity($db, $userId, $now), $commentText, "$contentType:$contentId", $now);
    if ($rateError !== null) {
        jsonResponse(['success' => false, 'message' => $rateError], 429);
    }

    $snapshot = commentContentSnapshot($db, $contentType, $contentId);
    $commentId = nextSequence($db, 'comments');
    $createdAt = new MongoDB\BSON\UTCDateTime();
    $document = [
        '_id' => $commentId,
        'user_id' => $userId,
        'content_type' => $contentType,
        'content_id' => $contentId,
        'comment_text' => $commentText,
        'parent_comment_id' => $parentId,
        'season_number' => $episodeRef['season'] ?? null,
        'episode_number' => $episodeRef['episode'] ?? null,
        'has_spoiler' => $hasSpoiler,
        'content_title' => $snapshot['title'],
        'content_poster' => $snapshot['poster'],
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ];
    $db->comments->insertOne($document);

    $user = $db->users->findOne(['_id' => $userId]);
    $newComment = commentPayload($document, [$userId => $user], [], []);

    jsonResponse(['success' => true, 'comment' => $newComment, 'id' => $commentId]);

} elseif ($method === 'PUT') {
    requireCsrf();
    $commentId = filter_var($input['comment_id'] ?? null, FILTER_VALIDATE_INT);
    $commentText = $input['comment_text'] ?? null;

    if ($commentId === false || !is_string($commentText) || trim($commentText) === '') {
        jsonResponse(['success' => false, 'message' => 'Gerekli alanlar eksik veya geçersiz'], 400);
    }
    $commentText = trim($commentText);
    if (mb_strlen($commentText, 'UTF-8') > COMMENT_MAX_LENGTH) {
        jsonResponse(['success' => false, 'message' => 'Yorum en fazla ' . COMMENT_MAX_LENGTH . ' karakter olabilir'], 400);
    }
    $moderationError = commentModerationError($commentText);
    if ($moderationError !== null) {
        jsonResponse(['success' => false, 'message' => $moderationError], 422);
    }

    $comment = $db->comments->findOne(['_id' => (int)$commentId]);

    if (!$comment) {
        jsonResponse(['success' => false, 'message' => 'Yorum bulunamadı'], 404);
    }

    if ($comment['user_id'] != $userId) {
        jsonResponse(['success' => false, 'message' => 'Bu yorumu düzenleme yetkiniz yok'], 403);
    }

    // Metin değişmediyse "düzenlendi" damgası vurulmaz; değiştiyse önceki sürüm geçmişe yazılır.
    if ($comment['comment_text'] === $commentText) {
        jsonResponse(['success' => true, 'message' => 'Yorum değişmedi', 'updated_at' => commentIso($comment['updated_at'] ?? $comment['created_at'])]);
    }
    recordCommentRevision($db, $comment, $userId);

    $updatedAt = new MongoDB\BSON\UTCDateTime();
    $db->comments->updateOne(
        ['_id' => (int)$commentId],
        ['$set' => ['comment_text' => $commentText, 'updated_at' => $updatedAt]]
    );

    jsonResponse(['success' => true, 'message' => 'Yorum düzenlendi', 'updated_at' => commentIso($updatedAt)]);

} elseif ($method === 'DELETE') {
    requireCsrf();
    $commentId = filter_var($input['comment_id'] ?? null, FILTER_VALIDATE_INT);

    if ($commentId === false) {
        jsonResponse(['success' => false, 'message' => 'comment_id gerekli'], 400);
    }
    $commentId = (int)$commentId;

    $comment = $db->comments->findOne(['_id' => $commentId]);

    if (!$comment) {
        jsonResponse(['success' => false, 'message' => 'Yorum bulunamadı'], 404);
    }

    if ($comment['user_id'] != $userId) {
        jsonResponse(['success' => false, 'message' => 'Bu yorumu silme yetkiniz yok'], 403);
    }

    // Eski MySQL şemasında ON DELETE CASCADE FK'si vardı, Mongo'da yok: yorum ve yanıtları arşive taşınır,
    // beğenileri elle temizlenir. Arşiv, yöneticinin geçmişi görmesini ve geri yüklemesini sağlar.
    archiveAndDeleteComment($db, $commentId, $userId, 'author');

    jsonResponse(['success' => true, 'message' => 'Yorum silindi']);
}
?>
