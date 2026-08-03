<?php
require_once 'config.php';
requireLogin();

$userId = getCurrentUserId();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $conn->prepare("
        SELECT n.*, u.username, u.email 
        FROM notifications n
        LEFT JOIN users u ON n.actor_id = u.id
        WHERE n.user_id = ?
        ORDER BY n.created_at DESC
        LIMIT 50
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $notifications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    jsonResponse(['success' => true, 'notifications' => $notifications]);
} elseif ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $input = json_decode(file_get_contents('php://input'), true);
    $notificationId = $input['id'] ?? null;
    
    if ($notificationId) {
        $stmt = $conn->prepare("UPDATE notifications SET is_read = TRUE WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $notificationId, $userId);
        
        if ($stmt->execute()) {
            jsonResponse(['success' => true]);
        }
    }
}
?>
