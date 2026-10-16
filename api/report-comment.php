<?php
require_once 'config.php';
require_once __DIR__ . '/lib/audit.php';
requireLogin();

$method = $_SERVER['REQUEST_METHOD'];
$userId = (int) getCurrentUserId();

if ($method === 'POST') {
    requireCsrf();
    $input = json_decode(file_get_contents('php://input'), true);
    $commentId = isset($input['comment_id']) ? (int) $input['comment_id'] : null;
    $reason = trim($input['reason'] ?? '');

    if (!$commentId || $reason === '') {
        jsonResponse(['success' => false, 'message' => 'Yorum ve şikayet nedeni gereklidir'], 400);
    }
    if (mb_strlen($reason) > 500) {
        jsonResponse(['success' => false, 'message' => 'Şikayet nedeni çok uzun'], 400);
    }
    if (!$db->comments->findOne(['_id' => $commentId])) {
        jsonResponse(['success' => false, 'message' => 'Yorum bulunamadı'], 404);
    }

    $db->comment_reports->updateOne(
        ['comment_id' => $commentId, 'reporter_id' => $userId],
        ['$setOnInsert' => [
            '_id' => nextSequence($db, 'comment_reports'),
            'comment_id' => $commentId,
            'reporter_id' => $userId,
            'reason' => $reason,
            'status' => 'pending',
            'created_at' => new MongoDB\BSON\UTCDateTime(),
        ]],
        ['upsert' => true]
    );

    jsonResponse(['success' => true, 'message' => 'Şikayetiniz alındı, incelenecek']);

} elseif ($method === 'GET') {
    requireAdmin($db);

    $reports = iterator_to_array($db->comment_reports->find(['status' => 'pending'], ['sort' => ['created_at' => -1]]), false);
    $commentIds = array_values(array_unique(array_map(fn($r) => $r->comment_id, $reports)));
    $reporterIds = array_values(array_unique(array_map(fn($r) => $r->reporter_id, $reports)));

    $commentsById = [];
    if ($commentIds) {
        foreach ($db->comments->find(['_id' => ['$in' => $commentIds]]) as $c) {
            $commentsById[$c->_id] = $c;
        }
    }

    $userIds = $reporterIds;
    foreach ($commentsById as $c) {
        $userIds[] = $c->user_id;
    }
    $userIds = array_values(array_unique($userIds));
    $usersById = [];
    if ($userIds) {
        foreach ($db->users->find(['_id' => ['$in' => $userIds]]) as $u) {
            $usersById[$u->_id] = $u;
        }
    }

    $rows = [];
    foreach ($reports as $r) {
        $comment = $commentsById[$r->comment_id] ?? null;
        $reporter = $usersById[$r->reporter_id] ?? null;
        $commentAuthor = $comment ? ($usersById[$comment->user_id] ?? null) : null;

        $rows[] = [
            'id' => $r->_id,
            'reason' => $r->reason,
            'created_at' => $r->created_at->toDateTime()->format('Y-m-d H:i:s'),
            'reporter_username' => $reporter->username ?? 'Bilinmiyor',
            'comment_id' => $r->comment_id,
            'comment_text' => $comment->comment_text ?? '(yorum silinmiş)',
            'comment_author' => $commentAuthor->username ?? 'Bilinmiyor',
        ];
    }

    jsonResponse(['success' => true, 'reports' => $rows]);

} elseif ($method === 'PUT') {
    requireCsrf();
    requireAdmin($db);
    $input = json_decode(file_get_contents('php://input'), true);
    $reportId = isset($input['id']) ? (int) $input['id'] : null;
    $action = $input['action'] ?? null;

    if (!$reportId || !in_array($action, ['dismiss', 'delete_comment'], true)) {
        jsonResponse(['success' => false, 'message' => 'Geçersiz istek'], 400);
    }

    $report = $db->comment_reports->findOne(['_id' => $reportId]);
    if (!$report) {
        jsonResponse(['success' => false, 'message' => 'Şikayet bulunamadı'], 404);
    }

    $adminId = (int) getCurrentUserId();
    $resolved = ['$set' => [
        'status' => 'resolved',
        'resolved_by' => $adminId,
        'resolved_at' => new MongoDB\BSON\UTCDateTime(),
        'resolution' => $action,
    ]];

    if ($action === 'delete_comment') {
        // Yorum ve yanıtları arşive taşınır (yönetici geri yükleyebilir); aynı yoruma gelen tüm şikâyetler çözülür.
        archiveAndDeleteComment($db, (int) $report->comment_id, $adminId, 'admin', 'Şikâyet üzerine: ' . $report->reason);
        $db->comment_reports->updateMany(['comment_id' => $report->comment_id], $resolved);
        auditLog($db, $adminId, 'report.delete_comment', 'comment', $report->comment_id, ['report_id' => $reportId]);
    } else {
        $db->comment_reports->updateOne(['_id' => $reportId], $resolved);
        auditLog($db, $adminId, 'report.dismiss', 'report', $reportId);
    }

    jsonResponse(['success' => true]);

} else {
    jsonResponse(['success' => false, 'message' => 'Geçersiz istek yöntemi'], 405);
}
?>
