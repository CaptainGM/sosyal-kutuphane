<?php
require_once 'config.php';
requireLogin();

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);
$userId = getCurrentUserId();

if ($method === 'GET') {
    $contentType = $_GET['content_type'] ?? null;
    $contentId = $_GET['content_id'] ?? null;
    $allComments = isset($_GET['all']) && $_GET['all'] === 'true';
    $targetUserId = isset($_GET['user_id']) ? intval($_GET['user_id']) : null;

    if ($contentType && $contentId) {
        $stmt = $conn->prepare("
            SELECT c.*, u.username, u.avatar_url,
                   (SELECT COUNT(*) FROM comment_likes WHERE comment_id = c.id) as likes_count,
                   EXISTS(SELECT 1 FROM comment_likes WHERE comment_id = c.id AND user_id = ?) as liked_by_me
            FROM comments c
            JOIN users u ON c.user_id = u.id
            WHERE c.content_type = ? AND c.content_id = ? AND c.parent_comment_id IS NULL
            ORDER BY c.created_at DESC
        ");
        $stmt->bind_param("isi", $userId, $contentType, $contentId);
    } else if ($allComments) {
        $stmt = $conn->prepare("
            SELECT c.*, u.username, u.avatar_url,
                   (SELECT COUNT(*) FROM comment_likes WHERE comment_id = c.id) as likes_count,
                   EXISTS(SELECT 1 FROM comment_likes WHERE comment_id = c.id AND user_id = ?) as liked_by_me
            FROM comments c
            JOIN users u ON c.user_id = u.id
            WHERE c.parent_comment_id IS NULL
            ORDER BY c.created_at DESC
            LIMIT 100
        ");
        $stmt->bind_param("i", $userId);
    } else if ($targetUserId) {
        $stmt = $conn->prepare("
            SELECT c.*, u.username, u.avatar_url,
                   (SELECT COUNT(*) FROM comment_likes WHERE comment_id = c.id) as likes_count,
                   EXISTS(SELECT 1 FROM comment_likes WHERE comment_id = c.id AND user_id = ?) as liked_by_me
            FROM comments c
            JOIN users u ON c.user_id = u.id
            WHERE c.user_id = ? AND c.parent_comment_id IS NULL
            ORDER BY c.created_at DESC
        ");
        $stmt->bind_param("ii", $userId, $targetUserId);
    } else {
        $stmt = $conn->prepare("
            SELECT c.*, u.username, u.avatar_url,
                   (SELECT COUNT(*) FROM comment_likes WHERE comment_id = c.id) as likes_count,
                   EXISTS(SELECT 1 FROM comment_likes WHERE comment_id = c.id AND user_id = ?) as liked_by_me
            FROM comments c
            JOIN users u ON c.user_id = u.id
            WHERE c.user_id = ? AND c.parent_comment_id IS NULL
            ORDER BY c.created_at DESC
        ");
        $stmt->bind_param("ii", $userId, $userId);
    }
    
    $stmt->execute();
    $comments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach ($comments as &$comment) {
        $stmt = $conn->prepare("
            SELECT c.*, u.username, u.avatar_url,
                   (SELECT COUNT(*) FROM comment_likes WHERE comment_id = c.id) as likes_count,
                   EXISTS(SELECT 1 FROM comment_likes WHERE comment_id = c.id AND user_id = ?) as liked_by_me
            FROM comments c
            JOIN users u ON c.user_id = u.id
            WHERE c.parent_comment_id = ?
            ORDER BY c.created_at ASC
        ");
        $stmt->bind_param("ii", $userId, $comment['id']);
        $stmt->execute();
        $comment['replies'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    jsonResponse(['success' => true, 'comments' => $comments]);

} elseif ($method === 'POST') {
    $contentType = $input['content_type'] ?? null;
    $contentId = $input['content_id'] ?? null;
    $commentText = $input['comment_text'] ?? null;
    $parentCommentId = $input['parent_comment_id'] ?? null;

    if (!$contentType || !$contentId || !$commentText) {
        jsonResponse(['success' => false, 'message' => 'Gerekli alanlar eksik'], 400);
    }

    $stmt = $conn->prepare("
        INSERT INTO comments (user_id, content_type, content_id, comment_text, parent_comment_id)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("isisi", $userId, $contentType, $contentId, $commentText, $parentCommentId);

    if ($stmt->execute()) {
        $commentId = $conn->insert_id;
        $stmt = $conn->prepare("
            SELECT c.*, u.username, u.avatar_url, 0 as likes_count, 0 as liked_by_me
            FROM comments c
            JOIN users u ON c.user_id = u.id
            WHERE c.id = ?
        ");
        $stmt->bind_param("i", $commentId);
        $stmt->execute();
        $newComment = $stmt->get_result()->fetch_assoc();

        jsonResponse(['success' => true, 'comment' => $newComment, 'id' => $commentId]);
    } else {
        jsonResponse(['success' => false, 'message' => 'Hata: ' . $conn->error], 500);
    }

} elseif ($method === 'PUT') {
    $commentId = $input['comment_id'] ?? null;
    $commentText = $input['comment_text'] ?? null;

    if (!$commentId || !$commentText) {
        jsonResponse(['success' => false, 'message' => 'Gerekli alanlar eksik'], 400);
    }
    $stmt = $conn->prepare("SELECT user_id FROM comments WHERE id = ?");
    $stmt->bind_param("i", $commentId);
    $stmt->execute();
    $comment = $stmt->get_result()->fetch_assoc();

    if (!$comment) {
        jsonResponse(['success' => false, 'message' => 'Yorum bulunamadı'], 404);
    }

    if ($comment['user_id'] != $userId) {
        jsonResponse(['success' => false, 'message' => 'Bu yorumu düzenleme yetkiniz yok'], 403);
    }

    $stmt = $conn->prepare("UPDATE comments SET comment_text = ? WHERE id = ?");
    $stmt->bind_param("si", $commentText, $commentId);

    if ($stmt->execute()) {
        jsonResponse(['success' => true, 'message' => 'Yorum düzenlendi']);
    } else {
        jsonResponse(['success' => false, 'message' => 'Hata: ' . $conn->error], 500);
    }

} elseif ($method === 'DELETE') {
    $commentId = $input['comment_id'] ?? null;

    if (!$commentId) {
        jsonResponse(['success' => false, 'message' => 'comment_id gerekli'], 400);
    }
    $stmt = $conn->prepare("SELECT user_id FROM comments WHERE id = ?");
    $stmt->bind_param("i", $commentId);
    $stmt->execute();
    $comment = $stmt->get_result()->fetch_assoc();

    if (!$comment) {
        jsonResponse(['success' => false, 'message' => 'Yorum bulunamadı'], 404);
    }

    if ($comment['user_id'] != $userId) {
        jsonResponse(['success' => false, 'message' => 'Bu yorumu silme yetkiniz yok'], 403);
    }

    $stmt = $conn->prepare("DELETE FROM comments WHERE id = ? OR parent_comment_id = ?");
    $stmt->bind_param("ii", $commentId, $commentId);

    if ($stmt->execute()) {
        jsonResponse(['success' => true, 'message' => 'Yorum silindi']);
    } else {
        jsonResponse(['success' => false, 'message' => 'Hata: ' . $conn->error], 500);
    }
}
?>

