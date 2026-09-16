<?php
require_once 'config.php';

// Giriş gerektirmez — sadece is_public=true olan listeler için çalışır.
$listId = isset($_GET['id']) ? (int) $_GET['id'] : null;
if (!$listId) {
    jsonResponse(['success' => false, 'message' => 'Liste ID gereklidir'], 400);
}

$list = $db->custom_lists->findOne(['_id' => $listId, 'is_public' => true]);
if (!$list) {
    jsonResponse(['success' => false, 'message' => 'Liste bulunamadı ya da herkese açık değil'], 404);
}

$owner = $db->users->findOne(['_id' => $list->user_id], ['projection' => ['username' => 1, 'avatar_url' => 1]]);

$items = iterator_to_array(
    $db->custom_list_items->find(['list_id' => $listId], ['sort' => ['added_at' => -1]]),
    false
);

$itemRows = [];
foreach ($items as $item) {
    $itemRows[] = [
        'content_type' => $item->content_type,
        'content_id' => $item->content_id,
        'content_title' => $item->content_title,
    ];
}

jsonResponse([
    'success' => true,
    'list' => [
        'name' => $list->name,
        'description' => $list->description,
        'owner' => $owner ? ['username' => $owner->username, 'avatar_url' => $owner->avatar_url ?? null] : null,
        'items' => $itemRows,
    ],
]);
?>
