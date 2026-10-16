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
