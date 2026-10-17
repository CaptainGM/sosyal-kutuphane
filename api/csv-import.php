<?php
require_once 'config.php';
require_once __DIR__ . '/lib/csv-import.php';

// Letterboxd / Goodreads CSV içe aktarma. İki aşama:
//  1) action=parse : dosya metni ayrıştırılır, normalize satırlar döner (kayıt yok)
//  2) action=import: istemci satırları 40'lık parçalar halinde yollar; her biri TMDB ya da
//     Google Books ile eşleşip kullanıcının durum kaydına yazılır.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Geçersiz istek'], 405);
}
requireLogin();
requireCsrf();

$userId = (int) getCurrentUserId();
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    jsonResponse(['success' => false, 'message' => 'Geçersiz istek gövdesi'], 400);
}

$action = $input['action'] ?? '';

if ($action === 'parse') {
    $csv = (string) ($input['csv'] ?? '');
    if ($csv === '' || strlen($csv) > 3 * 1024 * 1024) {
        jsonResponse(['success' => false, 'message' => 'Dosya boş veya 3 MB\'tan büyük'], 400);
    }
    try {
        $rows = csvImportParse($csv, (string) ($input['source'] ?? ''), (string) ($input['list'] ?? 'watched'));
    } catch (InvalidArgumentException $e) {
        jsonResponse(['success' => false, 'message' => 'Bu dosya türü desteklenmiyor'], 400);
    }
    jsonResponse(['success' => true, 'total' => count($rows), 'rows' => $rows]);
}

if ($action === 'import') {
    $rows = $input['rows'] ?? null;
    if (!is_array($rows) || count($rows) === 0 || count($rows) > 40) {
        jsonResponse(['success' => false, 'message' => 'Bir seferde en fazla 40 satır içe aktarılabilir'], 400);
    }

    $imported = 0;
    $notFound = [];
    foreach ($rows as $raw) {
        $row = csvSanitizeRow($raw);
        if ($row === null) {
            continue;
        }
        $matched = match ($row['kind']) {
            'movie' => csvImportMovie($db, $userId, $row),
            'series' => csvImportSeries($db, $userId, $row),
            default => csvImportBook($db, $userId, $row),
        };
        if ($matched) {
            $imported++;
        } else {
            $notFound[] = $row['title'];
        }
    }

    jsonResponse(['success' => true, 'imported' => $imported, 'not_found' => $notFound]);
}

jsonResponse(['success' => false, 'message' => 'Bilinmeyen işlem'], 400);

// --- Yardımcılar (yalnızca bu uç nokta kullanır) ---

function csvFetchJson(string $url): ?array {
    $context = stream_context_create(['http' => ['timeout' => 8, 'ignore_errors' => true]]);
    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        return null;
    }
    $data = json_decode($response, true);
    return is_array($data) ? $data : null;
}

// TMDB'de eşleşme: IMDb kimliği varsa birebir (/find), yoksa başlık ve yılla arama. 30 gün önbelleğe alınır.
function csvFindTmdb($db, string $kind, array $row): ?array {
    $apiKey = getenv('TMDB_API_KEY');
    if (!$apiKey) {
        return null;
    }
    $cacheKey = 'csv:find:v1:' . md5(json_encode([$kind, $row['imdb_id'] ?? null, $row['title'], $row['year'] ?? null]));
    $cached = cacheGet($db, $cacheKey);
    if (is_array($cached)) {
        return $cached === [] ? null : $cached;
    }

    $hit = null;
    if (!empty($row['imdb_id'])) {
        $data = csvFetchJson('https://api.themoviedb.org/3/find/' . $row['imdb_id'] . '?' . http_build_query([
            'api_key' => $apiKey, 'external_source' => 'imdb_id', 'language' => 'tr-TR',
        ]));
        $hit = $data[$kind === 'series' ? 'tv_results' : 'movie_results'][0] ?? null;
    }
    if (!is_array($hit)) {
        $query = ['api_key' => $apiKey, 'language' => 'tr-TR', 'query' => $row['title'], 'include_adult' => 'false'];
        if ($row['year'] !== null) {
            $query[$kind === 'series' ? 'first_air_date_year' : 'year'] = $row['year'];
        }
        $data = csvFetchJson('https://api.themoviedb.org/3/search/' . ($kind === 'series' ? 'tv' : 'movie') . '?' . http_build_query($query));
        $hit = $data['results'][0] ?? null;
    }

    $hit = is_array($hit) && !empty($hit['id']) ? $hit : null;
    cacheSet($db, $cacheKey, $hit ?? [], 30 * 86400);
    return $hit;
}

