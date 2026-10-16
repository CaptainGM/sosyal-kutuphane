<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../api/lib/badges.php';

final class BadgesTest extends TestCase
{
    public function testBadgeIdsAreUnique(): void
    {
        $ids = array_column(badgeDefinitions(), 'id');
        $this->assertSame(count($ids), count(array_unique($ids)));
    }

    public function testNoBadgesUnlockedForEmptyLibrary(): void
    {
        $badges = computeBadges([]);

        $this->assertCount(count(badgeDefinitions()), $badges);
        foreach ($badges as $badge) {
            $this->assertFalse($badge['unlocked'], $badge['id']);
            $this->assertSame(0, $badge['progress'], $badge['id']);
        }
    }

    public function testUnlocksAtExactlyTheGoal(): void
    {
        $badges = array_column(computeBadges(['watched_movies' => 10]), null, 'id');

        $this->assertTrue($badges['film-10']['unlocked']);
        $this->assertSame(10, $badges['film-10']['progress']);
        $this->assertFalse($badges['film-100']['unlocked']);
        $this->assertSame(10, $badges['film-100']['progress']);
    }

    public function testProgressIsCappedAtGoal(): void
    {
        $badges = array_column(computeBadges(['read_books' => 40]), null, 'id');

        $this->assertSame(25, $badges['book-25']['progress']);
        $this->assertSame(25, $badges['book-25']['goal']);
        $this->assertTrue($badges['book-25']['unlocked']);
    }

    public function testNegativeOrMissingCountsAreTreatedAsZero(): void
    {
        $badges = array_column(computeBadges(['ratings' => -5]), null, 'id');

        $this->assertSame(0, $badges['rater-25']['progress']);
        $this->assertFalse($badges['rater-25']['unlocked']);
    }

    public function testFirstStepUnlocksWithAnyItem(): void
    {
        $badges = array_column(computeBadges(['items' => 1]), null, 'id');

        $this->assertTrue($badges['first-step']['unlocked']);
    }
}
