<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../api/lib/search.php';

final class FuzzySearchTest extends TestCase
{
    public function testNormalizeFoldsTurkishLettersAndPunctuation(): void
    {
        $this->assertSame('guc yuzukleri', searchNormalize('Güç  Yüzükleri!'));
        $this->assertSame('sisli', searchNormalize('ŞİŞLİ'));
    }

    public function testMissingLetterStillMatchesTitle(): void
    {
        $this->assertGreaterThanOrEqual(SEARCH_FUZZY_THRESHOLD, searchSimilarity('odysey', 'Odyssey'));
        $this->assertGreaterThanOrEqual(SEARCH_FUZZY_THRESHOLD, searchSimilarity('odysey', 'The Odyssey'));
    }

    public function testMatchesWordInsideLongerTitle(): void
    {
        $this->assertGreaterThanOrEqual(SEARCH_FUZZY_THRESHOLD, searchSimilarity('inception', 'Inceptoin'));
    }

    public function testUnrelatedTitlesStayBelowThreshold(): void
    {
        // Dizi araması için kritik: film sorgusu başka bir yapımı yanlış eşleştirmemeli.
        $this->assertLessThan(SEARCH_FUZZY_THRESHOLD, searchSimilarity('Güç Yüzükleri', 'Yüzüklerin Efendisi'));
        $this->assertLessThan(SEARCH_FUZZY_THRESHOLD, searchSimilarity('Güç Yüzükleri', 'Dune'));
    }

    public function testPartialNamesMatchTitleStartOrWord(): void
    {
        // Kullanıcı tam adı yazmak zorunda değil: başlığın başı ya da bir sözcüğün başı yeterli.
        $this->assertGreaterThanOrEqual(SEARCH_FUZZY_THRESHOLD, searchSimilarity('odys', 'Odyssey'));
        $this->assertGreaterThanOrEqual(SEARCH_FUZZY_THRESHOLD, searchSimilarity('ody', 'The Odyssey'));
        $this->assertLessThan(SEARCH_FUZZY_THRESHOLD, searchSimilarity('od', 'Odyssey'), 'too short to count as partial');
    }

    public function testEmptyInputScoresZero(): void
    {
        $this->assertSame(0.0, searchSimilarity('', 'Odyssey'));
        $this->assertSame(0.0, searchSimilarity('Odyssey', '   '));
    }

    public function testRankKeepsOnlyCloseMatchesSortedByScore(): void
    {
        $items = [
            ['id' => 1, 'title' => 'Dune'],
            ['id' => 2, 'title' => 'Odyssey'],
            ['id' => 3, 'title' => 'The Odyssey'],
            ['id' => 4, 'title' => 'Yüzüklerin Efendisi'],
        ];

        $ranked = rankFuzzyResults($items, 'odysey', fn($item) => $item['title']);

        $ids = array_column($ranked, 'id');
        $this->assertSame(2, $ids[0], 'exact-after-typo title ranks first');
        $this->assertContains(3, $ids);
        $this->assertNotContains(1, $ids);
        $this->assertNotContains(4, $ids);
    }
}
