<?php
// Akşam seçici: süre ve ruh haline göre TMDB discover sorgusu kurar ve adaylardan bir öneri seçer.
// Her şey saf fonksiyondur (ağ ve veritabanı yok): tests/PickerTest.php doğrudan çağırır.

require_once __DIR__ . '/providers.php';

// Tür ya da ruh hali -> TMDB tür kimlikleri (film ve dizi türleri TMDB'de ayrı listelenir).
// Dizide karşılığı olmayan türlerin 'tv' listesi boştur: dizi aranırken o seçenek kullanılamaz (arayüz de gizler).
// 'heyecan' eski paylaşım bağlantıları için durur; arayüzde yerine Aksiyon ve Macera gösterilir.
const PICKER_MOODS = [
    'heyecan' => ['label' => 'Heyecanlı', 'movie' => [28, 12], 'tv' => [10759]],
    'aksiyon' => ['label' => 'Aksiyon', 'movie' => [28], 'tv' => [10759]],
    'macera' => ['label' => 'Macera', 'movie' => [12], 'tv' => [10759]],
    'komedi' => ['label' => 'Komedi', 'movie' => [35], 'tv' => [35]],
    'dram' => ['label' => 'Dram', 'movie' => [18], 'tv' => [18]],
    'duygusal' => ['label' => 'Duygusal', 'movie' => [18, 10749], 'tv' => [18]],
    'romantik' => ['label' => 'Romantik', 'movie' => [10749], 'tv' => []],
    'gerilim' => ['label' => 'Gerilim', 'movie' => [53, 9648], 'tv' => [9648, 80]],
    'gizem' => ['label' => 'Gizem', 'movie' => [9648], 'tv' => [9648]],
    'suc' => ['label' => 'Suç', 'movie' => [80], 'tv' => [80]],
    'korku' => ['label' => 'Korku', 'movie' => [27], 'tv' => [9648]],
    'bilimkurgu' => ['label' => 'Bilim kurgu', 'movie' => [878, 14], 'tv' => [10765]],
    'fantastik' => ['label' => 'Fantastik', 'movie' => [14], 'tv' => [10765]],
    'animasyon' => ['label' => 'Animasyon', 'movie' => [16], 'tv' => [16]],
    'aile' => ['label' => 'Aile', 'movie' => [10751, 16], 'tv' => [10751, 16]],
    'savas' => ['label' => 'Savaş', 'movie' => [10752], 'tv' => [10768]],
    'tarih' => ['label' => 'Tarih', 'movie' => [36], 'tv' => []],
    'muzik' => ['label' => 'Müzikal', 'movie' => [10402], 'tv' => []],
    'western' => ['label' => 'Western', 'movie' => [37], 'tv' => [37]],
    'gercek' => ['label' => 'Gerçek hikâye', 'movie' => [99, 36], 'tv' => [99]],
];

// Süre sınırları dakika: [en az, en çok]. Dizilerde bölüm süresi kastedilir.
const PICKER_DURATIONS = [
    'short' => ['label' => 'Kısa', 'movie' => [null, 100], 'tv' => [null, 30]],
    'medium' => ['label' => 'Orta', 'movie' => [100, 140], 'tv' => [30, 50]],
    'long' => ['label' => 'Uzun', 'movie' => [140, null], 'tv' => [50, null]],
    'any' => ['label' => 'Fark etmez', 'movie' => [null, null], 'tv' => [null, null]],
];

// Çıkış dönemi: çok eski yapımları ayıklamak için. Varsayılan "2000 ve sonrası"dır; "classic" 2000 öncesini ister.
const PICKER_ERAS = [
    'any' => 'Hepsi',
    'recent' => 'Son 5 yıl',
    'modern' => '2000 ve sonrası',
    'classic' => 'Klasikler',
];
const PICKER_DEFAULT_ERA = 'modern';
// En düşük TMDB puanı (0 = türün taban eşiği). Orijinal dil: boş = fark etmez.
const PICKER_RATINGS = [0, 7, 8];
const PICKER_LANGUAGES = ['tr', 'en'];

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
            if (!is_string($mood) || !isset(PICKER_MOODS[$mood]) || in_array($mood, $moods, true)) {
                continue;
            }
            // Dizilerde karşılığı olmayan türler (romantik, tarih, müzikal) atılır.
            if ($kind === 'series' && !PICKER_MOODS[$mood]['tv']) {
                continue;
            }
            $moods[] = $mood;
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

    $era = isset($input['era']) && is_string($input['era']) && isset(PICKER_ERAS[$input['era']]) ? $input['era'] : PICKER_DEFAULT_ERA;
    $rating = isset($input['rating']) && is_numeric($input['rating']) && in_array((int) $input['rating'], PICKER_RATINGS, true)
        ? (int) $input['rating'] : 0;
    $language = isset($input['lang']) && is_string($input['lang']) && in_array($input['lang'], PICKER_LANGUAGES, true)
        ? $input['lang'] : '';

    $provider = is_numeric($input['provider'] ?? null) ? (int) $input['provider'] : 0;
    if (!isset(STREAMING_PROVIDERS[$provider])) {
        $provider = 0;
    }

    return [
        'kind' => $kind,
        'duration' => $duration,
        'provider' => $provider,
        'era' => $era,
        'rating' => $rating,
        'lang' => $language,
        'moods' => array_slice($moods, 0, 3),
        'exclude' => array_slice(array_values(array_unique($exclude)), 0, 60),
        'seed' => isset($input['seed']) && is_numeric($input['seed']) ? (int) $input['seed'] : 0,
    ];
}

