<?php
require_once 'config.php';

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'POST') {
    requireLogin();
    requireCsrf();
    $userId = (int)getCurrentUserId();
    $input = json_decode(file_get_contents('php://input'), true);

    $avatar = $input['avatar'] ?? null;

    if ($avatar) {
        // XSS önlemi: avatar yalnızca http(s) ile başlayan geçerli bir URL olabilir.
        // javascript:, data: vb. şemalar ve script/HTML içeren değerler reddedilir.
        if (!filter_var($avatar, FILTER_VALIDATE_URL) || !(str_starts_with($avatar, 'http://') || str_starts_with($avatar, 'https://'))) {
            jsonResponse(['success' => false, 'message' => 'Geçersiz avatar URL'], 400);
        }

        $db->users->updateOne(
            ['_id' => $userId],
            ['$set' => ['avatar_url' => $avatar, 'updated_at' => new MongoDB\BSON\UTCDateTime()]]
        );
        jsonResponse(['success' => true, 'message' => 'Avatar güncellendi']);
    } else {
        jsonResponse(['success' => false, 'message' => 'Avatar gerekli'], 400);
    }
    exit;
}

$userId = $_GET['id'] ?? null;

if (!$userId) {
    jsonResponse(['success' => false, 'message' => 'Kullanıcı ID gereklidir'], 400);
}
$userId = (int)$userId;

$user = $db->users->findOne(['_id' => $userId]);

if (!$user) {
    jsonResponse(['success' => false, 'message' => 'Kullanıcı bulunamadı'], 404);
}

$stats = [
    'movies_watched' => $db->user_movie_status->countDocuments(['user_id' => $userId, 'status' => 'watched']),
    'books_read' => $db->user_book_status->countDocuments(['user_id' => $userId, 'status' => 'read']),
    'followers' => $db->follows->countDocuments(['following_id' => $userId]),
    'following' => $db->follows->countDocuments(['follower_id' => $userId])
];

jsonResponse([
    'success' => true,
    'user' => [
        'id' => $user->_id,
        'username' => $user->username,
        'email' => $user->email,
        'bio' => $user->bio,
        'avatar_url' => $user->avatar_url
    ],
    'stats' => $stats
]);
?>
