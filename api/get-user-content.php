<?php
require_once 'config.php';
requireLogin();
$userId = isset($_GET['user_id']) ? intval($_GET['user_id']) : getCurrentUserId();
$contentType = $_GET['type'] ?? null; 
if ($contentType === null) {
    $stmt = $conn->prepare("
        SELECT ums.movie_id, ums.status, ums.rating, ums.updated_at,
               m.tmdb_id, m.title, m.poster_path
        FROM user_movie_status ums
        LEFT JOIN movies m ON ums.movie_id = m.id
        WHERE ums.user_id = ? AND ums.status = 'watched'
        ORDER BY ums.updated_at DESC
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $watched = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt = $conn->prepare("
        SELECT ubs.book_id, ubs.status, ubs.rating, ubs.updated_at,
               b.google_books_id, b.title, b.cover_url
        FROM user_book_status ubs
        LEFT JOIN books b ON ubs.book_id = b.id
        WHERE ubs.user_id = ? AND ubs.status = 'read'
        ORDER BY ubs.updated_at DESC
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $read = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    jsonResponse(['success' => true, 'watched' => $watched, 'read' => $read]);
    exit;
}

$status = $_GET['status'] ?? 'watched'; 

if ($contentType === 'movie') {
    $stmt = $conn->prepare("
        SELECT m.*, ums.status, ums.rating
        FROM user_movie_status ums
        JOIN movies m ON ums.movie_id = m.id
        WHERE ums.user_id = ? AND ums.status = ?
        ORDER BY ums.updated_at DESC
    ");
} else if ($contentType === 'book') {
    $stmt = $conn->prepare("
        SELECT b.*, ubs.status, ubs.rating
        FROM user_book_status ubs
        JOIN books b ON ubs.book_id = b.id
        WHERE ubs.user_id = ? AND ubs.status = ?
        ORDER BY ubs.updated_at DESC
    ");
} else {
    jsonResponse(['success' => false, 'message' => 'Geçersiz content_type'], 400);
}

$stmt->bind_param("is", $userId, $status);
$stmt->execute();
$result = $stmt->get_result();
$content = $result->fetch_all(MYSQLI_ASSOC);

jsonResponse(['success' => true, 'content' => $content]);
?>

