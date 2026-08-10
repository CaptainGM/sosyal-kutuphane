<?php
require_once 'config.php';
requireLogin();

$targetUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;
$currentUserId = (int)getCurrentUserId();

if (!$targetUserId) {
    jsonResponse(['success' => false, 'message' => 'Kullanıcı ID gereklidir'], 400);
}

$follow = $db->follows->findOne(['follower_id' => $currentUserId, 'following_id' => $targetUserId]);

jsonResponse([
    'success' => true,
    'is_following' => $follow !== null
]);
?>
