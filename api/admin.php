<?php
// Yönetim konsolu API'si: yöneticinin her şeyi görebildiği tek uç, ?action= ile seçilir.
//   Okuma (GET):  overview, users, user, comments, comment, deleted, reports, audit, moderation, tickets
//   Yazma (POST, CSRF + yönetici): delete_comment, restore_comment, resolve_report,
//                                  suspend_user, unsuspend_user, mute_user, unmute_user,
//                                  resolve_ticket, reopen_ticket, reply_ticket
// Her yazma işlemi audit_log'a kaydedilir. Silinen yorumlar kalıcı yok olmaz (deleted_comments arşivi),
// düzenlemeler comment_revisions'ta saklanır. Özel mesajların içeriği bilinçli olarak kapsam dışıdır (gizlilik).
require_once 'config.php';
require_once __DIR__ . '/lib/audit.php';
require_once __DIR__ . '/lib/moderation-log.php';
require_once __DIR__ . '/lib/support.php';
require_once __DIR__ . '/lib/support-notify.php';

requireAdmin($db);
$adminId = (int)getCurrentUserId();
$method = $_SERVER['REQUEST_METHOD'];

function adminIso($utcDateTime): ?string {
    return $utcDateTime ? $utcDateTime->toDateTime()->format(DATE_ATOM) : null;
}

function adminUserBrief($user): ?array {
    if (!$user) {
        return null;
    }
    return [
        'id' => $user['_id'],
        'username' => $user['username'] ?? null,
        'avatar_url' => $user['avatar_url'] ?? null,
        'is_admin' => !empty($user['is_admin']),
        'status' => $user['status'] ?? 'active',
    ];
}

function adminUsersById($db, array $ids): array {
    $ids = array_values(array_unique(array_filter($ids, static fn ($id) => $id !== null)));
    $map = [];
    if ($ids) {
        foreach ($db->users->find(['_id' => ['$in' => $ids]]) as $user) {
            $map[$user['_id']] = $user;
        }
    }
    return $map;
}

function adminContentInfo($comment): array {
    $type = $comment['content_type'] ?? null;
    $id = $comment['content_id'] ?? null;
    return [
        'type' => $type,
        'id' => $id,
        'title' => $comment['content_title'] ?? null,
        'poster' => $comment['content_poster'] ?? null,
        'url' => $type ? 'detail.php?type=' . rawurlencode($type) . '&id=' . rawurlencode((string)$id) : null,
    ];
}

function adminCountMap($cursor): array {
    $map = [];
    foreach ($cursor as $row) {
        $map[$row['_id']] = (int)$row['count'];
    }
    return $map;
}

// Sayfadaki yorumlar için beğeni, şikâyet ve düzenleme sayıları (tek sorguyla).
function adminCommentCounts($db, array $ids): array {
    if (!$ids) {
        return ['likes' => [], 'reports' => [], 'revisions' => []];
    }
    $group = static fn (string $field) => [['$match' => [$field => ['$in' => $ids]]], ['$group' => ['_id' => '$' . $field, 'count' => ['$sum' => 1]]]];
    return [
        'likes' => adminCountMap($db->comment_likes->aggregate($group('comment_id'))),
        'reports' => adminCountMap($db->comment_reports->aggregate($group('comment_id'))),
        'revisions' => adminCountMap($db->comment_revisions->aggregate($group('comment_id'))),
    ];
}

function adminCommentRow($comment, array $usersById, array $counts): array {
    $id = $comment['_id'];
    return [
        'id' => $id,
        'author' => adminUserBrief($usersById[$comment['user_id']] ?? null),
        'content' => adminContentInfo($comment),
        'text' => $comment['comment_text'],
        'has_spoiler' => !empty($comment['has_spoiler']),
        'parent_comment_id' => $comment['parent_comment_id'] ?? null,
        'season_number' => $comment['season_number'] ?? null,
        'episode_number' => $comment['episode_number'] ?? null,
        'created_at' => adminIso($comment['created_at']),
        'updated_at' => adminIso($comment['updated_at'] ?? $comment['created_at']),
        'likes' => $counts['likes'][$id] ?? 0,
        'reports' => $counts['reports'][$id] ?? 0,
        'revisions' => $counts['revisions'][$id] ?? 0,
    ];
}

function adminDailyCounts($db, string $collection, string $field, int $days): array {
    $since = new MongoDB\BSON\UTCDateTime((time() - $days * 86400) * 1000);
    $cursor = $db->$collection->aggregate([
        ['$match' => [$field => ['$gte' => $since]]],
        ['$group' => ['_id' => ['$dateToString' => ['format' => '%Y-%m-%d', 'date' => '$' . $field]], 'count' => ['$sum' => 1]]],
    ]);
    return adminCountMap($cursor);
}

