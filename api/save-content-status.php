<?php
require_once 'config.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Geçersiz istek'], 405);
}
requireLogin();
requireCsrf();

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

if ($contentType === 'movie') {
    $contentCollectionName = 'movies';
    $idColumn = 'tmdb_id';
    $contentId = (int)$contentId;
    $statusCollectionName = 'user_movie_status';
    $statusIdField = 'movie_id';
} elseif ($contentType === 'book') {
    $contentCollectionName = 'books';
    $idColumn = 'google_books_id';
    $contentId = (string)$contentId;
    $statusCollectionName = 'user_book_status';
    $statusIdField = 'book_id';
} else {
    jsonResponse(['success' => false, 'message' => 'Geçersiz içerik türü'], 400);
}

$contentCollection = $db->$contentCollectionName;
$statusCollection = $db->$statusCollectionName;

if ($contentType === 'movie' && $contentData) {
    $contentCollection->updateOne(
        [$idColumn => $contentId],
        ['$setOnInsert' => [
            '_id' => nextSequence($db, 'movies'),
            'tmdb_id' => $contentId,
            'title' => $contentData['title'] ?? null,
            'poster_path' => $contentData['poster_path'] ?? null,
            'overview' => $contentData['overview'] ?? null,
            'release_date' => $contentData['release_date'] ?? null,
            'genres' => $contentData['genres'] ?? null,
            'rating' => $contentData['rating'] ?? null,
            'created_at' => new MongoDB\BSON\UTCDateTime(),
        ]],
        ['upsert' => true]
    );
}

if ($contentType === 'book' && $contentData) {
    $contentCollection->updateOne(
        [$idColumn => $contentId],
        ['$setOnInsert' => [
            '_id' => nextSequence($db, 'books'),
            'google_books_id' => $contentId,
            'title' => $contentData['title'] ?? null,
            'authors' => $contentData['authors'] ?? null,
            'cover_url' => $contentData['cover_url'] ?? null,
            'description' => $contentData['description'] ?? null,
            'published_date' => $contentData['published_date'] ?? null,
            'categories' => $contentData['categories'] ?? null,
            'created_at' => new MongoDB\BSON\UTCDateTime(),
        ]],
        ['upsert' => true]
    );
}

$content = $contentCollection->findOne([$idColumn => $contentId]);

if (!$content) {
    jsonResponse(['success' => false, 'message' => 'İçerik kaydedilemedi'], 500);
}

$dbContentId = $content->_id;
$ratingValue = $rating !== null ? (int)$rating : null;

$existing = $statusCollection->findOne(['user_id' => $userId, $statusIdField => $dbContentId]);

if ($existing) {
    $statusCollection->updateOne(
        ['_id' => $existing->_id],
        ['$set' => ['status' => $status, 'rating' => $ratingValue, 'updated_at' => new MongoDB\BSON\UTCDateTime()]]
    );
} else {
    $statusCollection->insertOne([
        '_id' => nextSequence($db, $statusCollectionName),
        'user_id' => $userId,
        $statusIdField => $dbContentId,
        'status' => $status,
        'rating' => $ratingValue,
        'updated_at' => new MongoDB\BSON\UTCDateTime(),
    ]);
}

jsonResponse(['success' => true, 'message' => 'Durumu kaydedildi']);
?>
