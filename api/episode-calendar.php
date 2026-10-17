<?php
// Takip edilen dizilerin sonraki bölüm tarihleri (giriş gerekir). Sona ermiş diziler listede yer almaz.
require_once 'config.php';
require_once __DIR__ . '/lib/tmdb.php';
require_once __DIR__ . '/lib/calendar.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['success' => false, 'message' => 'Geçersiz istek'], 405);
}
if (tmdbApiKey() === null) {
    tmdbUnavailableResponse();
}

$userId = (int) getCurrentUserId();
[$today] = calendarDates();

$series = [];
foreach ($db->user_series_status->find(['user_id' => $userId], ['projection' => ['series_id' => 1], 'limit' => CALENDAR_MAX_SERIES]) as $row) {
    $doc = $db->series->findOne(['_id' => $row['series_id']], ['projection' => ['tmdb_id' => 1, 'title' => 1, 'poster_path' => 1]]);
    if ($doc && !empty($doc['tmdb_id'])) {
        $series[] = $doc;
    }
}

$items = [];
foreach ($series as $doc) {
    $tv = tmdbGet($db, 'tv/' . (int) $doc['tmdb_id'], [], 21600);
    $entry = $tv ? episodeCalendarEntry($tv, $today) : null;
    if ($entry === null) {
        continue;
    }
    $items[] = $entry + [
        'tmdb_id' => (int) $doc['tmdb_id'],
        'title' => (string) ($tv['name'] ?? $doc['title'] ?? ''),
        'poster' => tmdbImageUrl($tv['poster_path'] ?? $doc['poster_path'] ?? null, 'w185'),
        'url' => 'detail.php?type=series&id=' . (int) $doc['tmdb_id'] . '&tab=season',
    ];
}

usort($items, static fn (array $a, array $b): int => [$a['air_date'], $a['title']] <=> [$b['air_date'], $b['title']]);

jsonResponse(['success' => true, 'today' => $today, 'items' => $items]);
