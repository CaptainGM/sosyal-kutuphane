<?php
// Yeni uçların (akşam seçici, bölümler) kullandığı küçük TMDB yardımcısı. Eski proxy kendi içinde aynı işi yapar.
// Anahtar yoksa ya da istek başarısız olursa null döner ve çağıran uç 503 verir; başarılı yanıtlar api_cache'te tutulur.

function tmdbApiKey(): ?string {
    $key = getenv('TMDB_API_KEY');
    return $key ? (string) $key : null;
}

function tmdbGet($db, string $path, array $query = [], int $ttlSeconds = 21600): ?array {
    $apiKey = tmdbApiKey();
    if ($apiKey === null) {
        return null;
    }

    ksort($query);
    $cacheKey = 'tmdb:lib:v1:' . md5(ltrim($path, '/') . '?' . http_build_query($query));
    $cached = cacheGet($db, $cacheKey);
    if (is_array($cached)) {
        return $cached;
    }

    $url = 'https://api.themoviedb.org/3/' . ltrim($path, '/') . '?'
        . http_build_query(['api_key' => $apiKey, 'language' => 'tr-TR'] + $query);
    $context = stream_context_create(['http' => ['timeout' => 8, 'ignore_errors' => true]]);
    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        return null;
    }

    $data = json_decode($response, true);
    if (!is_array($data) || (isset($data['success']) && $data['success'] === false)) {
        return null;
    }

    cacheSet($db, $cacheKey, $data, $ttlSeconds);
    return $data;
}

// Anahtar eksikse ortak yanıt: CI gibi anahtarsız ortamlarda uçlar 503 ile açıkça bildirir.
function tmdbUnavailableResponse(): void {
    jsonResponse(['success' => false, 'message' => 'TMDB bağlantısı yapılandırılmamış ya da ulaşılamıyor'], 503);
}

function tmdbImageUrl(?string $path, string $size = 'w342'): ?string {
    if (!is_string($path) || !preg_match('~^/[A-Za-z0-9_.-]+\.(?:jpg|png)$~', $path)) {
        return null;
    }
    return "https://image.tmdb.org/t/p/$size$path";
}

// Henüz yayınlanmamış bir film/dizi mi? Öyleyse ['date' => 'YYYY-MM-DD'|null] (tarih belli değilse null) döner;
// yayınlanmışsa, kitapsa ya da TMDB'ye ulaşılamıyorsa null (engel konmaz). Sonuç 1 saat önbelleğe alınır;
// çıkış tarihi gelince yorum, puan ve "izledim" kendiliğinden açılır.
function unreleasedContent($db, string $type, $contentId): ?array {
    if (($type !== 'movie' && $type !== 'series') || !is_numeric($contentId)) {
        return null;
    }
    $data = tmdbGet($db, ($type === 'series' ? 'tv/' : 'movie/') . (int) $contentId, [], 3600);
    return $data === null ? null : unreleasedFromTmdb($data, $type, date('Y-m-d'));
}

// Saf karar: TMDB detay yanıtı + bugünün tarihi -> yayınlanmamışsa ['date' => ...]. (tests/UnreleasedTest.php)
function unreleasedFromTmdb(array $data, string $type, string $today): ?array {
    $date = (string) ($data[$type === 'series' ? 'first_air_date' : 'release_date'] ?? '');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return $date > $today ? ['date' => $date] : null;
    }
    // Tarihi olmayan yapım, henüz üretim aşamasındaysa yayınlanmamış sayılır.
    $status = (string) ($data['status'] ?? '');
    return in_array($status, ['Rumored', 'Planned', 'In Production', 'Post Production'], true) ? ['date' => null] : null;
}

function unreleasedMessage(array $info, string $what): string {
    $when = $info['date'] !== null ? ' (' . date('d.m.Y', strtotime($info['date'])) . ')' : '';
    return "Bu yapım henüz yayınlanmadı$when. Çıkış tarihinden sonra $what.";
}
