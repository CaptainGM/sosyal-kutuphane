<?php
require_once 'config.php';

$userId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : null;
if ($userId === null) {
    requireLogin();
    $userId = (int) getCurrentUserId();
}

$userData = $db->users->findOne(['_id' => $userId]);

if (!$userData) {
    jsonResponse(['success' => false, 'message' => 'Kullanıcı bulunamadı'], 404);
}

// followers_count: bizi takip edenler (following_id = biz); following_count: bizim takip ettiklerimiz (follower_id = biz)
$followers = ['count' => $db->follows->countDocuments(['following_id' => $userId])];
$following = ['count' => $db->follows->countDocuments(['follower_id' => $userId])];

$movieAgg = $db->user_movie_status->aggregate([
    ['$match' => ['user_id' => $userId, 'status' => 'watched', 'rating' => ['$ne' => null]]],
    ['$group' => [
        '_id' => null,
        'total_movies' => ['$sum' => 1],
        'avg_movie_rating' => ['$avg' => '$rating'],
        'highest_rating' => ['$max' => '$rating']
    ]]
])->toArray();
$movieStats = $movieAgg[0] ?? null;

$bookAgg = $db->user_book_status->aggregate([
    ['$match' => ['user_id' => $userId, 'status' => 'read', 'rating' => ['$ne' => null]]],
    ['$group' => [
        '_id' => null,
        'total_books' => ['$sum' => 1],
        'avg_book_rating' => ['$avg' => '$rating']
    ]]
])->toArray();
$bookStats = $bookAgg[0] ?? null;

$genreAgg = $db->user_movie_status->aggregate([
    ['$match' => ['user_id' => $userId, 'status' => 'watched']],
    ['$lookup' => [
        'from' => 'movies',
        'localField' => 'movie_id',
        'foreignField' => '_id',
        'as' => 'movie'
    ]],
    ['$project' => [
        'genre' => ['$arrayElemAt' => [
            ['$arrayElemAt' => ['$movie.genres', 0]],
            0
        ]]
    ]],
    ['$group' => ['_id' => '$genre', 'count' => ['$sum' => 1]]],
    ['$sort' => ['count' => -1]],
    ['$limit' => 1]
])->toArray();
$favoriteGenre = $genreAgg[0] ?? null;

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
    'favorite_genre' => $favoriteGenre['_id'] ?? 'Belirlenmedi',
    'total_reviews' => 0
];

$stats['total_reviews'] = $db->comments->countDocuments(['user_id' => $userId, 'parent_comment_id' => null]);

function ratingCounts($db, string $statusCollectionName, int $userId): array {
    $pipeline = [
        ['$match' => ['user_id' => $userId, 'rating' => ['$ne' => null]]],
        ['$group' => ['_id' => '$rating', 'count' => ['$sum' => 1]]],
    ];
    $counts = [];
    foreach ($db->$statusCollectionName->aggregate($pipeline) as $row) {
        $counts[(int) $row->_id] = $row->count;
    }
    return $counts;
}

$histogram = array_fill(1, 10, 0);
foreach ([
    ratingCounts($db, 'user_movie_status', $userId),
    ratingCounts($db, 'user_series_status', $userId),
    ratingCounts($db, 'user_book_status', $userId),
] as $counts) {
    foreach ($counts as $rating => $count) {
        if ($rating >= 1 && $rating <= 10) {
            $histogram[$rating] += $count;
        }
    }
}
$stats['rating_distribution'] = $histogram;

jsonResponse([
    'success' => true,
    'username' => $userData->username,
    'email' => $userData->email,
    'stats' => $stats
]);
?>
