<?php
require_once 'config.php';
requireLogin();

// Yıllık özet — ayrı bir "watched_at" alanı yok, updated_at vekil olarak kullanılır.
$userId = (int) getCurrentUserId();
$currentYear = (int) date('Y');
$year = isset($_GET['year']) ? (int) $_GET['year'] : $currentYear;
if ($year < 2000 || $year > $currentYear) {
    $year = $currentYear;
}

$start = new MongoDB\BSON\UTCDateTime(strtotime("$year-01-01 00:00:00") * 1000);
$end = new MongoDB\BSON\UTCDateTime(strtotime(($year + 1) . "-01-01 00:00:00") * 1000);
$dateMatch = ['updated_at' => ['$gte' => $start, '$lt' => $end]];

function typeSummary($db, string $statusCollectionName, string $contentCollectionName, string $idField, int $userId, array $dateMatch, string $statusValue): array {
    $baseMatch = array_merge(['user_id' => $userId, 'status' => $statusValue], $dateMatch);
    $count = $db->$statusCollectionName->countDocuments($baseMatch);

    $ratingAgg = iterator_to_array($db->$statusCollectionName->aggregate([
        ['$match' => array_merge($baseMatch, ['rating' => ['$ne' => null]])],
        ['$group' => ['_id' => null, 'avg' => ['$avg' => '$rating'], 'count' => ['$sum' => 1]]],
    ]), false);
    $ratingsGiven = $ratingAgg[0]->count ?? 0;
    $avgRating = isset($ratingAgg[0]->avg) ? round((float) $ratingAgg[0]->avg, 1) : null;

    $topAgg = iterator_to_array($db->$statusCollectionName->aggregate([
        ['$match' => array_merge($baseMatch, ['rating' => ['$ne' => null]])],
        ['$sort' => ['rating' => -1, 'updated_at' => -1]],
        ['$limit' => 1],
        ['$lookup' => ['from' => $contentCollectionName, 'localField' => $idField, 'foreignField' => '_id', 'as' => 'content']],
        ['$unwind' => '$content'],
    ]), false);
    $top = null;
    if ($topAgg) {
        $top = [
            'title' => $topAgg[0]->content->title ?? 'Bilinmiyor',
            'rating' => $topAgg[0]->rating,
        ];
    }

    return ['count' => $count, 'ratings_given' => $ratingsGiven, 'avg_rating' => $avgRating, 'top' => $top];
}

function genreCounts($db, string $statusCollectionName, string $contentCollectionName, string $idField, int $userId, array $dateMatch, string $statusValue): array {
    $pipeline = [
        ['$match' => array_merge(['user_id' => $userId, 'status' => $statusValue], $dateMatch)],
        ['$lookup' => ['from' => $contentCollectionName, 'localField' => $idField, 'foreignField' => '_id', 'as' => 'content']],
        ['$unwind' => '$content'],
        ['$match' => ['content.genres' => ['$type' => 'array']]],
        ['$unwind' => '$content.genres'],
        ['$group' => ['_id' => '$content.genres', 'count' => ['$sum' => 1]]],
    ];
    $rows = iterator_to_array($db->$statusCollectionName->aggregate($pipeline), false);
    $counts = [];
    foreach ($rows as $r) {
        $g = $r->_id;
        $name = is_object($g) ? ($g->name ?? null) : (is_string($g) ? $g : null);
        if ($name === null) {
            continue;
        }
        $counts[$name] = ($counts[$name] ?? 0) + $r->count;
    }
    return $counts;
}

$movies = typeSummary($db, 'user_movie_status', 'movies', 'movie_id', $userId, $dateMatch, 'watched');
$series = typeSummary($db, 'user_series_status', 'series', 'series_id', $userId, $dateMatch, 'watched');
$books = typeSummary($db, 'user_book_status', 'books', 'book_id', $userId, $dateMatch, 'read');

$genreTotals = [];
foreach ([
    genreCounts($db, 'user_movie_status', 'movies', 'movie_id', $userId, $dateMatch, 'watched'),
    genreCounts($db, 'user_series_status', 'series', 'series_id', $userId, $dateMatch, 'watched'),
] as $counts) {
    foreach ($counts as $name => $c) {
        $genreTotals[$name] = ($genreTotals[$name] ?? 0) + $c;
    }
}
arsort($genreTotals);
$favoriteGenre = array_key_first($genreTotals);

// Yılın en yüksek puanlısı: üç türden en yüksek puanlanan tek içerik.
$bestOfYear = null;
foreach ([['type' => 'movie', 'label' => '🎬', 'data' => $movies['top']], ['type' => 'series', 'label' => '📺', 'data' => $series['top']], ['type' => 'book', 'label' => '📚', 'data' => $books['top']]] as $candidate) {
    if ($candidate['data'] && ($bestOfYear === null || $candidate['data']['rating'] > $bestOfYear['rating'])) {
        $bestOfYear = ['type' => $candidate['type'], 'label' => $candidate['label'], 'title' => $candidate['data']['title'], 'rating' => $candidate['data']['rating']];
    }
}

$totalComments = $db->comments->countDocuments(array_merge(['user_id' => $userId, 'parent_comment_id' => null], ['created_at' => $dateMatch['updated_at']]));
$newFollowers = $db->follows->countDocuments(array_merge(['following_id' => $userId], ['created_at' => $dateMatch['updated_at']]));

$totalItems = $movies['count'] + $series['count'] + $books['count'];
$totalRatingsGiven = $movies['ratings_given'] + $series['ratings_given'] + $books['ratings_given'];

jsonResponse([
    'success' => true,
    'year' => $year,
    'has_data' => $totalItems > 0 || $totalComments > 0,
    'movies' => $movies,
    'series' => $series,
    'books' => $books,
    'total_items' => $totalItems,
    'total_ratings_given' => $totalRatingsGiven,
    'total_comments' => $totalComments,
    'new_followers' => $newFollowers,
    'favorite_genre' => $favoriteGenre,
    'best_of_year' => $bestOfYear,
]);
?>
