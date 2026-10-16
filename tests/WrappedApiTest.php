<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ApiHelpers.php';

// Yıllık özet (çalışan sunucu ve MongoDB gerekir, bkz. ApiTest).
final class WrappedApiTest extends TestCase
{
    use ApiHelpers;

    public function testNewUserHasNoData(): void
    {
        [$client] = $this->newUser('phpunit_wrap_e');
        $res = $client->get('/api/wrapped.php');
        $this->assertTrue($res['data']['success'] ?? false);
        $this->assertFalse($res['data']['has_data']);
        $this->assertNull($res['data']['best_of_year']);
        $this->assertSame(array_fill(0, 12, 0), $res['data']['monthly']);
    }

    public function testSummaryCountsBestOfYearGenresAndMonths(): void
    {
        [$client] = $this->newUser('phpunit_wrap_c');
        $movieA = 750000 + random_int(1, 99999);
        $movieB = 760000 + random_int(1, 99999);
        $bookId = 'phpunit_wrap_' . $this->suffix();

        // Türler TMDB kimliği (sayı) olarak gelir: 28 Aksiyon, 878 Bilim-Kurgu.
        $client->post('/api/user-status.php', ['content_type' => 'movie', 'content_id' => $movieA, 'status' => 'watched', 'rating' => 7,
            'content_data' => ['title' => 'Özet Filmi A', 'poster_path' => '/abc.jpg', 'genres' => [28, 878]]]);
        $client->post('/api/user-status.php', ['content_type' => 'movie', 'content_id' => $movieB, 'status' => 'watched', 'rating' => 10,
            'content_data' => ['title' => 'Özet Filmi B', 'poster_path' => '/xyz.jpg', 'genres' => [878]]]);
        $client->post('/api/user-status.php', ['content_type' => 'book', 'content_id' => $bookId, 'status' => 'read', 'rating' => 9,
            'content_data' => ['title' => 'Özet Kitabı', 'cover_url' => 'http://example.com/c.jpg']]);

        $res = $client->get('/api/wrapped.php');
        $data = $res['data'];
        $this->assertTrue($data['has_data']);
        $this->assertSame(2, $data['movies']['count']);
        $this->assertSame(1, $data['books']['count']);
        $this->assertSame(3, $data['total_items']);
        $this->assertSame(3, $data['total_ratings_given']);
        $this->assertSame(8.5, $data['movies']['avg_rating']);

        $this->assertSame('Özet Filmi B', $data['best_of_year']['title']);
        $this->assertSame(10, $data['best_of_year']['rating']);
        $this->assertSame($movieB, $data['best_of_year']['content_id']);
        $this->assertSame('https://image.tmdb.org/t/p/w185/xyz.jpg', $data['best_of_year']['poster']);

        $this->assertSame('Bilim-Kurgu', $data['favorite_genre'], 'Sayısal TMDB tür kimlikleri Türkçe adlara çevrilmeli');
        $names = array_column($data['top_genres'], 'name');
        $this->assertSame(['Bilim-Kurgu', 'Aksiyon'], $names);
        $this->assertSame(2, $data['top_genres'][0]['count']);

        $this->assertCount(12, $data['monthly']);
        $this->assertSame(3, array_sum($data['monthly']), 'Aylık dağılımın toplamı toplam içerik sayısına eşit olmalı');
        $this->assertSame(3, $data['monthly'][(int) gmdate('n') - 1]);
    }

    public function testBookCoverIsServedOverHttpsAndYearIsClamped(): void
    {
        [$client] = $this->newUser('phpunit_wrap_y');
        $bookId = 'phpunit_wrap_' . $this->suffix();
        $client->post('/api/user-status.php', ['content_type' => 'book', 'content_id' => $bookId, 'status' => 'read', 'rating' => 8,
            'content_data' => ['title' => 'Kapaklı Kitap', 'cover_url' => 'http://example.com/c.jpg']]);

        $res = $client->get('/api/wrapped.php?year=1850');
        $this->assertSame((int) gmdate('Y'), $res['data']['year'], 'Geçersiz yıl bu yıla çekilmeli');
        $this->assertSame('https://example.com/c.jpg', $res['data']['best_of_year']['poster']);
    }

    public function testRequiresLogin(): void
    {
        $anonymous = new ApiClient(TEST_BASE_URL);
        $this->assertSame(401, $anonymous->get('/api/wrapped.php')['status']);
    }
}
