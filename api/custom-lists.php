<?php
require_once 'config.php';
requireLogin();

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);
$userId = (int)getCurrentUserId();

if ($method === 'GET') {
    $listId = $_GET['id'] ?? null;

    if ($listId) {
        $listId = (int)$listId;
        $list = $db->custom_lists->findOne(['_id' => $listId, 'user_id' => $userId]);

        if (!$list) {
            jsonResponse(['success' => false, 'message' => 'Liste bulunamadı']);
        }

        $items = iterator_to_array(
            $db->custom_list_items->find(['list_id' => $listId], ['sort' => ['added_at' => -1]]),
            false
        );

        $itemRows = [];
        foreach ($items as $item) {
            $itemRows[] = [
                'id' => $item->_id,
                'content_type' => $item->content_type,
                'content_id' => $item->content_id,
                'content_title' => $item->content_title,
                'added_at' => $item->added_at->toDateTime()->format('Y-m-d H:i:s'),
            ];
        }

        $listRow = [
            'id' => $list->_id,
            'name' => $list->name,
            'description' => $list->description,
            'is_public' => !empty($list->is_public),
            'created_at' => $list->created_at->toDateTime()->format('Y-m-d H:i:s'),
            'updated_at' => $list->updated_at->toDateTime()->format('Y-m-d H:i:s'),
            'items' => $itemRows,
        ];

        jsonResponse(['success' => true, 'list' => $listRow]);
    } else {
        $lists = iterator_to_array(
            $db->custom_lists->find(['user_id' => $userId], ['sort' => ['updated_at' => -1]]),
            false
        );

        $listIds = [];
        foreach ($lists as $list) {
            $listIds[] = $list->_id;
        }

        $itemCounts = [];
        if ($listIds) {
            foreach ($db->custom_list_items->find(['list_id' => ['$in' => $listIds]], ['projection' => ['list_id' => 1]]) as $item) {
                $itemCounts[$item->list_id] = ($itemCounts[$item->list_id] ?? 0) + 1;
            }
        }

        $listRows = [];
        foreach ($lists as $list) {
            $listRows[] = [
                'id' => $list->_id,
                'name' => $list->name,
                'description' => $list->description,
                'created_at' => $list->created_at->toDateTime()->format('Y-m-d H:i:s'),
                'updated_at' => $list->updated_at->toDateTime()->format('Y-m-d H:i:s'),
                'item_count' => $itemCounts[$list->_id] ?? 0,
            ];
        }

        jsonResponse(['success' => true, 'lists' => $listRows]);
    }

} elseif ($method === 'POST') {
    requireCsrf();
    $action = $input['action'] ?? 'create';

    if ($action === 'create') {
        $name = $input['name'] ?? null;
        $description = $input['description'] ?? '';

        if (!$name || empty(trim($name))) {
            jsonResponse(['success' => false, 'message' => 'Liste adı gereklidir']);
        }

        $listId = nextSequence($db, 'custom_lists');
        $now = new MongoDB\BSON\UTCDateTime();
        $db->custom_lists->insertOne([
            '_id' => $listId,
            'user_id' => $userId,
            'name' => $name,
            'description' => $description,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        jsonResponse(['success' => true, 'message' => 'Liste oluşturuldu', 'list_id' => $listId]);

    } elseif ($action === 'add_item') {
        $listId = $input['list_id'] ?? null;
        $contentType = $input['content_type'] ?? null;
        $contentId = $input['content_id'] ?? null;
        $contentTitle = $input['content_title'] ?? '';

        if (!$listId || !$contentType || !$contentId) {
            jsonResponse(['success' => false, 'message' => 'Gerekli alanlar eksik']);
        }
        $listId = (int)$listId;
        $contentId = (string)$contentId; // content_id şemada VARCHAR(255)

        $listOwner = $db->custom_lists->findOne(['_id' => $listId]);

        if (!$listOwner || $listOwner->user_id != $userId) {
            jsonResponse(['success' => false, 'message' => 'Bu listeye erişim yetkiniz yok']);
        }

        $db->custom_list_items->updateOne(
            ['list_id' => $listId, 'content_type' => $contentType, 'content_id' => $contentId],
            [
                '$set' => ['content_title' => $contentTitle],
                '$setOnInsert' => [
                    '_id' => nextSequence($db, 'custom_list_items'),
                    'list_id' => $listId,
                    'content_type' => $contentType,
                    'content_id' => $contentId,
                    'added_at' => new MongoDB\BSON\UTCDateTime(),
                ],
            ],
            ['upsert' => true]
        );

        $db->custom_lists->updateOne(['_id' => $listId], ['$set' => ['updated_at' => new MongoDB\BSON\UTCDateTime()]]);

        jsonResponse(['success' => true, 'message' => 'Öğe listeye eklendi']);

    } elseif ($action === 'toggle_public') {
        $listId = $input['list_id'] ?? null;
        $isPublic = !empty($input['is_public']);

        if (!$listId) {
            jsonResponse(['success' => false, 'message' => 'Liste ID gereklidir']);
        }
        $listId = (int)$listId;

        $result = $db->custom_lists->updateOne(
            ['_id' => $listId, 'user_id' => $userId],
            ['$set' => ['is_public' => $isPublic]]
        );

        if ($result->getMatchedCount() === 0) {
            jsonResponse(['success' => false, 'message' => 'Bu listeye erişim yetkiniz yok'], 403);
        }

        jsonResponse(['success' => true, 'is_public' => $isPublic]);
    }

} elseif ($method === 'PUT') {
    requireCsrf();
    $listId = $input['id'] ?? null;
    $name = $input['name'] ?? null;
    $description = $input['description'] ?? '';

    if (!$listId || !$name) {
        jsonResponse(['success' => false, 'message' => 'Gerekli alanlar eksik']);
    }
    $listId = (int)$listId;

    $db->custom_lists->updateOne(
        ['_id' => $listId, 'user_id' => $userId],
        ['$set' => ['name' => $name, 'description' => $description, 'updated_at' => new MongoDB\BSON\UTCDateTime()]]
    );

    jsonResponse(['success' => true, 'message' => 'Liste güncellendi']);

} elseif ($method === 'DELETE') {
    requireCsrf();
    $listId = $input['list_id'] ?? null;
    $itemId = $input['item_id'] ?? null;

    if (!$listId) {
        jsonResponse(['success' => false, 'message' => 'Liste ID gereklidir']);
    }
    $listId = (int)$listId;

    if ($itemId) {
        $itemId = (int)$itemId;
        // Liste gerçekten bu kullanıcıya mı ait, kontrol et.
        $listOwner = $db->custom_lists->findOne(['_id' => $listId, 'user_id' => $userId]);
        if (!$listOwner) {
            jsonResponse(['success' => false, 'message' => 'Bu listeye erişim yetkiniz yok'], 403);
        }
        $db->custom_list_items->deleteOne(['_id' => $itemId, 'list_id' => $listId]);
        jsonResponse(['success' => true, 'message' => 'Öğe listeden çıkarıldı']);
    } else {
        // Mongo'da cascade delete yok — liste gerçekten silindiyse öğelerini de temizle.
        $deleteResult = $db->custom_lists->deleteOne(['_id' => $listId, 'user_id' => $userId]);
        if ($deleteResult->getDeletedCount() > 0) {
            $db->custom_list_items->deleteMany(['list_id' => $listId]);
        }
        jsonResponse(['success' => true, 'message' => 'Liste silindi']);
    }
} else {
    jsonResponse(['success' => false, 'message' => 'Geçersiz istek yöntemi']);
}
?>
