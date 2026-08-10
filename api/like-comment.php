<?php
require_once 'config.php';
requireLogin();
requireCsrf();

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);
$currentUserId = (int)getCurrentUserId();
$commentId = isset($input['comment_id']) ? intval($input['comment_id']) : null;

if (!$commentId) {
    jsonResponse(['success' => false, 'message' => 'Yorum ID gereklidir']);
}

if ($method === 'POST') {
    $existingLike = $db->comment_likes->findOne(['user_id' => $currentUserId, 'comment_id' => $commentId]);

    if ($existingLike) {
        $db->comment_likes->deleteOne(['user_id' => $currentUserId, 'comment_id' => $commentId]);
        $liked = false;
    } else {
        $db->comment_likes->insertOne([
            '_id' => nextSequence($db, 'comment_likes'),
            'user_id' => $currentUserId,
            'comment_id' => $commentId,
            'created_at' => new MongoDB\BSON\UTCDateTime(),
        ]);
        $liked = true;
    }

    $likesCount = $db->comment_likes->countDocuments(['comment_id' => $commentId]);

    jsonResponse(['success' => true, 'likes_count' => $likesCount, 'liked' => $liked]);

} elseif ($method === 'DELETE') {
    $db->comment_likes->deleteOne(['user_id' => $currentUserId, 'comment_id' => $commentId]);

    $likesCount = $db->comment_likes->countDocuments(['comment_id' => $commentId]);

    jsonResponse(['success' => true, 'likes_count' => $likesCount]);

} else {
    jsonResponse(['success' => false, 'message' => 'Geçersiz metod']);
}
?>
