<?php
require_once 'config.php';

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0);

try {
    requireLogin();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Giriş yapmalısınız']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);
$userId = getCurrentUserId();

if (!$userId) {
    echo json_encode(['success' => false, 'message' => 'Kullanıcı bulunamadı']);
    exit;
}

if ($method === 'GET') {
    $listId = $_GET['id'] ?? null;

    if ($listId) {
        $stmt = $conn->prepare("SELECT id, name, description, created_at, updated_at FROM custom_lists WHERE id = ? AND user_id = ?");
        if (!$stmt) {
            echo json_encode(['success' => false, 'message' => 'SQL hatası: ' . $conn->error]);
            exit;
        }
        $stmt->bind_param("ii", $listId, $userId);
        $stmt->execute();
        $list = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$list) {
            echo json_encode(['success' => false, 'message' => 'Liste bulunamadı']);
            exit;
        }

        $stmt = $conn->prepare("SELECT id, content_type, content_id, content_title, added_at FROM custom_list_items WHERE list_id = ? ORDER BY added_at DESC");
        $stmt->bind_param("i", $listId);
        $stmt->execute();
        $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $list['items'] = $items;
        echo json_encode(['success' => true, 'list' => $list]);
    } else {
        $stmt = $conn->prepare("SELECT id, name, description, created_at, updated_at FROM custom_lists WHERE user_id = ? ORDER BY updated_at DESC");
        if (!$stmt) {
            echo json_encode(['success' => false, 'message' => 'SQL hatası: ' . $conn->error]);
            exit;
        }
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $lists = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($lists as &$list) {
            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM custom_list_items WHERE list_id = ?");
            $stmt->bind_param("i", $list['id']);
            $stmt->execute();
            $count = $stmt->get_result()->fetch_assoc();
            $list['item_count'] = $count['count'];
            $stmt->close();
        }

        echo json_encode(['success' => true, 'lists' => $lists]);
    }

} elseif ($method === 'POST') {
    $action = $input['action'] ?? 'create';
    
    if ($action === 'create') {
        $name = $input['name'] ?? null;
        $description = $input['description'] ?? '';

        if (!$name || empty(trim($name))) {
            echo json_encode(['success' => false, 'message' => 'Liste adı gereklidir']);
            exit;
        }

        $stmt = $conn->prepare("INSERT INTO custom_lists (user_id, name, description) VALUES (?, ?, ?)");
        if (!$stmt) {
            echo json_encode(['success' => false, 'message' => 'SQL hatası: ' . $conn->error]);
            exit;
        }
        $stmt->bind_param("iss", $userId, $name, $description);

        if ($stmt->execute()) {
            $listId = $stmt->insert_id;
            $stmt->close();
            echo json_encode(['success' => true, 'message' => 'Liste oluşturuldu', 'list_id' => $listId]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Liste oluşturulamadı: ' . $stmt->error]);
        }
    } elseif ($action === 'add_item') {
        $listId = $input['list_id'] ?? null;
        $contentType = $input['content_type'] ?? null;
        $contentId = $input['content_id'] ?? null;
        $contentTitle = $input['content_title'] ?? '';

        if (!$listId || !$contentType || !$contentId) {
            echo json_encode(['success' => false, 'message' => 'Gerekli alanlar eksik']);
            exit;
        }

        $stmt = $conn->prepare("SELECT user_id FROM custom_lists WHERE id = ?");
        $stmt->bind_param("i", $listId);
        $stmt->execute();
        $listOwner = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$listOwner || $listOwner['user_id'] != $userId) {
            echo json_encode(['success' => false, 'message' => 'Bu listeye erişim yetkiniz yok']);
            exit;
        }

        $stmt = $conn->prepare("INSERT INTO custom_list_items (list_id, content_type, content_id, content_title) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE content_title = VALUES(content_title)");
        $stmt->bind_param("isss", $listId, $contentType, $contentId, $contentTitle);

        if ($stmt->execute()) {
            $stmt->close();
            $conn->query("UPDATE custom_lists SET updated_at = CURRENT_TIMESTAMP WHERE id = $listId");
            echo json_encode(['success' => true, 'message' => 'Öğe listeye eklendi']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Öğe eklenirken hata oluştu']);
        }
    }

} elseif ($method === 'PUT') {
    $listId = $input['id'] ?? null;
    $name = $input['name'] ?? null;
    $description = $input['description'] ?? '';

    if (!$listId || !$name) {
        echo json_encode(['success' => false, 'message' => 'Gerekli alanlar eksik']);
        exit;
    }

    $stmt = $conn->prepare("UPDATE custom_lists SET name = ?, description = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ssii", $name, $description, $listId, $userId);

    if ($stmt->execute()) {
        $stmt->close();
        echo json_encode(['success' => true, 'message' => 'Liste güncellendi']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Liste güncellenirken hata oluştu']);
    }

} elseif ($method === 'DELETE') {
    $listId = $input['list_id'] ?? null;
    $itemId = $input['item_id'] ?? null;

    if (!$listId) {
        echo json_encode(['success' => false, 'message' => 'Liste ID gereklidir']);
        exit;
    }

    if ($itemId) {
        $stmt = $conn->prepare("DELETE FROM custom_list_items WHERE id = ? AND list_id = ?");
        $stmt->bind_param("ii", $itemId, $listId);
        if ($stmt->execute()) {
            $stmt->close();
            echo json_encode(['success' => true, 'message' => 'Öğe listeden çıkarıldı']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Öğe silinirken hata oluştu']);
        }
    } else {
        $stmt = $conn->prepare("DELETE FROM custom_lists WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $listId, $userId);
        if ($stmt->execute()) {
            $stmt->close();
            echo json_encode(['success' => true, 'message' => 'Liste silindi']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Liste silinirken hata oluştu']);
        }
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Geçersiz istek yöntemi']);
}

