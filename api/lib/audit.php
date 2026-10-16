<?php
// Denetim ve geçmiş: yönetici işlemlerinin kaydı, yorum düzenleme geçmişi ve silinen yorumların arşivi.
// Silinen bir yorum kalıcı olarak yok olmaz: yanıtlarıyla birlikte deleted_comments'a taşınır ve yönetici geri yükleyebilir.

function auditLog($db, int $actorId, string $action, string $targetType, $targetId, array $meta = []): void {
    $db->audit_log->insertOne([
        '_id' => nextSequence($db, 'audit_log'),
        'actor_id' => $actorId,
        'action' => $action,
        'target_type' => $targetType,
        'target_id' => $targetId,
        'meta' => $meta,
        'created_at' => new MongoDB\BSON\UTCDateTime(),
    ]);
}

// Düzenlenmeden hemen önce yorumun o anki metni saklanır; yönetici tüm sürümleri görebilir.
function recordCommentRevision($db, $comment, int $editorId): void {
    $db->comment_revisions->insertOne([
        '_id' => nextSequence($db, 'comment_revisions'),
        'comment_id' => $comment['_id'],
        'text' => $comment['comment_text'],
        'version_at' => $comment['updated_at'] ?? $comment['created_at'],
        'editor_id' => $editorId,
        'created_at' => new MongoDB\BSON\UTCDateTime(),
    ]);
}

function documentToArray($document): array {
    return is_array($document) ? $document : $document->getArrayCopy();
}

// Yorumu ve yanıtlarını arşive taşıyıp siler. Beğeniler silinir (geri yüklemede beğeniler dönmez).
// $via: 'author' (yazarı sildi), 'admin' (yönetici sildi). Silinen yorum kimlikleri döner.
function archiveAndDeleteComment($db, int $commentId, int $deletedBy, string $via, ?string $reason = null): array {
    $comment = $db->comments->findOne(['_id' => $commentId]);
    if (!$comment) {
        return [];
    }

    $replies = iterator_to_array($db->comments->find(['parent_comment_id' => $commentId]), false);
    $ids = [$commentId];
    foreach ($replies as $reply) {
        $ids[] = $reply['_id'];
    }

    $db->deleted_comments->insertOne([
        '_id' => nextSequence($db, 'deleted_comments'),
        'comment' => documentToArray($comment),
        'replies' => array_map('documentToArray', $replies),
        'likes_count' => $db->comment_likes->countDocuments(['comment_id' => ['$in' => $ids]]),
        'deleted_by' => $deletedBy,
        'via' => $via,
        'reason' => $reason,
        'deleted_at' => new MongoDB\BSON\UTCDateTime(),
    ]);

    $db->comments->deleteMany(['_id' => ['$in' => $ids]]);
    $db->comment_likes->deleteMany(['comment_id' => ['$in' => $ids]]);
    return $ids;
}

// Arşivden geri yükler: yorum ve yanıtları özgün kimlikleriyle yeniden eklenir. Çakışma varsa false döner.
function restoreArchivedComment($db, int $archiveId): bool {
    $archive = $db->deleted_comments->findOne(['_id' => $archiveId]);
    if (!$archive || !empty($archive['restored_at'])) {
        return false;
    }

    $rows = [documentToArray($archive['comment'])];
    foreach ($archive['replies'] ?? [] as $reply) {
        $rows[] = documentToArray($reply);
    }
    foreach ($rows as $row) {
        if ($db->comments->findOne(['_id' => $row['_id']])) {
            return false;
        }
    }

    $db->comments->insertMany($rows);
    $db->deleted_comments->updateOne(['_id' => $archiveId], ['$set' => ['restored_at' => new MongoDB\BSON\UTCDateTime()]]);
    return true;
}

function adminPageParams($page, $perPage = 25, int $maxPerPage = 100): array {
    $perPage = max(1, min($maxPerPage, (int) $perPage));
    $page = max(1, (int) $page);
    return ['page' => $page, 'per_page' => $perPage, 'skip' => ($page - 1) * $perPage];
}

// Başarılı girişte son giriş zamanı, giriş sayısı ve kısa bir giriş geçmişi (90 gün sonra kendiliğinden silinir) tutulur.
function recordLogin($db, $userId): void {
    $now = new MongoDB\BSON\UTCDateTime();
    $db->users->updateOne(['_id' => $userId], ['$set' => ['last_login_at' => $now], '$inc' => ['login_count' => 1]]);
    $db->login_history->insertOne([
        '_id' => nextSequence($db, 'login_history'),
        'user_id' => $userId,
        'at' => $now,
        'ua' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 160),
    ]);
}
