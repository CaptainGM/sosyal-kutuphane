<?php
require_once 'config.php';
require_once __DIR__ . '/lib/profile.php';
require_once __DIR__ . '/lib/moderation-log.php';

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'POST') {
    requireLogin();
    requireCsrf();
    $userId = (int)getCurrentUserId();
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input) || (!array_key_exists('avatar', $input) && !array_key_exists('bio', $input))) {
        jsonResponse(['success' => false, 'message' => 'Güncellenecek alan gerekli'], 400);
    }

    $set = [];
    $result = [];
    $oldUploadedAvatar = null;

    if (array_key_exists('bio', $input)) {
        if (!is_string($input['bio'])) {
            jsonResponse(['success' => false, 'message' => 'Geçersiz biyografi'], 400);
        }
        $bio = trim($input['bio']);
        $bioError = profileBioError($bio);
        if ($bioError !== null) {
            $verdict = commentModerationVerdict($bio);
            if ($verdict !== null) {
                rejectModeratedText($db, $userId, 'bio', ['reason' => $verdict['reason'], 'message' => $bioError], $bio);
            }
            jsonResponse(['success' => false, 'message' => $bioError], 422);
        }
        $set['bio'] = $bio;
        $result['bio'] = $bio;
    }

    if (array_key_exists('avatar', $input)) {
        // XSS önlemi: avatar yalnızca http(s) adresi ya da emoji olabilir; javascript:, data: ve HTML içeren değerler reddedilir.
        $avatar = is_string($input['avatar']) ? trim($input['avatar']) : '';
        if (profileAvatarKind($avatar) === null) {
            jsonResponse(['success' => false, 'message' => 'Geçersiz avatar'], 400);
        }
        $previous = $db->users->findOne(['_id' => $userId], ['projection' => ['avatar_url' => 1]]);
        $oldUploadedAvatar = profileUploadedAvatarPath($previous['avatar_url'] ?? null);
        $set['avatar_url'] = $avatar;
        $result['avatar_url'] = $avatar;
    }

    $set['updated_at'] = new MongoDB\BSON\UTCDateTime();
    $db->users->updateOne(['_id' => $userId], ['$set' => $set]);

    // Yüklenmiş fotoğraf başka bir avatarla değiştirildiyse dosya diskte artık kalmasın.
    if ($oldUploadedAvatar !== null && is_file($oldUploadedAvatar)) {
        @unlink($oldUploadedAvatar);
    }

    jsonResponse(['success' => true, 'message' => 'Profil güncellendi'] + $result);
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

// E-posta özel bir veridir: yalnızca hesabın sahibine ve yöneticiye döner.
$viewerId = isLoggedIn() ? (int)getCurrentUserId() : null;
$viewerIsAdmin = false;
if ($viewerId !== null) {
    $viewer = $db->users->findOne(['_id' => $viewerId], ['projection' => ['is_admin' => 1]]);
    $viewerIsAdmin = !empty($viewer->is_admin);
}
$showEmail = $viewerId === $userId || $viewerIsAdmin;

$stats = [
    'movies_watched' => $db->user_movie_status->countDocuments(['user_id' => $userId, 'status' => 'watched']),
    'series_watched' => $db->user_series_status->countDocuments(['user_id' => $userId, 'status' => ['$in' => ['watched', 'watching']]]),
    'books_read' => $db->user_book_status->countDocuments(['user_id' => $userId, 'status' => 'read']),
    'followers' => $db->follows->countDocuments(['following_id' => $userId]),
    'following' => $db->follows->countDocuments(['follower_id' => $userId])
];

jsonResponse([
    'success' => true,
    'user' => [
        'id' => $user->_id,
        'username' => $user->username,
        'email' => $showEmail ? $user->email : null,
        'bio' => $user['bio'] ?? null,
        'avatar_url' => $user['avatar_url'] ?? null
    ],
    'stats' => $stats
]);
?>
