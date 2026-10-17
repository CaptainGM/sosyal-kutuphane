<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ApiHelpers.php';

// Demo hesaplar: kütüphane serbest, başkalarını etkileyen işlemler kapalı, her girişte kişisel veriler sıfırlanır.
final class DemoAccountTest extends TestCase
{
    use ApiHelpers;

    // Demo işaretli bir kullanıcı; yeniden girişle sıfırlama da denenmiş olur.
    private function demoUser(): array
    {
        [$client, $id, $email] = $this->newUser('demo');
        $this->mongo()->users->updateOne(['_id' => $id], ['$set' => ['is_demo' => true]]);
        $login = $client->login($email, 'Sifre123456!');
        $this->assertSame(200, $login['status']);
        return [$client, $id, $email];
    }

    private function movieContentId(): int
    {
        return 900000000 + random_int(1, 99999999);
    }

    public function testDemoCanUseLibraryButNotAffectOthers(): void
    {
        [$client] = $this->demoUser();
        $contentId = $this->movieContentId();
        $content = ['title' => 'Demo Film', 'poster_path' => null, 'overview' => '', 'genres' => [], 'release_date' => '2001-01-01'];

        // Kişisel izleme listesi serbest
        $add = $client->post('/api/user-status.php', ['content_type' => 'movie', 'content_id' => $contentId, 'status' => 'watchlist', 'content_data' => $content]);
        $this->assertSame(200, $add['status'], json_encode($add['data']));

        // Yorum, puan, beğeni, şikâyet, takip ve mesaj reddedilir
        $comment = $client->post('/api/comments.php', ['content_type' => 'movie', 'content_id' => $contentId, 'comment_text' => 'merhaba']);
        $this->assertSame(403, $comment['status']);
        $this->assertStringContainsString('Demo', $comment['data']['message'] ?? '');

        $rating = $client->post('/api/user-status.php', ['content_type' => 'movie', 'content_id' => $contentId, 'rating' => 8]);
        $this->assertSame(403, $rating['status']);

        $statusWithRating = $client->post('/api/save-content-status.php', ['content_type' => 'movie', 'content_id' => $contentId, 'status' => 'watched', 'rating' => 7]);
        $this->assertSame(403, $statusWithRating['status']);

        $like = $client->post('/api/like-comment.php', ['comment_id' => 1]);
        $this->assertSame(403, $like['status']);

        $follow = $client->post('/api/follow.php', ['target_user_id' => 1]);
        $this->assertSame(403, $follow['status']);

        $message = $client->post('/api/messages.php', ['recipient_id' => 1, 'message' => 'selam']);
        $this->assertSame(403, $message['status']);

        // Okuma uçları açık
        $read = $client->get("/api/comments.php?content_type=movie&content_id=$contentId");
        $this->assertSame(200, $read['status']);
    }

    public function testLoginResetsDemoLibrary(): void
    {
        [$client, , $email] = $this->demoUser();
        $contentId = $this->movieContentId();
        $content = ['title' => 'Demo Film', 'poster_path' => null, 'overview' => '', 'genres' => [], 'release_date' => '2001-01-01'];
        $client->post('/api/user-status.php', ['content_type' => 'movie', 'content_id' => $contentId, 'status' => 'watchlist', 'content_data' => $content]);
        $before = $client->get("/api/user-status.php?content_type=movie&content_id=$contentId");
        $this->assertSame('watchlist', $before['data']['data']['status']);

        // Yeni bir ziyaretçi girer: kütüphane sıfır olmalı
        $client->login($email, 'Sifre123456!');
        $after = $client->get("/api/user-status.php?content_type=movie&content_id=$contentId");
        $this->assertNull($after['data']['data']['status']);
    }

    public function testNormalUserIsNotRestricted(): void
    {
        [$client, , $email] = $this->newUser('normal');
        $contentId = $this->movieContentId();
        $content = ['title' => 'Normal Film', 'poster_path' => null, 'overview' => '', 'genres' => [], 'release_date' => '2001-01-01'];
        $rating = $client->post('/api/user-status.php', ['content_type' => 'movie', 'content_id' => $contentId, 'status' => 'watched', 'rating' => 8, 'content_data' => $content]);
        $this->assertSame(200, $rating['status'], json_encode($rating['data']));
        $this->assertNotEmpty($email);
    }
}
