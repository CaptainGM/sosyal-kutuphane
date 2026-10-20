<?php
// Yeni çıkanlar: Türkiye sinema vizyon takvimi (TMDB discover). Saf fonksiyonlar, ağ ve veritabanı yok: tests/UpcomingTest.php çağırır.
// TMDB'nin "upcoming" ucu Türkiye için yalnızca önümüzdeki ~3 haftayı verir (Aralık'taki filmler görünmezdi); discover ucu
// bölgeye göre vizyon tarihi aralığı ve sinema gösterim türü (2: sınırlı, 3: geniş vizyon) ile bütün takvimi döndürür.

const UPCOMING_PER_PAGE = 20;
const UPCOMING_TMDB_PAGES = 5;
const UPCOMING_DAYS_AHEAD = 240;

function upcomingDiscoverQuery(string $apiKey, string $today, int $page): array {
    return [
        'api_key' => $apiKey,
        'language' => 'tr-TR',
        'region' => 'TR',
        'with_release_type' => '2|3',
        'include_adult' => 'false',
        // En bilinenler önce gelir: sayfa sınırı yüzünden kesilen kuyruk, bilinmeyen filmlerden oluşur.
        'sort_by' => 'popularity.desc',
        'release_date.gte' => $today,
        'release_date.lte' => date('Y-m-d', strtotime($today . ' +' . UPCOMING_DAYS_AHEAD . ' days')),
        'page' => max(1, min(UPCOMING_TMDB_PAGES, $page)),
    ];
}

// TMDB sonuçlarını arayüzün kullandığı biçime çevirir; yinelenenleri atar, vizyon tarihine göre sıralar.
// Aynı gündekiler popülerlik sırasını korur (sıralama kararlıdır).
function upcomingCalendar(array $results): array {
    $items = [];
    foreach ($results as $movie) {
        if (!is_array($movie) || empty($movie['id']) || isset($items[(int) $movie['id']])) {
            continue;
        }
        $items[(int) $movie['id']] = [
            'tmdb_id' => (int) $movie['id'],
            'title' => (string) ($movie['title'] ?? ''),
            'release_date' => ($movie['release_date'] ?? '') !== '' ? $movie['release_date'] : null,
            'poster_path' => $movie['poster_path'] ?? null,
            'overview' => mb_substr((string) ($movie['overview'] ?? ''), 0, 180),
            'vote_average' => isset($movie['vote_average']) ? round((float) $movie['vote_average'], 1) : null,
        ];
    }
    $items = array_values($items);
    // Tarihi olmayanlar sona gider.
    usort($items, static fn (array $a, array $b): int => strcmp($a['release_date'] ?? '9999-12-31', $b['release_date'] ?? '9999-12-31'));
    return $items;
}

// Takvimden istenen sayfayı keser. Dönüş: [öğeler, geçerli sayfa, toplam sayfa].
function upcomingPage(array $calendar, int $page): array {
    $totalPages = max(1, (int) ceil(count($calendar) / UPCOMING_PER_PAGE));
    $page = max(1, min($totalPages, $page));
    return [array_slice($calendar, ($page - 1) * UPCOMING_PER_PAGE, UPCOMING_PER_PAGE), $page, $totalPages];
}
