
<?php
require_once 'config.php';
requireLogin();

$targetUserId = $_GET['user_id'] ?? null;
$currentUserId = getCurrentUserId();

if (!$targetUserId) {
    jsonResponse(['success' => false, 'message' => 'Kullanıcı ID gereklidir'], 400);
}

$stmt = $conn->prepare("SELECT id FROM follows WHERE follower_id = ? AND following_id = ?");
$stmt->bind_param("ii", $currentUserId, $targetUserId);
$stmt->execute();
$result = $stmt->get_result();

jsonResponse([
    'success' => true,
    'is_following' => $result->num_rows > 0
]);
?>