function adminOverview($db): void {
    $now = time();
    $day = new MongoDB\BSON\UTCDateTime(($now - 86400) * 1000);
    $week = new MongoDB\BSON\UTCDateTime(($now - 7 * 86400) * 1000);

    $totals = [
        'users' => $db->users->countDocuments([]),
        'users_7d' => $db->users->countDocuments(['created_at' => ['$gte' => $week]]),
        'suspended' => $db->users->countDocuments(['status' => 'suspended']),
        'comments' => $db->comments->countDocuments(['parent_comment_id' => null]),
        'replies' => $db->comments->countDocuments(['parent_comment_id' => ['$ne' => null]]),
        'comments_24h' => $db->comments->countDocuments(['created_at' => ['$gte' => $day]]),
        'spoilers' => $db->comments->countDocuments(['has_spoiler' => true]),
        'episode_comments' => $db->comments->countDocuments(['episode_number' => ['$ne' => null]]),
        'edited' => count($db->comment_revisions->distinct('comment_id')),
        'reports_pending' => $db->comment_reports->countDocuments(['status' => 'pending']),
        'tickets_open' => $db->support_tickets->countDocuments(['status' => 'open']),
        'deleted' => $db->deleted_comments->countDocuments(['restored_at' => ['$exists' => false]]),
        'logins_24h' => $db->login_history->countDocuments(['at' => ['$gte' => $day]]),
        'library_items' => $db->user_movie_status->countDocuments([]) + $db->user_series_status->countDocuments([]) + $db->user_book_status->countDocuments([]),
    ];

    $days = 14;
    $comments = adminDailyCounts($db, 'comments', 'created_at', $days);
    $users = adminDailyCounts($db, 'users', 'created_at', $days);
    $logins = adminDailyCounts($db, 'login_history', 'at', $days);
    $daily = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $date = gmdate('Y-m-d', $now - $i * 86400);
        $daily[] = ['date' => $date, 'comments' => $comments[$date] ?? 0, 'users' => $users[$date] ?? 0, 'logins' => $logins[$date] ?? 0];
    }

    $topContent = [];
    $cursor = $db->comments->aggregate([
        ['$group' => [
            '_id' => ['type' => '$content_type', 'id' => '$content_id'],
            'count' => ['$sum' => 1],
            'title' => ['$first' => '$content_title'],
            'poster' => ['$first' => '$content_poster'],
        ]],
        ['$sort' => ['count' => -1]],
        ['$limit' => 6],
    ]);
    foreach ($cursor as $row) {
        $type = $row['_id']['type'];
        $id = $row['_id']['id'];
        $topContent[] = [
            'type' => $type,
            'id' => $id,
            'title' => $row['title'] ?? null,
            'poster' => $row['poster'] ?? null,
            'comments' => (int)$row['count'],
            'url' => 'detail.php?type=' . rawurlencode($type) . '&id=' . rawurlencode((string)$id),
        ];
    }

    jsonResponse(['success' => true, 'totals' => $totals, 'daily' => $daily, 'top_content' => $topContent, 'audit' => adminAuditRows($db, [], 0, 8)['rows']]);
}

function adminUsers($db): void {
    $p = adminPageParams($_GET['page'] ?? 1, $_GET['per_page'] ?? 20, 50);
    $filter = [];
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q !== '') {
        $regex = new MongoDB\BSON\Regex(preg_quote($q, '/'), 'i');
        $filter['$or'] = [['username' => $regex], ['email' => $regex]];
    }
    $status = $_GET['status'] ?? '';
    if ($status === 'suspended') {
        $filter['status'] = 'suspended';
    } elseif ($status === 'admin') {
        $filter['is_admin'] = true;
    }

    $sorts = ['new' => ['created_at' => -1], 'active' => ['last_login_at' => -1], 'name' => ['username' => 1]];
    $sort = $sorts[$_GET['sort'] ?? 'new'] ?? $sorts['new'];

    $total = $db->users->countDocuments($filter);
    $users = iterator_to_array($db->users->find($filter, ['sort' => $sort, 'skip' => $p['skip'], 'limit' => $p['per_page']]), false);
    $ids = array_map(static fn ($u) => $u['_id'], $users);

    $commentCounts = $ids ? adminCountMap($db->comments->aggregate([['$match' => ['user_id' => ['$in' => $ids]]], ['$group' => ['_id' => '$user_id', 'count' => ['$sum' => 1]]]])) : [];
    $followerCounts = $ids ? adminCountMap($db->follows->aggregate([['$match' => ['following_id' => ['$in' => $ids]]], ['$group' => ['_id' => '$following_id', 'count' => ['$sum' => 1]]]])) : [];
    $libraryCounts = [];
    foreach (['user_movie_status', 'user_series_status', 'user_book_status'] as $collection) {
        if ($ids) {
            foreach (adminCountMap($db->$collection->aggregate([['$match' => ['user_id' => ['$in' => $ids]]], ['$group' => ['_id' => '$user_id', 'count' => ['$sum' => 1]]]])) as $uid => $n) {
                $libraryCounts[$uid] = ($libraryCounts[$uid] ?? 0) + $n;
            }
        }
    }

    $rows = [];
    foreach ($users as $user) {
        $id = $user['_id'];
        $rows[] = adminUserBrief($user) + [
            'email' => $user['email'] ?? null,
            'bio' => $user['bio'] ?? null,
            'created_at' => adminIso($user['created_at'] ?? null),
            'last_login_at' => adminIso($user['last_login_at'] ?? null),
            'login_count' => (int)($user['login_count'] ?? 0),
            'muted_until' => adminIso($user['muted_until'] ?? null),
            'comments' => $commentCounts[$id] ?? 0,
            'library' => $libraryCounts[$id] ?? 0,
            'followers' => $followerCounts[$id] ?? 0,
        ];
    }

    jsonResponse(['success' => true, 'users' => $rows, 'total' => $total, 'page' => $p['page'], 'per_page' => $p['per_page']]);
}

