<?php
require_once 'config.php';
requireLogin();

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);
$currentUserId = getCurrentUserId();
$targetUserId = $input['target_user_id'] ?? null;

if (!$targetUserId) {
    jsonResponse(['success' => false, 'message' => 'Kullanıcı ID gereklidir'], 400);
}

if ($currentUserId == $targetUserId) {
    jsonResponse(['success' => false, 'message' => 'Kendinizi takip edemezsiniz'], 400);
}

if ($method === 'POST') {
    $stmt = $conn->prepare("INSERT IGNORE INTO follows (follower_id, following_id) VALUES (?, ?)");
    $stmt->bind_param("ii", $currentUserId, $targetUserId);
    
    if ($stmt->execute()) {
        $stmt = $conn->prepare("INSERT INTO notifications (user_id, actor_id, type, content_type) VALUES (?, ?, 'follow', 'user')");
        $stmt->bind_param("ii", $targetUserId, $currentUserId);
        $stmt->execute();
        
        jsonResponse(['success' => true, 'message' => 'Kullanıcı takip edildi']);
    }
} elseif ($method === 'DELETE') {
    $stmt = $conn->prepare("DELETE FROM follows WHERE follower_id = ? AND following_id = ?");
    $stmt->bind_param("ii", $currentUserId, $targetUserId);
    
    if ($stmt->execute()) {
        jsonResponse(['success' => true, 'message' => 'Takip kaldırıldı']);
    }
}
?>
