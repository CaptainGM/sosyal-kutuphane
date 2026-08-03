
<?php
require_once 'config.php';
requireLogin();

$userId = $_GET['user_id'] ?? getCurrentUserId();
$type = $_GET['type'] ?? null;
$status = $_GET['status'] ?? null;
if ($type === 'movie') {
    $statusFilter = $status ? $status : 'watched';
    $stmt = $conn->prepare("
        SELECT m.id, m.tmdb_id, m.title, m.poster_path as poster, ums.rating, ums.status, ums.updated_at
        FROM user_movie_status ums
        JOIN movies m ON ums.movie_id = m.id
        WHERE ums.user_id = ? AND ums.status = ?
        ORDER BY ums.updated_at DESC
    ");
    $stmt->bind_param("is", $userId, $statusFilter);
} elseif ($type === 'book') {
    $statusFilter = $status ? $status : 'read';
    $stmt = $conn->prepare("
        SELECT b.id, b.google_books_id, b.title, b.cover_url as poster, ubs.rating, ubs.status, ubs.updated_at
        FROM user_book_status ubs
        JOIN books b ON ubs.book_id = b.id
        WHERE ubs.user_id = ? AND ubs.status = ?
        ORDER BY ubs.updated_at DESC
    ");
    $stmt->bind_param("is", $userId, $statusFilter);
} else {
    jsonResponse(['success' => false, 'message' => 'type parametresi gerekli (movie veya book)'], 400);
}

$stmt->execute();
$result = $stmt->get_result();
$content = $result->fetch_all(MYSQLI_ASSOC);

jsonResponse(['success' => true, 'content' => $content]);
?>
