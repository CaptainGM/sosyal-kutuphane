<?php
require_once 'config.php';

// Tarayıcı artık TMDB'ye doğrudan gitmiyor — bu uç nokta üzerinden geçiyor ki
// (a) aynı veri tüm ziyaretçiler arasında Atlas'ta paylaşılan bir önbellekten
// sunulsun, (b) TMDB anahtarı istemci tarafında görünmesin.
$endpoint = $_GET['endpoint'] ?? null;
$type = ($_GET['type'] ?? 'movie') === 'series' ? 'series' : 'movie';
$tmdbType = $type === 'series' ? 'tv' : 'movie';

$apiKey = getenv('TMDB_API_KEY');
if (!$apiKey) {
    jsonResponse(['success' => false, 'message' => 'TMDB_API_KEY tanımlı değil (.env dosyasını kontrol edin)'], 500);
}

function tmdbFetch(string $url) {
    $context = stream_context_create(['http' => ['timeout' => 8, 'ignore_errors' => true]]);
    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        return null;
    }
    return json_decode($response, true);
}

// TMDB hata yanıtları {"success":false,...} şeklinde döner — bunları önbelleğe
// yazmıyoruz, yoksa geçici bir hata TTL süresince herkese aynı şekilde dönmeye devam eder.
function isTmdbError($data) {
    return isset($data['success']) && $data['success'] === false;
}

if ($endpoint === 'search') {
    $query = trim($_GET['query'] ?? '');
    $page = max(1, min(20, (int) ($_GET['page'] ?? 1)));
    if ($query === '') {
        jsonResponse(['results' => []]);
    }

    $cacheKey = "tmdb:$type:search:" . mb_strtolower($query) . ":$page";
    $cached = cacheGet($db, $cacheKey);
    if ($cached !== null) {
        jsonResponse($cached);
    }

    $url = "https://api.themoviedb.org/3/search/$tmdbType?api_key=$apiKey&query=" . urlencode($query) . "&language=tr-TR&page=$page";
    $data = tmdbFetch($url);
    if ($data === null) {
        jsonResponse(['results' => []]);
    }
    if (!isTmdbError($data)) {
        cacheSet($db, $cacheKey, $data, 3600); // 1 saat — arama sonuçları sık değişmez
    }
    jsonResponse($data);

} elseif ($endpoint === 'detail') {
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) {
        jsonResponse(['success' => false, 'message' => 'id gerekli'], 400);
    }

    $cacheKey = "tmdb:$type:detail:$id";
    $cached = cacheGet($db, $cacheKey);
    if ($cached !== null) {
        jsonResponse($cached);
    }

    $url = "https://api.themoviedb.org/3/$tmdbType/$id?api_key=$apiKey&language=tr-TR&append_to_response=credits";
    $data = tmdbFetch($url);
    if ($data === null) {
        jsonResponse(['success' => false, 'message' => 'İçerik alınamadı'], 502);
    }
    if (!isTmdbError($data)) {
        cacheSet($db, $cacheKey, $data, 86400); // 24 saat — detay bilgisi nadiren değişir
    }
    jsonResponse($data);

} elseif ($endpoint === 'list') {
    $list = in_array($_GET['list'] ?? '', ['top_rated', 'popular'], true) ? $_GET['list'] : 'popular';
    // "Tüm Filmler/Diziler" bölümü bu uç noktayı birkaç sayfa için çağırır —
    // eskiden istemci tarafında 100 sayfa (≈2000 içerik) çekiliyordu, burada
    // sabit bir üst sınırla (5 sayfa ≈ 100 içerik) sınırlandırıldı.
    $page = max(1, min(5, (int) ($_GET['page'] ?? 1)));

    $cacheKey = "tmdb:$type:$list:$page";
    $cached = cacheGet($db, $cacheKey);
    if ($cached !== null) {
        jsonResponse($cached);
    }

    $url = "https://api.themoviedb.org/3/$tmdbType/$list?api_key=$apiKey&language=tr-TR&page=$page";
    $data = tmdbFetch($url);
    if ($data === null) {
        jsonResponse(['results' => []]);
    }
    if (!isTmdbError($data)) {
        cacheSet($db, $cacheKey, $data, 21600); // 6 saat — popüler/en yüksek puanlı listeler yavaş değişir
    }
    jsonResponse($data);

} else {
    jsonResponse(['success' => false, 'message' => 'Geçersiz endpoint'], 400);
}
?>
