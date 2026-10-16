<?php
// Sezon sohbeti yardımcıları. Saf fonksiyonlar (ağ ve veritabanı yok): tests/EpisodeTest.php doğrudan çağırır.
// Sohbet bölüm başınadır; yorumlar `comments` koleksiyonunda season_number / episode_number alanlarıyla tutulur.
// Bölümü izlediğini işaretlemeyen kullanıcı sohbeti göremez ve yazamaz (spoiler güvenliği sunucuda uygulanır).

const EPISODE_MAX_SEASON = 100;
const EPISODE_MAX_NUMBER = 1000;
const EPISODE_THIS_WEEK_DAYS = 7;

// Sezon 0 "özel bölümler"dir (TMDB). Geçersizse null döner.
function episodeRef($season, $episode): ?array {
    $s = filter_var($season, FILTER_VALIDATE_INT);
    $e = filter_var($episode, FILTER_VALIDATE_INT);
    if ($s === false || $e === false || $s < 0 || $s > EPISODE_MAX_SEASON || $e < 1 || $e > EPISODE_MAX_NUMBER) {
        return null;
    }
    return ['season' => $s, 'episode' => $e];
}

function episodeKey(int $season, int $episode): string {
    return $season . ':' . $episode;
}

// Yayın tarihine göre bölümün durumu: yayınlanmadı, bu hafta yayınlandı ya da daha eski.
function episodeState(?string $airDate, int $now): string {
    if ($airDate === null || $airDate === '') {
        return 'unknown';
    }
    $time = strtotime($airDate . ' 00:00:00 UTC');
    if ($time === false) {
        return 'unknown';
    }
    // Gün bazında karşılaştırılır: bugün ve son 7 gün içinde yayınlananlar "bu hafta" sayılır.
    $today = strtotime(gmdate('Y-m-d', $now) . ' 00:00:00 UTC');
    $daysAgo = (int) round(($today - $time) / 86400);
    if ($daysAgo < 0) {
        return 'upcoming';
    }
    return $daysAgo <= EPISODE_THIS_WEEK_DAYS ? 'this_week' : 'aired';
}

// TMDB sezon yanıtını arayüzün kullanacağı sade bölüm listesine çevirir.
function episodeNormalizeSeason(array $season, int $now): array {
    $episodes = [];
    foreach ($season['episodes'] ?? [] as $episode) {
        if (!is_array($episode) || !isset($episode['episode_number'])) {
            continue;
        }
        $air = isset($episode['air_date']) && is_string($episode['air_date']) ? $episode['air_date'] : null;
        $still = $episode['still_path'] ?? null;
        $episodes[] = [
            'number' => (int) $episode['episode_number'],
            'name' => (string) ($episode['name'] ?? ''),
            'air_date' => $air,
            'overview' => isset($episode['overview']) ? mb_substr((string) $episode['overview'], 0, 400) : '',
            'runtime' => isset($episode['runtime']) ? (int) $episode['runtime'] : null,
            'still' => is_string($still) && preg_match('~^/[A-Za-z0-9_.-]+\.(?:jpg|png)$~', $still) ? 'https://image.tmdb.org/t/p/w300' . $still : null,
            'state' => episodeState($air, $now),
        ];
    }
    usort($episodes, static fn (array $a, array $b): int => $a['number'] <=> $b['number']);
    return $episodes;
}

// TMDB dizi yanıtındaki sezon listesi (özel bölümler sezon 0, sona alınır).
function episodeNormalizeSeasons(array $series): array {
    $seasons = [];
    foreach ($series['seasons'] ?? [] as $season) {
        if (!is_array($season) || !isset($season['season_number'])) {
            continue;
        }
        $seasons[] = [
            'number' => (int) $season['season_number'],
            'name' => (string) ($season['name'] ?? ''),
            'episode_count' => (int) ($season['episode_count'] ?? 0),
            'air_date' => isset($season['air_date']) && is_string($season['air_date']) ? $season['air_date'] : null,
        ];
    }
    usort($seasons, static function (array $a, array $b): int {
        $ak = $a['number'] === 0 ? PHP_INT_MAX : $a['number'];
        $bk = $b['number'] === 0 ? PHP_INT_MAX : $b['number'];
        return $ak <=> $bk;
    });
    return $seasons;
}

// Kullanıcı bu bölümün sohbetini görebilir mi? $watched: ["sezon:bölüm" => true, ...]
function episodeThreadUnlocked(array $watched, int $season, int $episode): bool {
    return isset($watched[episodeKey($season, $episode)]);
}

// Sezon ilerlemesi: izlenen / toplam (toplam bilinmiyorsa null).
function episodeSeasonProgress(array $watched, int $season, ?int $total): array {
    $count = 0;
    foreach (array_keys($watched) as $key) {
        if (str_starts_with($key, $season . ':')) {
            $count++;
        }
    }
    return [
        'watched' => $count,
        'total' => $total,
        'percent' => $total ? (int) round(min(1, $count / $total) * 100) : null,
    ];
}

// Kullanıcının bir dizide izlediğini işaretlediği bölümler: ["sezon:bölüm" => true, ...]
function episodeWatchedMap($db, int $userId, int $seriesId): array {
    $map = [];
    if ($userId <= 0) {
        return $map;
    }
    foreach ($db->user_episode_progress->find(['user_id' => $userId, 'series_id' => $seriesId], ['projection' => ['season' => 1, 'episode' => 1]]) as $row) {
        $map[episodeKey((int) $row['season'], (int) $row['episode'])] = true;
    }
    return $map;
}
