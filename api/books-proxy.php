<?php
require_once 'config.php';

// tmdb-proxy.php ile aynı gerekçe: Google Books istekleri artık tarayıcıdan
// değil bu uç noktadan geçiyor ki aynı arama/kategori sonuçları Atlas'ta
// önbelleklenip tüm ziyaretçiler arasında paylaşılsın.
$action = $_GET['action'] ?? null;

function googleBooksFetch(string $url) {
    // Anahtarsız istekler Google'ın paylaşımlı/anonim günlük kotasına tabi ve bu
    // kota oldukça düşük — GOOGLE_BOOKS_API_KEY .env'de tanımlıysa istekleri güçlendirir
    // (Google Cloud Console'dan ücretsiz alınabilir, Books API'yi etkinleştirip anahtar oluşturmak yeterli).
    $apiKey = getenv('GOOGLE_BOOKS_API_KEY');
    if ($apiKey) {
        $url .= '&key=' . urlencode($apiKey);
    }
    $context = stream_context_create(['http' => ['timeout' => 8, 'ignore_errors' => true]]);
    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        return null;
    }
    return json_decode($response, true);
}

if ($action === 'search') {
    $q = trim($_GET['q'] ?? '');
    if ($q === '') {
        jsonResponse(['items' => []]);
    }
    $maxResults = max(1, min(40, (int) ($_GET['maxResults'] ?? 20)));
    $orderBy = in_array($_GET['orderBy'] ?? '', ['relevance', 'newest'], true) ? $_GET['orderBy'] : 'relevance';
    $startIndex = max(0, (int) ($_GET['startIndex'] ?? 0));
    $langRestrict = preg_match('/^[a-z]{2}$/', $_GET['langRestrict'] ?? '') ? $_GET['langRestrict'] : '';

    $cacheKey = "books:search:$q:$maxResults:$orderBy:$startIndex:$langRestrict";
    $cached = cacheGet($db, $cacheKey);
    if ($cached !== null) {
        jsonResponse($cached);
    }

    $url = 'https://www.googleapis.com/books/v1/volumes?q=' . urlencode($q)
        . "&maxResults=$maxResults&orderBy=$orderBy&startIndex=$startIndex"
        . ($langRestrict ? "&langRestrict=$langRestrict" : '');
    $data = googleBooksFetch($url);
    if ($data === null) {
        jsonResponse(['items' => []]);
    }
    cacheSet($db, $cacheKey, $data, 3600); // 1 saat
    jsonResponse($data);

} elseif ($action === 'detail') {
    $id = $_GET['id'] ?? '';
    if ($id === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $id)) {
        jsonResponse(['success' => false, 'message' => 'Geçersiz id'], 400);
    }

    $cacheKey = "books:detail:$id";
    $cached = cacheGet($db, $cacheKey);
    if ($cached !== null) {
        jsonResponse($cached);
    }

    $url = 'https://www.googleapis.com/books/v1/volumes/' . urlencode($id);
    $data = googleBooksFetch($url);
    if ($data === null) {
        jsonResponse(['success' => false, 'message' => 'Kitap alınamadı'], 502);
    }
    cacheSet($db, $cacheKey, $data, 86400); // 24 saat
    jsonResponse($data);

} else {
    jsonResponse(['success' => false, 'message' => 'Geçersiz action'], 400);
}
?>
