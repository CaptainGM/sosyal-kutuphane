<?php
// Bölüm ilerlemesi: kullanıcının hangi bölümleri izlediğini işaretlediği. Sezon sohbeti bu işarete bağlıdır:
// izlenmemiş bir bölümün sohbetini göremez ve yazamazsınız (spoiler güvenliği).
require_once 'config.php';
require_once __DIR__ . '/lib/episodes.php';

requireLogin();
$userId = (int)getCurrentUserId();
$method = $_SERVER['REQUEST_METHOD'];

function episodeProgressResponse($db, int $userId, int $seriesId): void {
    $watched = episodeWatchedMap($db, $userId, $seriesId);
    $seasonCounts = [];
    foreach (array_keys($watched) as $key) {
        $season = (int)explode(':', $key)[0];
        $seasonCounts[$season] = ($seasonCounts[$season] ?? 0) + 1;
    }
    jsonResponse(['success' => true, 'watched' => (object)$watched, 'season_counts' => (object)$seasonCounts]);
}

if ($method === 'GET') {
    $seriesId = filter_var($_GET['series_id'] ?? null, FILTER_VALIDATE_INT);
    if ($seriesId === false || $seriesId < 1) {
        jsonResponse(['success' => false, 'message' => 'series_id gerekli'], 400);
    }
    episodeProgressResponse($db, $userId, $seriesId);

} elseif ($method === 'POST') {
    requireCsrf();
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }

    $seriesId = filter_var($input['series_id'] ?? null, FILTER_VALIDATE_INT);
    if ($seriesId === false || $seriesId < 1) {
        jsonResponse(['success' => false, 'message' => 'series_id gerekli'], 400);
    }

    // Tek bölüm (season + episode) ya da toplu işlem (season + episodes dizisi).
    $refs = [];
    if (isset($input['episodes']) && is_array($input['episodes'])) {
        foreach (array_slice($input['episodes'], 0, 200) as $number) {
            $ref = episodeRef($input['season'] ?? null, $number);
            if ($ref !== null) {
                $refs[] = $ref;
            }
        }
    } else {
        $ref = episodeRef($input['season'] ?? null, $input['episode'] ?? null);
        if ($ref !== null) {
            $refs[] = $ref;
        }
    }
    if (!$refs) {
        jsonResponse(['success' => false, 'message' => 'Geçerli bir bölüm belirtilmedi'], 400);
    }

    $watched = !empty($input['watched']);
    foreach ($refs as $ref) {
        $filter = ['user_id' => $userId, 'series_id' => $seriesId, 'season' => $ref['season'], 'episode' => $ref['episode']];
        if ($watched) {
            $db->user_episode_progress->updateOne($filter, ['$setOnInsert' => ['watched_at' => new MongoDB\BSON\UTCDateTime()]], ['upsert' => true]);
        } else {
            $db->user_episode_progress->deleteOne($filter);
        }
    }

    episodeProgressResponse($db, $userId, $seriesId);

} else {
    jsonResponse(['success' => false, 'message' => 'Geçersiz istek yöntemi'], 405);
}
