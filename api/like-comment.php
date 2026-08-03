<?php
require_once 'config.php';
error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json');

try {
    requireLogin();
    
    $method = $_SERVER['REQUEST_METHOD'];
    $input = json_decode(file_get_contents('php://input'), true);
    $currentUserId = getCurrentUserId();
    $commentId = isset($input['comment_id']) ? intval($input['comment_id']) : null;

    if (!$commentId) {
        echo json_encode(['success' => false, 'message' => 'Yorum ID gereklidir']);
        exit;
    }

    if ($method === 'POST') {
        $stmt = $conn->prepare("SELECT id FROM comment_likes WHERE user_id = ? AND comment_id = ?");
        if (!$stmt) {
            echo json_encode(['success' => false, 'message' => 'SQL hazırlama hatası: ' . $conn->error]);
            exit;
        }
        $stmt->bind_param("ii", $currentUserId, $commentId);
        $stmt->execute();
        $existingLike = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if ($existingLike) {
            $stmt = $conn->prepare("DELETE FROM comment_likes WHERE user_id = ? AND comment_id = ?");
            $stmt->bind_param("ii", $currentUserId, $commentId);
            $stmt->execute();
            $stmt->close();
            $liked = false;
        } else {
            $stmt = $conn->prepare("INSERT INTO comment_likes (user_id, comment_id) VALUES (?, ?)");
            $stmt->bind_param("ii", $currentUserId, $commentId);
            $stmt->execute();
            $stmt->close();
            $liked = true;
        }
        $stmt = $conn->prepare("SELECT COUNT(*) as likes FROM comment_likes WHERE comment_id = ?");
        $stmt->bind_param("i", $commentId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $likesCount = $result['likes'];
        $stmt->close();
        
        echo json_encode(['success' => true, 'likes_count' => $likesCount, 'liked' => $liked]);
        exit;

    } elseif ($method === 'DELETE') {
        $stmt = $conn->prepare("DELETE FROM comment_likes WHERE user_id = ? AND comment_id = ?");
        $stmt->bind_param("ii", $currentUserId, $commentId);
        $stmt->execute();
        $stmt->close();
        
        $stmt = $conn->prepare("SELECT COUNT(*) as likes FROM comment_likes WHERE comment_id = ?");
        $stmt->bind_param("i", $commentId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $likesCount = $result['likes'];
        $stmt->close();
        
        echo json_encode(['success' => true, 'likes_count' => $likesCount]);
        exit;
    } else {
        echo json_encode(['success' => false, 'message' => 'Geçersiz metod']);
        exit;
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Sunucu hatası: ' . $e->getMessage()]);
    exit;
}
