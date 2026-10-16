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

// Afiş adresleri: içerik türüne göre tek sorguyla toplanır.
$idsByType = [];
foreach ($items as $item) {
    $idsByType[$item->content_type][] = (string) $item->content_id;
}
$posterSources = [
    'movie' => ['movies', 'tmdb_id', 'poster_path', true],
    'series' => ['series', 'tmdb_id', 'poster_path', true],
    'book' => ['books', 'google_books_id', 'cover_url', false],
];
$posters = [];
foreach ($posterSources as $type => [$collection, $field, $posterField, $numeric]) {
    if (empty($idsByType[$type])) {
        continue;
    }
    $ids = array_values(array_unique($numeric ? array_map('intval', $idsByType[$type]) : $idsByType[$type]));
    foreach ($db->$collection->find([$field => ['$in' => $ids]], ['projection' => [$field => 1, $posterField => 1]]) as $doc) {
        $path = $doc[$posterField] ?? null;
        if (!is_string($path) || $path === '') {
            continue;
        }
        $url = $type === 'book' ? preg_replace('~^http://~', 'https://', $path) : ($path[0] === '/' ? 'https://image.tmdb.org/t/p/w342' . $path : null);
        if ($url !== null) {
            $posters[$type . ':' . (string) $doc[$field]] = $url;
        }
    }
}

$itemRows = [];
foreach ($items as $item) {
    $itemRows[] = [
        'content_type' => $item->content_type,
        'content_id' => $item->content_id,
        'content_title' => $item->content_title,
        'poster' => $posters[$item->content_type . ':' . (string) $item->content_id] ?? null,
    ];
}

jsonResponse([
    'success' => true,
    'list' => [
        'name' => $list->name,
        'description' => $list->description,
        'owner' => $owner ? ['id' => $owner->_id, 'username' => $owner->username, 'avatar_url' => $owner->avatar_url ?? null] : null,
        'item_count' => count($itemRows),
        'updated_at' => isset($list->updated_at) ? $list->updated_at->toDateTime()->format(DATE_ATOM) : null,
        'items' => $itemRows,
    ],
]);
?>
