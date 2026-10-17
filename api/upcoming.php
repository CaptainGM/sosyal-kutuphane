<?php
// Herkese açık ve oturumsuz bir uç: oturum açılmaz, yanıt önbelleğe alınabilir.
define('SK_NO_SESSION', true);
require_once 'config.php';
publicCache();

// Yakında vizyona girecek filmler (TMDB, Türkiye). Sonuçlar 6 saat önbelleklenir;
// hatalı yanıtlar önbelleğe yazılmaz, böylece geçici bir hata uzun süre görünmez.
$apiKey = getenv('TMDB_API_KEY');
if (!$apiKey) {
    jsonResponse(['success' => false, 'message' => 'TMDB_API_KEY tanımlı değil'], 500);
}

$page = max(1, min(5, (int) ($_GET['page'] ?? 1)));
$cacheKey = "upcoming:tr:$page";

$cached = cacheGet($db, $cacheKey);
if ($cached !== null) {
    jsonResponse(['success' => true, 'page' => $page] + $cached);
}

$url = 'https://api.themoviedb.org/3/movie/upcoming?' . http_build_query([
    'api_key' => $apiKey,
    'language' => 'tr-TR',
    'region' => 'TR',
    'page' => $page,
]);
$context = stream_context_create(['http' => ['timeout' => 8, 'ignore_errors' => true]]);
$response = @file_get_contents($url, false, $context);
$data = $response === false ? null : json_decode($response, true);

if (!is_array($data) || !isset($data['results']) || !is_array($data['results'])) {
    jsonResponse(['success' => false, 'message' => 'Yeni çıkanlar şu an alınamıyor'], 502);
}

$items = array_map(fn($movie) => [
    'tmdb_id' => (int) $movie['id'],
    'title' => (string) ($movie['title'] ?? ''),
    'release_date' => $movie['release_date'] ?? null,
    'poster_path' => $movie['poster_path'] ?? null,
    'overview' => mb_substr((string) ($movie['overview'] ?? ''), 0, 180),
    'vote_average' => isset($movie['vote_average']) ? round((float) $movie['vote_average'], 1) : null,
], array_filter($data['results'], fn($movie) => !empty($movie['id'])));
$items = array_values($items);

$payload = ['total_pages' => min(5, (int) ($data['total_pages'] ?? 1)), 'items' => $items];
cacheSet($db, $cacheKey, $payload, 6 * 3600);

jsonResponse(['success' => true, 'page' => $page] + $payload);
?>
