<?php
// Yorumların yanındaki içerik kartı (başlık + afiş) için özet.
// Yorum kaydedilirken bir kez alınır ve yorum belgesine yazılır; eski yorumlar sosyal sayfada ilk görüntülenmede tamamlanır.
// Sonuçlar api_cache'te tutulur, böylece aynı içerik için TMDB / Google Books tekrar çağrılmaz.

function commentSnapshotFetch(string $url): ?array {
    $context = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        return null;
    }
    $data = json_decode($response, true);
    return is_array($data) ? $data : null;
}

function commentSnapshotHttps($url): ?string {
    return is_string($url) && $url !== '' ? preg_replace('~^http://~', 'https://', $url) : null;
}

// Google Books yanıtındaki (volumeInfo) başlık ve kapak.
function commentBookSnapshotFromVolume($data): array {
    $info = is_array($data) ? ($data['volumeInfo'] ?? null) : null;
    if (!is_array($info)) {
        return ['title' => null, 'poster' => null];
    }
    return [
        'title' => isset($info['title']) && is_string($info['title']) ? $info['title'] : null,
        'poster' => commentSnapshotHttps($info['imageLinks']['thumbnail'] ?? null),
    ];
}

function commentBookSnapshot($db, string $bookId): array {
    // 1) Bu kitap bir kullanıcının kütüphanesindeyse başlığı ve kapağı zaten kayıtlıdır.
    $book = $db->books->findOne(['google_books_id' => $bookId], ['projection' => ['title' => 1, 'cover_url' => 1]]);
    if ($book && !empty($book['title'])) {
        return ['title' => (string) $book['title'], 'poster' => commentSnapshotHttps($book['cover_url'] ?? null)];
    }

    // 2) Detay sayfası açılırken books-proxy yanıtı önbelleğe yazmıştır.
    $snapshot = commentBookSnapshotFromVolume(cacheGet($db, "books:detail:$bookId"));
    if ($snapshot['title'] !== null) {
        return $snapshot;
    }

    // 3) Google Books; GOOGLE_BOOKS_API_KEY varsa kullanılır (anahtarsız istekler çabuk kotaya takılır).
    $url = 'https://www.googleapis.com/books/v1/volumes/' . rawurlencode($bookId);
    $apiKey = getenv('GOOGLE_BOOKS_API_KEY');
    if ($apiKey) {
        $url .= '?key=' . urlencode($apiKey);
    }
    return commentBookSnapshotFromVolume(commentSnapshotFetch($url));
}

function commentContentSnapshot($db, string $type, $contentId): array {
    $cacheKey = "comment-content:v1:$type:$contentId";
    $cached = cacheGet($db, $cacheKey);
    if (is_array($cached) && array_key_exists('title', $cached)) {
        return $cached;
    }

    $snapshot = ['title' => null, 'poster' => null];
    if ($type === 'book') {
        $snapshot = commentBookSnapshot($db, (string) $contentId);
    } else {
        $apiKey = getenv('TMDB_API_KEY');
        if ($apiKey) {
            $tmdbType = $type === 'series' ? 'tv' : 'movie';
            $url = "https://api.themoviedb.org/3/$tmdbType/" . (int) $contentId
                . '?api_key=' . urlencode($apiKey) . '&language=tr-TR';
            $data = commentSnapshotFetch($url);
            if (is_array($data) && isset($data['id'])) {
                $title = $data['title'] ?? $data['name'] ?? null;
                $snapshot['title'] = is_string($title) ? $title : null;
                $posterPath = $data['poster_path'] ?? null;
                if (is_string($posterPath) && preg_match('~^/[A-Za-z0-9_.-]+\.(?:jpg|png)$~', $posterPath)) {
                    $snapshot['poster'] = 'https://image.tmdb.org/t/p/w185' . $posterPath;
                }
            }
        }
    }

    // Başarısız aramalar kısa süre, başarılı olanlar uzun süre önbellekte kalır.
    cacheSet($db, $cacheKey, $snapshot, $snapshot['title'] !== null ? 30 * 86400 : 600);
    return $snapshot;
}