// Kullanıcının son kütüphane hareketleri (film, dizi, kitap): durum, puan ve zaman.
function adminUserActivity($db, int $userId, int $limit = 20): array {
    $sources = [
        ['collection' => 'user_movie_status', 'idField' => 'movie_id', 'content' => 'movies', 'type' => 'movie', 'public' => 'tmdb_id'],
        ['collection' => 'user_series_status', 'idField' => 'series_id', 'content' => 'series', 'type' => 'series', 'public' => 'tmdb_id'],
        ['collection' => 'user_book_status', 'idField' => 'book_id', 'content' => 'books', 'type' => 'book', 'public' => 'google_books_id'],
    ];
    $rows = [];
    foreach ($sources as $source) {
        $found = iterator_to_array($db->{$source['collection']}->find(['user_id' => $userId], ['sort' => ['updated_at' => -1], 'limit' => $limit]), false);
        $ids = array_values(array_unique(array_map(static fn ($row) => $row[$source['idField']], $found)));
        $docs = [];
        if ($ids) {
            foreach ($db->{$source['content']}->find(['_id' => ['$in' => $ids]]) as $doc) {
                $docs[$doc['_id']] = $doc;
            }
        }
        foreach ($found as $row) {
            $doc = $docs[$row[$source['idField']]] ?? null;
            if ($doc === null) {
                continue;
            }
            $rows[] = [
                'type' => $source['type'],
                'status' => (string)$row['status'],
                'rating' => isset($row['rating']) && $row['rating'] !== null ? (int)$row['rating'] : null,
                'title' => (string)($doc['title'] ?? ''),
                'content_id' => $doc[$source['public']] ?? null,
                'at' => adminIso($row['updated_at'] ?? null),
            ];
        }
    }
    usort($rows, static fn (array $a, array $b): int => strcmp((string)$b['at'], (string)$a['at']));
    return array_slice($rows, 0, $limit);
}

function adminUserDetail($db): void {
    $userId = filter_var($_GET['user_id'] ?? null, FILTER_VALIDATE_INT);
    $user = $userId ? $db->users->findOne(['_id' => $userId]) : null;
    if (!$user) {
        jsonResponse(['success' => false, 'message' => 'Kullanıcı bulunamadı'], 404);
    }

    $library = [];
    foreach (['movie' => 'user_movie_status', 'series' => 'user_series_status', 'book' => 'user_book_status'] as $type => $collection) {
        $rows = [];
        foreach ($db->$collection->aggregate([['$match' => ['user_id' => $userId]], ['$group' => ['_id' => '$status', 'count' => ['$sum' => 1]]]]) as $row) {
            $rows[(string)$row['_id']] = (int)$row['count'];
        }
        $library[$type] = $rows;
    }

    $recent = iterator_to_array($db->comments->find(['user_id' => $userId], ['sort' => ['created_at' => -1], 'limit' => 15]), false);
    $counts = adminCommentCounts($db, array_map(static fn ($c) => $c['_id'], $recent));
    $usersById = [$userId => $user];

    $logins = [];
    foreach ($db->login_history->find(['user_id' => $userId], ['sort' => ['at' => -1], 'limit' => 10]) as $login) {
        $logins[] = ['at' => adminIso($login['at']), 'ua' => $login['ua'] ?? ''];
    }

    $myCommentIds = [];
    foreach ($db->comments->find(['user_id' => $userId], ['projection' => ['_id' => 1]]) as $c) {
        $myCommentIds[] = $c['_id'];
    }

    jsonResponse([
        'success' => true,
        'user' => adminUserBrief($user) + [
            'email' => $user['email'] ?? null,
            'bio' => $user['bio'] ?? null,
            'created_at' => adminIso($user['created_at'] ?? null),
            'last_login_at' => adminIso($user['last_login_at'] ?? null),
            'login_count' => (int)($user['login_count'] ?? 0),
            'muted_until' => adminIso($user['muted_until'] ?? null),
            'suspended_reason' => $user['suspended_reason'] ?? null,
            'suspended_at' => adminIso($user['suspended_at'] ?? null),
            'totp_enabled' => !empty($user['totp_enabled']),
        ],
        'stats' => [
            'comments' => $db->comments->countDocuments(['user_id' => $userId]),
            'followers' => $db->follows->countDocuments(['following_id' => $userId]),
            'following' => $db->follows->countDocuments(['follower_id' => $userId]),
            'reports_filed' => $db->comment_reports->countDocuments(['reporter_id' => $userId]),
            'reports_against' => $myCommentIds ? $db->comment_reports->countDocuments(['comment_id' => ['$in' => $myCommentIds]]) : 0,
            'deleted' => $db->deleted_comments->countDocuments(['comment.user_id' => $userId]),
        ],
        'library' => $library,
        'activity' => adminUserActivity($db, $userId),
        'recent_comments' => array_map(static fn ($c) => adminCommentRow($c, $usersById, $counts), $recent),
        'logins' => $logins,
        'audit' => adminAuditRows($db, ['target_type' => 'user', 'target_id' => $userId], 0, 10)['rows'],
    ]);
}

