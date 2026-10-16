<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ApiHelpers.php';

// Takip akışı: yalnızca takip edilenlerin hareketleri, engel ve e-posta gizliliği (çalışan sunucu ve MongoDB gerekir, bkz. ApiTest).
final class FeedApiTest extends TestCase
{
    use ApiHelpers;

    private function watch(ApiClient $client, string $type, $contentId, string $status, ?int $rating, string $title): void
    {
        $body = ['content_type' => $type, 'content_id' => $contentId, 'status' => $status, 'content_data' => ['title' => $title]];
        if ($rating !== null) {
            $body['rating'] = $rating;
        }
        $result = $client->post('/api/user-status.php', $body);
        $this->assertTrue($result['data']['success'] ?? false, json_encode($result['data']));
    }

    public function testFeedListsOnlyFollowedUsersActivitiesNewestFirst(): void
    {
        [$viewer] = $this->newUser('phpunit_feed_v');
        [$followed, $followedId] = $this->newUser('phpunit_feed_f');
        [$stranger] = $this->newUser('phpunit_feed_s');

        $movieId = 700000 + random_int(1, 99999);
        $seriesId = 710000 + random_int(1, 99999);
        $bookId = 'phpunit_feed_' . $this->suffix();
        $this->watch($followed, 'movie', $movieId, 'watched', 9, 'Akış Filmi');
        $this->watch($followed, 'series', $seriesId, 'watching', null, 'Akış Dizisi');
        $this->watch($followed, 'book', $bookId, 'read', 8, 'Akış Kitabı');
        $this->watch($stranger, 'movie', 720000 + random_int(1, 99999), 'watched', 7, 'Yabancı Film');

        $viewer->post('/api/follow.php', ['target_user_id' => $followedId]);

        $feed = $viewer->get('/api/feed.php');
        $this->assertTrue($feed['data']['success'] ?? false);
        $this->assertSame(1, $feed['data']['following_count']);

        $titles = array_column($feed['data']['activities'], 'title');
        $this->assertContains('Akış Filmi', $titles);
        $this->assertContains('Akış Dizisi', $titles);
        $this->assertContains('Akış Kitabı', $titles);
        $this->assertNotContains('Yabancı Film', $titles, 'Takip edilmeyen kullanıcı akışta görünmemeli');

        $first = $feed['data']['activities'][0];
        $this->assertSame('Akış Kitabı', $first['title'], 'En son eklenen en üstte olmalı');
        $this->assertSame('book', $first['type']);
        $this->assertSame($followedId, $first['user']['id']);
        $this->assertArrayNotHasKey('email', $first['user']);

        $ats = array_column($feed['data']['activities'], 'at');
        $sorted = $ats;
        rsort($sorted);
        $this->assertSame($sorted, $ats, 'Akış yeniden eskiye sıralı olmalı');

        $series = array_values(array_filter($feed['data']['activities'], static fn (array $a): bool => $a['type'] === 'series'))[0];
        $this->assertSame('watching', $series['status']);
        $this->assertNull($series['rating']);
    }

    public function testFeedIsEmptyWithoutFollowsAndRequiresLogin(): void
    {
        [$viewer] = $this->newUser('phpunit_feed_e');
        $feed = $viewer->get('/api/feed.php');
        $this->assertSame([], $feed['data']['activities']);
        $this->assertSame(0, $feed['data']['following_count']);

        $anonymous = new ApiClient(TEST_BASE_URL);
        $this->assertSame(401, $anonymous->get('/api/feed.php')['status']);
    }

    public function testBlockedFollowedUserIsHiddenEvenIfFollowRowRemains(): void
    {
        [$viewer, $viewerId] = $this->newUser('phpunit_feed_bv');
        [$followed, $followedId] = $this->newUser('phpunit_feed_bf');
        $this->watch($followed, 'movie', 730000 + random_int(1, 99999), 'watched', 6, 'Engelli Akış Filmi');
        $viewer->post('/api/follow.php', ['target_user_id' => $followedId]);

        $this->assertContains('Engelli Akış Filmi', array_column($viewer->get('/api/feed.php')['data']['activities'], 'title'));

        // Takip kaydı kalsa bile engel, akışı ve yorum listesini kapatır (iki yönde de geçerlidir).
        $this->mongo()->blocks->insertOne(['_id' => $this->nextId('blocks'), 'blocker_id' => $followedId, 'blocked_id' => $viewerId, 'created_at' => new MongoDB\BSON\UTCDateTime()]);
        $this->assertNotContains('Engelli Akış Filmi', array_column($viewer->get('/api/feed.php')['data']['activities'], 'title'));
    }

    public function testFollowingModeOfCommentsReturnsOnlyFollowedAuthors(): void
    {
        [$viewer] = $this->newUser('phpunit_feed_cv');
        [$followed, $followedId] = $this->newUser('phpunit_feed_cf');
        [$stranger] = $this->newUser('phpunit_feed_cs');
        $contentId = 740000 + random_int(1, 99999);

        $followed->post('/api/comments.php', ['content_type' => 'movie', 'content_id' => $contentId, 'comment_text' => 'Takip edilenin yorumu']);
        $stranger->post('/api/comments.php', ['content_type' => 'movie', 'content_id' => $contentId, 'comment_text' => 'Yabancının yorumu']);
        $viewer->post('/api/follow.php', ['target_user_id' => $followedId]);

        $texts = $this->texts($viewer->get('/api/comments.php?following=true'));
        $this->assertContains('Takip edilenin yorumu', $texts);
        $this->assertNotContains('Yabancının yorumu', $texts);

        $anonymous = new ApiClient(TEST_BASE_URL);
        $this->assertSame(401, $anonymous->get('/api/comments.php?following=true')['status']);
    }

    public function testFollowingListDoesNotLeakEmails(): void
    {
        [$viewer] = $this->newUser('phpunit_feed_lv');
        [, $followedId] = $this->newUser('phpunit_feed_lf');
        $viewer->post('/api/follow.php', ['target_user_id' => $followedId]);

        $list = $viewer->get('/api/get-following.php');
        $this->assertCount(1, $list['data']['following']);
        $this->assertArrayNotHasKey('email', $list['data']['following'][0]);
    }
}
