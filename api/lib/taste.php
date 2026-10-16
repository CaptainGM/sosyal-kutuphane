<?php
// Zevk eşleşmesi: iki kullanıcının kütüphanesini karşılaştırır.
// Puan ve rapor saf fonksiyonlardır (veritabanı yok, tests/TasteTest.php doğrudan çağırır);
// kütüphaneyi okuyan kısım tasteLibraries()'dir.
//
// Kütüphane öğesi: ['key' => 'movie:12', 'type' => 'movie', 'content_id' => 27205, 'title' => '...', 'poster' => url|null,
//                   'rating' => 1..10|null, 'status' => 'watched', 'genres' => ['Dram', ...]]

const TASTE_FULL_CONFIDENCE_SHARED = 5;
const TASTE_WATCHLIST_STATUSES = ['watchlist', 'readinglist', 'to_read'];

function tasteLabel(?int $score): ?string {
    if ($score === null) {
        return null;
    }
    if ($score >= 90) {
        return 'Zevk ikizleri';
    }
    if ($score >= 75) {
        return 'Çok uyumlu';
    }
    if ($score >= 55) {
        return 'Uyumlu';
    }
    if ($score >= 35) {
        return 'Karışık';
    }
    return 'Zıt kutuplar';
}

function tasteIndex(array $items): array {
    $indexed = [];
    foreach ($items as $item) {
        $indexed[$item['key']] = $item;
    }
    return $indexed;
}

function tasteGenreCounts(array $items): array {
    $counts = [];
    foreach ($items as $item) {
        foreach ($item['genres'] as $genre) {
            if (is_string($genre) && $genre !== '') {
                $counts[$genre] = ($counts[$genre] ?? 0) + 1;
            }
        }
    }
    return $counts;
}

function tasteBrief(array $item): array {
    return ['type' => $item['type'], 'content_id' => $item['content_id'], 'title' => $item['title'], 'poster' => $item['poster']];
}

