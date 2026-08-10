<?php
require_once 'config.php';
requireLogin();

$userId = (int)($_GET['user_id'] ?? getCurrentUserId());
$type = $_GET['type'] ?? null;
$status = $_GET['status'] ?? null;

if ($type === 'movie') {
    $statusFilter = $status ? $status : 'watched';
    $rows = iterator_to_array($db->user_movie_status->find(
        ['user_id' => $userId, 'status' => $statusFilter],
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
            'tmdb_id' => $m->tmdb_id,
            'title' => $m->title,
            'poster' => $m->poster_path ?? null,
            'rating' => $r->rating ?? null,
            'status' => $r->status,
            'updated_at' => isset($r->updated_at) ? $r->updated_at->toDateTime()->format('Y-m-d H:i:s') : null,
        ];
    }
} elseif ($type === 'book') {
    $statusFilter = $status ? $status : 'read';
    $rows = iterator_to_array($db->user_book_status->find(
        ['user_id' => $userId, 'status' => $statusFilter],
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
            'google_books_id' => $b->google_books_id,
            'title' => $b->title,
            'poster' => $b->cover_url ?? null,
            'rating' => $r->rating ?? null,
            'status' => $r->status,
            'updated_at' => isset($r->updated_at) ? $r->updated_at->toDateTime()->format('Y-m-d H:i:s') : null,
        ];
    }
} else {
    jsonResponse(['success' => false, 'message' => 'type parametresi gerekli (movie veya book)'], 400);
}

jsonResponse(['success' => true, 'content' => $content]);
?>
