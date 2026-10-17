<?php
// Letterboxd ve Goodreads dışa aktarım dosyalarını ortak bir satır biçimine çevirir.
// Ağ ya da veritabanı çağrısı yapmaz; bu yüzden birim testleriyle doğrulanır.
// Ortak satır: kind (movie|book), title, year, author, isbn, status, rating (1-10 ya da null).

const CSV_IMPORT_MAX_ROWS = 3000;

// Dosyayı okur, başlığa göre satırları eşler ve kaynağa özgü normalizasyonu uygular.
function csvImportParse(string $content, string $source, string $list = 'watched'): array {
    $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

    $handle = fopen('php://memory', 'r+');
    fwrite($handle, $content);
    rewind($handle);

    $header = fgetcsv($handle, null, ',', '"', '');
    if (!is_array($header)) {
        fclose($handle);
        return [];
    }
    $header = array_map(fn($name) => trim((string) $name), $header);

    $rows = [];
    while (($cells = fgetcsv($handle, null, ',', '"', '')) !== false && count($rows) < CSV_IMPORT_MAX_ROWS) {
        if (count($cells) === 1 && trim((string) $cells[0]) === '') {
            continue;
        }
        $row = [];
        foreach ($header as $index => $name) {
            $row[$name] = $cells[$index] ?? '';
        }
        $rows[] = $row;
    }
    fclose($handle);

    return match ($source) {
        'letterboxd' => csvNormalizeLetterboxd($rows, $list),
        'goodreads' => csvNormalizeGoodreads($rows),
        'imdb' => csvNormalizeImdb($rows, $list),
        default => throw new InvalidArgumentException('Desteklenmeyen kaynak: ' . $source),
    };
}

// Letterboxd: watched.csv / ratings.csv (Rating sütunu varsa kullanılır) ve watchlist.csv.
function csvNormalizeLetterboxd(array $rows, string $list): array {
    $status = $list === 'watchlist' ? 'watchlist' : 'watched';
    $result = [];
    foreach ($rows as $row) {
        $title = trim((string) ($row['Name'] ?? ''));
        if ($title === '') {
            continue;
        }
        $year = trim((string) ($row['Year'] ?? ''));
        $rating = $status === 'watched' ? csvStarsToTen((string) ($row['Rating'] ?? '')) : null;

        $result[] = [
            'kind' => 'movie',
            'title' => $title,
            'year' => ctype_digit($year) ? (int) $year : null,
            'author' => null,
            'isbn' => null,
            'status' => $status,
            'rating' => $rating,
        ];
    }
    return $result;
}

// IMDb: ratings.csv (Your Rating, 1-10 tam sayı) ve watchlist.csv. Başlık türü film ya da dizi belirler;
// kısa film, bölüm gibi türler atlanır. Const (tt...) kimliği TMDB'de birebir eşleşme için saklanır.
function csvNormalizeImdb(array $rows, string $list): array {
    $watchlist = $list === 'watchlist';
    $result = [];
    foreach ($rows as $row) {
        $title = trim((string) ($row['Title'] ?? ''));
        $kind = match (trim((string) ($row['Title Type'] ?? ''))) {
            'movie', 'tvMovie' => 'movie',
            'tvSeries', 'tvMiniSeries' => 'series',
            default => null,
        };
        if ($title === '' || $kind === null) {
            continue;
        }
        $year = trim((string) ($row['Year'] ?? ''));
        $const = trim((string) ($row['Const'] ?? ''));

        $result[] = [
            'kind' => $kind,
            'title' => $title,
            'year' => ctype_digit($year) ? (int) $year : null,
            'author' => null,
            'isbn' => null,
            'imdb_id' => preg_match('/^tt\d{7,9}$/', $const) ? $const : null,
            'status' => $kind === 'movie' ? ($watchlist ? 'watchlist' : 'watched') : ($watchlist ? 'watchlist' : 'watching'),
            'rating' => $watchlist ? null : csvImdbRating((string) ($row['Your Rating'] ?? '')),
        ];
    }
    return $result;
}