// Raporun tamamı. Skor: ortak puanlanmış içeriklerde puan yakınlığı (ağırlıklı) ve tür benzerliği.
// Az ortak içerikte puan 50'ye doğru çekilir, böylece tek bir ortak film "zevk ikizi" yaptırmaz.
function tasteReport(array $mineItems, array $theirItems): array {
    $mine = tasteIndex($mineItems);
    $theirs = tasteIndex($theirItems);
    $sharedKeys = array_keys(array_intersect_key($mine, $theirs));

    $agreements = [];
    $pairs = [];
    foreach ($sharedKeys as $key) {
        $m = $mine[$key];
        $t = $theirs[$key];
        if ($m['rating'] !== null && $t['rating'] !== null) {
            $diff = abs($m['rating'] - $t['rating']);
            $agreements[] = 1 - min(9, $diff) / 9;
            $pairs[] = ['item' => tasteBrief($m), 'mine' => $m['rating'], 'theirs' => $t['rating'], 'diff' => $diff];
        }
    }
    $sharedRated = count($agreements);

    $myGenres = tasteGenreCounts($mineItems);
    $theirGenres = tasteGenreCounts($theirItems);
    $commonGenreNames = array_keys(array_intersect_key($myGenres, $theirGenres));
    $unionCount = count(array_unique(array_merge(array_keys($myGenres), array_keys($theirGenres))));
    $jaccard = $unionCount > 0 ? count($commonGenreNames) / $unionCount : null;

    usort($commonGenreNames, static function (string $a, string $b) use ($myGenres, $theirGenres): int {
        return min($myGenres[$b], $theirGenres[$b]) <=> min($myGenres[$a], $theirGenres[$a]) ?: strcmp($a, $b);
    });

    $agreement = $sharedRated > 0 ? array_sum($agreements) / $sharedRated : null;
    if ($agreement !== null) {
        $weight = min(1, $sharedRated / TASTE_FULL_CONFIDENCE_SHARED);
        $ratingPart = $weight * $agreement + (1 - $weight) * 0.5;
        $value = $jaccard !== null ? 0.7 * $ratingPart + 0.3 * $jaccard : $ratingPart;
        $basis = 'ratings';
    } elseif ($jaccard !== null && count($commonGenreNames) > 0) {
        $value = 0.8 * $jaccard;
        $basis = 'genres';
    } else {
        $value = null;
        $basis = 'none';
    }
    $score = $value === null ? null : (int) round(max(0, min(1, $value)) * 100);

    $loved = array_values(array_filter($pairs, static fn (array $p): bool => $p['mine'] >= 8 && $p['theirs'] >= 8));
    usort($loved, static fn (array $a, array $b): int => ($b['mine'] + $b['theirs']) <=> ($a['mine'] + $a['theirs']));
    $disagreements = array_values(array_filter($pairs, static fn (array $p): bool => $p['diff'] >= 4));
    usort($disagreements, static fn (array $a, array $b): int => $b['diff'] <=> $a['diff']);

    // İkisinin de izleme listesinde olanlar: birlikte izlenebilir.
    $watchTogether = [];
    foreach ($sharedKeys as $key) {
        if (in_array($mine[$key]['status'], TASTE_WATCHLIST_STATUSES, true) && in_array($theirs[$key]['status'], TASTE_WATCHLIST_STATUSES, true)) {
            $watchTogether[] = tasteBrief($mine[$key]);
        }
    }

    // Biri çok sevmiş, diğeri kütüphanesine hiç eklememiş: birbirlerine öneri.
    $theyLoved = [];
    foreach ($theirs as $key => $item) {
        if ($item['rating'] !== null && $item['rating'] >= 8 && !isset($mine[$key])) {
            $theyLoved[] = ['item' => tasteBrief($item), 'rating' => $item['rating']];
        }
    }
    usort($theyLoved, static fn (array $a, array $b): int => $b['rating'] <=> $a['rating']);
    $youLoved = [];
    foreach ($mine as $key => $item) {
        if ($item['rating'] !== null && $item['rating'] >= 8 && !isset($theirs[$key])) {
            $youLoved[] = ['item' => tasteBrief($item), 'rating' => $item['rating']];
        }
    }
    usort($youLoved, static fn (array $a, array $b): int => $b['rating'] <=> $a['rating']);

    $confidence = $sharedRated >= TASTE_FULL_CONFIDENCE_SHARED ? 'high' : ($sharedRated >= 2 ? 'medium' : 'low');

    return [
        'score' => $score,
        'label' => tasteLabel($score),
        'basis' => $basis,
        'confidence' => $confidence,
        'shared_total' => count($sharedKeys),
        'shared_rated' => $sharedRated,
        'agreement' => $agreement === null ? null : (int) round($agreement * 100),
        'genre_overlap' => $jaccard === null ? null : (int) round($jaccard * 100),
        'common_genres' => array_slice($commonGenreNames, 0, 6),
        'loved_together' => array_slice($loved, 0, 6),
        'disagreements' => array_slice($disagreements, 0, 4),
        'watch_together' => array_slice($watchTogether, 0, 6),
        'they_loved_you_havent' => array_slice($theyLoved, 0, 6),
        'you_loved_they_havent' => array_slice($youLoved, 0, 6),
        'my_count' => count($mineItems),
        'their_count' => count($theirItems),
    ];
}

// Detay sayfası türleri TMDB kimliği (sayı) olarak kaydeder; karşılaştırma ve gösterim için Türkçe adlara çevrilir.
const TASTE_GENRE_NAMES = [
    28 => 'Aksiyon', 12 => 'Macera', 16 => 'Animasyon', 35 => 'Komedi', 80 => 'Suç', 99 => 'Belgesel', 18 => 'Dram',
    10751 => 'Aile', 14 => 'Fantastik', 36 => 'Tarih', 27 => 'Korku', 10402 => 'Müzik', 9648 => 'Gizem',
    10749 => 'Romantik', 878 => 'Bilim-Kurgu', 10770 => 'TV Filmi', 53 => 'Gerilim', 10752 => 'Savaş', 37 => 'Western',
    10759 => 'Aksiyon & Macera', 10762 => 'Çocuk', 10763 => 'Haber', 10764 => 'Reality', 10765 => 'Bilim Kurgu & Fantazi',
    10766 => 'Pembe Dizi', 10767 => 'Talk Show', 10768 => 'Savaş & Politika',
];

