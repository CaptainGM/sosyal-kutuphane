<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ApiHelpers.php';

// Zevk eşleşmesi uçları ve akşam seçici. Kütüphane verisi doğrudan MongoDB'ye yazılır (deterministik);
// TMDB'ye dokunan uçlar anahtar yoksa 503 döner ve test bunu kabul eder (CI'da anahtar yok).
final class TasteApiTest extends TestCase
{
    use ApiHelpers;

    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as [$collection, $filter]) {
            $this->mongo()->$collection->deleteMany($filter);
        }
        $this->cleanup = [];
    }

    // Test içeriği ve kullanıcı durumları ekler: [tmdb_id => [başlık, türler]], [tmdb_id => [durum, puan]]
    private function seedLibrary(int $userId, array $items): void
    {
        foreach ($items as $tmdbId => [$status, $rating, $title, $genres]) {
            $movie = $this->mongo()->movies->findOne(['tmdb_id' => $tmdbId]);
            if (!$movie) {
                $movieId = $this->nextId('movies');
                $this->mongo()->movies->insertOne([
                    '_id' => $movieId, 'tmdb_id' => $tmdbId, 'title' => $title, 'poster_path' => '/test.jpg',
                    'genres' => array_map(fn ($g) => ['id' => 1, 'name' => $g], $genres), 'created_at' => new MongoDB\BSON\UTCDateTime(),
                ]);
                $this->cleanup[] = ['movies', ['_id' => $movieId]];
            } else {
                $movieId = $movie['_id'];
            }
            $this->mongo()->user_movie_status->insertOne([
                '_id' => $this->nextId('user_movie_status'), 'user_id' => $userId, 'movie_id' => $movieId,
                'status' => $status, 'rating' => $rating, 'updated_at' => new MongoDB\BSON\UTCDateTime(),
            ]);
            $this->cleanup[] = ['user_movie_status', ['user_id' => $userId, 'movie_id' => $movieId]];
        }
    }

    public function testCompatibilityReportAndMatchLeaderboard(): void
    {
        [$a, $aId] = $this->newUser('phpunit_taste_a');
        [$b, $bId, , $bName] = $this->newUser('phpunit_taste_b');
        $base = 9_100_000 + random_int(1, 50_000) * 10;

        $this->seedLibrary($aId, [
            $base + 1 => ['watched', 10, 'Ortak Bir', ['Dram']], $base + 2 => ['watched', 9, 'Ortak İki', ['Dram']],
            $base + 3 => ['watched', 9, 'Ortak Üç', ['Gerilim']], $base + 4 => ['watched', 2, 'Ayrışılan', ['Gerilim']],
            $base + 5 => ['watched', 8, 'Benim Sevdiğim', ['Dram']], $base + 6 => ['watchlist', null, 'Birlikte İzleriz', ['Dram']],
        ]);
        $this->seedLibrary($bId, [
            $base + 1 => ['watched', 9, 'Ortak Bir', ['Dram']], $base + 2 => ['watched', 9, 'Ortak İki', ['Dram']],
            $base + 3 => ['watched', 8, 'Ortak Üç', ['Gerilim']], $base + 4 => ['watched', 9, 'Ayrışılan', ['Gerilim']],
            $base + 7 => ['watched', 10, 'Onun Sevdiği', ['Dram']], $base + 6 => ['watchlist', null, 'Birlikte İzleriz', ['Dram']],
        ]);

        $res = $a->get("/api/compatibility.php?user_id=$bId");
        $this->assertTrue($res['data']['success']);
        $report = $res['data']['report'];
        $this->assertSame($report['score'], $res['data']['score'], 'Eski alanlar korunmalı');
        $this->assertSame(4, $report['shared_rated']);
        $this->assertGreaterThan(50, $report['score']);
        $this->assertSame($bName, $res['data']['target']['username']);
        $this->assertSame($base + 4, $report['disagreements'][0]['item']['content_id']);
        $this->assertSame($base + 7, $report['they_loved_you_havent'][0]['item']['content_id']);
        $this->assertSame($base + 5, $report['you_loved_they_havent'][0]['item']['content_id']);
        $this->assertSame([$base + 6], array_column($report['watch_together'], 'content_id'));
        $this->assertContains('Dram', $report['common_genres']);

        $matches = $a->get('/api/taste-matches.php?limit=30');
        $this->assertTrue($matches['data']['success']);
        $this->assertTrue($matches['data']['has_enough_data']);
        $found = array_values(array_filter($matches['data']['matches'], static fn (array $m): bool => $m['user_id'] === $bId));
        $this->assertCount(1, $found);
        $this->assertSame($report['score'], $found[0]['score']);
        $this->assertFalse($found[0]['is_following']);
    }

    public function testCompatibilityValidatesInput(): void
    {
        $guest = new ApiClient(TEST_BASE_URL);
        $this->assertSame(401, $guest->get('/api/compatibility.php?user_id=1')['status']);
        $this->assertSame(401, $guest->get('/api/taste-matches.php')['status']);

        [$a, $aId] = $this->newUser('phpunit_taste_self');
        $this->assertSame(400, $a->get("/api/compatibility.php?user_id=$aId")['status'], 'Kendisiyle eşleşme olmaz');
        $this->assertSame(404, $a->get('/api/compatibility.php?user_id=987654321')['status']);

        $empty = $a->get('/api/taste-matches.php');
        $this->assertFalse($empty['data']['has_enough_data'], 'Kütüphanesi boş kullanıcıya açıklayıcı durum döner');
    }

    public function testBlockedUsersAreExcludedFromMatches(): void
    {
        [$a, $aId] = $this->newUser('phpunit_taste_blocker');
        [, $bId] = $this->newUser('phpunit_taste_blocked');
        $base = 9_200_000 + random_int(1, 50_000) * 10;
        $shared = [$base + 1 => ['watched', 9, 'Engel Testi', ['Dram']]];
        $this->seedLibrary($aId, $shared);
        $this->seedLibrary($bId, $shared);

        $ids = fn () => array_column($a->get('/api/taste-matches.php?limit=30')['data']['matches'], 'user_id');
        $this->assertContains($bId, $ids());

        $this->mongo()->blocks->insertOne(['_id' => $this->nextId('blocks'), 'blocker_id' => $aId, 'blocked_id' => $bId, 'created_at' => new MongoDB\BSON\UTCDateTime()]);
        $this->cleanup[] = ['blocks', ['blocker_id' => $aId, 'blocked_id' => $bId]];
        $this->assertNotContains($bId, $ids());
    }

    public function testEveningPickerRespondsOrReportsMissingTmdbKey(): void
    {
        $guest = new ApiClient(TEST_BASE_URL);
        $res = $guest->get('/api/evening-picker.php?kind=movie&duration=short&moods=gerilim,komedi&seed=42');

        if ($res['status'] === 503) {
            $this->assertFalse($res['data']['success']);
            return;
        }

        $this->assertTrue($res['data']['success']);
        if ($res['data']['pick'] === null) {
            $this->assertNotEmpty($res['data']['message']);
            return;
        }
        $this->assertNotEmpty($res['data']['pick']['title']);
        $this->assertStringStartsWith('detail.php?type=movie&id=', $res['data']['pick']['url']);
        $this->assertIsString($res['data']['reason']);
        $this->assertLessThanOrEqual(2, count($res['data']['alternatives']));
        // Aynı tohum aynı öneriyi verir ("başka öner" için istemci yeni tohum gönderir).
        $again = $guest->get('/api/evening-picker.php?kind=movie&duration=short&moods=gerilim,komedi&seed=42');
        $this->assertSame($res['data']['pick']['id'], $again['data']['pick']['id']);
    }

    public function testEveningPickerIgnoresUnknownParameters(): void
    {
        $guest = new ApiClient(TEST_BASE_URL);
        $res = $guest->get('/api/evening-picker.php?kind=podcast&duration=epic&moods=nope&exclude=abc,-1');
        $this->assertContains($res['status'], [200, 503], 'Bilinmeyen değerler hata değil, varsayılana düşer');
    }
}
