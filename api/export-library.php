<?php
require_once 'config.php';
requireLogin();

$userId = (int) getCurrentUserId();
$user = $db->users->findOne(['_id' => $userId], ['projection' => ['username' => 1, 'email' => 1, 'created_at' => 1]]);

function formatDate($bsonDate)
{
    return $bsonDate ? $bsonDate->toDateTime()->format('Y-m-d H:i:s') : null;
}

// İzlenen filmler
$movieStatuses = iterator_to_array($db->user_movie_status->find(['user_id' => $userId]), false);
$movieIds = array_map(fn($s) => $s->movie_id, $movieStatuses);
$moviesById = [];
if ($movieIds) {
    foreach ($db->movies->find(['_id' => ['$in' => $movieIds]]) as $m) {
        $moviesById[$m->_id] = $m;
    }
}
$watchedMovies = [];
foreach ($movieStatuses as $s) {
    $m = $moviesById[$s->movie_id] ?? null;
    $watchedMovies[] = [
        'title' => $m->title ?? null,
        'tmdb_id' => $m->tmdb_id ?? null,
        'status' => $s->status,
        'rating' => $s->rating ?? null,
        'updated_at' => formatDate($s->updated_at ?? null),
    ];
}

// Okunan kitaplar
$bookStatuses = iterator_to_array($db->user_book_status->find(['user_id' => $userId]), false);
$bookIds = array_map(fn($s) => $s->book_id, $bookStatuses);
$booksById = [];
if ($bookIds) {
    foreach ($db->books->find(['_id' => ['$in' => $bookIds]]) as $b) {
        $booksById[$b->_id] = $b;
    }
}
$readBooks = [];
foreach ($bookStatuses as $s) {
    $b = $booksById[$s->book_id] ?? null;
    $readBooks[] = [
        'title' => $b->title ?? null,
        'google_books_id' => $b->google_books_id ?? null,
        'status' => $s->status,
        'rating' => $s->rating ?? null,
        'updated_at' => formatDate($s->updated_at ?? null),
    ];
}

// İzlenen diziler
$seriesStatuses = iterator_to_array($db->user_series_status->find(['user_id' => $userId]), false);
$seriesIds = array_map(fn($s) => $s->series_id, $seriesStatuses);
$seriesById = [];
if ($seriesIds) {
    foreach ($db->series->find(['_id' => ['$in' => $seriesIds]]) as $s) {
        $seriesById[$s->_id] = $s;
    }
}
$watchedSeries = [];
foreach ($seriesStatuses as $s) {
    $ser = $seriesById[$s->series_id] ?? null;
    $watchedSeries[] = [
        'title' => $ser->title ?? null,
        'tmdb_id' => $ser->tmdb_id ?? null,
        'status' => $s->status,
        'rating' => $s->rating ?? null,
        'updated_at' => formatDate($s->updated_at ?? null),
    ];
}

// Özel listeler + öğeleri
$lists = iterator_to_array($db->custom_lists->find(['user_id' => $userId]), false);
$listIds = array_map(fn($l) => $l->_id, $lists);
$itemsByList = [];
if ($listIds) {
    foreach ($db->custom_list_items->find(['list_id' => ['$in' => $listIds]]) as $item) {
        $itemsByList[$item->list_id][] = [
            'content_type' => $item->content_type,
            'content_id' => $item->content_id,
            'content_title' => $item->content_title,
            'added_at' => formatDate($item->added_at ?? null),
        ];
    }
}
$exportedLists = [];
foreach ($lists as $l) {
    $exportedLists[] = [
        'name' => $l->name,
        'description' => $l->description ?? null,
        'created_at' => formatDate($l->created_at ?? null),
        'items' => $itemsByList[$l->_id] ?? [],
    ];
}

// Kendi yorumları
$comments = [];
foreach ($db->comments->find(['user_id' => $userId], ['sort' => ['created_at' => -1]]) as $c) {
    $comments[] = [
        'content_type' => $c->content_type,
        'content_id' => $c->content_id,
        'comment_text' => $c->comment_text,
        'created_at' => formatDate($c->created_at ?? null),
    ];
}

$export = [
    'exported_at' => date('Y-m-d H:i:s'),
    'account' => [
        'username' => $user->username ?? null,
        'email' => $user->email ?? null,
        'member_since' => formatDate($user->created_at ?? null),
    ],
    'watched_movies' => $watchedMovies,
    'read_books' => $readBooks,
    'watched_series' => $watchedSeries,
    'custom_lists' => $exportedLists,
    'comments' => $comments,
];

header('Content-Disposition: attachment; filename="kutuphanem.json"');
echo json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
exit;
?>
