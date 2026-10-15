<?php
require_once 'config.php';

// TMDB istekleri tarayıcıdan değil buradan geçer: ortak önbellek + gizli API anahtarı.
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

// Hata yanıtlarını önbelleğe yazma, yoksa geçici bir hata TTL boyunca herkese döner.
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

    // credits, fragmanlar (videos) ve Nerede İzlenir (watch/providers) tek istekte çekilir
    $url = "https://api.themoviedb.org/3/$tmdbType/$id?api_key=$apiKey&language=tr-TR&append_to_response=credits,videos,watch/providers&include_video_language=tr,en";
    $data = tmdbFetch($url);
    if ($data === null) {
        jsonResponse(['success' => false, 'message' => 'İçerik alınamadı'], 502);
    }
    // Türkçe fragman bulunamadıysa İngilizce fragmanları da yedek olarak sorgula
    if (empty($data['videos']['results'])) {
        $enVideos = tmdbFetch("https://api.themoviedb.org/3/$tmdbType/$id/videos?api_key=$apiKey&language=en-US");
        if (!empty($enVideos['results'])) {
            $data['videos']['results'] = $enVideos['results'];
        }
    }
    if (!isTmdbError($data)) {
        cacheSet($db, $cacheKey, $data, 86400); // 24 saat
    }
    jsonResponse($data);

} elseif ($endpoint === 'similar') {
    // Detay sayfasındaki "Benzer İçerikler" şeridi.
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) {
        jsonResponse(['success' => false, 'message' => 'id gerekli'], 400);
    }

    $cacheKey = "tmdb:$type:similar:$id";
    $cached = cacheGet($db, $cacheKey);
    if ($cached !== null) {
        jsonResponse($cached);
    }

    $url = "https://api.themoviedb.org/3/$tmdbType/$id/similar?api_key=$apiKey&language=tr-TR&page=1";
    $data = tmdbFetch($url);
    if ($data === null) {
        jsonResponse(['results' => []]);
    }
    if (!isTmdbError($data)) {
        cacheSet($db, $cacheKey, $data, 86400); // 24 saat
    }
    jsonResponse($data);

} elseif ($endpoint === 'list') {
    $list = in_array($_GET['list'] ?? '', ['top_rated', 'popular'], true) ? $_GET['list'] : 'popular';
    $page = max(1, min(5, (int) ($_GET['page'] ?? 1))); // en fazla 5 sayfa (~100 içerik)

    $cacheKey = "tmdb:$type:$list:$page";
    $cached = cacheGet($db, $cacheKey);
    if ($cached !== null) {
        jsonResponse($cached);
    }

    if ($list === 'top_rated') {
        if ($type === 'series') {
            // TMDB tv/top_rated az oy alan veya çıkmamış dizileri öne çıkardığı için güvenilir filtreli discover kullanılır
            $url = "https://api.themoviedb.org/3/discover/tv?api_key=$apiKey&language=tr-TR&sort_by=vote_average.desc&vote_count.gte=1500&first_air_date.lte=2026-01-01&page=$page";
        } else {
            // Filmlerde de sahte/düşük oylu içerikleri eleyip kült başyapıtları (Esaretin Bedeli, Baba, Kara Şövalye vb.) listeler
            $url = "https://api.themoviedb.org/3/discover/movie?api_key=$apiKey&language=tr-TR&sort_by=vote_average.desc&vote_count.gte=2000&page=$page";
        }
    } else {
        $url = "https://api.themoviedb.org/3/$tmdbType/$list?api_key=$apiKey&language=tr-TR&page=$page";
    }
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
