<?php
// Zevk eşleşmesi: iki kullanıcının kütüphanesini karşılaştırır (puan yakınlığı, ortak türler, birlikte sevilenler,
// ayrışılanlar, birbirine öneriler). Hesaplama api/lib/taste.php'dedir (saf fonksiyonlar, birim testli).
// Eski arayüzün okuduğu score / basis / shared_rated_count / common_genres alanları korunur.
require_once 'config.php';
require_once __DIR__ . '/lib/taste.php';
requireLogin();

$userId = (int) getCurrentUserId();
$targetUserId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : null;

if (!$targetUserId || $targetUserId === $userId) {
    jsonResponse(['success' => false, 'message' => 'Geçersiz kullanıcı'], 400);
}

$target = $db->users->findOne(['_id' => $targetUserId], ['projection' => ['username' => 1, 'avatar_url' => 1]]);
if (!$target) {
    jsonResponse(['success' => false, 'message' => 'Kullanıcı bulunamadı'], 404);
}

$libraries = tasteLibraries($db, [$userId, $targetUserId]);
$report = tasteReport($libraries[$userId], $libraries[$targetUserId]);

jsonResponse([
    'success' => true,
    'score' => $report['score'],
    'basis' => $report['basis'],
    'shared_rated_count' => $report['shared_rated'],
    'common_genres' => array_slice($report['common_genres'], 0, 5),
    'target' => ['id' => $targetUserId, 'username' => $target['username'], 'avatar_url' => $target['avatar_url'] ?? null],
    'report' => $report,
]);
