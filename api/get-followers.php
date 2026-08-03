<?php
require_once 'config.php';

$userId = $_GET['user_id'] ?? null;

if (!$userId) {
    jsonResponse(['success' => false, 'message' => 'Kullanıcı ID gereklidir'], 400);
}
$stmt = $conn->prepare("
    SELECT u.id, u.username, u.avatar_url
    FROM follows
    JOIN users u ON follows.follower_id = u.id
    WHERE follows.following_id = ?
    ORDER BY u.username
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
$followers = $result->fetch_all(MYSQLI_ASSOC);

jsonResponse(['success' => true, 'followers' => $followers]);
?>

