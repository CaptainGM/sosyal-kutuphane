<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../api/lib/taste.php';

final class TasteTest extends TestCase
{
    private function item(string $key, ?int $rating, array $genres = ['Dram'], string $status = 'watched', string $title = 'Film'): array
    {
        return ['key' => $key, 'type' => 'movie', 'content_id' => (int) substr($key, 6), 'title' => $title . ' ' . $key, 'poster' => null, 'rating' => $rating, 'status' => $status, 'genres' => $genres];
    }

    private function library(array $ratings, array $genres = ['Dram']): array
    {
        $items = [];
        foreach ($ratings as $id => $rating) {
            $items[] = $this->item("movie:$id", $rating, $genres);
        }
        return $items;
    }

    public function testIdenticalLibrariesAreSoulmates(): void
    {
        $report = tasteReport($this->library([1 => 9, 2 => 8, 3 => 7, 4 => 10, 5 => 6]), $this->library([1 => 9, 2 => 8, 3 => 7, 4 => 10, 5 => 6]));
        $this->assertSame(100, $report['score']);
        $this->assertSame('Zevk ikizleri', $report['label']);
        $this->assertSame('high', $report['confidence']);
        $this->assertSame(5, $report['shared_rated']);
    }

    public function testOppositeRatingsAndDisjointGenresScoreZero(): void
    {
        $mine = $this->library([1 => 10, 2 => 10, 3 => 10, 4 => 10, 5 => 10], ['Dram']);
        $theirs = $this->library([1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 1], ['Korku']);
        $report = tasteReport($mine, $theirs);
        $this->assertSame(0, $report['score']);
        $this->assertSame('Zıt kutuplar', $report['label']);
    }

    public function testNoOverlapAtAllGivesNoScore(): void
    {
        $report = tasteReport([$this->item('movie:1', 8, ['Dram'])], [$this->item('movie:2', 8, ['Korku'])]);
        $this->assertNull($report['score']);
        $this->assertNull($report['label']);
        $this->assertSame('none', $report['basis']);
    }

    public function testSingleSharedFilmIsPulledTowardsMiddle(): void
    {
        $report = tasteReport([$this->item('movie:1', 9)], [$this->item('movie:1', 9)]);
        $this->assertSame('low', $report['confidence']);
        $this->assertLessThan(90, $report['score'], 'Tek ortak film "zevk ikizi" yaptırmamalı');
        $this->assertGreaterThan(50, $report['score']);
    }

    public function testGenresOnlyBasisIsCapped(): void
    {
        $report = tasteReport([$this->item('movie:1', 8, ['Dram'])], [$this->item('movie:2', 8, ['Dram'])]);
        $this->assertSame('genres', $report['basis']);
        $this->assertSame(80, $report['score']);
        $this->assertSame(['Dram'], $report['common_genres']);
    }

    public function testLovedTogetherDisagreementsAndSuggestions(): void
    {
        $mine = [
            $this->item('movie:1', 10), $this->item('movie:2', 9), $this->item('movie:3', 2),
            $this->item('movie:5', 9), $this->item('movie:7', 5, ['Dram'], 'watchlist'),
        ];
        $theirs = [
            $this->item('movie:1', 9), $this->item('movie:2', 8), $this->item('movie:3', 9),
            $this->item('movie:6', 10), $this->item('movie:7', null, ['Dram'], 'watchlist'),
        ];
        $report = tasteReport($mine, $theirs);

        $this->assertSame(['movie 1', 'movie 2'], array_map(fn ($p) => 'movie ' . $p['item']['content_id'], $report['loved_together']));
        $this->assertSame(7, $report['disagreements'][0]['diff']);
        $this->assertSame(3, $report['disagreements'][0]['item']['content_id']);
        $this->assertSame(6, $report['they_loved_you_havent'][0]['item']['content_id']);
        $this->assertSame(5, $report['you_loved_they_havent'][0]['item']['content_id']);
        $this->assertSame([7], array_map(fn ($i) => $i['content_id'], $report['watch_together']));
    }

    public function testLabelThresholds(): void
    {
        $this->assertNull(tasteLabel(null));
        $this->assertSame('Zevk ikizleri', tasteLabel(90));
        $this->assertSame('Çok uyumlu', tasteLabel(89));
        $this->assertSame('Uyumlu', tasteLabel(55));
        $this->assertSame('Karışık', tasteLabel(54));
        $this->assertSame('Zıt kutuplar', tasteLabel(34));
    }

    public function testGenreNamesAcceptStringsAndDocuments(): void
    {
        $names = tasteGenreNames(['Dram', ['id' => 18, 'name' => 'Gerilim'], (object) ['name' => 'Komedi'], ['id' => 1], 'Dram']);
        $this->assertSame(['Dram', 'Gerilim', 'Komedi'], $names);
        $this->assertSame([], tasteGenreNames(null));
    }

    public function testNumericTmdbGenreIdsAreMappedToTurkishNames(): void
    {
        $this->assertSame(['Macera', 'Aksiyon', 'Fantastik'], tasteGenreNames([12, 28, 14]));
        $this->assertSame(['Dram', 'x1'], tasteGenreNames(['18', 99999, 'x1']), 'Bilinmeyen kimlik atılır, metin korunur');
    }

    public function testImageUrlRules(): void
    {
        $this->assertSame('https://image.tmdb.org/t/p/w185/abc.jpg', tasteImageUrl('movie', '/abc.jpg'));
        $this->assertNull(tasteImageUrl('movie', 'abc.jpg'));
        $this->assertSame('https://books.example/c.jpg', tasteImageUrl('book', 'http://books.example/c.jpg'));
        $this->assertNull(tasteImageUrl('series', null));
    }
}
