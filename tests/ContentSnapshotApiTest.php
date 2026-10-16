<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ApiHelpers.php';

// Yorumun yanındaki içerik kartı (başlık + kapak): kitaplar için kaynak sırası (çalışan sunucu ve MongoDB gerekir).
final class ContentSnapshotApiTest extends TestCase
{
    use ApiHelpers;

    private function cardOf(ApiClient $client, string $bookId): array
    {
        $list = $client->get('/api/comments.php');
        foreach ($list['data']['comments'] as $comment) {
            if ($comment['content_type'] === 'book' && $comment['content_id'] === $bookId) {
                return $comment;
            }
        }
        $this->fail('Kitap yorumu listede bulunamadı');
    }

    public function testBookCommentCardUsesTheDetailPageCache(): void
    {
        [$client] = $this->newUser('phpunit_snap_c');
        $bookId = 'phpunit_snap_' . $this->suffix();
        // Detay sayfası açılırken books-proxy bunu önbelleğe yazar; yorum çoğunlukla oradan yazılır.
        $this->mongo()->api_cache->insertOne([
            '_id' => "books:detail:$bookId",
            'data' => json_encode(['volumeInfo' => ['title' => 'Önbellekteki Kitap', 'imageLinks' => ['thumbnail' => 'http://books.example/kapak.jpg']]]),
            'cached_at' => new MongoDB\BSON\UTCDateTime(),
            'expires_at' => new MongoDB\BSON\UTCDateTime((time() + 3600) * 1000),
        ]);

        $posted = $client->post('/api/comments.php', ['content_type' => 'book', 'content_id' => $bookId, 'comment_text' => 'Güzel kitap']);
        $this->assertTrue($posted['data']['success'] ?? false);

        $card = $this->cardOf($client, $bookId);
        $this->assertSame('Önbellekteki Kitap', $card['content_title']);
        $this->assertSame('https://books.example/kapak.jpg', $card['content_poster'], 'Kapak https üzerinden sunulmalı');
        $this->assertSame("detail.php?type=book&id=$bookId", $card['content_url']);
    }

    public function testBookCommentCardPrefersTheLibraryRecord(): void
    {
        [$client] = $this->newUser('phpunit_snap_l');
        $bookId = 'phpunit_snaplib_' . $this->suffix();
        $client->post('/api/user-status.php', ['content_type' => 'book', 'content_id' => $bookId, 'status' => 'read',
            'content_data' => ['title' => 'Kütüphanedeki Kitap', 'cover_url' => 'http://books.example/lib.jpg']]);

        $client->post('/api/comments.php', ['content_type' => 'book', 'content_id' => $bookId, 'comment_text' => 'Okudum, sevdim']);

        $card = $this->cardOf($client, $bookId);
        $this->assertSame('Kütüphanedeki Kitap', $card['content_title']);
        $this->assertSame('https://books.example/lib.jpg', $card['content_poster']);
    }
}
