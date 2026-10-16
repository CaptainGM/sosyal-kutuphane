<?php
// Yazım toleransı (bulanık arama) yardımcıları. Yan etkisi yok; birim testlidir.
// Kural: yalnızca yakın eşleşmeler eklenir (sıkı eşik). Film araması boş kaldığında dizi
// arşivine düşme özelliği bu yüzden bozulmaz: "Güç Yüzükleri" gibi sorgular yanlış film döndürmez.

const SEARCH_FUZZY_THRESHOLD = 0.75;

// Türkçe harfleri ASCII karşılıklarına çevirip büyük/küçük harf ve noktalamayı yok sayar.
function searchNormalize(string $text): string {
    // Büyük İ, mb_strtolower ile "i + birleşik nokta" olur; önce düz i'ye çevrilir.
    $text = strtr(trim($text), ['İ' => 'i', 'I' => 'i']);
    $text = preg_replace('/\p{Mn}+/u', '', mb_strtolower($text, 'UTF-8'));
    $text = strtr($text, ['ı' => 'i', 'ğ' => 'g', 'ş' => 's', 'ç' => 'c', 'ö' => 'o', 'ü' => 'u', 'â' => 'a', 'î' => 'i', 'û' => 'u']);
    $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);
    return trim((string) preg_replace('/\s+/u', ' ', $text));
}

// 0..1 arası benzerlik. Sorgu ile başlığın tamamını, başlıktaki sözcük pencerelerini ve kısmi adları karşılaştırır.
function searchSimilarity(string $query, string $title): float {
    $q = searchNormalize($query);
    $t = searchNormalize($title);
    if ($q === '' || $t === '') {
        return 0.0;
    }

    $best = 1 - levenshtein($q, $t) / max(strlen($q), strlen($t));

    // Başlığın birkaç sözcüklük pencereleri: "odysey" ~ "the odyssey" içindeki "odyssey".
    $words = explode(' ', $t);
    $windowSize = max(1, substr_count($q, ' ') + 1);
    for ($start = 0; $start < count($words); $start++) {
        $window = implode(' ', array_slice($words, $start, $windowSize));
        $score = 1 - levenshtein($q, $window) / max(strlen($q), strlen($window));
        $best = max($best, $score);
    }

    // Kısmi ad: kullanıcı adın başını yazdıysa (en az 3 karakter) başlığın başı ya da bir sözcüğü yeterlidir.
    if (strlen($q) >= 3) {
        if (str_starts_with($t, $q)) {
            $best = max($best, 0.8);
        }
        foreach ($words as $word) {
            if (str_starts_with($word, $q)) {
                $best = max($best, 0.8);
                break;
            }
        }
    }

    return max(0.0, min(1.0, $best));
}

// Adayları benzerliğe göre süzer ve sıralar. $titleOf, bir öğenin başlığını döndürür.
function rankFuzzyResults(array $items, string $query, callable $titleOf, float $threshold = SEARCH_FUZZY_THRESHOLD): array {
    $scored = [];
    foreach ($items as $item) {
        $score = searchSimilarity($query, (string) $titleOf($item));
        if ($score >= $threshold) {
            $scored[] = ['score' => $score, 'item' => $item];
        }
    }
    usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
    return array_map(fn($row) => $row['item'], $scored);
}
