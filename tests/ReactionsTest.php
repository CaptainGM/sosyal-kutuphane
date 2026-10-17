<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ApiHelpers.php';

// Yorum tepkileri: bir kullanıcı bir yoruma en fazla bir tepki, kendi yorumuna tepki yok, demo hesap tepki veremez.
final class ReactionsTest extends TestCase
{
    use ApiHelpers;

    private function commentOn(ApiClient $author): array
    {
        $contentId = 900000000 + random_int(1, 99999999);
        $res = $author->post('/api/comments.php', ['content_type' => 'movie', 'content_id' => $contentId, 'comment_text' => 'Tepki testi ' . $this->suffix()]);
        $this->assertTrue($res['data']['success'] ?? false, json_encode($res['data']));
        return [(int) $res['data']['id'], $contentId];
    }

    private function rowFor(ApiClient $viewer, int $contentId, int $commentId): array
    {
        $list = $viewer->get("/api/comments.php?content_type=movie&content_id=$contentId");
        foreach ($list['data']['comments'] ?? [] as $row) {
            if ((int) $row['id'] === $commentId) {
                return $row;
            }
        }
        $this->fail('Yorum listede yok');
    }

    public function testReactAgainChangeAndRemove(): void
    {
        [$author] = $this->newUser('rxauthor');
        [$reactor] = $this->newUser('rxreactor');
        [$commentId, $contentId] = $this->commentOn($author);

        $first = $reactor->post('/api/comment-reaction.php', ['comment_id' => $commentId, 'type' => 'laugh']);
        $this->assertSame(200, $first['status']);
        $this->assertSame('laugh', $first['data']['my_reaction']);
        $this->assertSame(1, $first['data']['reactions']['laugh']);

        // Tepki değiştirilir: sayı taşınır, aynı kullanıcı iki tepki veremez
        $changed = $reactor->post('/api/comment-reaction.php', ['comment_id' => $commentId, 'type' => 'cry']);
        $this->assertSame('cry', $changed['data']['my_reaction']);
        $this->assertSame(0, $changed['data']['reactions']['laugh']);
        $this->assertSame(1, $changed['data']['reactions']['cry']);

        // Yorum listesi tepkileri ve kullanıcının kendi tepkisini gösterir
        $seenByReactor = $this->rowFor($reactor, $contentId, $commentId);
        $this->assertSame(1, $seenByReactor['reactions']['cry']);
        $this->assertSame('cry', $seenByReactor['my_reaction']);
        $this->assertNull($this->rowFor($author, $contentId, $commentId)['my_reaction']);

        $removed = $reactor->delete('/api/comment-reaction.php', ['comment_id' => $commentId]);
        $this->assertNull($removed['data']['my_reaction']);
        $this->assertSame(0, $removed['data']['reactions']['cry']);
    }

    public function testRejectsOwnCommentInvalidTypeAndGuests(): void
    {
        [$author] = $this->newUser('rxself');
        [$commentId] = $this->commentOn($author);

        $own = $author->post('/api/comment-reaction.php', ['comment_id' => $commentId, 'type' => 'laugh']);
        $this->assertSame(403, $own['status']);

        [$other] = $this->newUser('rxother');
        $invalid = $other->post('/api/comment-reaction.php', ['comment_id' => $commentId, 'type' => 'dance']);
        $this->assertSame(400, $invalid['status']);

        $guest = new ApiClient(TEST_BASE_URL);
        $anonymous = $guest->post('/api/comment-reaction.php', ['comment_id' => $commentId, 'type' => 'laugh']);
        $this->assertSame(401, $anonymous['status']);
    }

    public function testDemoAccountCannotReact(): void
    {
        [$author] = $this->newUser('rxdauthor');
        [$commentId] = $this->commentOn($author);

        [$demo, $demoId, $demoEmail] = $this->newUser('rxdemo');
        $this->mongo()->users->updateOne(['_id' => $demoId], ['$set' => ['is_demo' => true]]);
        $demo->login($demoEmail, 'Sifre123456!');

        $res = $demo->post('/api/comment-reaction.php', ['comment_id' => $commentId, 'type' => 'wow']);
        $this->assertSame(403, $res['status']);
    }
}
