<?php
require_once 'config.php';

$type = $_GET['type'] ?? 'movie';
$sortBy = $_GET['sort'] ?? 'comments';
$limit = min((int)($_GET['limit'] ?? 10), 50);

if ($type === 'movie') {
    $contentCollectionName = 'movies';
    $statusCollectionName = 'user_movie_status';
    $contentIdCol = 'movie_id';
    $externalIdCol = 'tmdb_id';
    $imageCol = 'poster_path';
} elseif ($type === 'book') {
    $contentCollectionName = 'books';
    $statusCollectionName = 'user_book_status';
    $contentIdCol = 'book_id';
    $externalIdCol = 'google_books_id';
    $imageCol = 'cover_url';
} else {
    jsonResponse(['success' => false, 'message' => 'Geçersiz içerik türü'], 400);
}

$contentCollection = $db->$contentCollectionName;
$statusCollection = $db->$statusCollectionName;

$results = [];

try {
    if ($limit > 0 && $sortBy === 'comments') {
        $grouped = $db->comments->aggregate([
            ['$match' => ['content_type' => $type]],
            ['$group' => ['_id' => '$content_id', 'comment_count' => ['$sum' => 1]]],
            ['$sort' => ['comment_count' => -1]],
            ['$limit' => $limit],
        ])->toArray();

        $extIds = array_map(fn($g) => $g->_id, $grouped);
        $docs = iterator_to_array($contentCollection->find([$externalIdCol => ['$in' => $extIds]]));
        $docMap = [];
        foreach ($docs as $d) {
            $docMap[(string)$d->$externalIdCol] = $d;
        }

        foreach ($grouped as $g) {
            $d = $docMap[(string)$g->_id] ?? null;
            if (!$d) {
                continue;
            }
            $results[] = [
                'external_id' => $d->$externalIdCol,
                'title' => $d->title,
                'image_url' => $d->$imageCol ?? null,
                'comment_count' => (int)$g->comment_count,
            ];
        }

    } elseif ($limit > 0 && $sortBy === 'lists') {
        $grouped = $statusCollection->aggregate([
            ['$group' => ['_id' => '$' . $contentIdCol, 'list_count' => ['$sum' => 1]]],
            ['$sort' => ['list_count' => -1]],
            ['$limit' => $limit],
        ])->toArray();

        $ids = array_map(fn($g) => $g->_id, $grouped);
        $docs = iterator_to_array($contentCollection->find(['_id' => ['$in' => $ids]]));
        $docMap = [];
        foreach ($docs as $d) {
            $docMap[$d->_id] = $d;
        }

        foreach ($grouped as $g) {
            $d = $docMap[$g->_id] ?? null;
            if (!$d) {
                continue;
            }
            $results[] = [
                'external_id' => $d->$externalIdCol,
                'title' => $d->title,
                'image_url' => $d->$imageCol ?? null,
                'list_count' => (int)$g->list_count,
            ];
        }

    } elseif ($limit > 0 && $sortBy === 'ratings') {
        $grouped = $statusCollection->aggregate([
            ['$match' => ['rating' => ['$gt' => 0]]],
            ['$group' => ['_id' => '$' . $contentIdCol, 'avg_rating' => ['$avg' => '$rating'], 'vote_count' => ['$sum' => 1]]],
            ['$match' => ['vote_count' => ['$gte' => 1]]],
            ['$sort' => ['avg_rating' => -1, 'vote_count' => -1]],
            ['$limit' => $limit],
        ])->toArray();

        $ids = array_map(fn($g) => $g->_id, $grouped);
        $docs = iterator_to_array($contentCollection->find(['_id' => ['$in' => $ids]]));
        $docMap = [];
        foreach ($docs as $d) {
            $docMap[$d->_id] = $d;
        }

        foreach ($grouped as $g) {
            $d = $docMap[$g->_id] ?? null;
            if (!$d) {
                continue;
            }
            $results[] = [
                'external_id' => $d->$externalIdCol,
                'title' => $d->title,
                'image_url' => $d->$imageCol ?? null,
                'avg_rating' => (float)$g->avg_rating,
                'vote_count' => (int)$g->vote_count,
            ];
        }
    }
} catch (Exception $e) {
    jsonResponse(['success' => false, 'message' => 'Veritabanı hatası: ' . $e->getMessage()], 500);
}

$formattedResults = [];
foreach ($results as $row) {
    $item = [
        'id' => $row['external_id'],
        'title' => $row['title'],
        'type' => $type
    ];

    if ($type === 'movie') {
        $item['poster_path'] = $row['image_url'];
    } else {
        $item['cover_url'] = $row['image_url'];
    }

    if (isset($row['comment_count'])) {
        $item['comment_count'] = (int)$row['comment_count'];
    }
    if (isset($row['list_count'])) {
        $item['list_count'] = (int)$row['list_count'];
    }
    if (isset($row['avg_rating'])) {
        $item['avg_rating'] = round((float)$row['avg_rating'], 1);
        $item['vote_count'] = (int)$row['vote_count'];
    }

    $formattedResults[] = $item;
}

jsonResponse([
    'success' => true,
    'type' => $type,
    'sort' => $sortBy,
    'data' => $formattedResults
]);
?>