function adminComments($db): void {
    $p = adminPageParams($_GET['page'] ?? 1, $_GET['per_page'] ?? 20, 50);
    $filter = [];
    switch ($_GET['filter'] ?? 'all') {
        case 'spoiler':
            $filter['has_spoiler'] = true;
            break;
        case 'replies':
            $filter['parent_comment_id'] = ['$ne' => null];
            break;
        case 'episode':
            $filter['episode_number'] = ['$ne' => null];
            break;
        case 'edited':
            $filter['_id'] = ['$in' => array_map('intval', $db->comment_revisions->distinct('comment_id'))];
            break;
        case 'reported':
            $filter['_id'] = ['$in' => array_map('intval', $db->comment_reports->distinct('comment_id'))];
            break;
    }
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q !== '') {
        $regex = new MongoDB\BSON\Regex(preg_quote($q, '/'), 'i');
        $authorIds = [];
        foreach ($db->users->find(['username' => $regex], ['projection' => ['_id' => 1], 'limit' => 50]) as $author) {
            $authorIds[] = $author['_id'];
        }
        $textOrAuthor = [['comment_text' => $regex]];
        if ($authorIds) {
            $textOrAuthor[] = ['user_id' => ['$in' => $authorIds]];
        }
        $filter['$and'][] = ['$or' => $textOrAuthor];
    }
    $userFilter = filter_var($_GET['user_id'] ?? null, FILTER_VALIDATE_INT);
    if ($userFilter) {
        $filter['user_id'] = $userFilter;
    }

    $total = $db->comments->countDocuments($filter);
    $comments = iterator_to_array($db->comments->find($filter, ['sort' => ['created_at' => -1], 'skip' => $p['skip'], 'limit' => $p['per_page']]), false);
    $usersById = adminUsersById($db, array_map(static fn ($c) => $c['user_id'], $comments));
    $counts = adminCommentCounts($db, array_map(static fn ($c) => $c['_id'], $comments));

    jsonResponse([
        'success' => true,
        'comments' => array_map(static fn ($c) => adminCommentRow($c, $usersById, $counts), $comments),
        'total' => $total,
        'page' => $p['page'],
        'per_page' => $p['per_page'],
    ]);
}

function adminCommentDetail($db): void {
    $commentId = filter_var($_GET['comment_id'] ?? null, FILTER_VALIDATE_INT);
    $comment = $commentId ? $db->comments->findOne(['_id' => $commentId]) : null;
    if (!$comment) {
        jsonResponse(['success' => false, 'message' => 'Yorum bulunamadı'], 404);
    }

    $revisions = iterator_to_array($db->comment_revisions->find(['comment_id' => $commentId], ['sort' => ['created_at' => 1]]), false);
    $versions = [];
    foreach ($revisions as $revision) {
        $versions[] = ['text' => $revision['text'], 'at' => adminIso($revision['version_at'] ?? $revision['created_at'])];
    }
    $versions[] = ['text' => $comment['comment_text'], 'at' => adminIso($comment['updated_at'] ?? $comment['created_at']), 'current' => true];

    $replies = iterator_to_array($db->comments->find(['parent_comment_id' => $commentId], ['sort' => ['created_at' => 1]]), false);
    $reports = iterator_to_array($db->comment_reports->find(['comment_id' => $commentId], ['sort' => ['created_at' => -1]]), false);

    $userIds = [$comment['user_id']];
    foreach ($replies as $reply) {
        $userIds[] = $reply['user_id'];
    }
    foreach ($reports as $report) {
        $userIds[] = $report['reporter_id'];
    }
    $usersById = adminUsersById($db, $userIds);
    $counts = adminCommentCounts($db, array_merge([$commentId], array_map(static fn ($r) => $r['_id'], $replies)));

    jsonResponse([
        'success' => true,
        'comment' => adminCommentRow($comment, $usersById, $counts),
        'versions' => $versions,
        'replies' => array_map(static fn ($r) => adminCommentRow($r, $usersById, $counts), $replies),
        'reports' => array_map(static fn ($r) => [
            'id' => $r['_id'],
            'reporter' => adminUserBrief($usersById[$r['reporter_id']] ?? null),
            'reason' => $r['reason'],
            'status' => $r['status'],
            'created_at' => adminIso($r['created_at']),
        ], $reports),
    ]);
}

