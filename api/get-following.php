<?php
require_once 'config.php';
requireLogin();

$currentUserId = (int)getCurrentUserId();

$follows = $db->follows->find(['follower_id' => $currentUserId]);
$followingIds = [];
foreach ($follows as $follow) {
    $followingIds[] = $follow->following_id;
}

$following = [];
if ($followingIds) {
    $users = $db->users->find(['_id' => ['$in' => $followingIds]]);
    foreach ($users as $user) {
        $following[] = [
            'id' => $user->_id,
            'username' => $user->username,
            'email' => $user->email,
            'bio' => $user->bio ?? null,
            'avatar_url' => $user->avatar_url ?? null,
        ];
    }
    usort($following, fn($a, $b) => strcmp($a['username'], $b['username']));
}

jsonResponse(['success' => true, 'following' => $following]);
?>
