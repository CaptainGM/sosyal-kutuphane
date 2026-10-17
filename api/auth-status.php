<?php
require_once 'config.php';

if (isLoggedIn()) {
    $userId = $_SESSION['user_id'];
    $user = $db->users->findOne(
        ['_id' => $userId],
        ['projection' => ['username' => 1, 'email' => 1, 'bio' => 1, 'avatar_url' => 1, 'totp_enabled' => 1, 'is_admin' => 1, 'status' => 1, 'is_demo' => 1]]
    );

    // Askıya alınan hesabın oturumu kapanır; arayüz giriş ekranına döner.
    if ($user && ($user->status ?? 'active') === 'suspended') {
        $_SESSION = [];
        session_destroy();
        jsonResponse(['realtime' => false, 'success' => true, 'user' => null, 'suspended' => true]);
    }

    if ($user) {
        jsonResponse(['realtime' => getenv('ENABLE_SSE') === '1', 
            'success' => true,
            'user' => [
                'id' => $user->_id,
                'username' => $user->username,
                'email' => $user->email,
                'bio' => $user->bio ?? null,
                'avatar_url' => $user->avatar_url ?? null,
                'totp_enabled' => $user->totp_enabled ?? false,
                'is_admin' => $user->is_admin ?? false,
                'is_demo' => !empty($user->is_demo),
            ],
            'csrf_token' => csrfToken()
        ]);
    } else {
        jsonResponse(['realtime' => getenv('ENABLE_SSE') === '1', 
            'success' => false,
            'user' => null
        ]);
    }
} else {
    jsonResponse(['realtime' => getenv('ENABLE_SSE') === '1', 
        'success' => true,
        'user' => null
    ]);
}
?>
