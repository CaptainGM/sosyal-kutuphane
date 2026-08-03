<?php
require_once 'config.php';
requireLogin();

$userId = $_GET['user_id'] ?? getCurrentUserId();
$stmt = $conn->prepare("SELECT id, username, email FROM users WHERE id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$userData = $stmt->get_result()->fetch_assoc();

if (!$userData) {
    jsonResponse(['success' => false, 'message' => 'Kullanıcı bulunamadı'], 404);
}
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM follows WHERE follower_id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$followers = $stmt->get_result()->fetch_assoc();

$stmt = $conn->prepare("SELECT COUNT(*) as count FROM follows WHERE follower_id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$following = $stmt->get_result()->fetch_assoc();
$stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_movies,
        AVG(rating) as avg_movie_rating,
        MAX(rating) as highest_rating
    FROM user_movie_status
    WHERE user_id = ? AND status = 'watched' AND rating IS NOT NULL
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$movieStats = $stmt->get_result()->fetch_assoc();
$stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_books,
        AVG(rating) as avg_book_rating
    FROM user_book_status
    WHERE user_id = ? AND status = 'read' AND rating IS NOT NULL
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$bookStats = $stmt->get_result()->fetch_assoc();
$stmt = $conn->prepare("
    SELECT JSON_UNQUOTE(JSON_EXTRACT(m.genres, '$[0]')) as genre, COUNT(*) as count
    FROM user_movie_status ums
    JOIN movies m ON ums.movie_id = m.id
    WHERE ums.user_id = ? AND ums.status = 'watched'
    GROUP BY JSON_UNQUOTE(JSON_EXTRACT(m.genres, '$[0]'))
    ORDER BY count DESC
    LIMIT 1
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$favoriteGenre = $stmt->get_result()->fetch_assoc();
$stats = [
    'followers_count' => (int)($followers['count'] ?? 0),
    'following_count' => (int)($following['count'] ?? 0),
    'movies' => [
        'total_watched' => (int)($movieStats['total_movies'] ?? 0),
        'avg_rating' => round($movieStats['avg_movie_rating'] ?? 0, 1),
        'highest_rating' => $movieStats['highest_rating'] ?? 0
    ],
    'books' => [
        'total_read' => (int)($bookStats['total_books'] ?? 0),
        'avg_rating' => round($bookStats['avg_book_rating'] ?? 0, 1)
    ],
    'favorite_genre' => $favoriteGenre['genre'] ?? 'Belirlenmedi',
    'total_reviews' => 0
];
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM reviews WHERE user_id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$reviews = $stmt->get_result()->fetch_assoc();
$stats['total_reviews'] = (int)($reviews['count'] ?? 0);

jsonResponse([
    'success' => true,
    'username' => $userData['username'],
    'email' => $userData['email'],
    'stats' => $stats
]);
?>
