<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ApiHelpers.php';

// Sezon sohbeti: bölüm yorumları yalnızca o bölümü izlediğini işaretleyen kullanıcıya açılır ve
// genel listelere (içerik, sosyal, profil) asla sızmaz. TMDB anahtarı gerektirmez.
final class EpisodeThreadApiTest extends TestCase
{
    use ApiHelpers;

    private function mark(ApiClient $client, int $seriesId, int $season, int $episode, bool $watched = true): array
    {
        return $client->post('/api/episode-progress.php', ['series_id' => $seriesId, 'season' => $season, 'episode' => $episode, 'watched' => $watched]);
    }

    private function thread(ApiClient $client, int $seriesId, int $season, int $episode): array
    {
        return $client->get("/api/comments.php?content_type=series&content_id=$seriesId&season=$season&episode=$episode");
    }

    public function testThreadIsLockedUntilWatchedAndNeverLeaksIntoGeneralLists(): void
    {
        $seriesId = 998000 + random_int(1, 900);
        [$a, $aId] = $this->newUser('phpunit_ep_a');
        [$b] = $this->newUser('phpunit_ep_b');
        $secret = 'Final sahnesinde kapı açılıyor ' . $this->suffix();

        $this->assertTrue($this->mark($a, $seriesId, 1, 3)['data']['watched']['1:3']);
        $this->assertTrue($a->post('/api/comments.php', ['content_type' => 'series', 'content_id' => $seriesId, 'comment_text' => $secret, 'season_number' => 1, 'episode_number' => 3])['data']['success'] ?? false);
        $this->assertTrue($a->post('/api/comments.php', ['content_type' => 'series', 'content_id' => $seriesId, 'comment_text' => 'Dizi genel olarak güzel'])['data']['success'] ?? false);

        // İzlemeyen kullanıcı: kilitli, içerik yok, yazamaz.
        $locked = $this->thread($b, $seriesId, 1, 3);
        $this->assertTrue($locked['data']['locked']);
        $this->assertSame(1, $locked['data']['thread_count']);
        $this->assertSame([], $locked['data']['comments']);
        $this->assertSame(403, $b->post('/api/comments.php', ['content_type' => 'series', 'content_id' => $seriesId, 'comment_text' => 'izlemeden yazıyorum', 'season_number' => 1, 'episode_number' => 3])['status']);

        // Sızıntı denetimi: hiçbir genel listede bölüm yorumu olmamalı (ne izleyene ne izlemeyene ne misafire).
        $guest = new ApiClient(TEST_BASE_URL);
        foreach ([$a, $b, $guest] as $viewer) {
            $this->assertNotContains($secret, $this->texts($viewer->get('/api/comments.php?all=true')), 'all=true listesinde bölüm yorumu görünmemeli');
            $this->assertNotContains($secret, $this->texts($viewer->get("/api/comments.php?user_id=$aId")), 'user_id= listesinde bölüm yorumu görünmemeli');
            $this->assertSame(['Dizi genel olarak güzel'], $this->texts($viewer->get("/api/comments.php?content_type=series&content_id=$seriesId")));
        }
        $this->assertNotContains($secret, $this->texts($a->get('/api/comments.php')), 'Kendi yorum listesinde de görünmemeli');

        // Bölümü izlediğini işaretleyince sohbet açılır.
        $this->mark($b, $seriesId, 1, 3);
        $open = $this->thread($b, $seriesId, 1, 3);
        $this->assertFalse($open['data']['locked']);
        $this->assertSame([$secret], $this->texts($open));

        // İşareti kaldırınca yine kilitlenir.
        $this->mark($b, $seriesId, 1, 3, false);
        $this->assertTrue($this->thread($b, $seriesId, 1, 3)['data']['locked']);
    }

