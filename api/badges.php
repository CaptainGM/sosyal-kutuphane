<?php
require_once 'config.php';
require_once __DIR__ . '/lib/badges.php';

// user_id verilmezse oturum açmış kullanıcının rozetleri döner; profiller herkese açıktır.
$targetId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : null;
if ($targetId === null) {
    requireLogin();
    $targetId = (int) getCurrentUserId();
}

if (!$db->users->findOne(['_id' => $targetId], ['projection' => ['_id' => 1]])) {
    jsonResponse(['success' => false, 'message' => 'Kullanıcı bulunamadı'], 404);
}

$items = 0;
$ratings = 0;
foreach (['user_movie_status', 'user_book_status', 'user_series_status'] as $collection) {
    $items += $db->$collection->countDocuments(['user_id' => $targetId]);
    $ratings += $db->$collection->countDocuments(['user_id' => $targetId, 'rating' => ['$ne' => null]]);
}

$counts = [
    'items' => $items,
    'watched_movies' => $db->user_movie_status->countDocuments(['user_id' => $targetId, 'status' => 'watched']),
    'read_books' => $db->user_book_status->countDocuments(['user_id' => $targetId, 'status' => 'read']),
    'ratings' => $ratings,
    'reviews' => $db->comments->countDocuments(['user_id' => $targetId, 'parent_comment_id' => null]),
    'followers' => $db->follows->countDocuments(['following_id' => $targetId]),
    'lists' => $db->custom_lists->countDocuments(['user_id' => $targetId]),
];

jsonResponse(['success' => true, 'badges' => computeBadges($counts)]);
?>
