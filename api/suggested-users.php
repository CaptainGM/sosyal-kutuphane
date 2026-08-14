<?php
require_once 'config.php';
requireLogin();

// 2. derece bağlantılar (takip ettiklerinin takip ettikleri), ortak sayıya göre sıralı.
// Soğuk başlangıçta en çok takipçisi olanlara düşer.
$currentUserId = (int) getCurrentUserId();
$limit = min((int) ($_GET['limit'] ?? 6), 20);

$followingIds = [];
foreach ($db->follows->find(['follower_id' => $currentUserId], ['projection' => ['following_id' => 1]]) as $row) {
    $followingIds[] = $row->following_id;
}
$excludeIds = array_merge($followingIds, [$currentUserId]);

$candidates = [];
if ($followingIds) {
    $pipeline = [
        ['$match' => ['follower_id' => ['$in' => $followingIds], 'following_id' => ['$nin' => $excludeIds]]],
        ['$group' => ['_id' => '$following_id', 'mutual_count' => ['$sum' => 1]]],
        ['$sort' => ['mutual_count' => -1]],
        ['$limit' => $limit],
    ];
    $candidates = iterator_to_array($db->follows->aggregate($pipeline), false);
}

// Yedek: en çok takipçisi olan kullanıcılar.
if (!$candidates) {
    $pipeline = [
        ['$match' => ['following_id' => ['$nin' => $excludeIds]]],
        ['$group' => ['_id' => '$following_id', 'mutual_count' => ['$sum' => 1]]],
        ['$sort' => ['mutual_count' => -1]],
        ['$limit' => $limit],
    ];
    $candidates = iterator_to_array($db->follows->aggregate($pipeline), false);
}

$userIds = array_map(fn($c) => $c->_id, $candidates);
$usersById = [];
if ($userIds) {
    foreach ($db->users->find(['_id' => ['$in' => $userIds]]) as $u) {
        $usersById[$u->_id] = $u;
    }
}

$suggestions = [];
foreach ($candidates as $c) {
    $u = $usersById[$c->_id] ?? null;
    if (!$u) {
        continue;
    }
    $suggestions[] = [
        'id' => $u->_id,
        'username' => $u->username,
        'bio' => $u->bio ?? null,
        'avatar_url' => $u->avatar_url ?? null,
        'mutual_count' => $c->mutual_count,
    ];
}

jsonResponse(['success' => true, 'suggestions' => $suggestions]);
?>