function adminDeleted($db): void {
    $p = adminPageParams($_GET['page'] ?? 1, $_GET['per_page'] ?? 20, 50);
    $total = $db->deleted_comments->countDocuments([]);
    $rows = iterator_to_array($db->deleted_comments->find([], ['sort' => ['deleted_at' => -1], 'skip' => $p['skip'], 'limit' => $p['per_page']]), false);

    $userIds = [];
    foreach ($rows as $row) {
        $userIds[] = $row['comment']['user_id'];
        $userIds[] = $row['deleted_by'];
    }
    $usersById = adminUsersById($db, $userIds);

    $items = [];
    foreach ($rows as $row) {
        $comment = $row['comment'];
        $items[] = [
            'archive_id' => $row['_id'],
            'comment_id' => $comment['_id'],
            'author' => adminUserBrief($usersById[$comment['user_id']] ?? null),
            'content' => adminContentInfo($comment),
            'text' => $comment['comment_text'],
            'has_spoiler' => !empty($comment['has_spoiler']),
            'created_at' => adminIso($comment['created_at']),
            'replies' => count($row['replies'] ?? []),
            'likes' => (int)($row['likes_count'] ?? 0),
            'deleted_by' => adminUserBrief($usersById[$row['deleted_by']] ?? null),
            'via' => $row['via'] ?? null,
            'reason' => $row['reason'] ?? null,
            'deleted_at' => adminIso($row['deleted_at']),
            'restored_at' => adminIso($row['restored_at'] ?? null),
        ];
    }

    jsonResponse(['success' => true, 'deleted' => $items, 'total' => $total, 'page' => $p['page'], 'per_page' => $p['per_page']]);
}

function adminReports($db): void {
    $status = $_GET['status'] ?? 'pending';
    $filter = in_array($status, ['pending', 'resolved'], true) ? ['status' => $status] : [];
    $p = adminPageParams($_GET['page'] ?? 1, $_GET['per_page'] ?? 20, 50);
    $total = $db->comment_reports->countDocuments($filter);
    $reports = iterator_to_array($db->comment_reports->find($filter, ['sort' => ['created_at' => -1], 'skip' => $p['skip'], 'limit' => $p['per_page']]), false);

    $commentIds = array_map(static fn ($r) => $r['comment_id'], $reports);
    $comments = [];
    if ($commentIds) {
        foreach ($db->comments->find(['_id' => ['$in' => $commentIds]]) as $c) {
            $comments[$c['_id']] = $c;
        }
    }
    $userIds = [];
    foreach ($reports as $r) {
        $userIds[] = $r['reporter_id'];
        $userIds[] = $r['resolved_by'] ?? null;
        $userIds[] = isset($comments[$r['comment_id']]) ? $comments[$r['comment_id']]['user_id'] : null;
    }
    $usersById = adminUsersById($db, $userIds);

    $rows = [];
    foreach ($reports as $r) {
        $comment = $comments[$r['comment_id']] ?? null;
        $rows[] = [
            'id' => $r['_id'],
            'comment_id' => $r['comment_id'],
            'reason' => $r['reason'],
            'status' => $r['status'],
            'resolution' => $r['resolution'] ?? null,
            'created_at' => adminIso($r['created_at']),
            'resolved_at' => adminIso($r['resolved_at'] ?? null),
            'resolved_by' => adminUserBrief($usersById[$r['resolved_by'] ?? 0] ?? null),
            'reporter' => adminUserBrief($usersById[$r['reporter_id']] ?? null),
            'comment' => $comment ? [
                'text' => $comment['comment_text'],
                'author' => adminUserBrief($usersById[$comment['user_id']] ?? null),
                'content' => adminContentInfo($comment),
                'has_spoiler' => !empty($comment['has_spoiler']),
            ] : null,
        ];
    }

    jsonResponse(['success' => true, 'reports' => $rows, 'total' => $total, 'page' => $p['page'], 'per_page' => $p['per_page']]);
}

function adminAuditRows($db, array $filter, int $skip, int $limit): array {
    $entries = iterator_to_array($db->audit_log->find($filter, ['sort' => ['created_at' => -1], 'skip' => $skip, 'limit' => $limit]), false);
    $usersById = adminUsersById($db, array_map(static fn ($e) => $e['actor_id'], $entries));
    $rows = [];
    foreach ($entries as $entry) {
        $rows[] = [
            'id' => $entry['_id'],
            'actor' => adminUserBrief($usersById[$entry['actor_id']] ?? null),
            'action' => $entry['action'],
            'target_type' => $entry['target_type'],
            'target_id' => $entry['target_id'],
            'meta' => $entry['meta'] ? json_decode(json_encode($entry['meta']), true) : (object)[],
            'created_at' => adminIso($entry['created_at']),
        ];
    }
    return ['rows' => $rows, 'total' => $db->audit_log->countDocuments($filter)];
}

