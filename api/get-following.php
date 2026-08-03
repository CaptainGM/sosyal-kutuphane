<?php
require_once 'config.php';
requireLogin();

$currentUserId = getCurrentUserId();
$stmt = $conn->prepare("
    SELECT u.id, u.username, u.email, u.bio, u.avatar_url
    FROM follows
    JOIN users u ON follows.following_id = u.id
    WHERE follows.follower_id = ?
    ORDER BY u.username
");
$stmt->bind_param("i", $currentUserId);
$stmt->execute();
$result = $stmt->get_result();
$following = $result->fetch_all(MYSQLI_ASSOC);

jsonResponse(['success' => true, 'following' => $following]);
?>

