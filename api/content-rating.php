<?php
require_once 'config.php';


$contentType = $_GET['content_type'] ?? null;
$contentId = $_GET['content_id'] ?? null;

if (!$contentType || !$contentId) {
    jsonResponse(['success' => false, 'message' => 'İçerik türü ve ID gereklidir.'], 400);
}

if ($contentType === 'movie') {
    $contentCollectionName = 'movies';
    $statusCollectionName = 'user_movie_status';
    $contentIdCol = 'movie_id';
    $contentDbIdCol = 'tmdb_id';
    $contentId = (int)$contentId;
} elseif ($contentType === 'book') {
    $contentCollectionName = 'books';
    $statusCollectionName = 'user_book_status';
    $contentIdCol = 'book_id';
    $contentDbIdCol = 'google_books_id';
    $contentId = (string)$contentId;
} elseif ($contentType === 'series') {
    $contentCollectionName = 'series';
    $statusCollectionName = 'user_series_status';
    $contentIdCol = 'series_id';
    $contentDbIdCol = 'tmdb_id';
    $contentId = (int)$contentId;
} else {
    jsonResponse(['success' => false, 'message' => 'Geçersiz içerik türü.'], 400);
}


$content = $db->$contentCollectionName->findOne([$contentDbIdCol => $contentId]);

if (!$content) {
    jsonResponse([
        'success' => true,
        'data' => [
            'average_rating' => null,
            'total_votes' => 0,
            'rating_distribution' => []
        ]
    ]);
}

$dbContentId = $content->_id;
$statusCollection = $db->$statusCollectionName;


$statsResult = $statusCollection->aggregate([
    ['$match' => [$contentIdCol => $dbContentId, 'rating' => ['$gt' => 0]]],
    ['$group' => ['_id' => null, 'average_rating' => ['$avg' => '$rating'], 'total_votes' => ['$sum' => 1]]],
])->toArray();
$stats = $statsResult[0] ?? null;


$distributionResult = $statusCollection->aggregate([
    ['$match' => [$contentIdCol => $dbContentId, 'rating' => ['$gt' => 0]]],
    ['$group' => ['_id' => '$rating', 'count' => ['$sum' => 1]]],
    ['$sort' => ['_id' => -1]],
])->toArray();

$distribution = [];
foreach ($distributionResult as $row) {
    $distribution[$row->_id] = (int)$row->count;
}

jsonResponse([
    'success' => true,
    'data' => [
        'average_rating' => $stats ? round((float)$stats->average_rating, 1) : null,
        'total_votes' => $stats ? (int)$stats->total_votes : 0,
        'rating_distribution' => $distribution
    ]
]);
?>