function adminAudit($db): void {
    $p = adminPageParams($_GET['page'] ?? 1, $_GET['per_page'] ?? 25, 100);
    $filter = [];
    $targetType = $_GET['target_type'] ?? '';
    if (in_array($targetType, ['user', 'comment', 'report', 'ticket'], true)) {
        $filter['target_type'] = $targetType;
    }
    $result = adminAuditRows($db, $filter, $p['skip'], $p['per_page']);
    jsonResponse(['success' => true, 'entries' => $result['rows'], 'total' => $result['total'], 'page' => $p['page'], 'per_page' => $p['per_page']]);
}

// --- Yazma işlemleri ---

function adminReason($raw): ?string {
    $reason = is_string($raw) ? trim($raw) : '';
    return $reason === '' ? null : mb_substr($reason, 0, 300);
}

// Askıya alma ve susturma: kendine ve diğer yöneticilere uygulanamaz.
function adminTargetUser($db, int $adminId, $rawId): array {
    $userId = filter_var($rawId, FILTER_VALIDATE_INT);
    $user = $userId ? $db->users->findOne(['_id' => $userId]) : null;
    if (!$user) {
        jsonResponse(['success' => false, 'message' => 'Kullanıcı bulunamadı'], 404);
    }
    if ($userId === $adminId) {
        jsonResponse(['success' => false, 'message' => 'Kendi hesabına bu işlem uygulanamaz'], 400);
    }
    if (!empty($user['is_admin'])) {
        jsonResponse(['success' => false, 'message' => 'Yönetici hesaplarına bu işlem uygulanamaz'], 400);
    }
    return [$userId, $user];
}

// Destek talepleri: durum süzgeci (open | resolved | hepsi), en yeni önce. Sayılar süzgeç düğmelerinde görünür.
function adminTickets($db): void {
    $status = $_GET['status'] ?? 'open';
    $filter = in_array($status, ['open', 'resolved'], true) ? ['status' => $status] : [];
    $p = adminPageParams($_GET['page'] ?? 1, $_GET['per_page'] ?? 20, 50);
    $total = $db->support_tickets->countDocuments($filter);
    $tickets = iterator_to_array($db->support_tickets->find($filter, ['sort' => ['created_at' => -1], 'skip' => $p['skip'], 'limit' => $p['per_page']]), false);

    $userIds = [];
    foreach ($tickets as $ticket) {
        $userIds[] = $ticket['user_id'] ?? null;
        $userIds[] = $ticket['resolved_by'] ?? null;
    }
    $users = adminUsersById($db, $userIds);

    jsonResponse([
        'success' => true,
        'tickets' => array_map(static fn ($ticket) => supportTicketRow($ticket, true) + [
            'user' => adminUserBrief($users[$ticket['user_id'] ?? 0] ?? null),
            'resolved_by' => adminUserBrief($users[$ticket['resolved_by'] ?? 0] ?? null),
        ], $tickets),
        'counts' => [
            'open' => $db->support_tickets->countDocuments(['status' => 'open']),
            'resolved' => $db->support_tickets->countDocuments(['status' => 'resolved']),
        ],
        'total' => $total,
        'page' => $p['page'],
        'per_page' => $p['per_page'],
    ]);
}

// Yöneticinin bir talebe yanıtı ve/veya çözümü. $text null ise yalnızca durum değişir.
// Talep sahibine zil bildirimi gider (çözüldüyse "çözüldü", değilse "yanıt"); misafir talebinde yanıt e-postayla iletilir.
// Dönüş: ['emailed' => bool|null] (null: e-posta gerekmiyordu).
function adminApplyTicketReply($db, int $adminId, $ticket, ?string $text, bool $resolve, $now): array {
    $ticketId = (int) $ticket['_id'];
    $wasResolved = ($ticket['status'] ?? 'open') === 'resolved';
    $set = ['updated_at' => $now];
    if ($resolve) {
        $set += ['status' => 'resolved', 'resolved_by' => $adminId, 'resolved_at' => $now];
    }
    $update = [];
    if ($text !== null) {
        $set['admin_note'] = $text; // eski kayıtlar ve eski arayüz için son yönetici metni
        $update['$push'] = ['replies' => ['by' => 'admin', 'text' => $text, 'at' => $now, 'admin_id' => $adminId]];
    }
    $update['$set'] = $set;
    $db->support_tickets->updateOne(['_id' => $ticketId], $update);

    $fresh = $db->support_tickets->findOne(['_id' => $ticketId]);
    $emailed = null;
    if (($ticket['user_id'] ?? null) === null) {
        if ($text !== null) {
            $emailed = supportEmailGuest($fresh, $text);
        }
    } elseif ($resolve && !$wasResolved) {
        supportNotifyOwner($db, $fresh, 'resolved');
    } elseif ($text !== null) {
        supportNotifyOwner($db, $fresh, 'reply');
    }
    return ['emailed' => $emailed];
}

