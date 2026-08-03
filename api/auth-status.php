<?php
require_once 'config.php';

if (isLoggedIn()) {
    $userId = $_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT id, username, email, bio, avatar_url FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    
    if ($user) {
        jsonResponse([
            'success' => true,
            'user' => $user
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
