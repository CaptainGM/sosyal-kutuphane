<?php
require_once 'config.php';
require_once __DIR__ . '/lib/taste.php';
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

// Bir türün özeti. $type: movie|series|book; $publicField ve $posterField içerik belgesindeki alan adlarıdır.
function typeSummary($db, string $statusCollectionName, string $contentCollectionName, string $idField, int $userId, array $dateMatch, string $statusValue, string $type, string $publicField, string $posterField): array {
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
        $content = $topAgg[0]->content;
        $top = [
            'type' => $type,
            'title' => $content->title ?? 'Bilinmiyor',
            'rating' => $topAgg[0]->rating,
            'content_id' => $content->$publicField ?? null,
            'poster' => tasteImageUrl($type, $content->$posterField ?? null),
        ];
    }

    return ['count' => $count, 'ratings_given' => $ratingsGiven, 'avg_rating' => $avgRating, 'top' => $top];
}

// Tür adı sayıları. Detay sayfası türleri TMDB kimliği (sayı) olarak da kaydeder; Türkçe adlara çevrilir.
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
        foreach (tasteGenreNames([$r->_id]) as $name) {
            $counts[$name] = ($counts[$name] ?? 0) + $r->count;
        }
    }
    return $counts;
}

// Ay ay (1-12) bitirilen içerik sayısı.
function monthlyCounts($db, string $statusCollectionName, int $userId, array $dateMatch, string $statusValue): array {
    $rows = $db->$statusCollectionName->aggregate([
        ['$match' => array_merge(['user_id' => $userId, 'status' => $statusValue], $dateMatch)],
        ['$group' => ['_id' => ['$month' => '$updated_at'], 'count' => ['$sum' => 1]]],
    ]);
    $months = array_fill(1, 12, 0);
    foreach ($rows as $r) {
        $months[(int) $r->_id] = (int) $r->count;
    }
    return $months;
}

$movies = typeSummary($db, 'user_movie_status', 'movies', 'movie_id', $userId, $dateMatch, 'watched', 'movie', 'tmdb_id', 'poster_path');
$series = typeSummary($db, 'user_series_status', 'series', 'series_id', $userId, $dateMatch, 'watched', 'series', 'tmdb_id', 'poster_path');
$books = typeSummary($db, 'user_book_status', 'books', 'book_id', $userId, $dateMatch, 'read', 'book', 'google_books_id', 'cover_url');

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
$topGenres = [];
foreach (array_slice($genreTotals, 0, 5, true) as $name => $c) {
    $topGenres[] = ['name' => (string) $name, 'count' => (int) $c];
}

$monthly = array_fill(1, 12, 0);
foreach ([
    monthlyCounts($db, 'user_movie_status', $userId, $dateMatch, 'watched'),
    monthlyCounts($db, 'user_series_status', $userId, $dateMatch, 'watched'),
    monthlyCounts($db, 'user_book_status', $userId, $dateMatch, 'read'),
] as $months) {
    foreach ($months as $month => $c) {
        $monthly[$month] += $c;
    }
}

// Yılın en yüksek puanlısı: üç türden en yüksek puanlanan tek içerik.
$bestOfYear = null;
foreach ([$movies['top'], $series['top'], $books['top']] as $candidate) {
    if ($candidate && ($bestOfYear === null || $candidate['rating'] > $bestOfYear['rating'])) {
        $bestOfYear = $candidate;
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
    'top_genres' => $topGenres,
    'monthly' => array_values($monthly),
    'best_of_year' => $bestOfYear,
]);
?>