// IMDb puanı zaten 1-10 tam sayıdır; geçersiz ya da boş değer puansız sayılır.
function csvImdbRating(string $raw): ?int {
    $raw = trim($raw);
    return ctype_digit($raw) && (int) $raw >= 1 && (int) $raw <= 10 ? (int) $raw : null;
}

// Goodreads: Exclusive Shelf değeri durumu belirler (read, to-read, currently-reading).
function csvNormalizeGoodreads(array $rows): array {
    $shelfToStatus = ['read' => 'read', 'to-read' => 'readinglist', 'currently-reading' => 'readinglist'];
    $result = [];
    foreach ($rows as $row) {
        $title = trim((string) ($row['Title'] ?? ''));
        $shelf = trim((string) ($row['Exclusive Shelf'] ?? ''));
        if ($title === '' || !isset($shelfToStatus[$shelf])) {
            continue;
        }
        $status = $shelfToStatus[$shelf];
        $isbn = csvGoodreadsIsbn((string) ($row['ISBN13'] ?? '')) ?? csvGoodreadsIsbn((string) ($row['ISBN'] ?? ''));

        $result[] = [
            'kind' => 'book',
            'title' => $title,
            'year' => null,
            'author' => trim((string) ($row['Author'] ?? '')) ?: null,
            'isbn' => $isbn,
            'status' => $status,
            'rating' => $status === 'read' ? csvStarsToTen((string) ($row['My Rating'] ?? '')) : null,
        ];
    }
    return $result;
}

// 0.5-5 yıldızı 1-10 ölçeğine çevirir. Boş ya da 0 değer "puansız" demektir.
function csvStarsToTen(string $raw): ?int {
    $raw = trim($raw);
    if ($raw === '' || !is_numeric($raw)) {
        return null;
    }
    $stars = (float) $raw;
    if ($stars < 0.5 || $stars > 5) {
        return null;
    }
    return (int) round($stars * 2);
}

// Goodreads ISBN'leri ="0123456789" biçiminde sarılı gelir; yalnızca rakam ve X tutulur.
function csvGoodreadsIsbn(string $raw): ?string {
    $digits = strtoupper((string) preg_replace('/[^0-9Xx]/', '', $raw));
    return (strlen($digits) === 10 || strlen($digits) === 13) ? $digits : null;
}

// İçe aktarma uç noktasına gelen satırı doğrular. Geçersiz satır null döner.
function csvSanitizeRow($row): ?array {
    if (!is_array($row)) {
        return null;
    }
    $allowedStatuses = ['movie' => ['watched', 'watchlist'], 'series' => ['watching', 'watchlist'], 'book' => ['read', 'readinglist']];
    $kind = $row['kind'] ?? null;
    $status = $row['status'] ?? null;
    if (!isset($allowedStatuses[$kind]) || !in_array($status, $allowedStatuses[$kind], true)) {
        return null;
    }

    $title = mb_substr(trim((string) ($row['title'] ?? '')), 0, 200);
    if ($title === '') {
        return null;
    }

    $rating = $row['rating'] ?? null;
    $rating = (is_int($rating) || (is_string($rating) && ctype_digit($rating))) ? (int) $rating : null;
    if ($rating !== null && ($rating < 1 || $rating > 10)) {
        $rating = null;
    }

    $year = $row['year'] ?? null;
    $year = (is_int($year) || (is_string($year) && ctype_digit($year))) ? (int) $year : null;
    if ($year !== null && ($year < 1870 || $year > 2100)) {
        $year = null;
    }

    $author = isset($row['author']) ? mb_substr(trim((string) $row['author']), 0, 200) : '';
    $isbn = is_string($row['isbn'] ?? null) && preg_match('/^[0-9X]{10}$|^[0-9]{13}$/i', $row['isbn'])
        ? strtoupper($row['isbn'])
        : null;

    $imdbId = is_string($row['imdb_id'] ?? null) && preg_match('/^tt\d{7,9}$/', $row['imdb_id']) ? $row['imdb_id'] : null;

    return [
        'kind' => $kind,
        'title' => $title,
        'year' => $year,
        'author' => $author !== '' ? $author : null,
        'isbn' => $isbn,
        'imdb_id' => $imdbId,
        'status' => $status,
        'rating' => $rating,
    ];
}
