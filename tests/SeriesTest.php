<?php

use PHPUnit\Framework\TestCase;

final class SeriesTest extends TestCase
{
    private static MongoDB\Database $db;

    public static function setUpBeforeClass(): void
    {
        $client = new MongoDB\Client(TEST_MONGO_URI);
        self::$db = $client->selectDatabase(TEST_MONGO_DB);
    }

    private function uniqueSuffix(): string
    {
        return substr(bin2hex(random_bytes(4)), 0, 8);
    }

    public function testSeriesEndToEndStatusRatingCommentsAndListCascade(): void
    {
        $suffix = $this->uniqueSuffix();
        $client = new ApiClient(TEST_BASE_URL);
        $client->register("phpunit_series_$suffix", "phpunit_series_$suffix@test.com", 'sifre123456');

        $tmdbId = 500000 + random_int(1, 99999);
        $contentData = [
            'title' => 'PHPUnit Test Dizisi',
            'poster_path' => '/phpunit-series.jpg',
            'overview' => 'PHPUnit tarafından eklenen test dizisi.',
            'first_air_date' => '2020-05-01',
            'genres' => [18, 10765],
        ];

        // Sadece puan verildiğinde (status belirtilmeden) diziler için varsayılan durum 'watching' olmalı.
        $rate = $client->post('/api/user-status.php', [
            'content_type' => 'series',
            'content_id' => $tmdbId,
            'rating' => 9,
            'content_data' => $contentData,
        ]);
        $this->assertTrue($rate['data']['success'] ?? false);

        $status = $client->get("/api/user-status.php?content_type=series&content_id=$tmdbId");
        $this->assertSame('watching', $status['data']['data']['status'] ?? null);
        $this->assertSame(9, $status['data']['data']['rating'] ?? null);

        $rating = $client->get("/api/content-rating.php?content_type=series&content_id=$tmdbId");
        $this->assertEquals(9.0, $rating['data']['data']['average_rating'] ?? null);
        $this->assertSame(1, $rating['data']['data']['total_votes'] ?? null);

        // Parametresiz genel bakış (feed.html'in kullandığı uç) 'series' anahtarında dönmeli.
        $overview = $client->get('/api/get-user-content.php');
        $seriesTitles = array_column($overview['data']['series'] ?? [], 'title');
        $this->assertContains('PHPUnit Test Dizisi', $seriesTitles);

        // type=series filtresiyle de aynı içerik dönmeli.
        $typed = $client->get('/api/get-user-content.php?type=series&status=watching');
        $typedTitles = array_column($typed['data']['content'] ?? [], 'title');
        $this->assertContains('PHPUnit Test Dizisi', $typedTitles);

        // Yorumlar: content_type opak string olarak akıyor, dizi için de çalışmalı.
        $comment = $client->post('/api/comments.php', [
            'content_type' => 'series',
            'content_id' => $tmdbId,
            'comment_text' => 'PHPUnit dizi yorumu',
            'parent_comment_id' => null,
        ]);
        $this->assertTrue($comment['data']['success'] ?? false);

        $comments = $client->get("/api/comments.php?content_type=series&content_id=$tmdbId");
        $commentTexts = array_column($comments['data']['comments'] ?? [], 'comment_text');
        $this->assertContains('PHPUnit dizi yorumu', $commentTexts);

        // Özel liste: ekleme + liste silinince öğenin cascade silinmesi.
        $createList = $client->post('/api/custom-lists.php', ['action' => 'create', 'name' => 'PHPUnit Dizi Listem']);
        $this->assertTrue($createList['data']['success'] ?? false);
        $listId = $createList['data']['list_id'];

        $addItem = $client->post('/api/custom-lists.php', [
            'action' => 'add_item',
            'list_id' => $listId,
            'content_type' => 'series',
            'content_id' => $tmdbId,
            'content_title' => 'PHPUnit Test Dizisi',
        ]);
        $this->assertTrue($addItem['data']['success'] ?? false);

        $itemsBeforeDelete = self::$db->custom_list_items->countDocuments(['list_id' => $listId]);
        $this->assertSame(1, $itemsBeforeDelete);

        $deleteList = $client->delete('/api/custom-lists.php', ['list_id' => $listId]);
        $this->assertTrue($deleteList['data']['success'] ?? false);

        $itemsAfterDelete = self::$db->custom_list_items->countDocuments(['list_id' => $listId]);
        $this->assertSame(0, $itemsAfterDelete, 'Liste silinince öğeleri de (cascade) silinmeli');

        // Platform içi popülerlik: type=series de desteklenmeli.
        $popular = $client->get('/api/popular-content.php?type=series&sort=ratings&limit=5');
        $this->assertTrue($popular['data']['success'] ?? false);
        $popularTitles = array_column($popular['data']['data'] ?? [], 'title');
        $this->assertContains('PHPUnit Test Dizisi', $popularTitles);

        // Kütüphane dışa aktarma: watched_series alanında görünmeli.
        $export = $client->get('/api/export-library.php');
        $this->assertArrayHasKey('watched_series', $export['data']);
        $exportTitles = array_column($export['data']['watched_series'], 'title');
        $this->assertContains('PHPUnit Test Dizisi', $exportTitles);
    }
}
