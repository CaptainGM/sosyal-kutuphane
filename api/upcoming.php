<?php
// Herkese açık ve oturumsuz bir uç: oturum açılmaz, yanıt önbelleğe alınabilir.
define('SK_NO_SESSION', true);
require_once 'config.php';
require_once __DIR__ . '/lib/upcoming.php';
publicCache();

// Türkiye sinema vizyon takvimi (TMDB discover, bkz. lib/upcoming.php). Bütün takvim bir kez alınıp 6 saat önbelleklenir,
// sayfalama burada yapılır. Takvim eksik kaldıysa (TMDB sayfalarından biri yanıt vermediyse) yalnızca 10 dakika tutulur,
// böylece geçici bir hata uzun süre görünmez.
$apiKey = getenv('TMDB_API_KEY');
if (!$apiKey) {
    jsonResponse(['success' => false, 'message' => 'TMDB_API_KEY tanımlı değil'], 500);
}

$cacheKey = 'upcoming:tr:v2';
$cached = cacheGet($db, $cacheKey);
if ($cached === null) {
    $today = date('Y-m-d');
    $context = stream_context_create(['http' => ['timeout' => 8, 'ignore_errors' => true]]);
    $results = [];
    $complete = true;
    for ($tmdbPage = 1; $tmdbPage <= UPCOMING_TMDB_PAGES; $tmdbPage++) {
        $response = @file_get_contents('https://api.themoviedb.org/3/discover/movie?' . http_build_query(upcomingDiscoverQuery($apiKey, $today, $tmdbPage)), false, $context);
        $data = $response === false ? null : json_decode($response, true);
        if (!is_array($data) || !isset($data['results']) || !is_array($data['results'])) {
            if ($tmdbPage === 1) {
                jsonResponse(['success' => false, 'message' => 'Yeni çıkanlar şu an alınamıyor'], 502);
            }
            $complete = false;
            break;
        }
        $results = array_merge($results, $data['results']);
        if ($tmdbPage >= (int) ($data['total_pages'] ?? 1)) {
            break;
        }
    }
    $cached = ['items' => upcomingCalendar($results)];
    cacheSet($db, $cacheKey, $cached, $complete ? 6 * 3600 : 600);
}

[$items, $page, $totalPages] = upcomingPage($cached['items'] ?? [], (int) ($_GET['page'] ?? 1));
jsonResponse(['success' => true, 'page' => $page, 'total_pages' => $totalPages, 'total' => count($cached['items'] ?? []), 'items' => $items]);
?>