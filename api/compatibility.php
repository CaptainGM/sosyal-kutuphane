<?php
require_once 'config.php';
requireLogin();

$userId = (int) getCurrentUserId();
$targetUserId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : null;

if (!$targetUserId || $targetUserId === $userId) {
    jsonResponse(['success' => false, 'message' => 'Geçersiz kullanıcı'], 400);
}

// Ortak puanlanmış içeriklerde iki kullanıcının puanları ne kadar yakınsa
// eşleşme o kadar yüksek (fark 0 -> %100, fark 9 -> %0).
function sharedAgreement($db, string $statusCollectionName, string $idField, int $userId, int $targetUserId): array {
    $mine = iterator_to_array($db->$statusCollectionName->find(
        ['user_id' => $userId, 'rating' => ['$ne' => null]],
        ['projection' => [$idField => 1, 'rating' => 1]]
    ), false);
    $myRatings = [];
    foreach ($mine as $row) {
        $myRatings[(string) $row->$idField] = $row->rating;
    }

    $theirs = iterator_to_array($db->$statusCollectionName->find(
        ['user_id' => $targetUserId, 'rating' => ['$ne' => null]],
        ['projection' => [$idField => 1, 'rating' => 1]]
    ), false);

    $agreements = [];
    foreach ($theirs as $row) {
        $key = (string) $row->$idField;
        if (isset($myRatings[$key])) {
            $diff = abs($myRatings[$key] - $row->rating);
            $agreements[] = 1 - min(9, $diff) / 9;
        }
    }

    return $agreements;
}

function genreSet($db, string $statusCollectionName, string $contentCollectionName, string $idField, int $userId): array {
    $pipeline = [
        ['$match' => ['user_id' => $userId]],
        ['$lookup' => ['from' => $contentCollectionName, 'localField' => $idField, 'foreignField' => '_id', 'as' => 'content']],
        ['$unwind' => '$content'],
        ['$match' => ['content.genres' => ['$type' => 'array']]],
        ['$unwind' => '$content.genres'],
        ['$group' => ['_id' => '$content.genres']],
    ];
    $rows = iterator_to_array($db->$statusCollectionName->aggregate($pipeline), false);
    $names = [];
    foreach ($rows as $r) {
        $g = $r->_id;
        $name = is_object($g) ? ($g->name ?? null) : (is_string($g) ? $g : null);
        if ($name !== null) {
            $names[$name] = true;
        }
    }
    return array_keys($names);
}

$agreements = array_merge(
    sharedAgreement($db, 'user_movie_status', 'movie_id', $userId, $targetUserId),
    sharedAgreement($db, 'user_series_status', 'series_id', $userId, $targetUserId),
    sharedAgreement($db, 'user_book_status', 'book_id', $userId, $targetUserId)
);

$myGenres = array_merge(
    genreSet($db, 'user_movie_status', 'movies', 'movie_id', $userId),
    genreSet($db, 'user_series_status', 'series', 'series_id', $userId)
);
$theirGenres = array_merge(
    genreSet($db, 'user_movie_status', 'movies', 'movie_id', $targetUserId),
    genreSet($db, 'user_series_status', 'series', 'series_id', $targetUserId)
);
$commonGenres = array_values(array_unique(array_intersect($myGenres, $theirGenres)));

if (count($agreements) > 0) {
    $score = round((array_sum($agreements) / count($agreements)) * 100);
    $basis = 'ratings';
} elseif ($commonGenres) {
    $unionCount = count(array_unique(array_merge($myGenres, $theirGenres)));
    $score = $unionCount > 0 ? round((count($commonGenres) / $unionCount) * 100) : 0;
    $basis = 'genres';
} else {
    $score = null;
    $basis = 'none';
}

jsonResponse([
    'success' => true,
    'score' => $score,
    'basis' => $basis,
    'shared_rated_count' => count($agreements),
    'common_genres' => array_slice($commonGenres, 0, 5),
]);
?>
