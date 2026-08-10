<?php
require_once 'config.php';
requireLogin();

$userId = (int) getCurrentUserId();
$limit = min((int) ($_GET['limit'] ?? 8), 20);

function watchedIds($db, string $statusCollectionName, string $idField, int $userId): array
{
    $ids = [];
    foreach ($db->$statusCollectionName->find(['user_id' => $userId], ['projection' => [$idField => 1]]) as $row) {
        $ids[] = $row->$idField;
    }
    return $ids;
}

// Kullanıcının 7+ puan verdiği içeriklerin tür/kategorilerini sayıp en sık geçen 3'ünü döner.
function topTags($db, string $statusCollectionName, string $contentCollectionName, string $idField, string $tagField, int $userId): array
{
    $pipeline = [
        ['$match' => ['user_id' => $userId, 'rating' => ['$gte' => 7]]],
        ['$lookup' => ['from' => $contentCollectionName, 'localField' => $idField, 'foreignField' => '_id', 'as' => 'content']],
        ['$unwind' => '$content'],
        ['$match' => ["content.$tagField" => ['$type' => 'array']]],
        ['$unwind' => "\$content.$tagField"],
        ['$group' => ['_id' => "\$content.$tagField", 'count' => ['$sum' => 1]]],
        ['$sort' => ['count' => -1]],
        ['$limit' => 3],
    ];
    $rows = iterator_to_array($db->$statusCollectionName->aggregate($pipeline), false);
    return array_map(fn($r) => $r->_id, $rows);
}

// $tags boşsa (soğuk başlangıç) ya da eşleşen sonuç yoksa genel popülerliğe düşer.
function recommendContent($db, string $contentCollectionName, string $statusCollectionName, string $idField, string $tagField, array $excludeIds, array $tags, int $limit): array
{
    if ($tags) {
        $match = ["$tagField" => ['$in' => $tags]];
        if ($excludeIds) {
            $match['_id'] = ['$nin' => $excludeIds];
        }
        $pipeline = [
            ['$match' => $match],
            ['$lookup' => ['from' => $statusCollectionName, 'localField' => '_id', 'foreignField' => $idField, 'as' => 'statuses']],
            ['$addFields' => [
                'avg_rating' => ['$avg' => '$statuses.rating'],
                'vote_count' => ['$size' => ['$filter' => ['input' => '$statuses', 'cond' => ['$ne' => ['$$this.rating', null]]]]],
            ]],
            ['$match' => ['vote_count' => ['$gt' => 0]]],
            ['$sort' => ['avg_rating' => -1, 'vote_count' => -1]],
            ['$limit' => $limit],
        ];
        $results = iterator_to_array($db->$contentCollectionName->aggregate($pipeline), false);
        if ($results) {
            return $results;
        }
    }

    // Bos PHP dizisi BSON array'e kodlanir, $match ise bir obje (document) bekler.
    $match = $excludeIds ? ['_id' => ['$nin' => $excludeIds]] : (object) [];
    $pipeline = [
        ['$match' => $match],
        ['$lookup' => ['from' => $statusCollectionName, 'localField' => '_id', 'foreignField' => $idField, 'as' => 'statuses']],
        ['$addFields' => ['vote_count' => ['$size' => '$statuses']]],
        ['$match' => ['vote_count' => ['$gt' => 0]]],
        ['$sort' => ['vote_count' => -1]],
        ['$limit' => $limit],
    ];
    return iterator_to_array($db->$contentCollectionName->aggregate($pipeline), false);
}

$watchedMovieIds = watchedIds($db, 'user_movie_status', 'movie_id', $userId);
$watchedBookIds = watchedIds($db, 'user_book_status', 'book_id', $userId);

$topGenres = topTags($db, 'user_movie_status', 'movies', 'movie_id', 'genres', $userId);
$topCategories = topTags($db, 'user_book_status', 'books', 'book_id', 'categories', $userId);

$movieDocs = recommendContent($db, 'movies', 'user_movie_status', 'movie_id', 'genres', $watchedMovieIds, $topGenres, $limit);
$bookDocs = recommendContent($db, 'books', 'user_book_status', 'book_id', 'categories', $watchedBookIds, $topCategories, $limit);

$movies = [];
foreach ($movieDocs as $m) {
    $movies[] = [
        'id' => $m->tmdb_id,
        'title' => $m->title,
        'poster_path' => $m->poster_path ?? null,
        'avg_rating' => isset($m->avg_rating) ? round((float) $m->avg_rating, 1) : null,
    ];
}

$books = [];
foreach ($bookDocs as $b) {
    $books[] = [
        'id' => $b->google_books_id,
        'title' => $b->title,
        'cover_url' => $b->cover_url ?? null,
        'avg_rating' => isset($b->avg_rating) ? round((float) $b->avg_rating, 1) : null,
    ];
}

jsonResponse(['success' => true, 'movies' => $movies, 'books' => $books]);
?>
