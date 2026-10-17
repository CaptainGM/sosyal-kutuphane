<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../api/lib/tmdb.php';

final class UnreleasedTest extends TestCase
{
    public function testFutureReleaseDateIsUnreleased(): void
    {
        $info = unreleasedFromTmdb(['release_date' => '2026-12-18', 'status' => 'Post Production'], 'movie', '2026-10-17');
        $this->assertSame(['date' => '2026-12-18'], $info);
        $this->assertStringContainsString('18.12.2026', unreleasedMessage($info, 'yorum yapabilirsin'));
    }

    public function testPastAndTodayAreReleased(): void
    {
        $this->assertNull(unreleasedFromTmdb(['release_date' => '2026-10-17'], 'movie', '2026-10-17'));
        $this->assertNull(unreleasedFromTmdb(['release_date' => '1994-09-23', 'status' => 'Released'], 'movie', '2026-10-17'));
    }

    public function testSeriesUsesFirstAirDate(): void
    {
        $this->assertSame(['date' => '2027-01-05'], unreleasedFromTmdb(['first_air_date' => '2027-01-05'], 'series', '2026-10-17'));
        $this->assertNull(unreleasedFromTmdb(['first_air_date' => '2019-04-14', 'release_date' => '2099-01-01'], 'series', '2026-10-17'));
    }

    public function testMissingDateDependsOnProductionStatus(): void
    {
        $this->assertSame(['date' => null], unreleasedFromTmdb(['release_date' => '', 'status' => 'In Production'], 'movie', '2026-10-17'));
        $this->assertStringNotContainsString('(', unreleasedMessage(['date' => null], 'yorum yapabilirsin'));
        $this->assertNull(unreleasedFromTmdb(['release_date' => '', 'status' => 'Released'], 'movie', '2026-10-17'));
        $this->assertNull(unreleasedFromTmdb([], 'movie', '2026-10-17'), 'Bilgi yoksa engel konmaz');
    }

    public function testBooksAndUnknownContentNeverBlock(): void
    {
        $this->assertNull(unreleasedContent(null, 'book', 'abc123'));
        $this->assertNull(unreleasedContent(null, 'movie', 'not-a-number'));
    }
}
