<?php
// Türkiye'de abonelikle izlenen yayın platformları (TMDB sağlayıcı kimlikleri). Yalnızca bu liste kabul edilir.
const STREAMING_PROVIDERS = [
    8 => 'Netflix',
    119 => 'Prime Video',
    337 => 'Disney+',
    2 => 'Apple TV',
    11 => 'MUBI',
    342 => 'puhutv',
];

// Seçilen platform için TMDB keşif parametreleri. Yalnızca abonelikle izlenebilenler (kiralık/satın alma hariç).
// Boş, bilinmeyen ya da geçersiz değerde [] döner: filtre uygulanmaz.
function streamingProviderParams($raw): array {
    $id = is_numeric($raw) ? (int) $raw : 0;
    if (!isset(STREAMING_PROVIDERS[$id])) {
        return [];
    }
    return [
        'with_watch_providers' => $id,
        'watch_region' => 'TR',
        'with_watch_monetization_types' => 'flatrate',
    ];
}
