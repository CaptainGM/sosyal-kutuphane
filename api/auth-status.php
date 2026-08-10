<?php
require_once 'config.php';

if (isLoggedIn()) {
    $userId = $_SESSION['user_id'];
    $user = $db->users->findOne(
        ['_id' => $userId],
        ['projection' => ['username' => 1, 'email' => 1, 'bio' => 1, 'avatar_url' => 1]]
    );

    if ($user) {
        jsonResponse([
            'success' => true,
            'user' => [
                'id' => $user->_id,
                'username' => $user->username,
                'email' => $user->email,
                'bio' => $user->bio ?? null,
                'avatar_url' => $user->avatar_url ?? null,
            ],
            'csrf_token' => csrfToken()
        ]);
    } else {
        jsonResponse([
            'success' => false,
            'user' => null
        ]);
    }
} else {
    jsonResponse([
        'success' => true,
        'user' => null
    ]);
}
?>
