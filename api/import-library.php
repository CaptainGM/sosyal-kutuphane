<?php
require_once 'config.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Geçersiz istek'], 405);
}
requireLogin();
requireCsrf();

// export-library.php'nin ürettiği JSON'u geri okur. Yorumlar içe aktarılmıyor
// (başkasının dosyasını yüklemek spam yoruma yol açmasın diye).
$userId = (int) getCurrentUserId();
$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    jsonResponse(['success' => false, 'message' => 'Geçersiz JSON dosyası'], 400);
}

function importStatusRows($db, int $userId, array $rows, string $externalIdField, string $contentCollectionName, string $statusCollectionName, string $statusIdField): int {
    $count = 0;
    foreach ($rows as $row) {
        $externalId = $row[$externalIdField] ?? null;
        if ($externalId === null || $externalId === '') {
            continue;
        }
        $externalId = $externalIdField === 'google_books_id' ? (string) $externalId : (int) $externalId;

        $db->$contentCollectionName->updateOne(
            [$externalIdField => $externalId],
            ['$setOnInsert' => [
                '_id' => nextSequence($db, $contentCollectionName),
                $externalIdField => $externalId,
                'title' => $row['title'] ?? null,
                'created_at' => new MongoDB\BSON\UTCDateTime(),
            ]],
            ['upsert' => true]
        );
        $content = $db->$contentCollectionName->findOne([$externalIdField => $externalId]);
        if (!$content) {
            continue;
        }

        $status = in_array($row['status'] ?? null, ['watched', 'watchlist', 'read', 'readinglist'], true) ? $row['status'] : 'watched';
        $rating = isset($row['rating']) && $row['rating'] !== null ? (int) $row['rating'] : null;

        $db->$statusCollectionName->updateOne(
            ['user_id' => $userId, $statusIdField => $content->_id],
            ['$set' => ['status' => $status, 'rating' => $rating, 'updated_at' => new MongoDB\BSON\UTCDateTime()],
                '$setOnInsert' => ['_id' => nextSequence($db, $statusCollectionName)]],
            ['upsert' => true]
        );
        $count++;
    }
    return $count;
}

$movieCount = importStatusRows($db, $userId, $input['watched_movies'] ?? [], 'tmdb_id', 'movies', 'user_movie_status', 'movie_id');
$bookCount = importStatusRows($db, $userId, $input['read_books'] ?? [], 'google_books_id', 'books', 'user_book_status', 'book_id');
$seriesCount = importStatusRows($db, $userId, $input['watched_series'] ?? [], 'tmdb_id', 'series', 'user_series_status', 'series_id');

$listCount = 0;
$listItemCount = 0;
foreach (($input['custom_lists'] ?? []) as $list) {
    $name = trim((string) ($list['name'] ?? ''));
    if ($name === '') {
        continue;
    }

    $existingList = $db->custom_lists->findOne(['user_id' => $userId, 'name' => $name]);
    if ($existingList) {
        $listId = $existingList->_id;
    } else {
        $listId = nextSequence($db, 'custom_lists');
        $db->custom_lists->insertOne([
            '_id' => $listId,
            'user_id' => $userId,
            'name' => $name,
            'description' => $list['description'] ?? null,
            'created_at' => new MongoDB\BSON\UTCDateTime(),
            'updated_at' => new MongoDB\BSON\UTCDateTime(),
        ]);
        $listCount++;
    }

    foreach (($list['items'] ?? []) as $item) {
        $contentType = $item['content_type'] ?? null;
        $contentId = isset($item['content_id']) ? (string) $item['content_id'] : null;
        if (!$contentType || !$contentId) {
            continue;
        }

        $result = $db->custom_list_items->updateOne(
            ['list_id' => $listId, 'content_type' => $contentType, 'content_id' => $contentId],
            ['$setOnInsert' => [
                '_id' => nextSequence($db, 'custom_list_items'),
                'list_id' => $listId,
                'content_type' => $contentType,
                'content_id' => $contentId,
                'content_title' => $item['content_title'] ?? null,
                'added_at' => new MongoDB\BSON\UTCDateTime(),
            ]],
            ['upsert' => true]
        );
        if ($result->getUpsertedCount() > 0) {
            $listItemCount++;
        }
    }
}

jsonResponse([
    'success' => true,
    'message' => 'Kütüphane içe aktarıldı',
    'imported' => [
        'movies' => $movieCount,
        'books' => $bookCount,
        'series' => $seriesCount,
        'lists' => $listCount,
        'list_items' => $listItemCount,
    ],
]);
?>
