<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../api/lib/moderation.php';

// Yorum moderasyonu ve spam sınırının HTTP katmanındaki davranışı (çalışan sunucu ve MongoDB gerekir, bkz. ApiTest).
final class CommentModerationApiTest extends TestCase
{
    private function uniqueSuffix(): string
    {
        return substr(bin2hex(random_bytes(4)), 0, 8);
    }

    private function newUserClient(): ApiClient
    {
        $suffix = $this->uniqueSuffix();
        $client = new ApiClient(TEST_BASE_URL);
        $client->register("phpunit_mod_$suffix", "phpunit_mod_$suffix@test.com", 'Sifre123456!');
        return $client;
    }

    public function testLinkAndIbanAreRejectedWithUnprocessableStatus(): void
    {
        $client = $this->newUserClient();

        $link = $client->post('/api/comments.php', ['content_type' => 'movie', 'content_id' => 999210, 'comment_text' => 'Bak: https://spam.example/indir']);
        $this->assertSame(422, $link['status']);
        $this->assertFalse($link['data']['success'] ?? true);

        $iban = $client->post('/api/comments.php', ['content_type' => 'movie', 'content_id' => 999210, 'comment_text' => 'TR33 0006 1005 1978 6457 8413 26']);
        $this->assertSame(422, $iban['status']);
    }

    public function testDuplicateCommentOnSameContentIsRateLimited(): void
    {
        $client = $this->newUserClient();

        $first = $client->post('/api/comments.php', ['content_type' => 'movie', 'content_id' => 999211, 'comment_text' => 'Aynı yorum']);
        $this->assertTrue($first['data']['success'] ?? false);

        $second = $client->post('/api/comments.php', ['content_type' => 'movie', 'content_id' => 999211, 'comment_text' => 'aynı yorum ']);
        $this->assertSame(429, $second['status'], 'Aynı içeriğe aynı yorum tekrar gönderilemez');
    }

    public function testBurstOfCommentsIsLimitedAfterFiveInAMinute(): void
    {
        $client = $this->newUserClient();
        $ids = [];

        for ($i = 1; $i <= COMMENT_BURST_LIMIT; $i++) {
            $res = $client->post('/api/comments.php', ['content_type' => 'movie', 'content_id' => 999212, 'comment_text' => "Hızlı yorum $i"]);
            $this->assertTrue($res['data']['success'] ?? false, "$i. yorum kabul edilmeli");
            $ids[] = $res['data']['id'];
        }

        $blocked = $client->post('/api/comments.php', ['content_type' => 'movie', 'content_id' => 999212, 'comment_text' => 'Altıncı yorum']);
        $this->assertSame(429, $blocked['status']);

        foreach ($ids as $id) {
            $client->delete('/api/comments.php', ['comment_id' => $id]);
        }
    }

    public function testInvalidTypesAreRejectedWithoutServerError(): void
    {
        $client = $this->newUserClient();

        $arrayText = $client->post('/api/comments.php', ['content_type' => 'movie', 'content_id' => 999213, 'comment_text' => ['x']]);
        $this->assertSame(400, $arrayText['status'], 'Dizi yorum metni 500 değil 400 dönmeli');

        $badType = $client->post('/api/comments.php', ['content_type' => 'podcast', 'content_id' => 1, 'comment_text' => 'Merhaba']);
        $this->assertSame(400, $badType['status']);

        $badId = $client->post('/api/comments.php', ['content_type' => 'movie', 'content_id' => 'abc', 'comment_text' => 'Merhaba']);
        $this->assertSame(400, $badId['status']);
    }

    public function testRepliesAreSingleLevelAndEditIsModerated(): void
    {
        $client = $this->newUserClient();
        $contentId = 999214;

        $top = $client->post('/api/comments.php', ['content_type' => 'movie', 'content_id' => $contentId, 'comment_text' => 'Üst yorum']);
        $topId = $top['data']['id'];

        $reply = $client->post('/api/comments.php', ['content_type' => 'movie', 'content_id' => $contentId, 'comment_text' => 'Yanıt', 'parent_comment_id' => $topId]);
        $this->assertTrue($reply['data']['success'] ?? false);

        $nested = $client->post('/api/comments.php', ['content_type' => 'movie', 'content_id' => $contentId, 'comment_text' => 'Yanıtın yanıtı', 'parent_comment_id' => $reply['data']['id']]);
        $this->assertSame(404, $nested['status'], 'Yanıta yanıt yazılamaz');

        $edit = $client->put('/api/comments.php', ['comment_id' => $topId, 'comment_text' => 'ahmak herif']);
        $this->assertSame(422, $edit['status'], 'Düzenleme de moderasyondan geçer');

        $client->delete('/api/comments.php', ['comment_id' => $topId]);
    }

    public function testEditedCommentIsFlaggedAndCreatedAtIsZoned(): void
    {
        $client = $this->newUserClient();
        $contentId = 999215;

        $created = $client->post('/api/comments.php', ['content_type' => 'movie', 'content_id' => $contentId, 'comment_text' => 'İlk hali']);
        $commentId = $created['data']['id'];

        sleep(2);
        $client->put('/api/comments.php', ['comment_id' => $commentId, 'comment_text' => 'Düzenlenmiş hali']);

        $list = $client->get("/api/comments.php?content_type=movie&content_id=$contentId");
        $comment = $list['data']['comments'][0] ?? null;
        $this->assertNotNull($comment);
        $this->assertTrue($comment['edited'], 'Düzenlenen yorum işaretlenmeli');
        $this->assertStringEndsWith('+00:00', $comment['created_at'], 'Zaman damgası saat dilimi taşımalı');
        $this->assertNotSame($comment['created_at'], $comment['updated_at']);

        $client->delete('/api/comments.php', ['comment_id' => $commentId]);
    }
}
