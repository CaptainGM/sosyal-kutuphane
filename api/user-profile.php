
<?php
require_once 'config.php';

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'POST') {
    requireLogin();
    $userId = getCurrentUserId();
    $input = json_decode(file_get_contents('php://input'), true);
    
    $avatar = $input['avatar'] ?? null;
    
    if ($avatar) {
        $stmt = $conn->prepare("UPDATE users SET avatar_url = ? WHERE id = ?");
        $stmt->bind_param("si", $avatar, $userId);
        
        if ($stmt->execute()) {
            jsonResponse(['success' => true, 'message' => 'Avatar güncellendi']);
        } else {
            jsonResponse(['success' => false, 'message' => 'Avatar güncellenemedi'], 500);
        }
    } else {
        jsonResponse(['success' => false, 'message' => 'Avatar gerekli'], 400);
    }
    exit;
}
$userId = $_GET['id'] ?? null;

if (!$userId) {
    jsonResponse(['success' => false, 'message' => 'Kullanıcı ID gereklidir'], 400);
}
$stmt = $conn->prepare("SELECT id, username, email, bio, avatar_url FROM users WHERE id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if (!$user) {
    jsonResponse(['success' => false, 'message' => 'Kullanıcı bulunamadı'], 404);
}
$stmt = $conn->prepare("
    SELECT COUNT(*) as count FROM user_movie_status 
    WHERE user_id = ? AND status = 'watched'
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$movies = $stmt->get_result()->fetch_assoc();
$stmt = $conn->prepare("
    SELECT COUNT(*) as count FROM user_book_status 
    WHERE user_id = ? AND status = 'read'
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$books = $stmt->get_result()->fetch_assoc();
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM follows WHERE following_id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$followers = $stmt->get_result()->fetch_assoc();
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM follows WHERE follower_id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$following = $stmt->get_result()->fetch_assoc();

$stats = [
    'movies_watched' => $movies['count'] ?? 0,
    'books_read' => $books['count'] ?? 0,
    'followers' => $followers['count'] ?? 0,
    'following' => $following['count'] ?? 0
];

jsonResponse([
    'success' => true,
    'user' => $user,
    'stats' => $stats
]);
?>
