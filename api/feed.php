<?php
// Takip akışı: takip edilen kullanıcıların izleme ve okuma hareketleri (film, dizi, kitap) tek istekte, yeniden eskiye.
// Yorumlar ayrı uçtan gelir (GET /api/comments.php?following=true) ve arayüzde bu listeyle birleştirilir.
require_once 'config.php';
require_once __DIR__ . '/lib/social.php';
require_once __DIR__ . '/lib/taste.php';
requireLogin();

$userId = (int) getCurrentUserId();
$limit = max(1, min(60, (int) ($_GET['limit'] ?? 40)));
$followedIds = followedUserIds($db, $userId);

if (!$followedIds) {
    jsonResponse(['success' => true, 'following_count' => 0, 'activities' => []]);
}

$sources = [
    ['status' => 'user_movie_status', 'id' => 'movie_id', 'content' => 'movies', 'type' => 'movie', 'statuses' => ['watched'], 'public' => 'tmdb_id', 'poster' => 'poster_path'],
    ['status' => 'user_series_status', 'id' => 'series_id', 'content' => 'series', 'type' => 'series', 'statuses' => ['watched', 'watching'], 'public' => 'tmdb_id', 'poster' => 'poster_path'],
    ['status' => 'user_book_status', 'id' => 'book_id', 'content' => 'books', 'type' => 'book', 'statuses' => ['read'], 'public' => 'google_books_id', 'poster' => 'cover_url'],
];

$rows = [];
foreach ($sources as $source) {
    $found = iterator_to_array($db->{$source['status']}->find(
        ['user_id' => ['$in' => $followedIds], 'status' => ['$in' => $source['statuses']]],
        ['sort' => ['updated_at' => -1], 'limit' => $limit]
    ), false);
    if (!$found) {
        continue;
    }

    $contentIds = array_values(array_unique(array_map(static fn ($row) => $row[$source['id']], $found)));
    $docs = [];
    foreach ($db->{$source['content']}->find(['_id' => ['$in' => $contentIds]]) as $doc) {
        $docs[$doc['_id']] = $doc;
    }

    foreach ($found as $row) {
        $doc = $docs[$row[$source['id']]] ?? null;
        $updated = $row['updated_at'] ?? null;
        if ($doc === null || $updated === null) {
            continue;
        }
        $rows[] = [
            'user_id' => (int) $row['user_id'],
            'type' => $source['type'],
            'status' => (string) $row['status'],
            'rating' => isset($row['rating']) && $row['rating'] !== null ? (int) $row['rating'] : null,
            'content_id' => $doc[$source['public']] ?? null,
            'title' => (string) ($doc['title'] ?? ''),
            'poster' => tasteImageUrl($source['type'], $doc[$source['poster']] ?? null),
            'at' => $updated->toDateTime()->format(DATE_RFC3339_EXTENDED),
        ];
    }
}

$rows = feedSortAndLimit($rows, $limit);

$users = [];
foreach ($db->users->find(['_id' => ['$in' => $followedIds]], ['projection' => ['username' => 1, 'avatar_url' => 1]]) as $user) {
    $users[(int) $user['_id']] = ['id' => (int) $user['_id'], 'username' => $user['username'], 'avatar_url' => $user['avatar_url'] ?? null];
}

$activities = [];
foreach ($rows as $row) {
    $user = $users[$row['user_id']] ?? null;
    if ($user === null || $row['content_id'] === null) {
        continue;
    }
    $row['user'] = $user;
    unset($row['user_id']);
    $activities[] = $row;
}

jsonResponse(['success' => true, 'following_count' => count($followedIds), 'activities' => $activities]);
