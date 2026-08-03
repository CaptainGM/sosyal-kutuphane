<?php
require_once 'config.php';
requireLogin();

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);
$userId = getCurrentUserId();
$contentType = $input['content_type'] ?? null; 
$contentId = $input['content_id'] ?? null; 
$status = $input['status'] ?? 'watched'; 
$rating = $input['rating'] ?? null;
$contentData = $input['content_data'] ?? null; 

if (!$contentType || !$contentId) {
    jsonResponse(['success' => false, 'message' => 'content_type ve content_id gerekli'], 400);
}
if ($contentType === 'movie' && $contentData) {
    $stmt = $conn->prepare("
        INSERT IGNORE INTO movies (tmdb_id, title, poster_path, overview, release_date, genres, rating)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param(
        "issssss",
        $contentId,
        $contentData['title'],
        $contentData['poster_path'],
        $contentData['overview'],
        $contentData['release_date'],
        $contentData['genres'],
        $contentData['rating']
    );
    $stmt->execute();
}

if ($contentType === 'book' && $contentData) {
    $stmt = $conn->prepare("
        INSERT IGNORE INTO books (google_books_id, title, authors, cover_url, description, published_date, categories)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param(
        "issssss",
        $contentId,
        $contentData['title'],
        $contentData['authors'],
        $contentData['cover_url'],
        $contentData['description'],
        $contentData['published_date'],
        $contentData['categories']
    );
    $stmt->execute();
}
$idColumn = ($contentType === 'movie') ? 'tmdb_id' : 'google_books_id';
$table = ($contentType === 'movie') ? 'movies' : 'books';

$stmt = $conn->prepare("SELECT id FROM $table WHERE $idColumn = ?");
$stmt->bind_param("s", $contentId);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();

if (!$result) {
    jsonResponse(['success' => false, 'message' => 'İçerik kaydedilemedi'], 500);
}

$dbContentId = $result['id'];
$statusTable = ($contentType === 'movie') ? 'user_movie_status' : 'user_book_status';
$contentIdCol = ($contentType === 'movie') ? 'movie_id' : 'book_id';
$stmt = $conn->prepare("SELECT id FROM $statusTable WHERE user_id = ? AND $contentIdCol = ?");
$stmt->bind_param("ii", $userId, $dbContentId);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();

if ($existing) {
    $stmt = $conn->prepare("UPDATE $statusTable SET status = ?, rating = ? WHERE user_id = ? AND $contentIdCol = ?");
    $stmt->bind_param("siii", $status, $rating, $userId, $dbContentId);
} else {
    $stmt = $conn->prepare("INSERT INTO $statusTable (user_id, $contentIdCol, status, rating) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("iisi", $userId, $dbContentId, $status, $rating);
}

if ($stmt->execute()) {
    jsonResponse(['success' => true, 'message' => 'Durumu kaydedildi']);
} else {
    jsonResponse(['success' => false, 'message' => 'Hata: ' . $conn->error], 500);
}
?>

