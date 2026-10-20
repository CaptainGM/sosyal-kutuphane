<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../api/lib/upcoming.php';

final class UpcomingTest extends TestCase
{
    public function testDiscoverQueryAsksForTurkeyTheatricalReleasesFromTodayOn(): void
    {
        $query = upcomingDiscoverQuery('KEY', '2026-10-20', 2);
        $this->assertSame('TR', $query['region']);
        $this->assertSame('2|3', $query['with_release_type'], 'yalnızca sinema vizyonu (sınırlı + geniş)');
        $this->assertSame('2026-10-20', $query['release_date.gte']);
        $this->assertSame('2027-06-17', $query['release_date.lte'], 'bugünden 240 gün sonrası');
        $this->assertSame('popularity.desc', $query['sort_by']);
        $this->assertSame(2, $query['page']);
        $this->assertSame('false', $query['include_adult']);
    }

    public function testDiscoverQueryKeepsPageInsideTmdbPageBudget(): void
    {
        $this->assertSame(1, upcomingDiscoverQuery('K', '2026-10-20', 0)['page']);
        $this->assertSame(UPCOMING_TMDB_PAGES, upcomingDiscoverQuery('K', '2026-10-20', 99)['page']);
    }

    public function testCalendarSortsByReleaseDateKeepsPopularityOrderWithinADayAndDropsDuplicates(): void
    {
        $calendar = upcomingCalendar([
            ['id' => 1, 'title' => 'Aralık filmi', 'release_date' => '2026-12-18', 'poster_path' => '/a.jpg', 'overview' => 'x', 'vote_average' => 0],
            ['id' => 2, 'title' => 'Kasım, popüler', 'release_date' => '2026-11-06', 'poster_path' => '/b.jpg', 'overview' => 'x', 'vote_average' => 6.66],
            ['id' => 3, 'title' => 'Kasım, daha az popüler', 'release_date' => '2026-11-06', 'poster_path' => null, 'overview' => '', 'vote_average' => 0],
            ['id' => 2, 'title' => 'Yinelenen', 'release_date' => '2026-11-06'],
            ['title' => 'Kimliksiz'],
            'bozuk',
            ['id' => 4, 'title' => 'Tarihsiz', 'release_date' => ''],
        ]);

        $this->assertSame([2, 3, 1, 4], array_column($calendar, 'tmdb_id'));
        $this->assertSame('Kasım, popüler', $calendar[0]['title'], 'yineleneni ilk gelen kazanır');
        $this->assertSame(6.7, $calendar[0]['vote_average']);
        $this->assertNull($calendar[3]['release_date'], 'tarihsiz kayıt sona gider');
        $this->assertNull($calendar[1]['poster_path']);
    }

    public function testCalendarShortensLongOverviews(): void
    {
        $calendar = upcomingCalendar([['id' => 1, 'title' => 'Uzun', 'release_date' => '2026-11-06', 'overview' => str_repeat('ş', 400)]]);
        $this->assertSame(180, mb_strlen($calendar[0]['overview']));
    }

    public function testPageSlicesTwentyPerPageAndClampsOutOfRangeRequests(): void
    {
        $calendar = array_map(static fn (int $i): array => ['tmdb_id' => $i], range(1, 45));

        [$items, $page, $total] = upcomingPage($calendar, 1);
        $this->assertSame([20, 1, 3], [count($items), $page, $total]);

        [$items, $page, $total] = upcomingPage($calendar, 3);
        $this->assertSame([5, 3, 3], [count($items), $page, $total]);
        $this->assertSame(41, $items[0]['tmdb_id']);

        [, $page] = upcomingPage($calendar, 99);
        $this->assertSame(3, $page, 'sayfa sayısını aşan istek son sayfaya düşer');

        [, $page] = upcomingPage($calendar, 0);
        $this->assertSame(1, $page);

        [$items, $page, $total] = upcomingPage([], 1);
        $this->assertSame([0, 1, 1], [count($items), $page, $total], 'boş takvim hatasız döner');
    }
}