// Engellenen denemeler: kim, neyi (mesaj içeriği hariç), neden, ne zaman. Karar yönetici işlemleriyle verilir.
function adminModeration($db): void {
    $p = adminPageParams($_GET['page'] ?? 1, $_GET['per_page'] ?? 20, 50);
    $since = new MongoDB\BSON\UTCDateTime((time() - MODERATION_WINDOW_DAYS * 86400) * 1000);
    $total = $db->moderation_events->countDocuments([]);
    $events = iterator_to_array($db->moderation_events->find([], ['sort' => ['created_at' => -1], 'skip' => $p['skip'], 'limit' => $p['per_page']]), false);
    $summary = iterator_to_array($db->moderation_events->aggregate([
        ['$match' => ['created_at' => ['$gte' => $since]]],
        ['$group' => ['_id' => '$user_id', 'count' => ['$sum' => 1], 'last_at' => ['$max' => '$created_at']]],
        ['$sort' => ['count' => -1]],
        ['$limit' => 20],
    ]), false);

    $ids = [];
    foreach ($events as $event) {
        $ids[] = (int) $event['user_id'];
    }
    foreach ($summary as $row) {
        $ids[] = (int) $row['_id'];
    }
    $users = adminUsersById($db, $ids);

    jsonResponse([
        'success' => true,
        'window_days' => MODERATION_WINDOW_DAYS,
        'summary' => array_map(static fn ($row) => [
            'user' => adminUserBrief($users[(int) $row['_id']] ?? null),
            'count' => (int) $row['count'],
            'last_at' => adminIso($row['last_at']),
        ], $summary),
        'events' => array_map(static fn ($event) => [
            'id' => (int) $event['_id'],
            'user' => adminUserBrief($users[(int) $event['user_id']] ?? null),
            'kind' => $event['kind'],
            'reason' => $event['reason'],
            'text' => $event['text'] ?? null,
            'created_at' => adminIso($event['created_at']),
        ], $events),
        'total' => $total,
        'page' => $p['page'],
        'per_page' => $p['per_page'],
    ]);
}

if ($method === 'GET') {
    switch ($_GET['action'] ?? 'overview') {
        case 'overview':
            adminOverview($db);
            break;
        case 'users':
            adminUsers($db);
            break;
        case 'user':
            adminUserDetail($db);
            break;
        case 'comments':
            adminComments($db);
            break;
        case 'comment':
            adminCommentDetail($db);
            break;
        case 'deleted':
            adminDeleted($db);
            break;
        case 'reports':
            adminReports($db);
            break;
        case 'audit':
            adminAudit($db);
            break;
        case 'moderation':
            adminModeration($db);
            break;
        case 'tickets':
            adminTickets($db);
            break;
        default:
            jsonResponse(['success' => false, 'message' => 'Bilinmeyen işlem'], 400);
    }
}

if ($method !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Geçersiz istek yöntemi'], 405);
}

requireCsrf();
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}
$now = new MongoDB\BSON\UTCDateTime();

