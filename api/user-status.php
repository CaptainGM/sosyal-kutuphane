<?php
require_once 'config.php';
requireLogin();

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);
$currentUserId = getCurrentUserId();
if ($method === 'GET') {
    $contentType = $_GET['content_type'] ?? null;
    $contentId = $_GET['content_id'] ?? null;
    $status = $_GET['status'] ?? null;
    $rating = $_GET['rating'] ?? null;
} else {
    $contentType = $input['content_type'] ?? null;
    $contentId = $input['content_id'] ?? null;
    $status = $input['status'] ?? null; 
    $rating = $input['rating'] ?? null;
}

if (!$contentType || !$contentId) {
    jsonResponse(['success' => false, 'message' => 'İçerik türü ve ID gereklidir.'], 400);
}
if ($contentType === 'movie') {
    $statusTable = 'user_movie_status';
    $contentIdCol = 'movie_id';
    $contentDbIdCol = 'tmdb_id'; 
    $contentTable = 'movies';
} elseif ($contentType === 'book') {
    $statusTable = 'user_book_status';
    $contentIdCol = 'book_id';
    $contentDbIdCol = 'google_books_id'; 
    $contentTable = 'books';
} else {
    jsonResponse(['success' => false, 'message' => 'Geçersiz içerik türü.'], 400);
}
function saveContentToDb($contentType, $contentId, $input) {
    global $conn;
    if ($contentType === 'movie') {
        $stmt = $conn->prepare("INSERT IGNORE INTO movies (tmdb_id, title, poster_path) VALUES (?, ?, ?)");
        $stmt->bind_param("iss", $contentId, $input['title'], $input['poster_path']);
        $stmt->execute();
    } elseif ($contentType === 'book') {
        $stmt = $conn->prepare("INSERT IGNORE INTO books (google_books_id, title, cover_url) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $contentId, $input['title'], $input['cover_url']);
        $stmt->execute();
    }
}
if (in_array($method, ['POST', 'PUT', 'DELETE']) && isset($input['content_data'])) {
    saveContentToDb($contentType, $contentId, $input['content_data']);
}
$stmt = $conn->prepare("SELECT id FROM $contentTable WHERE $contentDbIdCol = ?");
$stmt->bind_param("s", $contentId);
$stmt->execute();
$dbContentId = $stmt->get_result()->fetch_assoc()['id'] ?? null;

if (!$dbContentId) {
    jsonResponse(['success' => false, 'message' => 'İçerik veritabanında bulunamadı.'], 404);
}

if ($method === 'GET') {
    $response = ['status' => null, 'rating' => null];
    $stmt = $conn->prepare("SELECT status, rating FROM $statusTable WHERE user_id = ? AND $contentIdCol = ?");
    $stmt->bind_param("ii", $currentUserId, $dbContentId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    
    if ($result) {
        $response['status'] = $result['status'];
        $response['rating'] = $result['rating'];
    }
    
    jsonResponse(['success' => true, 'data' => $response]);

} elseif ($method === 'POST') {
    if ($status && $rating !== null && $rating > 0) {
        $stmt = $conn->prepare("DELETE FROM $statusTable WHERE user_id = ? AND $contentIdCol = ?");
        $stmt->bind_param("ii", $currentUserId, $dbContentId);
        $stmt->execute();

        $stmt = $conn->prepare("INSERT INTO $statusTable (user_id, $contentIdCol, status, rating) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("iisi", $currentUserId, $dbContentId, $status, $rating);
        
        if ($stmt->execute()) {
            jsonResponse(['success' => true, 'message' => 'Durum ve puan kaydedildi.', 'new_status' => $status, 'new_rating' => $rating]);
        } else {
            jsonResponse(['success' => false, 'message' => 'Kayıt hatası: ' . $conn->error], 500);
        }
    }
    elseif ($status) {
        $stmt = $conn->prepare("DELETE FROM $statusTable WHERE user_id = ? AND $contentIdCol = ?");
        $stmt->bind_param("ii", $currentUserId, $dbContentId);
        $stmt->execute();
        $stmt = $conn->prepare("INSERT INTO $statusTable (user_id, $contentIdCol, status) VALUES (?, ?, ?)");
        $stmt->bind_param("iis", $currentUserId, $dbContentId, $status);
        
        if ($stmt->execute()) {
            jsonResponse(['success' => true, 'message' => 'Durum güncellendi.', 'new_status' => $status]);
        } else {
            jsonResponse(['success' => false, 'message' => 'Durum güncellemesi hatası: ' . $conn->error], 500);
        }
    }
    elseif ($rating !== null && $rating > 0) {
        $defaultStatus = ($contentType === 'movie') ? 'watched' : 'read'; 
        $stmt = $conn->prepare("SELECT id FROM $statusTable WHERE user_id = ? AND $contentIdCol = ?");
        $stmt->bind_param("ii", $currentUserId, $dbContentId);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        
        if ($existing) {
            $stmt = $conn->prepare("UPDATE $statusTable SET rating = ? WHERE user_id = ? AND $contentIdCol = ?");
            $stmt->bind_param("iii", $rating, $currentUserId, $dbContentId);
        } else {
            $stmt = $conn->prepare("INSERT INTO $statusTable (user_id, $contentIdCol, status, rating) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("iisi", $currentUserId, $dbContentId, $defaultStatus, $rating);
        }
        
        if ($stmt->execute()) {
            jsonResponse(['success' => true, 'message' => 'Puan kaydedildi.', 'new_rating' => $rating]);
        } else {
            jsonResponse(['success' => false, 'message' => 'Puan kaydedilemedi: ' . $conn->error], 500);
        }
    }

    jsonResponse(['success' => false, 'message' => 'Geçersiz POST işlemi.'], 400);

} elseif ($method === 'DELETE') {
    
    $stmt = $conn->prepare("DELETE FROM $statusTable WHERE user_id = ? AND $contentIdCol = ?");
    $stmt->bind_param("ii", $currentUserId, $dbContentId);
    
    if ($stmt->execute()) {
        jsonResponse(['success' => true, 'message' => 'İçerik listeden kaldırıldı.']);
    }
}
?>
