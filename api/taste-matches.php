<?php
// "Sana en uyan kişiler": kütüphanesi olan diğer kullanıcılarla zevk uyumunu hesaplar ve en yüksekleri sıralar.
// Küçük ölçek için tasarlandı (en fazla 150 aday); engellenen kullanıcılar dışarıda bırakılır.
require_once 'config.php';
require_once __DIR__ . '/lib/taste.php';
requireLogin();

$userId = (int) getCurrentUserId();
$limit = max(1, min(30, (int) ($_GET['limit'] ?? 12)));

// Engelleme iki yönde de uygulanır.
$blockedIds = [];
foreach ($db->blocks->find(['$or' => [['blocker_id' => $userId], ['blocked_id' => $userId]]]) as $block) {
    $blockedIds[] = (int) ($block['blocker_id'] === $userId ? $block['blocked_id'] : $block['blocker_id']);
}

// Kütüphanesi olan kullanıcılar: üç durum koleksiyonundaki farklı user_id değerleri.
$candidateIds = [];
foreach (['user_movie_status', 'user_series_status', 'user_book_status'] as $collection) {
    foreach ($db->$collection->distinct('user_id') as $id) {
        $candidateIds[(int) $id] = true;
    }
}
unset($candidateIds[$userId]);
foreach ($blockedIds as $id) {
    unset($candidateIds[$id]);
}
$candidateIds = array_slice(array_keys($candidateIds), 0, 150);

$libraries = tasteLibraries($db, array_merge([$userId], $candidateIds));
$mine = $libraries[$userId] ?? [];

$matches = [];
foreach ($candidateIds as $candidateId) {
    $report = tasteReport($mine, $libraries[$candidateId] ?? []);
    if ($report['score'] === null) {
        continue;
    }
    $matches[] = ['user_id' => $candidateId, 'report' => $report];
}

usort($matches, static fn (array $a, array $b): int => [$b['report']['score'], $b['report']['shared_rated']] <=> [$a['report']['score'], $a['report']['shared_rated']]);
$matches = array_slice($matches, 0, $limit);

$ids = array_column($matches, 'user_id');
$usersById = [];
$following = [];
if ($ids) {
    foreach ($db->users->find(['_id' => ['$in' => $ids]], ['projection' => ['username' => 1, 'avatar_url' => 1, 'bio' => 1]]) as $user) {
        $usersById[$user['_id']] = $user;
    }
    foreach ($db->follows->find(['follower_id' => $userId, 'following_id' => ['$in' => $ids]]) as $follow) {
        $following[(int) $follow['following_id']] = true;
    }
}

$rows = [];
foreach ($matches as $match) {
    $user = $usersById[$match['user_id']] ?? null;
    if ($user === null) {
        continue;
    }
    $report = $match['report'];
    $rows[] = [
        'user_id' => $match['user_id'],
        'username' => $user['username'],
        'avatar_url' => $user['avatar_url'] ?? null,
        'bio' => $user['bio'] ?? null,
        'score' => $report['score'],
        'label' => $report['label'],
        'confidence' => $report['confidence'],
        'shared_total' => $report['shared_total'],
        'shared_rated' => $report['shared_rated'],
        'common_genres' => array_slice($report['common_genres'], 0, 3),
        'is_following' => isset($following[$match['user_id']]),
    ];
}

jsonResponse([
    'success' => true,
    'matches' => $rows,
    'my_library_count' => count($mine),
    'has_enough_data' => count($mine) >= 3,
]);
