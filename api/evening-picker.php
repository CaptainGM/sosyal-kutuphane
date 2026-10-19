<?php
// Akşam seçici: süre ve ruh haline göre tek bir film ya da dizi önerir.
//   ?kind=movie|series&duration=short|medium|long|any&moods=gerilim,komedi&seed=123&exclude=1,2,3
// Giriş yapmış kullanıcının kütüphanesindekiler önerilmez; "başka öner" istemcinin gönderdiği exclude listesiyle çalışır.
require_once 'config.php';
require_once __DIR__ . '/lib/tmdb.php';
require_once __DIR__ . '/lib/picker.php';

$params = pickerNormalizeParams($_GET);
if (tmdbApiKey() === null) {
    tmdbUnavailableResponse();
}

// Kütüphanedeki içeriklerin TMDB kimlikleri (movies / series koleksiyonlarında tmdb_id olarak saklanır).
function pickerLibraryTmdbIds($db, int $userId, string $kind, ?string $status = null): array {
    $statusCollection = $kind === 'series' ? 'user_series_status' : 'user_movie_status';
    $idField = $kind === 'series' ? 'series_id' : 'movie_id';
    $contentCollection = $kind === 'series' ? 'series' : 'movies';

    $filter = ['user_id' => $userId];
    if ($status !== null) {
        $filter['status'] = $status;
    }
    $contentIds = [];
    foreach ($db->$statusCollection->find($filter, ['projection' => [$idField => 1]]) as $row) {
        $contentIds[] = $row[$idField];
    }
    if (!$contentIds) {
        return [];
    }

    $tmdbIds = [];
    foreach ($db->$contentCollection->find(['_id' => ['$in' => $contentIds]], ['projection' => ['tmdb_id' => 1]]) as $doc) {
        if (isset($doc['tmdb_id'])) {
            $tmdbIds[] = (int)$doc['tmdb_id'];
        }
    }
    return $tmdbIds;
}

$exclude = $params['exclude'];
if (isLoggedIn()) {
    $exclude = array_merge($exclude, pickerLibraryTmdbIds($db, (int)getCurrentUserId(), $params['kind']));
}

$seed = $params['seed'] > 0 ? $params['seed'] : random_int(1, 2147483646);
$choice = null;
// Kısıtlar sıkıysa ilk sayfa az sonuç verebilir; sayfa 1..3 arasında dönülür.
foreach ([1 + ($seed % 3), 1, 2, 3] as $page) {
    $discover = pickerDiscoverQuery($params, $page);
    $data = tmdbGet($db, $discover['path'], $discover['query'], 21600);
    if ($data === null) {
        tmdbUnavailableResponse();
    }
    $choice = pickerChoose($data['results'] ?? [], $exclude, $seed);
    if ($choice['pick'] !== null) {
        break;
    }
}

if ($choice === null || $choice['pick'] === null) {
    jsonResponse(['success' => true, 'pick' => null, 'message' => 'Bu ölçülere uygun yeni bir öneri bulunamadı. Süreyi ya da ruh halini gevşetmeyi dene.']);
}

$typePath = $params['kind'] === 'series' ? 'tv' : 'movie';
$typeName = $params['kind'] === 'series' ? 'series' : 'movie';

function pickerCard(array $item, string $typeName): array {
    $date = (string)($item['release_date'] ?? $item['first_air_date'] ?? '');
    return [
        'id' => (int)$item['id'],
        'type' => $typeName,
        'title' => (string)($item['title'] ?? $item['name'] ?? ''),
        'year' => $date !== '' ? substr($date, 0, 4) : null,
        'rating' => isset($item['vote_average']) ? round((float)$item['vote_average'], 1) : null,
        'poster' => tmdbImageUrl($item['poster_path'] ?? null, 'w342'),
        'url' => 'detail.php?type=' . $typeName . '&id=' . (int)$item['id'],
    ];
}

$pick = $choice['pick'];
$detail = tmdbGet($db, "$typePath/" . (int)$pick['id'], [], 86400) ?? [];
$runtime = $params['kind'] === 'series' ? ($detail['episode_run_time'][0] ?? null) : ($detail['runtime'] ?? null);
$genres = [];
$genreIds = [];
foreach ($detail['genres'] ?? [] as $genre) {
    if (is_array($genre) && isset($genre['name'])) {
        $genres[] = (string)$genre['name'];
        $genreIds[] = (int)($genre['id'] ?? 0);
    }
}

$card = pickerCard($pick, $typeName) + [
    'overview' => mb_substr((string)($pick['overview'] ?? ''), 0, 420),
    'backdrop' => tmdbImageUrl($pick['backdrop_path'] ?? null, 'w780'),
    'runtime' => $runtime ? (int)$runtime : null,
    'genres' => array_slice($genres, 0, 4),
    'genre_ids' => array_values(array_filter($genreIds)),
    'poster_path' => $pick['poster_path'] ?? null,
    'tagline' => (string)($detail['tagline'] ?? ''),
];

// Film bir serinin ilk filmi değilse ve öncekilerden izlenmemiş olan varsa uyarı eklenir (ör. Hobbit: Beş Ordunun Savaşı).
$franchise = null;
$collectionId = (int)($detail['belongs_to_collection']['id'] ?? 0);
if ($params['kind'] === 'movie' && $collectionId > 0) {
    $collection = tmdbGet($db, "collection/$collectionId", [], 86400);
    if ($collection !== null) {
        $watched = isLoggedIn() ? pickerLibraryTmdbIds($db, (int)getCurrentUserId(), 'movie', 'watched') : [];
        $franchise = pickerFranchiseNote($collection, (int)$pick['id'], date('Y-m-d'), $watched);
        if ($franchise !== null) {
            $withPoster = static fn (array $part): array => $part + ['poster' => tmdbImageUrl($part['poster_path'] ?? null, 'w185')];
            $franchise['first'] = $withPoster($franchise['first']);
            $franchise['before'] = array_map($withPoster, $franchise['before']);
        }
    }
}

jsonResponse([
    'success' => true,
    'pick' => $card,
    'franchise' => $franchise,
    'alternatives' => array_map(static fn (array $item): array => pickerCard($item, $typeName), $choice['alternatives']),
    'reel' => array_values(array_filter(array_map(static fn (string $path): ?string => tmdbImageUrl($path, 'w185'), $choice['reel']))),
    'reason' => pickerReason($params, $pick, $runtime ? (int)$runtime : null),
    'seed' => $seed,
    'candidate_count' => $choice['candidate_count'],
]);
