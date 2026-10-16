<?php
// Sezon sohbeti için dizinin sezon ve bölüm listesi (TMDB, önbellekli) ve kullanıcının işaretleri.
//   ?series_id=ID            -> sezonlar ve sezon bazında ilerleme
//   ?series_id=ID&season=N   -> o sezonun bölümleri, izlendi bilgisi ve sohbet yorum sayıları
require_once 'config.php';
require_once __DIR__ . '/lib/tmdb.php';
require_once __DIR__ . '/lib/episodes.php';

$seriesId = filter_var($_GET['series_id'] ?? null, FILTER_VALIDATE_INT);
if ($seriesId === false || $seriesId < 1) {
    jsonResponse(['success' => false, 'message' => 'series_id gerekli'], 400);
}
$userId = isLoggedIn() ? (int)getCurrentUserId() : 0;
$watched = episodeWatchedMap($db, $userId, $seriesId);

if (!isset($_GET['season'])) {
    $series = tmdbGet($db, "tv/$seriesId", [], 43200);
    if ($series === null) {
        tmdbUnavailableResponse();
    }

    $seasons = episodeNormalizeSeasons($series);
    foreach ($seasons as &$season) {
        $season['progress'] = episodeSeasonProgress($watched, $season['number'], $season['episode_count'] ?: null);
    }
    unset($season);

    $next = $series['next_episode_to_air'] ?? null;
    jsonResponse([
        'success' => true,
        'series' => [
            'id' => $seriesId,
            'name' => (string)($series['name'] ?? ''),
            'status' => $series['status'] ?? null,
            'next_episode' => is_array($next) ? [
                'season' => (int)($next['season_number'] ?? 0),
                'episode' => (int)($next['episode_number'] ?? 0),
                'air_date' => $next['air_date'] ?? null,
            ] : null,
        ],
        'seasons' => $seasons,
    ]);
}

$seasonNumber = filter_var($_GET['season'], FILTER_VALIDATE_INT);
if ($seasonNumber === false || $seasonNumber < 0 || $seasonNumber > EPISODE_MAX_SEASON) {
    jsonResponse(['success' => false, 'message' => 'Geçersiz sezon'], 400);
}

$data = tmdbGet($db, "tv/$seriesId/season/$seasonNumber", [], 21600);
if ($data === null) {
    tmdbUnavailableResponse();
}

$episodes = episodeNormalizeSeason($data, time());

// Bölüm sohbetlerindeki üst düzey yorum sayıları (içerikleri değil, yalnızca sayı: spoiler taşımaz).
$counts = [];
$cursor = $db->comments->aggregate([
    ['$match' => ['content_type' => 'series', 'content_id' => $seriesId, 'season_number' => $seasonNumber, 'parent_comment_id' => null]],
    ['$group' => ['_id' => '$episode_number', 'count' => ['$sum' => 1]]],
]);
foreach ($cursor as $row) {
    $counts[(int)$row['_id']] = (int)$row['count'];
}

foreach ($episodes as &$episode) {
    $episode['watched'] = episodeThreadUnlocked($watched, $seasonNumber, $episode['number']);
    $episode['thread_count'] = $counts[$episode['number']] ?? 0;
}
unset($episode);

jsonResponse([
    'success' => true,
    'season' => ['number' => $seasonNumber, 'name' => (string)($data['name'] ?? '')],
    'episodes' => $episodes,
    'progress' => episodeSeasonProgress($watched, $seasonNumber, count($episodes) ?: null),
]);
