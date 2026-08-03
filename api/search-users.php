<?php
require_once 'config.php';

$query = $_GET['q'] ?? '';
$currentUserId = getCurrentUserId(); 
if (strlen($query) < 1) {
    jsonResponse(['success' => true, 'users' => []]);
}
$searchTerm = '%' . $query . '%';

if ($currentUserId > 0) {
    $stmt = $conn->prepare("
        SELECT id, username, bio, avatar_url
        FROM users
        WHERE username LIKE ?
        AND id != ?
        LIMIT 20
    ");
    $stmt->bind_param("si", $searchTerm, $currentUserId);
} else {
    $stmt = $conn->prepare("
        SELECT id, username, bio, avatar_url
        FROM users
        WHERE username LIKE ?
        LIMIT 20
    ");
    $stmt->bind_param("s", $searchTerm);
}

$stmt->execute();
$result = $stmt->get_result();
$users = $result->fetch_all(MYSQLI_ASSOC);

jsonResponse(['success' => true, 'users' => $users]);
?>