switch ($input['action'] ?? '') {
    case 'delete_comment':
        $commentId = filter_var($input['comment_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$commentId || !$db->comments->findOne(['_id' => $commentId])) {
            jsonResponse(['success' => false, 'message' => 'Yorum bulunamadı'], 404);
        }
        $reason = adminReason($input['reason'] ?? null);
        archiveAndDeleteComment($db, $commentId, $adminId, 'admin', $reason);
        $db->comment_reports->updateMany(
            ['comment_id' => $commentId, 'status' => 'pending'],
            ['$set' => ['status' => 'resolved', 'resolved_by' => $adminId, 'resolved_at' => $now, 'resolution' => 'delete_comment']]
        );
        auditLog($db, $adminId, 'comment.delete', 'comment', $commentId, ['reason' => $reason]);
        jsonResponse(['success' => true]);

    case 'restore_comment':
        $archiveId = filter_var($input['archive_id'] ?? null, FILTER_VALIDATE_INT);
        $archive = $archiveId ? $db->deleted_comments->findOne(['_id' => $archiveId]) : null;
        if (!$archive) {
            jsonResponse(['success' => false, 'message' => 'Arşiv kaydı bulunamadı'], 404);
        }
        if (!restoreArchivedComment($db, $archiveId)) {
            jsonResponse(['success' => false, 'message' => 'Yorum geri yüklenemedi (zaten geri yüklenmiş ya da kimlik çakışıyor)'], 409);
        }
        auditLog($db, $adminId, 'comment.restore', 'comment', $archive['comment']['_id'], ['archive_id' => $archiveId]);
        jsonResponse(['success' => true]);

    case 'resolve_report':
        $reportId = filter_var($input['report_id'] ?? null, FILTER_VALIDATE_INT);
        $resolution = $input['resolution'] ?? null;
        $report = $reportId ? $db->comment_reports->findOne(['_id' => $reportId]) : null;
        if (!$report || !in_array($resolution, ['dismiss', 'delete_comment'], true)) {
            jsonResponse(['success' => false, 'message' => 'Geçersiz istek'], 400);
        }
        $resolved = ['$set' => ['status' => 'resolved', 'resolved_by' => $adminId, 'resolved_at' => $now, 'resolution' => $resolution]];
        if ($resolution === 'delete_comment') {
            archiveAndDeleteComment($db, (int)$report['comment_id'], $adminId, 'admin', 'Şikâyet üzerine: ' . $report['reason']);
            $db->comment_reports->updateMany(['comment_id' => $report['comment_id']], $resolved);
            auditLog($db, $adminId, 'report.delete_comment', 'comment', $report['comment_id'], ['report_id' => $reportId]);
        } else {
            $db->comment_reports->updateOne(['_id' => $reportId], $resolved);
            auditLog($db, $adminId, 'report.dismiss', 'report', $reportId);
        }
        jsonResponse(['success' => true]);

    case 'suspend_user':
        [$targetId] = adminTargetUser($db, $adminId, $input['user_id'] ?? null);
        $reason = adminReason($input['reason'] ?? null);
        $db->users->updateOne(['_id' => $targetId], ['$set' => ['status' => 'suspended', 'suspended_reason' => $reason, 'suspended_at' => $now, 'suspended_by' => $adminId]]);
        auditLog($db, $adminId, 'user.suspend', 'user', $targetId, ['reason' => $reason]);
        jsonResponse(['success' => true]);

    case 'unsuspend_user':
        [$targetId] = adminTargetUser($db, $adminId, $input['user_id'] ?? null);
        $db->users->updateOne(['_id' => $targetId], ['$set' => ['status' => 'active'], '$unset' => ['suspended_reason' => '', 'suspended_at' => '', 'suspended_by' => '']]);
        auditLog($db, $adminId, 'user.unsuspend', 'user', $targetId);
        jsonResponse(['success' => true]);

    case 'mute_user':
        [$targetId] = adminTargetUser($db, $adminId, $input['user_id'] ?? null);
        $hours = max(1, min(720, (int)($input['hours'] ?? 24)));
        $until = new MongoDB\BSON\UTCDateTime((time() + $hours * 3600) * 1000);
        $db->users->updateOne(['_id' => $targetId], ['$set' => ['muted_until' => $until]]);
        auditLog($db, $adminId, 'user.mute', 'user', $targetId, ['hours' => $hours, 'reason' => adminReason($input['reason'] ?? null)]);
        jsonResponse(['success' => true, 'muted_until' => adminIso($until)]);

    case 'unmute_user':
        [$targetId] = adminTargetUser($db, $adminId, $input['user_id'] ?? null);
        $db->users->updateOne(['_id' => $targetId], ['$unset' => ['muted_until' => '']]);
        auditLog($db, $adminId, 'user.unmute', 'user', $targetId);
        jsonResponse(['success' => true]);

    case 'resolve_ticket':
    case 'reopen_ticket':
    case 'reply_ticket':
        $ticketId = filter_var($input['ticket_id'] ?? null, FILTER_VALIDATE_INT);
        $ticket = $ticketId ? $db->support_tickets->findOne(['_id' => $ticketId]) : null;
        if (!$ticket) {
            jsonResponse(['success' => false, 'message' => 'Talep bulunamadı'], 404);
        }
        if ($input['action'] === 'reopen_ticket') {
            $db->support_tickets->updateOne(['_id' => $ticketId], ['$set' => ['status' => 'open', 'updated_at' => $now], '$unset' => ['resolved_by' => '', 'resolved_at' => '']]);
            auditLog($db, $adminId, 'ticket.reopen', 'ticket', $ticketId);
            jsonResponse(['success' => true]);
        }
        if ($input['action'] === 'reply_ticket') {
            $reply = supportCleanReply($input['message'] ?? null);
            if (isset($reply['error'])) {
                jsonResponse(['success' => false, 'message' => $reply['error']], 422);
            }
            if (count((array) ($ticket['replies'] ?? [])) >= SUPPORT_REPLY_MAX_PER_TICKET) {
                jsonResponse(['success' => false, 'message' => 'Bu talepte yanıt sınırına ulaşıldı'], 429);
            }
            $resolve = !empty($input['resolve']);
            $result = adminApplyTicketReply($db, $adminId, $ticket, $reply['message'], $resolve, $now);
            auditLog($db, $adminId, 'ticket.reply', 'ticket', $ticketId, ['resolve' => $resolve]);
            jsonResponse(['success' => true, 'emailed' => $result['emailed']]);
        }
        $note = supportCleanNote($input['note'] ?? null);
        $result = adminApplyTicketReply($db, $adminId, $ticket, $note, true, $now);
        auditLog($db, $adminId, 'ticket.resolve', 'ticket', $ticketId, $note !== null ? ['note' => $note] : []);
        jsonResponse(['success' => true, 'emailed' => $result['emailed']]);

    default:
        jsonResponse(['success' => false, 'message' => 'Bilinmeyen işlem'], 400);
}