// TMDB discover sorgusu. Aynı ruh hallerindeki türler "veya" ile birleşir (pipe), kalite eşiği kötü önerileri eler.
// Dönemin [en erken, en geç] çıkış tarihi (YYYY-MM-DD); sınır yoksa null. Gelecekteki yapımlar hiçbir dönemde önerilmez.
function pickerEraBounds(string $era, string $today): array {
    $year = (int) substr($today, 0, 4);
    return match ($era) {
        'recent' => [($year - 5) . '-01-01', $today],
        'modern' => ['2000-01-01', $today],
        'classic' => [null, '1999-12-31'],
        default => [null, $today],
    };
}

function pickerDiscoverQuery(array $params, int $page = 1, ?string $today = null): array {
    $today ??= date('Y-m-d');
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

    [$from, $until] = pickerEraBounds($params['era'] ?? PICKER_DEFAULT_ERA, $today);
    $dateKey = $isSeries ? 'first_air_date' : 'primary_release_date';
    if ($from !== null) {
        $query["$dateKey.gte"] = $from;
    }
    if ($until !== null) {
        $query["$dateKey.lte"] = $until;
    }
    if (($params['rating'] ?? 0) > 0) {
        $query['vote_average.gte'] = max($query['vote_average.gte'], (float) $params['rating']);
    }
    if (($params['lang'] ?? '') !== '') {
        $query['with_original_language'] = $params['lang'];
    }
    $query += streamingProviderParams($params['provider'] ?? 0);

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

// TMDB koleksiyon adlarındaki ek sözcükleri atar: "Hobbit [Seri]", "Hobbit Serisi", "Hobbit Koleksiyonu" -> "Hobbit".
function pickerCollectionName(string $name): string {
    $clean = trim((string) preg_replace('/\s*(\[Seri\]|Koleksiyonu|Serisi|Collection|Üçlemesi|Dörtlemesi)\s*$/iu', '', trim($name)));
    return $clean !== '' ? $clean : trim($name);
}

// Film serisi uyarısı. Önerilen film bir serinin ilk filmi değilse ve önceki filmlerden izlenmemiş olan varsa,
// "önce şunu izlemen önerilir" bilgisi döner; yoksa null. Sıralama çıkış tarihine göredir.
// $collection: TMDB collection/{id} yanıtı (name, parts[]). $watchedIds: kullanıcının izlediği TMDB film kimlikleri.
// Henüz çıkmamış parçalar sırayı etkilemez; çıkış tarihi bilinmeyenler sayılmaz. Saf fonksiyon (ağ ve veritabanı yok).
function pickerFranchiseNote(array $collection, int $pickId, string $today, array $watchedIds = []): ?array {
    $parts = [];
    foreach ($collection['parts'] ?? [] as $part) {
        if (!is_array($part) || empty($part['id']) || empty($part['release_date'])) {
            continue;
        }
        if ((string) $part['release_date'] > $today && (int) $part['id'] !== $pickId) {
            continue;
        }
        $parts[] = $part;
    }
    usort($parts, static fn (array $a, array $b): int => [(string) $a['release_date'], (int) $a['id']] <=> [(string) $b['release_date'], (int) $b['id']]);

    $index = null;
    foreach ($parts as $i => $part) {
        if ((int) $part['id'] === $pickId) {
            $index = $i;
            break;
        }
    }
    if ($index === null || $index === 0) {
        return null;
    }

    $watched = array_flip(array_map('intval', $watchedIds));
    $missing = array_values(array_filter(
        array_slice($parts, 0, $index),
        static fn (array $part): bool => !isset($watched[(int) $part['id']])
    ));
    if (!$missing) {
        return null;
    }

    $card = static fn (array $part): array => [
        'id' => (int) $part['id'],
        'title' => (string) ($part['title'] ?? $part['name'] ?? ''),
        'year' => substr((string) $part['release_date'], 0, 4),
        'poster_path' => $part['poster_path'] ?? null,
        'url' => 'detail.php?type=movie&id=' . (int) $part['id'],
    ];

    return [
        'collection' => pickerCollectionName((string) ($collection['name'] ?? '')),
        'position' => $index + 1,
        'total' => count($parts),
        'missing_count' => count($missing),
        'starts_at_first' => (int) $missing[0]['id'] === (int) $parts[0]['id'],
        'first' => $card($missing[0]),
        'before' => array_map($card, array_slice($missing, 0, 3)),
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
