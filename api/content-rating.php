<?php
require_once 'config.php';


$contentType = $_GET['content_type'] ?? null;
$contentId = $_GET['content_id'] ?? null;

if (!$contentType || !$contentId) {
    jsonResponse(['success' => false, 'message' => 'İçerik türü ve ID gereklidir.'], 400);
}

if ($contentType === 'movie') {
    $contentTable = 'movies';
    $statusTable = 'user_movie_status';
    $contentIdCol = 'movie_id';
    $contentDbIdCol = 'tmdb_id';
} elseif ($contentType === 'book') {
    $contentTable = 'books';
    $statusTable = 'user_book_status';
    $contentIdCol = 'book_id';
    $contentDbIdCol = 'google_books_id';
} else {
    jsonResponse(['success' => false, 'message' => 'Geçersiz içerik türü.'], 400);
}


$stmt = $conn->prepare("SELECT id FROM $contentTable WHERE $contentDbIdCol = ?");
$stmt->bind_param("s", $contentId);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();

if (!$result) {
    jsonResponse([
        'success' => true,
        'data' => [
            'average_rating' => null,
            'total_votes' => 0,
            'rating_distribution' => []
        ]
    ]);
}

$dbContentId = $result['id'];


$stmt = $conn->prepare("
    SELECT 
        AVG(rating) as average_rating,
        COUNT(rating) as total_votes
    FROM $statusTable 
    WHERE $contentIdCol = ? AND rating IS NOT NULL AND rating > 0
");
$stmt->bind_param("i", $dbContentId);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();


$stmt = $conn->prepare("
    SELECT rating, COUNT(*) as count
    FROM $statusTable 
    WHERE $contentIdCol = ? AND rating IS NOT NULL AND rating > 0
    GROUP BY rating
    ORDER BY rating DESC
");
$stmt->bind_param("i", $dbContentId);
$stmt->execute();
$distributionResult = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$distribution = [];
foreach ($distributionResult as $row) {
    $distribution[$row['rating']] = (int)$row['count'];
}

jsonResponse([
    'success' => true,
    'data' => [
        'average_rating' => $stats['average_rating'] ? round((float)$stats['average_rating'], 1) : null,
        'total_votes' => (int)$stats['total_votes'],
        'rating_distribution' => $distribution
    ]
]);
?>
