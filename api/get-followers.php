<?php
require_once 'config.php';

$userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;

if (!$userId) {
    jsonResponse(['success' => false, 'message' => 'Kullanıcı ID gereklidir'], 400);
}

$follows = $db->follows->find(['following_id' => $userId]);
$followerIds = [];
foreach ($follows as $follow) {
    $followerIds[] = $follow->follower_id;
}

$followers = [];
if ($followerIds) {
    $users = $db->users->find(['_id' => ['$in' => $followerIds]]);
    foreach ($users as $user) {
        $followers[] = [
            'id' => $user->_id,
            'username' => $user->username,
            'avatar_url' => $user->avatar_url ?? null,
        ];
    }
    usort($followers, fn($a, $b) => strcmp($a['username'], $b['username']));
}

jsonResponse(['success' => true, 'followers' => $followers]);
?>
