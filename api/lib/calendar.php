<?php
// Takip edilen dizilerin sonraki bölüm takvimi ve yayın öncesi bildirimleri.
// Veri TMDB'nin next_episode_to_air alanından gelir (tmdbGet önbelleğiyle). Bildirimler, bildirim listesi açıldığında
// bir kez üretilir (release_reminders ile aynı desen); ayrı bir zamanlayıcı gerektirmez.

require_once __DIR__ . '/push.php';

const CALENDAR_MAX_SERIES = 40;

// Bugünün tarihi (Türkiye saatine göre) ve yarın, YYYY-MM-DD.
function calendarDates(?DateTimeImmutable $now = null): array {
    $now = ($now ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone('Europe/Istanbul'));
    return [$now->format('Y-m-d'), $now->modify('+1 day')->format('Y-m-d')];
}

// TMDB dizi yanıtından sonraki bölümü çıkarır. Sona ermiş ya da tarihi geçmiş bölümde null döner.
function episodeCalendarEntry(array $tv, string $today): ?array {
    $next = $tv['next_episode_to_air'] ?? null;
    if (!is_array($next) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($next['air_date'] ?? ''))) {
        return null;
    }
    if ($next['air_date'] < $today) {
        return null;
    }
    return [
        'air_date' => $next['air_date'],
        'season' => (int) ($next['season_number'] ?? 0),
        'episode' => (int) ($next['episode_number'] ?? 0),
        'episode_name' => trim((string) ($next['name'] ?? '')),
    ];
}

// Bildirim yalnızca bugün ya da yarın yayınlanacak bölümler için üretilir.
function episodeAlertLabel(string $airDate, string $today, string $tomorrow): ?string {
    if ($airDate === $today) {
        return 'bugün yayında';
    }
    if ($airDate === $tomorrow) {
        return 'yarın yayında';
    }
    return null;
}

// Kullanıcının takip ettiği dizilerden bugün/yarın yayınlanacak, daha önce bildirilmemiş bölümleri bildirime çevirir.
function notifyDueEpisodes($db, int $userId): void {
    [$today, $tomorrow] = calendarDates();
    $series = [];
    foreach ($db->user_series_status->find(['user_id' => $userId], ['projection' => ['series_id' => 1], 'limit' => CALENDAR_MAX_SERIES]) as $row) {
        $doc = $db->series->findOne(['_id' => $row['series_id']], ['projection' => ['tmdb_id' => 1, 'title' => 1]]);
        if ($doc && !empty($doc['tmdb_id'])) {
            $series[] = $doc;
        }
    }

    foreach ($series as $doc) {
        $tv = tmdbGet($db, 'tv/' . (int) $doc['tmdb_id'], [], 21600);
        $entry = $tv ? episodeCalendarEntry($tv, $today) : null;
        $label = $entry ? episodeAlertLabel($entry['air_date'], $today, $tomorrow) : null;
        if ($label === null) {
            continue;
        }
        // Aynı bölüm için bildirim bir kez üretilir: benzersiz anahtarın eklenip eklenmediğine bakılır.
        $result = $db->episode_notifications->updateOne(
            ['user_id' => $userId, 'tmdb_id' => (int) $doc['tmdb_id'], 'season' => $entry['season'], 'episode' => $entry['episode']],
            ['$setOnInsert' => ['created_at' => new MongoDB\BSON\UTCDateTime()]],
            ['upsert' => true]
        );
        if ($result->getUpsertedCount() !== 1) {
            continue;
        }
        $name = (string) ($tv['name'] ?? $doc['title'] ?? '');
        $title = $name . ' · S' . $entry['season'] . ' B' . $entry['episode'] . ' — ' . $label;
        $db->notifications->insertOne([
            '_id' => nextSequence($db, 'notifications'),
            'user_id' => $userId,
            'actor_id' => null,
            'type' => 'episode',
            'content_type' => 'series',
            'content_id' => (int) $doc['tmdb_id'],
            'content_title' => $title,
            'is_read' => false,
            'created_at' => new MongoDB\BSON\UTCDateTime(),
        ]);
        pushNotifyUser($db, $userId, $title, 'detail.php?type=series&id=' . (int) $doc['tmdb_id'] . '&tab=season');
    }
}