function tasteGenreNames($genres): array {
    $names = [];
    if (is_iterable($genres)) {
        foreach ($genres as $genre) {
            if (is_int($genre) || (is_string($genre) && ctype_digit($genre))) {
                $known = TASTE_GENRE_NAMES[(int) $genre] ?? null;
                if ($known !== null) {
                    $names[] = $known;
                }
            } elseif (is_string($genre)) {
                $names[] = $genre;
            } elseif (is_object($genre) || is_array($genre)) {
                $name = is_array($genre) ? ($genre['name'] ?? null) : ($genre->name ?? null);
                if (is_string($name)) {
                    $names[] = $name;
                }
            }
        }
    }
    return array_values(array_unique($names));
}

function tasteImageUrl(string $type, $path): ?string {
    if (!is_string($path) || $path === '') {
        return null;
    }
    if ($type === 'book') {
        return preg_replace('~^http://~', 'https://', $path);
    }
    return $path[0] === '/' ? 'https://image.tmdb.org/t/p/w185' . $path : null;
}

// Birden çok kullanıcının kütüphanesini üç durum koleksiyonundan okur: [kullanıcı kimliği => [öğe, ...]].
function tasteLibraries($db, array $userIds): array {
    $libraries = [];
    foreach ($userIds as $id) {
        $libraries[(int) $id] = [];
    }
    if (!$userIds) {
        return $libraries;
    }

    $definitions = [
        ['type' => 'movie', 'status' => 'user_movie_status', 'id' => 'movie_id', 'content' => 'movies', 'genres' => 'genres', 'public' => 'tmdb_id', 'poster' => 'poster_path'],
        ['type' => 'series', 'status' => 'user_series_status', 'id' => 'series_id', 'content' => 'series', 'genres' => 'genres', 'public' => 'tmdb_id', 'poster' => 'poster_path'],
        ['type' => 'book', 'status' => 'user_book_status', 'id' => 'book_id', 'content' => 'books', 'genres' => 'categories', 'public' => 'google_books_id', 'poster' => 'cover_url'],
    ];

    foreach ($definitions as $def) {
        $rows = iterator_to_array($db->{$def['status']}->find(['user_id' => ['$in' => array_values(array_map('intval', $userIds))]]), false);
        if (!$rows) {
            continue;
        }

        $contentIds = array_values(array_unique(array_map(static fn ($row) => $row[$def['id']], $rows)));
        $docs = [];
        foreach ($db->{$def['content']}->find(['_id' => ['$in' => $contentIds]]) as $doc) {
            $docs[$doc['_id']] = $doc;
        }

        foreach ($rows as $row) {
            $doc = $docs[$row[$def['id']]] ?? null;
            if ($doc === null) {
                continue;
            }
            $libraries[(int) $row['user_id']][] = [
                'key' => $def['type'] . ':' . $row[$def['id']],
                'type' => $def['type'],
                'content_id' => $doc[$def['public']] ?? null,
                'title' => (string) ($doc['title'] ?? ''),
                'poster' => tasteImageUrl($def['type'], $doc[$def['poster']] ?? null),
                'rating' => isset($row['rating']) && $row['rating'] !== null ? (int) $row['rating'] : null,
                'status' => (string) ($row['status'] ?? ''),
                'genres' => tasteGenreNames($doc[$def['genres']] ?? null),
            ];
        }
    }

    return $libraries;
}
