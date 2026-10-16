<?php
// Akşam seçici: süre ve ruh haline göre TMDB discover sorgusu kurar ve adaylardan bir öneri seçer.
// Her şey saf fonksiyondur (ağ ve veritabanı yok): tests/PickerTest.php doğrudan çağırır.

// Ruh hali -> TMDB tür kimlikleri (film ve dizi türleri TMDB'de ayrı listelenir).
const PICKER_MOODS = [
    'heyecan' => ['label' => 'Heyecanlı', 'movie' => [28, 12], 'tv' => [10759]],
    'gerilim' => ['label' => 'Gerilim', 'movie' => [53, 9648], 'tv' => [9648, 80]],
    'komedi' => ['label' => 'Eğlenceli', 'movie' => [35], 'tv' => [35]],
    'duygusal' => ['label' => 'Duygusal', 'movie' => [18, 10749], 'tv' => [18]],
    'bilimkurgu' => ['label' => 'Bilim kurgu', 'movie' => [878, 14], 'tv' => [10765]],
    'korku' => ['label' => 'Korku', 'movie' => [27], 'tv' => [9648]],
    'aile' => ['label' => 'Aile', 'movie' => [10751, 16], 'tv' => [10751, 16]],
    'gercek' => ['label' => 'Gerçek hikâye', 'movie' => [99, 36], 'tv' => [99]],
];

// Süre sınırları dakika: [en az, en çok]. Dizilerde bölüm süresi kastedilir.
const PICKER_DURATIONS = [
    'short' => ['label' => 'Kısa', 'movie' => [null, 100], 'tv' => [null, 30]],
    'medium' => ['label' => 'Orta', 'movie' => [100, 140], 'tv' => [30, 50]],
    'long' => ['label' => 'Uzun', 'movie' => [140, null], 'tv' => [50, null]],
    'any' => ['label' => 'Fark etmez', 'movie' => [null, null], 'tv' => [null, null]],
];

// İstemciden gelen değerleri doğrular ve güvenli bir parametre dizisine çevirir. Bilinmeyen ruh halleri atılır.
function pickerNormalizeParams(array $input): array {
    $kind = ($input['kind'] ?? 'movie') === 'series' ? 'series' : 'movie';
    $duration = isset($input['duration']) && is_string($input['duration']) && isset(PICKER_DURATIONS[$input['duration']])
        ? $input['duration'] : 'any';

    $moods = [];
    $rawMoods = $input['moods'] ?? [];
    if (is_string($rawMoods)) {
        $rawMoods = explode(',', $rawMoods);
    }
    if (is_array($rawMoods)) {
        foreach ($rawMoods as $mood) {
            if (is_string($mood) && isset(PICKER_MOODS[$mood]) && !in_array($mood, $moods, true)) {
                $moods[] = $mood;
            }
        }
    }

    $exclude = [];
    $rawExclude = $input['exclude'] ?? [];
    if (is_string($rawExclude)) {
        $rawExclude = explode(',', $rawExclude);
    }
    if (is_array($rawExclude)) {
        foreach ($rawExclude as $id) {
            if (is_numeric($id) && (int) $id > 0) {
                $exclude[] = (int) $id;
            }
        }
    }

    return [
        'kind' => $kind,
        'duration' => $duration,
        'moods' => array_slice($moods, 0, 3),
        'exclude' => array_slice(array_values(array_unique($exclude)), 0, 60),
        'seed' => isset($input['seed']) && is_numeric($input['seed']) ? (int) $input['seed'] : 0,
    ];
}

// TMDB discover sorgusu. Aynı ruh hallerindeki türler "veya" ile birleşir (pipe), kalite eşiği kötü önerileri eler.
function pickerDiscoverQuery(array $params, int $page = 1): array {
    $isSeries = $params['kind'] === 'series';
    $typeKey = $isSeries ? 'tv' : 'movie';

    $genreIds = [];
    foreach ($params['moods'] as $mood) {
        foreach (PICKER_MOODS[$mood][$typeKey] as $id) {
            $genreIds[$id] = true;
        }
    }

    $query = [
        'sort_by' => 'popularity.desc',
        'include_adult' => 'false',
        'page' => max(1, min(5, $page)),
        'vote_average.gte' => $isSeries ? 7.0 : 6.5,
        'vote_count.gte' => $isSeries ? 300 : 500,
    ];
    if ($genreIds) {
        $query['with_genres'] = implode('|', array_keys($genreIds));
    }

    [$min, $max] = PICKER_DURATIONS[$params['duration']][$typeKey];
    if ($min !== null) {
        $query['with_runtime.gte'] = $min;
    }
    if ($max !== null) {
        $query['with_runtime.lte'] = $max;
    }

    return ['path' => $isSeries ? 'discover/tv' : 'discover/movie', 'query' => $query];
}

// Aynı tohum aynı karışımı verir (testlerde ve "başka öner" akışında tekrarlanabilir).
function pickerShuffle(array $items, int $seed): array {
    mt_srand($seed);
    for ($i = count($items) - 1; $i > 0; $i--) {
        $j = mt_rand(0, $i);
        [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
    }
    return array_values($items);
}

// Adaylar TMDB sonuç öğeleridir. Afişsiz ve dışlananlar elenir; ilk öneri, sonraki ikisi alternatiftir.
// "reel" çark animasyonu için karışık afiş yollarıdır.
function pickerChoose(array $results, array $excludeIds, int $seed): array {
    $excluded = array_flip($excludeIds);
    $candidates = [];
    foreach ($results as $item) {
        if (!is_array($item) || empty($item['id']) || empty($item['poster_path']) || isset($excluded[(int) $item['id']])) {
            continue;
        }
        $candidates[] = $item;
    }

    $shuffled = pickerShuffle($candidates, $seed);
    return [
        'pick' => $shuffled[0] ?? null,
        'alternatives' => array_slice($shuffled, 1, 2),
        'reel' => array_values(array_filter(array_map(
            static fn (array $item): ?string => $item['poster_path'] ?? null,
            array_slice($shuffled, 0, 14)
        ))),
        'candidate_count' => count($candidates),
    ];
}

// Önerinin neden seçildiğini tek cümleyle açıklar (arayüzde "Neden bu?" satırı).
function pickerReason(array $params, array $item, ?int $runtime): string {
    $parts = [];
    if ($runtime) {
        $parts[] = $runtime . ' dk' . ($params['kind'] === 'series' ? ' bölüm' : '');
    }
    if (!empty($item['vote_average'])) {
        $parts[] = number_format((float) $item['vote_average'], 1) . ' puan';
    }
    $labels = array_map(static fn (string $mood): string => PICKER_MOODS[$mood]['label'], $params['moods']);
    if ($labels) {
        $parts[] = implode(' / ', $labels);
    }
    return $parts ? implode(' · ', $parts) : 'Popüler ve beğenilen bir yapım';
}
