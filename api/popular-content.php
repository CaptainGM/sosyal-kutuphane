<?php
require_once 'config.php';

header('Content-Type: application/json');

$type = $_GET['type'] ?? 'movie';
$sortBy = $_GET['sort'] ?? 'comments';
$limit = min((int)($_GET['limit'] ?? 10), 50);

if ($type === 'movie') {
    $contentTable = 'movies';
    $statusTable = 'user_movie_status';
    $contentIdCol = 'movie_id';
    $externalIdCol = 'tmdb_id';
    $imageCol = 'poster_path';
} elseif ($type === 'book') {
    $contentTable = 'books';
    $statusTable = 'user_book_status';
    $contentIdCol = 'book_id';
    $externalIdCol = 'google_books_id';
    $imageCol = 'cover_url';
} else {
    jsonResponse(['success' => false, 'message' => 'Geçersiz içerik türü'], 400);
}

$results = [];

try {
    if ($sortBy === 'comments') {
        $sql = "
            SELECT 
                c.$externalIdCol as external_id,
                c.title,
                c.$imageCol as image_url,
                COUNT(cm.id) as comment_count
            FROM $contentTable c
            INNER JOIN comments cm ON cm.content_type = ? AND cm.content_id = c.$externalIdCol
            GROUP BY c.id
            ORDER BY comment_count DESC
            LIMIT ?
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("si", $type, $limit);
        $stmt->execute();
        $results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    } elseif ($sortBy === 'lists') {
        $sql = "
            SELECT 
                c.$externalIdCol as external_id,
                c.title,
                c.$imageCol as image_url,
                COUNT(s.id) as list_count
            FROM $contentTable c
            INNER JOIN $statusTable s ON s.$contentIdCol = c.id
            GROUP BY c.id
            ORDER BY list_count DESC
            LIMIT ?
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $limit);
        $stmt->execute();
        $results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    } elseif ($sortBy === 'ratings') {
        $sql = "
            SELECT 
                c.$externalIdCol as external_id,
                c.title,
                c.$imageCol as image_url,
                AVG(s.rating) as avg_rating,
                COUNT(s.rating) as vote_count
            FROM $contentTable c
            INNER JOIN $statusTable s ON s.$contentIdCol = c.id AND s.rating IS NOT NULL AND s.rating > 0
            GROUP BY c.id
            HAVING vote_count >= 1
            ORDER BY avg_rating DESC, vote_count DESC
            LIMIT ?
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $limit);
        $stmt->execute();
        $results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
} catch (Exception $e) {
    jsonResponse(['success' => false, 'message' => 'Veritabanı hatası: ' . $e->getMessage()], 500);
}

$formattedResults = [];
foreach ($results as $row) {
    $item = [
        'id' => $row['external_id'],
        'title' => $row['title'],
        'type' => $type
    ];
    
    if ($type === 'movie') {
        $item['poster_path'] = $row['image_url'];
    } else {
        $item['cover_url'] = $row['image_url'];
    }
    
    if (isset($row['comment_count'])) {
        $item['comment_count'] = (int)$row['comment_count'];
    }
    if (isset($row['list_count'])) {
        $item['list_count'] = (int)$row['list_count'];
    }
    if (isset($row['avg_rating'])) {
        $item['avg_rating'] = round((float)$row['avg_rating'], 1);
        $item['vote_count'] = (int)$row['vote_count'];
    }
    
    $formattedResults[] = $item;
}

jsonResponse([
    'success' => true,
    'type' => $type,
    'sort' => $sortBy,
    'data' => $formattedResults
]);
?>
