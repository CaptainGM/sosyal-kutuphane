<?php
require_once 'config.php';
require_once __DIR__ . '/lib/search.php';
require_once __DIR__ . '/lib/providers.php';

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
// Az sonuçta yazım toleransı: sorgunun kısa önekiyle ek aday çekilir; yalnızca yakın eşleşmeler eklenir.
// Film araması boş kaldığında dizi arşivine düşme akışı bu yüzden bozulmaz (bkz. api/lib/search.php).
function tmdbFuzzyAugment($db, string $apiKey, string $tmdbType, string $type, string $query, array $results): array {
    $prefix = mb_substr($query, 0, max(3, intdiv(mb_strlen($query), 2)));
    $key = "tmdb:$type:search:" . mb_strtolower($prefix) . ":1";

    $candidates = cacheGet($db, $key);
    if ($candidates === null) {
        $url = "https://api.themoviedb.org/3/search/$tmdbType?api_key=$apiKey&query=" . urlencode($prefix) . "&language=tr-TR&page=1";
        $candidates = tmdbFetch($url);
        if (!is_array($candidates) || isTmdbError($candidates)) {
            return $results;
        }
        cacheSet($db, $key, $candidates, 3600);
    }

    $seen = array_flip(array_map(fn($item) => $item['id'] ?? null, $results));
    $fresh = array_values(array_filter(
        $candidates['results'] ?? [],
        fn($item) => is_array($item) && isset($item['id']) && !isset($seen[$item['id']])
    ));
    $close = rankFuzzyResults($fresh, $query, fn($item) => $item['title'] ?? $item['name'] ?? '');

    return array_slice(array_merge($results, $close), 0, 20);
}


if ($endpoint === 'search') {
    $query = trim($_GET['query'] ?? '');
    $page = max(1, min(20, (int) ($_GET['page'] ?? 1)));
    if ($query === '') {
        jsonResponse(['results' => []]);
    }

    $cacheKey = "tmdb:$type:search:v2:" . mb_strtolower($query) . ":$page"; // v2: bulanık eşleşme eklendi, eski girdiler kullanılmaz
    $cached = cacheGet($db, $cacheKey);
    if ($cached !== null) {
        jsonResponse($cached);
    }

    $url = "https://api.themoviedb.org/3/search/$tmdbType?api_key=$apiKey&query=" . urlencode($query) . "&language=tr-TR&page=$page";
    $data = tmdbFetch($url);
    if ($data === null) {
        jsonResponse(['results' => []]);
    }
    if (!isTmdbError($data) && $page === 1 && mb_strlen($query) >= 4 && count($data['results'] ?? []) < 5) {
        $data['results'] = tmdbFuzzyAugment($db, $apiKey, $tmdbType, $type, $query, $data['results'] ?? []);
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
            $url = "https://api.themoviedb.org/3/discover/tv?api_key=$apiKey&language=tr-TR&sort_by=vote_average.desc&vote_count.gte=1500&first_air_date.lte=" . date('Y-m-d') . "&page=$page";
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

} elseif ($endpoint === 'discover') {
    $page = max(1, min(20, (int) ($_GET['page'] ?? 1)));
    $year = trim($_GET['year'] ?? '');
    $genre = trim($_GET['genre'] ?? '');
    $minRating = (float) ($_GET['min_rating'] ?? 0);
    $sortByRaw = trim($_GET['sort_by'] ?? 'popularity');

    $params = [
        'api_key' => $apiKey,
        'language' => 'tr-TR',
        'page' => $page
    ];

    // Sıralama eşlemesi
    if ($sortByRaw === 'rating') {
        $params['sort_by'] = 'vote_average.desc';
        $params['vote_count.gte'] = 50; // anlamsız 1 oylu sahte içerikleri ele
    } elseif ($sortByRaw === 'year') {
        $params['sort_by'] = $type === 'series' ? 'first_air_date.desc' : 'primary_release_date.desc';
    } else {
        $params['sort_by'] = 'popularity.desc';
    }

    // Yıl filtresi
    if ($year !== '') {
        if ($type === 'series') {
            $params['first_air_date_year'] = (int) $year;
        } else {
            $params['primary_release_year'] = (int) $year;
        }
    }

    // Tür filtresi
    if ($genre !== '') {
        $params['with_genres'] = $genre;
    }

    // Yayın platformu filtresi (yalnızca abonelikle izlenebilenler)
    $params += streamingProviderParams($_GET['provider'] ?? '');

    // Minimum puan filtresi
    if ($minRating > 0) {
        $params['vote_average.gte'] = $minRating;
        if (!isset($params['vote_count.gte'])) {
            $params['vote_count.gte'] = 10;
        }
    }

    $cacheKey = "tmdb:$type:discover:" . md5(json_encode($params));
    $cached = cacheGet($db, $cacheKey);
    if ($cached !== null) {
        jsonResponse($cached);
    }

    $url = "https://api.themoviedb.org/3/discover/$tmdbType?" . http_build_query($params);
    $data = tmdbFetch($url);
    if ($data === null) {
        jsonResponse(['results' => [], 'total_pages' => 0, 'total_results' => 0]);
    }
    if (!isTmdbError($data)) {
        cacheSet($db, $cacheKey, $data, 7200); // 2 saat önbellek
    }
    jsonResponse($data);

} else {
    jsonResponse(['success' => false, 'message' => 'Geçersiz endpoint'], 400);
}
?>
