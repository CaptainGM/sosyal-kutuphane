<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ApiHelpers.php';
require_once __DIR__ . '/../api/lib/calendar.php';

final class CalendarTest extends TestCase
{
    use ApiHelpers;

    public function testDatesFollowTurkeyTimeZone(): void
    {
        // 23:30 UTC = Türkiye'de ertesi gün 02:30
        [$today, $tomorrow] = calendarDates(new DateTimeImmutable('2026-10-17 23:30:00', new DateTimeZone('UTC')));
        $this->assertSame('2026-10-18', $today);
        $this->assertSame('2026-10-19', $tomorrow);
    }

    public function testNextEpisodeIsReadFromTmdbAndPastOnesAreIgnored(): void
    {
        $future = episodeCalendarEntry([
            'next_episode_to_air' => ['air_date' => '2026-10-20', 'season_number' => 5, 'episode_number' => 3, 'name' => 'Yeni Bölüm'],
        ], '2026-10-17');
        $this->assertSame(['air_date' => '2026-10-20', 'season' => 5, 'episode' => 3, 'episode_name' => 'Yeni Bölüm'], $future);

        $this->assertNull(episodeCalendarEntry(['next_episode_to_air' => ['air_date' => '2026-10-01']], '2026-10-17'), 'Geçmiş tarih');
        $this->assertNull(episodeCalendarEntry(['next_episode_to_air' => null], '2026-10-17'), 'Sona ermiş dizi');
        $this->assertNull(episodeCalendarEntry(['next_episode_to_air' => ['air_date' => 'yakında']], '2026-10-17'), 'Bozuk tarih');
    }

    public function testAlertsOnlyForTodayAndTomorrow(): void
    {
        $this->assertSame('bugün yayında', episodeAlertLabel('2026-10-17', '2026-10-17', '2026-10-18'));
        $this->assertSame('yarın yayında', episodeAlertLabel('2026-10-18', '2026-10-17', '2026-10-18'));
        $this->assertNull(episodeAlertLabel('2026-10-25', '2026-10-17', '2026-10-18'));
    }

    public function testCalendarRequiresLogin(): void
    {
        $guest = new ApiClient(TEST_BASE_URL);
        $res = $guest->get('/api/episode-calendar.php');
        $this->assertSame(401, $res['status']);
    }
}
