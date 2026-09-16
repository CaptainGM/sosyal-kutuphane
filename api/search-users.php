<?php
require_once 'config.php';

$query = $_GET['q'] ?? '';
$currentUserId = getCurrentUserId();
if (strlen($query) < 1) {
    jsonResponse(['success' => true, 'users' => []]);
}

$filter = ['username' => ['$regex' => preg_quote($query), '$options' => 'i']];
if ($currentUserId > 0) {
    $filter['_id'] = ['$ne' => (int)$currentUserId];
}

$result = $db->users->find($filter, ['limit' => 20]);

$users = [];
foreach ($result as $user) {
    $users[] = [
        'id' => $user->_id,
        'username' => $user->username,
        'bio' => $user->bio ?? null,
        'avatar_url' => $user->avatar_url ?? null,
    ];
}

jsonResponse(['success' => true, 'users' => $users]);
?>