    public function testRepliesInheritTheEpisodeAndMismatchIsRejected(): void
    {
        $seriesId = 998000 + random_int(1, 900);
        [$a] = $this->newUser('phpunit_ep_reply');
        $this->mark($a, $seriesId, 2, 5);

        $top = $a->post('/api/comments.php', ['content_type' => 'series', 'content_id' => $seriesId, 'comment_text' => 'Bölüm tartışması', 'season_number' => 2, 'episode_number' => 5]);
        $topId = $top['data']['id'];

        $reply = $a->post('/api/comments.php', ['content_type' => 'series', 'content_id' => $seriesId, 'comment_text' => 'Katılıyorum', 'parent_comment_id' => $topId]);
        $this->assertTrue($reply['data']['success'] ?? false);
        $this->assertSame(2, $reply['data']['comment']['season_number']);
        $this->assertSame(5, $reply['data']['comment']['episode_number']);

        $mismatch = $a->post('/api/comments.php', ['content_type' => 'series', 'content_id' => $seriesId, 'comment_text' => 'Yanlış bölüm', 'parent_comment_id' => $topId, 'season_number' => 2, 'episode_number' => 6]);
        $this->assertSame(400, $mismatch['status']);

        $thread = $this->thread($a, $seriesId, 2, 5);
        $this->assertCount(1, $thread['data']['comments']);
        $this->assertSame('Katılıyorum', $thread['data']['comments'][0]['replies'][0]['comment_text']);
    }

    public function testInvalidReferencesAreRejected(): void
    {
        $seriesId = 998000 + random_int(1, 900);
        [$a] = $this->newUser('phpunit_ep_bad');

        $this->assertSame(400, $a->post('/api/episode-progress.php', ['series_id' => $seriesId, 'season' => -1, 'episode' => 1, 'watched' => true])['status']);
        $this->assertSame(400, $a->post('/api/episode-progress.php', ['series_id' => $seriesId, 'season' => 1, 'episode' => 0, 'watched' => true])['status']);
        $this->assertSame(400, $a->post('/api/episode-progress.php', ['season' => 1, 'episode' => 1, 'watched' => true])['status']);
        $this->assertSame(400, $a->get('/api/episode-progress.php')['status']);

        $movie = $a->post('/api/comments.php', ['content_type' => 'movie', 'content_id' => 999400, 'comment_text' => 'film bölümü olmaz', 'season_number' => 1, 'episode_number' => 1]);
        $this->assertSame(400, $movie['status']);
        $this->assertSame(400, $this->thread($a, $seriesId, 0, 0)['status']);

        $guest = new ApiClient(TEST_BASE_URL);
        $this->assertSame(401, $this->thread($guest, $seriesId, 1, 1)['status'], 'Bölüm sohbeti oturum gerektirir');
        $this->assertSame(401, $guest->post('/api/episode-progress.php', ['series_id' => $seriesId, 'season' => 1, 'episode' => 1, 'watched' => true])['status']);
    }

    public function testBulkProgressAndSeasonCounts(): void
    {
        $seriesId = 998000 + random_int(1, 900);
        [$a] = $this->newUser('phpunit_ep_bulk');

        $bulk = $a->post('/api/episode-progress.php', ['series_id' => $seriesId, 'season' => 2, 'episodes' => [1, 2, 3, 3, 'x'], 'watched' => true]);
        $this->assertSame(3, $bulk['data']['season_counts']['2']);

        $a->post('/api/episode-progress.php', ['series_id' => $seriesId, 'season' => 2, 'episodes' => [1, 2], 'watched' => false]);
        $state = $a->get("/api/episode-progress.php?series_id=$seriesId");
        $this->assertSame(['2:3' => true], $state['data']['watched']);
        $this->assertSame(1, $state['data']['season_counts']['2']);
    }

    public function testEpisodeListEndpointRespondsOrReportsMissingTmdbKey(): void
    {
        [$a] = $this->newUser('phpunit_ep_list');
        $this->assertSame(400, $a->get('/api/episodes.php')['status']);

        $res = $a->get('/api/episodes.php?series_id=1396');
        if ($res['status'] === 503) {
            $this->assertFalse($res['data']['success']);
            return;
        }
        $this->assertTrue($res['data']['success']);
        $this->assertNotEmpty($res['data']['seasons']);
        $this->assertSame(1, $res['data']['seasons'][0]['number'], 'Özel bölümler (sezon 0) sona alınır');
    }
}
