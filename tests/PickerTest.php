<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../api/lib/picker.php';

final class PickerTest extends TestCase
{
    public function testNormalizeParamsDropsUnknownValuesAndCapsMoods(): void
    {
        $params = pickerNormalizeParams([
            'kind' => 'series',
            'duration' => 'epic',
            'moods' => ['gerilim', 'nope', 'komedi', 'gerilim', 'korku', 'aile'],
            'exclude' => '5,abc,-3,5,7',
        ]);
        $this->assertSame('series', $params['kind']);
        $this->assertSame('any', $params['duration']);
        $this->assertSame(['gerilim', 'komedi', 'korku'], $params['moods']);
        $this->assertSame([5, 7], $params['exclude']);
    }

    public function testDefaultsAreMovieAnyDuration(): void
    {
        $params = pickerNormalizeParams([]);
        $this->assertSame('movie', $params['kind']);
        $this->assertSame('any', $params['duration']);
        $this->assertSame([], $params['moods']);
    }

    public function testMovieQueryUsesMoodGenresAndRuntimeLimits(): void
    {
        $query = pickerDiscoverQuery(pickerNormalizeParams(['kind' => 'movie', 'duration' => 'short', 'moods' => ['gerilim']]), 2);
        $this->assertSame('discover/movie', $query['path']);
        $this->assertSame('53|9648', $query['query']['with_genres']);
        $this->assertSame(100, $query['query']['with_runtime.lte']);
        $this->assertArrayNotHasKey('with_runtime.gte', $query['query']);
        $this->assertSame(2, $query['query']['page']);
        $this->assertSame(500, $query['query']['vote_count.gte']);
    }

    public function testSeriesQueryUsesTvGenresAndEpisodeRuntime(): void
    {
        $query = pickerDiscoverQuery(pickerNormalizeParams(['kind' => 'series', 'duration' => 'long', 'moods' => ['bilimkurgu']]));
        $this->assertSame('discover/tv', $query['path']);
        $this->assertSame('10765', $query['query']['with_genres']);
        $this->assertSame(50, $query['query']['with_runtime.gte']);
    }

    public function testAnyDurationAndNoMoodsAddNoFilters(): void
    {
        $query = pickerDiscoverQuery(pickerNormalizeParams([]));
        $this->assertArrayNotHasKey('with_genres', $query['query']);
        $this->assertArrayNotHasKey('with_runtime.lte', $query['query']);
        $this->assertArrayNotHasKey('with_runtime.gte', $query['query']);
    }

    public function testChooseExcludesSeenAndPosterlessAndIsDeterministic(): void
    {
        $results = [];
        for ($i = 1; $i <= 12; $i++) {
            $results[] = ['id' => $i, 'poster_path' => $i === 4 ? null : "/p$i.jpg", 'vote_average' => 7 + $i / 10];
        }
        $a = pickerChoose($results, [1, 2], 1234);
        $b = pickerChoose($results, [1, 2], 1234);
        $c = pickerChoose($results, [1, 2], 99);

        $this->assertSame($a['pick']['id'], $b['pick']['id']);
        $this->assertSame(9, $a['candidate_count'], '12 aday - 2 dışlanan - 1 afişsiz');
        $this->assertNotContains($a['pick']['id'], [1, 2, 4]);
        $this->assertCount(2, $a['alternatives']);
        $this->assertCount(9, $a['reel']);
        $this->assertNotSame($a['pick']['id'] . $a['alternatives'][0]['id'], $c['pick']['id'] . $c['alternatives'][0]['id'], 'Farklı tohum farklı seçki vermeli');
    }

    public function testChooseWithNoCandidatesReturnsNullPick(): void
    {
        $result = pickerChoose([['id' => 1, 'poster_path' => '/a.jpg']], [1], 7);
        $this->assertNull($result['pick']);
        $this->assertSame([], $result['alternatives']);
    }

    public function testReasonSummarisesRuntimeRatingAndMoods(): void
    {
        $params = pickerNormalizeParams(['kind' => 'series', 'moods' => ['komedi', 'aile']]);
        $this->assertSame('22 dk bölüm · 8.2 puan · Eğlenceli / Aile', pickerReason($params, ['vote_average' => 8.2], 22));
        $this->assertSame('Popüler ve beğenilen bir yapım', pickerReason(pickerNormalizeParams([]), [], null));
    }
}
