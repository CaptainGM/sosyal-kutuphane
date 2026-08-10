<?php
require_once 'config.php';
requireLogin();
$userId = isset($_GET['user_id']) ? intval($_GET['user_id']) : getCurrentUserId();
$contentType = $_GET['type'] ?? null;
if ($contentType === null) {
    $watchedRows = iterator_to_array($db->user_movie_status->find(
        ['user_id' => $userId, 'status' => 'watched'],
        ['sort' => ['updated_at' => -1]]
    ));
    $movieIds = array_map(fn($r) => $r->movie_id, $watchedRows);
    $movies = iterator_to_array($db->movies->find(['_id' => ['$in' => $movieIds]]));
    $movieMap = [];
    foreach ($movies as $m) {
        $movieMap[$m->_id] = $m;
    }
    $watched = [];
    foreach ($watchedRows as $r) {
        $m = $movieMap[$r->movie_id] ?? null;
        $watched[] = [
            'movie_id' => $r->movie_id,
            'status' => $r->status,
            'rating' => $r->rating ?? null,
            'updated_at' => isset($r->updated_at) ? $r->updated_at->toDateTime()->format('Y-m-d H:i:s') : null,
            'tmdb_id' => $m->tmdb_id ?? null,
            'title' => $m->title ?? null,
            'poster_path' => $m->poster_path ?? null,
        ];
    }

    $readRows = iterator_to_array($db->user_book_status->find(
        ['user_id' => $userId, 'status' => 'read'],
        ['sort' => ['updated_at' => -1]]
    ));
    $bookIds = array_map(fn($r) => $r->book_id, $readRows);
    $books = iterator_to_array($db->books->find(['_id' => ['$in' => $bookIds]]));
    $bookMap = [];
    foreach ($books as $b) {
        $bookMap[$b->_id] = $b;
    }
    $read = [];
    foreach ($readRows as $r) {
        $b = $bookMap[$r->book_id] ?? null;
        $read[] = [
            'book_id' => $r->book_id,
            'status' => $r->status,
            'rating' => $r->rating ?? null,
            'updated_at' => isset($r->updated_at) ? $r->updated_at->toDateTime()->format('Y-m-d H:i:s') : null,
            'google_books_id' => $b->google_books_id ?? null,
            'title' => $b->title ?? null,
            'cover_url' => $b->cover_url ?? null,
        ];
    }

    jsonResponse(['success' => true, 'watched' => $watched, 'read' => $read]);
}

$status = $_GET['status'] ?? 'watched';

if ($contentType === 'movie') {
    $rows = iterator_to_array($db->user_movie_status->find(
        ['user_id' => $userId, 'status' => $status],
        ['sort' => ['updated_at' => -1]]
    ));
    $movieIds = array_map(fn($r) => $r->movie_id, $rows);
    $movies = iterator_to_array($db->movies->find(['_id' => ['$in' => $movieIds]]));
    $movieMap = [];
    foreach ($movies as $m) {
        $movieMap[$m->_id] = $m;
    }

    $content = [];
    foreach ($rows as $r) {
        $m = $movieMap[$r->movie_id] ?? null;
        if (!$m) {
            continue;
        }
        $content[] = [
            'id' => $m->_id,
            'tmdb_id' => $m->tmdb_id ?? null,
            'title' => $m->title ?? null,
            'poster_path' => $m->poster_path ?? null,
            'overview' => $m->overview ?? null,
            'release_date' => $m->release_date ?? null,
            'genres' => $m->genres ?? null,
            'created_at' => isset($m->created_at) ? $m->created_at->toDateTime()->format('Y-m-d H:i:s') : null,
            'status' => $r->status,
            'rating' => $r->rating ?? null,
        ];
    }
} else if ($contentType === 'book') {
    $rows = iterator_to_array($db->user_book_status->find(
        ['user_id' => $userId, 'status' => $status],
        ['sort' => ['updated_at' => -1]]
    ));
    $bookIds = array_map(fn($r) => $r->book_id, $rows);
    $books = iterator_to_array($db->books->find(['_id' => ['$in' => $bookIds]]));
    $bookMap = [];
    foreach ($books as $b) {
        $bookMap[$b->_id] = $b;
    }

    $content = [];
    foreach ($rows as $r) {
        $b = $bookMap[$r->book_id] ?? null;
        if (!$b) {
            continue;
        }
        $content[] = [
            'id' => $b->_id,
            'google_books_id' => $b->google_books_id ?? null,
            'title' => $b->title ?? null,
            'cover_url' => $b->cover_url ?? null,
            'description' => $b->description ?? null,
            'published_date' => $b->published_date ?? null,
            'categories' => $b->categories ?? null,
            'created_at' => isset($b->created_at) ? $b->created_at->toDateTime()->format('Y-m-d H:i:s') : null,
            'status' => $r->status,
            'rating' => $r->rating ?? null,
        ];
    }
} else {
    jsonResponse(['success' => false, 'message' => 'Geçersiz content_type'], 400);
}

jsonResponse(['success' => true, 'content' => $content]);
?>
