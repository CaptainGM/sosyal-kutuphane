<?php
require_once 'config.php';
requireLogin();

$userId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : (int) getCurrentUserId();
$limit = min((int) ($_GET['limit'] ?? 100), 300);

function diaryRows($db, string $statusCollectionName, string $contentCollectionName, string $idField, int $userId, string $type, string $status): array {
    $pipeline = [
        ['$match' => ['user_id' => $userId, 'status' => $status]],
        ['$lookup' => ['from' => $contentCollectionName, 'localField' => $idField, 'foreignField' => '_id', 'as' => 'content']],
        ['$unwind' => '$content'],
        ['$sort' => ['updated_at' => -1]],
    ];
    $rows = [];
    foreach ($db->$statusCollectionName->aggregate($pipeline) as $row) {
        $rows[] = [
            'type' => $type,
            'id' => $type === 'book' ? ($row->content->google_books_id ?? null) : ($row->content->tmdb_id ?? null),
            'title' => $row->content->title ?? 'Bilinmiyor',
            'poster' => $type === 'book' ? ($row->content->cover_url ?? null) : ($row->content->poster_path ?? null),
            'rating' => $row->rating ?? null,
            'date' => $row->updated_at->toDateTime()->format('Y-m-d H:i:s'),
        ];
    }
    return $rows;
}

$entries = array_merge(
    diaryRows($db, 'user_movie_status', 'movies', 'movie_id', $userId, 'movie', 'watched'),
    diaryRows($db, 'user_series_status', 'series', 'series_id', $userId, 'series', 'watched'),
    diaryRows($db, 'user_book_status', 'books', 'book_id', $userId, 'book', 'read')
);

usort($entries, fn($a, $b) => strcmp($b['date'], $a['date']));
$entries = array_slice($entries, 0, $limit);

jsonResponse(['success' => true, 'entries' => $entries]);
?>
