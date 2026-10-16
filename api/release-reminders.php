<?php
require_once 'config.php';
requireLogin();
$userId = (int) getCurrentUserId();

// Hatırlatıcılar (user_id, tmdb_id) çiftiyle tekildir (setup-db.php). Vizyon tarihi gelince
// notifications.php bunları bildirime çevirir ve notified=true yapar.
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $rows = iterator_to_array($db->release_reminders->find(
        ['user_id' => $userId],
        ['sort' => ['release_date' => 1]]
    ), false);

    $reminders = array_map(fn($row) => [
        'tmdb_id' => (int) $row->tmdb_id,
        'title' => (string) ($row->title ?? ''),
        'release_date' => $row->release_date ?? null,
    ], $rows);

    jsonResponse(['success' => true, 'reminders' => $reminders]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $input = json_decode(file_get_contents('php://input'), true);
    $input = is_array($input) ? $input : [];

    $tmdbId = isset($input['tmdb_id']) ? (int) $input['tmdb_id'] : 0;
    if ($tmdbId <= 0) {
        jsonResponse(['success' => false, 'message' => 'tmdb_id gerekli'], 400);
    }

    if (($input['action'] ?? 'add') === 'remove') {
        $db->release_reminders->deleteOne(['user_id' => $userId, 'tmdb_id' => $tmdbId]);
        jsonResponse(['success' => true, 'active' => false]);
    }

    $releaseDate = $input['release_date'] ?? null;
    if (!is_string($releaseDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $releaseDate)) {
        jsonResponse(['success' => false, 'message' => 'Geçerli bir vizyon tarihi gerekli'], 400);
    }

    $title = mb_substr(trim((string) ($input['title'] ?? '')), 0, 200);

    $db->release_reminders->updateOne(
        ['user_id' => $userId, 'tmdb_id' => $tmdbId],
        [
            '$set' => ['title' => $title, 'release_date' => $releaseDate, 'notified' => false],
            '$setOnInsert' => [
                '_id' => nextSequence($db, 'release_reminders'),
                'created_at' => new MongoDB\BSON\UTCDateTime(),
            ],
        ],
        ['upsert' => true]
    );

    jsonResponse(['success' => true, 'active' => true]);
}

jsonResponse(['success' => false, 'message' => 'Geçersiz istek yöntemi'], 405);
?>
