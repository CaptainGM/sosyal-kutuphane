<?php
require_once 'config.php';
requireLogin();
requireCsrf();

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);
$currentUserId = getCurrentUserId();
if ($method === 'GET') {
    $contentType = $_GET['content_type'] ?? null;
    $contentId = $_GET['content_id'] ?? null;
    $status = $_GET['status'] ?? null;
    $rating = $_GET['rating'] ?? null;
} else {
    $contentType = $input['content_type'] ?? null;
    $contentId = $input['content_id'] ?? null;
    $status = $input['status'] ?? null;
    $rating = $input['rating'] ?? null;
}

if ($rating !== null) {
    $rating = (int)$rating;
}

if (!$contentType || !$contentId) {
    jsonResponse(['success' => false, 'message' => 'İçerik türü ve ID gereklidir.'], 400);
}
if ($contentType === 'movie') {
    $statusCollectionName = 'user_movie_status';
    $contentIdCol = 'movie_id';
    $contentDbIdCol = 'tmdb_id';
    $contentTableName = 'movies';
    $contentId = (int)$contentId;
} elseif ($contentType === 'book') {
    $statusCollectionName = 'user_book_status';
    $contentIdCol = 'book_id';
    $contentDbIdCol = 'google_books_id';
    $contentTableName = 'books';
    $contentId = (string)$contentId;
} else {
    jsonResponse(['success' => false, 'message' => 'Geçersiz içerik türü.'], 400);
}

$statusCollection = $db->$statusCollectionName;
$contentCollection = $db->$contentTableName;

function saveContentToDb($contentCollection, $contentTableName, $contentDbIdCol, $contentType, $contentId, $input) {
    global $db;
    if ($contentType === 'movie') {
        $contentCollection->updateOne(
            [$contentDbIdCol => $contentId],
            ['$setOnInsert' => [
                '_id' => nextSequence($db, 'movies'),
                'tmdb_id' => $contentId,
                'title' => $input['title'] ?? null,
                'poster_path' => $input['poster_path'] ?? null,
                'created_at' => new MongoDB\BSON\UTCDateTime(),
            ]],
            ['upsert' => true]
        );
    } elseif ($contentType === 'book') {
        $contentCollection->updateOne(
            [$contentDbIdCol => $contentId],
            ['$setOnInsert' => [
                '_id' => nextSequence($db, 'books'),
                'google_books_id' => $contentId,
                'title' => $input['title'] ?? null,
                'cover_url' => $input['cover_url'] ?? null,
                'created_at' => new MongoDB\BSON\UTCDateTime(),
            ]],
            ['upsert' => true]
        );
    }
}
if (in_array($method, ['POST', 'PUT', 'DELETE']) && isset($input['content_data'])) {
    saveContentToDb($contentCollection, $contentTableName, $contentDbIdCol, $contentType, $contentId, $input['content_data']);
}

$content = $contentCollection->findOne([$contentDbIdCol => $contentId]);
$dbContentId = $content->_id ?? null;

if (!$dbContentId) {
    jsonResponse(['success' => false, 'message' => 'İçerik veritabanında bulunamadı.'], 404);
}

if ($method === 'GET') {
    $response = ['status' => null, 'rating' => null];
    $existing = $statusCollection->findOne(['user_id' => $currentUserId, $contentIdCol => $dbContentId]);

    if ($existing) {
        $response['status'] = $existing->status;
        $response['rating'] = $existing->rating;
    }

    jsonResponse(['success' => true, 'data' => $response]);

} elseif ($method === 'POST') {
    if ($status && $rating !== null && $rating > 0) {
        $statusCollection->deleteOne(['user_id' => $currentUserId, $contentIdCol => $dbContentId]);

        $statusCollection->insertOne([
            '_id' => nextSequence($db, $statusCollectionName),
            'user_id' => $currentUserId,
            $contentIdCol => $dbContentId,
            'status' => $status,
            'rating' => $rating,
            'updated_at' => new MongoDB\BSON\UTCDateTime(),
        ]);

        jsonResponse(['success' => true, 'message' => 'Durum ve puan kaydedildi.', 'new_status' => $status, 'new_rating' => $rating]);
    }
    elseif ($status) {
        $statusCollection->deleteOne(['user_id' => $currentUserId, $contentIdCol => $dbContentId]);
        $statusCollection->insertOne([
            '_id' => nextSequence($db, $statusCollectionName),
            'user_id' => $currentUserId,
            $contentIdCol => $dbContentId,
            'status' => $status,
            'rating' => null,
            'updated_at' => new MongoDB\BSON\UTCDateTime(),
        ]);

        jsonResponse(['success' => true, 'message' => 'Durum güncellendi.', 'new_status' => $status]);
    }
    elseif ($rating !== null && $rating > 0) {
        $defaultStatus = ($contentType === 'movie') ? 'watched' : 'read';
        $existing = $statusCollection->findOne(['user_id' => $currentUserId, $contentIdCol => $dbContentId]);

        if ($existing) {
            $statusCollection->updateOne(
                ['_id' => $existing->_id],
                ['$set' => ['rating' => $rating, 'updated_at' => new MongoDB\BSON\UTCDateTime()]]
            );
        } else {
            $statusCollection->insertOne([
                '_id' => nextSequence($db, $statusCollectionName),
                'user_id' => $currentUserId,
                $contentIdCol => $dbContentId,
                'status' => $defaultStatus,
                'rating' => $rating,
                'updated_at' => new MongoDB\BSON\UTCDateTime(),
            ]);
        }

        jsonResponse(['success' => true, 'message' => 'Puan kaydedildi.', 'new_rating' => $rating]);
    }

    jsonResponse(['success' => false, 'message' => 'Geçersiz POST işlemi.'], 400);

} elseif ($method === 'DELETE') {

    $statusCollection->deleteOne(['user_id' => $currentUserId, $contentIdCol => $dbContentId]);

    jsonResponse(['success' => true, 'message' => 'İçerik listeden kaldırıldı.']);
}
?>