function csvImportMovie($db, int $userId, array $row): bool {
    $hit = csvFindTmdb($db, 'movie', $row);
    if ($hit === null) {
        return false;
    }
    $tmdbId = (int) $hit['id'];

    // Alan adları save-content-status.php ile aynıdır; profil ve detay sayfaları aynı şekli bekler.
    $db->movies->updateOne(
        ['tmdb_id' => $tmdbId],
        ['$setOnInsert' => [
            '_id' => nextSequence($db, 'movies'),
            'tmdb_id' => $tmdbId,
            'title' => $hit['title'] ?? $row['title'],
            'poster_path' => $hit['poster_path'] ?? null,
            'overview' => $hit['overview'] ?? null,
            'release_date' => $hit['release_date'] ?? null,
            'genres' => null,
            'rating' => isset($hit['vote_average']) ? (float) $hit['vote_average'] : null,
            'created_at' => new MongoDB\BSON\UTCDateTime(),
        ]],
        ['upsert' => true]
    );

    $movie = $db->movies->findOne(['tmdb_id' => $tmdbId]);
    if (!$movie) {
        return false;
    }
    csvSaveStatus($db, $userId, 'user_movie_status', 'movie_id', $movie->_id, $row);
    return true;
}

function csvImportSeries($db, int $userId, array $row): bool {
    $hit = csvFindTmdb($db, 'series', $row);
    if ($hit === null) {
        return false;
    }
    $tmdbId = (int) $hit['id'];

    // Alan adları save-content-status.php ile aynıdır.
    $db->series->updateOne(
        ['tmdb_id' => $tmdbId],
        ['$setOnInsert' => [
            '_id' => nextSequence($db, 'series'),
            'tmdb_id' => $tmdbId,
            'title' => $hit['name'] ?? $row['title'],
            'poster_path' => $hit['poster_path'] ?? null,
            'overview' => $hit['overview'] ?? null,
            'first_air_date' => $hit['first_air_date'] ?? null,
            'genres' => null,
            'created_at' => new MongoDB\BSON\UTCDateTime(),
        ]],
        ['upsert' => true]
    );

    $series = $db->series->findOne(['tmdb_id' => $tmdbId]);
    if (!$series) {
        return false;
    }
    csvSaveStatus($db, $userId, 'user_series_status', 'series_id', $series->_id, $row);
    return true;
}

function csvImportBook($db, int $userId, array $row): bool {
    $query = ['maxResults' => 1, 'printType' => 'books'];
    $query['q'] = $row['isbn'] !== null
        ? 'isbn:' . $row['isbn']
        : 'intitle:' . $row['title'] . ($row['author'] !== null ? ' inauthor:' . $row['author'] : '');
    $key = getenv('GOOGLE_BOOKS_API_KEY');
    if ($key) {
        $query['key'] = $key;
    }

    $data = csvFetchJson('https://www.googleapis.com/books/v1/volumes?' . http_build_query($query));
    $item = $data['items'][0] ?? null;
    if (!is_array($item) || empty($item['id'])) {
        return false;
    }

    $info = $item['volumeInfo'] ?? [];
    $googleId = (string) $item['id'];
    $cover = $info['imageLinks']['thumbnail'] ?? null;
    if (is_string($cover)) {
        $cover = str_replace('http://', 'https://', $cover);
    }

    $db->books->updateOne(
        ['google_books_id' => $googleId],
        ['$setOnInsert' => [
            '_id' => nextSequence($db, 'books'),
            'google_books_id' => $googleId,
            'title' => $info['title'] ?? $row['title'],
            'authors' => $info['authors'] ?? ($row['author'] !== null ? [$row['author']] : null),
            'cover_url' => $cover,
            'description' => $info['description'] ?? null,
            'published_date' => $info['publishedDate'] ?? null,
            'categories' => $info['categories'] ?? null,
            'created_at' => new MongoDB\BSON\UTCDateTime(),
        ]],
        ['upsert' => true]
    );

    $book = $db->books->findOne(['google_books_id' => $googleId]);
    if (!$book) {
        return false;
    }
    csvSaveStatus($db, $userId, 'user_book_status', 'book_id', $book->_id, $row);
    return true;
}

// Puan yalnızca gelirse yazılır: aynı kaydı tekrar içe aktarmak mevcut puanı silmez.
function csvSaveStatus($db, int $userId, string $collection, string $idField, $contentId, array $row): void {
    $set = ['status' => $row['status'], 'updated_at' => new MongoDB\BSON\UTCDateTime()];
    if ($row['rating'] !== null) {
        $set['rating'] = $row['rating'];
    }
    $db->$collection->updateOne(
        ['user_id' => $userId, $idField => $contentId],
        ['$set' => $set, '$setOnInsert' => ['_id' => nextSequence($db, $collection)]],
        ['upsert' => true]
    );
}
?>
