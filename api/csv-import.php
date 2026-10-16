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
        $matched = $row['kind'] === 'movie'
            ? csvImportMovie($db, $userId, $row)
            : csvImportBook($db, $userId, $row);
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

function csvImportMovie($db, int $userId, array $row): bool {
    $apiKey = getenv('TMDB_API_KEY');
    if (!$apiKey) {
        return false;
    }

    $query = ['api_key' => $apiKey, 'language' => 'tr-TR', 'query' => $row['title'], 'include_adult' => 'false'];
    if ($row['year'] !== null) {
        $query['year'] = $row['year'];
    }
    $data = csvFetchJson('https://api.themoviedb.org/3/search/movie?' . http_build_query($query));
    $hit = $data['results'][0] ?? null;
    if (!is_array($hit) || empty($hit['id'])) {
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
