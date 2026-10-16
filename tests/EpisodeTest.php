<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../api/lib/episodes.php';

final class EpisodeTest extends TestCase
{
    public function testEpisodeRefValidation(): void
    {
        $this->assertSame(['season' => 2, 'episode' => 5], episodeRef('2', '5'));
        $this->assertSame(['season' => 0, 'episode' => 1], episodeRef(0, 1), 'Sezon 0 özel bölümlerdir');
        $this->assertNull(episodeRef(-1, 1));
        $this->assertNull(episodeRef(1, 0));
        $this->assertNull(episodeRef('x', 1));
        $this->assertNull(episodeRef(101, 1));
        $this->assertNull(episodeRef(1, 1001));
        $this->assertNull(episodeRef([1], 1));
    }

    public function testEpisodeStateFromAirDate(): void
    {
        $now = strtotime('2026-10-16 12:00:00 UTC');
        $this->assertSame('upcoming', episodeState('2026-10-20', $now));
        $this->assertSame('this_week', episodeState('2026-10-16', $now));
        $this->assertSame('this_week', episodeState('2026-10-10', $now));
        $this->assertSame('aired', episodeState('2026-10-01', $now));
        $this->assertSame('unknown', episodeState(null, $now));
        $this->assertSame('unknown', episodeState('not-a-date', $now));
    }

    public function testNormalizeSeasonKeepsOrderAndRejectsBadStill(): void
    {
        $now = strtotime('2026-10-16 12:00:00 UTC');
        $episodes = episodeNormalizeSeason(['episodes' => [
            ['episode_number' => 2, 'name' => 'İkinci', 'air_date' => '2026-10-09', 'still_path' => '/good.jpg', 'runtime' => 50],
            ['episode_number' => 1, 'name' => 'Birinci', 'air_date' => '2026-09-01', 'still_path' => 'javascript:alert(1)', 'overview' => str_repeat('a', 500)],
            ['name' => 'Numarasız'],
        ]], $now);

        $this->assertCount(2, $episodes);
        $this->assertSame([1, 2], array_column($episodes, 'number'));
        $this->assertNull($episodes[0]['still']);
        $this->assertSame(400, mb_strlen($episodes[0]['overview']));
        $this->assertSame('https://image.tmdb.org/t/p/w300/good.jpg', $episodes[1]['still']);
        $this->assertSame('this_week', $episodes[1]['state']);
        $this->assertSame('aired', $episodes[0]['state']);
    }

    public function testSeasonsPutSpecialsLast(): void
    {
        $seasons = episodeNormalizeSeasons(['seasons' => [
            ['season_number' => 0, 'name' => 'Özel', 'episode_count' => 3],
            ['season_number' => 2, 'name' => '2. Sezon', 'episode_count' => 10],
            ['season_number' => 1, 'name' => '1. Sezon', 'episode_count' => 8],
        ]]);
        $this->assertSame([1, 2, 0], array_column($seasons, 'number'));
    }

    public function testThreadUnlockedOnlyForWatchedEpisodes(): void
    {
        $watched = [episodeKey(1, 3) => true];
        $this->assertTrue(episodeThreadUnlocked($watched, 1, 3));
        $this->assertFalse(episodeThreadUnlocked($watched, 1, 4));
        $this->assertFalse(episodeThreadUnlocked($watched, 2, 3));
    }

    public function testSeasonProgress(): void
    {
        $watched = [episodeKey(1, 1) => true, episodeKey(1, 2) => true, episodeKey(2, 1) => true, episodeKey(10, 1) => true];
        $this->assertSame(['watched' => 2, 'total' => 8, 'percent' => 25], episodeSeasonProgress($watched, 1, 8));
        $this->assertSame(['watched' => 1, 'total' => null, 'percent' => null], episodeSeasonProgress($watched, 2, null));
        $this->assertSame(1, episodeSeasonProgress($watched, 10, 5)['watched'], '1. sezon 10. sezonla karışmamalı');
    }
}
