<?php

use PHPUnit\Framework\TestCase;

// Bu testler çalışan bir PHP sunucusuna (TEST_BASE_URL, varsayılan http://localhost:8000)
// ve erişilebilir bir MongoDB'ye ihtiyaç duyar. Yerelde:
//   php -S localhost:8000 &
//   vendor/bin/phpunit
// GitHub Actions'ta bunlar workflow tarafından otomatik sağlanır (bkz. .github/workflows/ci.yml).
final class ApiTest extends TestCase
{
    private static MongoDB\Database $db;

    public static function setUpBeforeClass(): void
    {
        $client = new MongoDB\Client(TEST_MONGO_URI);
        self::$db = $client->selectDatabase(TEST_MONGO_DB);
    }

    private function uniqueSuffix(): string
    {
        return substr(bin2hex(random_bytes(4)), 0, 8);
    }

    public function testRegisterLoginAndWrongPasswordRejected(): void
    {
        $suffix = $this->uniqueSuffix();
        $email = "phpunit_$suffix@test.com";
        $username = "phpunit_$suffix";

        $client = new ApiClient(TEST_BASE_URL);
        $register = $client->register($username, $email, 'dogrusifre123');
        $this->assertTrue($register['data']['success'] ?? false, 'Kayıt başarısız olmamalı');
        $this->assertNotEmpty($register['data']['csrf_token'] ?? null);

        $fresh = new ApiClient(TEST_BASE_URL);
        $wrongLogin = $fresh->login($email, 'yanlissifre');
        $this->assertSame(401, $wrongLogin['status']);
        $this->assertFalse($wrongLogin['data']['success'] ?? true);

        $rightLogin = $fresh->login($email, 'dogrusifre123');
        $this->assertTrue($rightLogin['data']['success'] ?? false);
        $this->assertSame($username, $rightLogin['data']['user']['username'] ?? null);
    }

    public function testCsrfTokenRequiredForStateChangingRequests(): void
    {
        $suffix = $this->uniqueSuffix();
        $client = new ApiClient(TEST_BASE_URL);
        $client->register("phpunit_csrf_$suffix", "phpunit_csrf_$suffix@test.com", 'sifre123456');

        // Aynı client'ta csrfToken var, onu geçici olarak silip header'sız isteği doğrula.
        $realToken = $client->csrfToken;
        $client->csrfToken = null;
        $res = $client->post('/api/comments.php', ['content_type' => 'movie', 'content_id' => 1, 'comment_text' => 'csrfsiz deneme']);
        $this->assertSame(403, $res['status'], 'CSRF token olmadan istek 403 dönmeli');

        $client->csrfToken = $realToken;
        $res2 = $client->post('/api/comments.php', ['content_type' => 'movie', 'content_id' => 999001, 'comment_text' => 'csrfli deneme']);
        $this->assertTrue($res2['data']['success'] ?? false, 'Doğru CSRF token ile istek başarılı olmalı');

        // Temizlik
        $client->delete('/api/comments.php', ['comment_id' => $res2['data']['id']]);
    }

    public function testCommentReplyLikeDeleteCascadesCommentLikes(): void
    {
        $suffix = $this->uniqueSuffix();
        $client = new ApiClient(TEST_BASE_URL);
        $client->register("phpunit_cmt_$suffix", "phpunit_cmt_$suffix@test.com", 'sifre123456');

        $contentId = 999100;
        $comment = $client->post('/api/comments.php', ['content_type' => 'movie', 'content_id' => $contentId, 'comment_text' => 'Ana yorum']);
        $this->assertTrue($comment['data']['success'] ?? false);
        $commentId = $comment['data']['id'];

        $reply = $client->post('/api/comments.php', ['content_type' => 'movie', 'content_id' => $contentId, 'comment_text' => 'Yanıt', 'parent_comment_id' => $commentId]);
        $this->assertTrue($reply['data']['success'] ?? false);

        $like = $client->post('/api/like-comment.php', ['comment_id' => $commentId]);
        $this->assertTrue($like['data']['success'] ?? false);
        $this->assertSame(1, $like['data']['likes_count'] ?? 0);

        $likesBeforeDelete = self::$db->comment_likes->countDocuments(['comment_id' => $commentId]);
        $this->assertSame(1, $likesBeforeDelete);

        $delete = $client->delete('/api/comments.php', ['comment_id' => $commentId]);
        $this->assertTrue($delete['data']['success'] ?? false);

        $remainingComments = self::$db->comments->countDocuments(['content_type' => 'movie', 'content_id' => $contentId]);
        $this->assertSame(0, $remainingComments, 'Yorum ve yanıtı silinmeli');

        $remainingLikes = self::$db->comment_likes->countDocuments(['comment_id' => $commentId]);
        $this->assertSame(0, $remainingLikes, 'Silinen yorumun beğenileri de (comment_likes cascade) temizlenmeli');
    }

    public function testFollowCreatesNotification(): void
    {
        $suffix = $this->uniqueSuffix();
        $followerClient = new ApiClient(TEST_BASE_URL);
        $followerReg = $followerClient->register("phpunit_flw_a_$suffix", "phpunit_flw_a_$suffix@test.com", 'sifre123456');
        $followerId = $followerReg['data']['user']['id'];

        $targetClient = new ApiClient(TEST_BASE_URL);
        $targetReg = $targetClient->register("phpunit_flw_b_$suffix", "phpunit_flw_b_$suffix@test.com", 'sifre123456');
        $targetId = $targetReg['data']['user']['id'];

        $follow = $followerClient->post('/api/follow.php', ['target_user_id' => $targetId]);
        $this->assertTrue($follow['data']['success'] ?? false);

        $status = $followerClient->get("/api/follow-status.php?user_id=$targetId");
        $this->assertTrue($status['data']['is_following'] ?? false);

        $notifications = $targetClient->get('/api/notifications.php');
        $types = array_column($notifications['data']['notifications'] ?? [], 'type');
        $actorIds = array_column($notifications['data']['notifications'] ?? [], 'actor_id');
        $this->assertContains('follow', $types);
        $this->assertContains($followerId, $actorIds);
    }

    public function testContentStatusAndRatingAggregation(): void
    {
        $suffix = $this->uniqueSuffix();
        $client = new ApiClient(TEST_BASE_URL);
        $client->register("phpunit_rate_$suffix", "phpunit_rate_$suffix@test.com", 'sifre123456');

        $contentId = 999200 + random_int(1, 999);
        $save = $client->post('/api/save-content-status.php', [
            'content_type' => 'movie',
            'content_id' => $contentId,
            'rating' => 8,
            'content_data' => ['title' => 'PHPUnit Test Filmi', 'poster_path' => '/x.jpg'],
        ]);
        $this->assertTrue($save['data']['success'] ?? false);

        $status = $client->get("/api/user-status.php?content_type=movie&content_id=$contentId");
        $this->assertSame('watched', $status['data']['data']['status'] ?? null);
        $this->assertSame(8, $status['data']['data']['rating'] ?? null);

        $rating = $client->get("/api/content-rating.php?content_type=movie&content_id=$contentId");
        $this->assertEquals(8.0, $rating['data']['data']['average_rating'] ?? null);
        $this->assertSame(1, $rating['data']['data']['total_votes'] ?? null);
    }

    public function testCustomListCrudAndItemCascade(): void
    {
        $suffix = $this->uniqueSuffix();
        $client = new ApiClient(TEST_BASE_URL);
        $client->register("phpunit_list_$suffix", "phpunit_list_$suffix@test.com", 'sifre123456');

        $create = $client->post('/api/custom-lists.php', ['action' => 'create', 'name' => 'PHPUnit Listem', 'description' => 'test']);
        $this->assertTrue($create['data']['success'] ?? false);
        $listId = $create['data']['list_id'];

        $addItem = $client->post('/api/custom-lists.php', [
            'action' => 'add_item',
            'list_id' => $listId,
            'content_type' => 'movie',
            'content_id' => 999300,
            'content_title' => 'PHPUnit Filmi',
        ]);
        $this->assertTrue($addItem['data']['success'] ?? false);

        $itemsBeforeDelete = self::$db->custom_list_items->countDocuments(['list_id' => $listId]);
        $this->assertSame(1, $itemsBeforeDelete);

        $delete = $client->delete('/api/custom-lists.php', ['list_id' => $listId]);
        $this->assertTrue($delete['data']['success'] ?? false);

        $itemsAfterDelete = self::$db->custom_list_items->countDocuments(['list_id' => $listId]);
        $this->assertSame(0, $itemsAfterDelete, 'Liste silinince öğeleri de (cascade) silinmeli');
    }

    public function testMessagingCreatesConversationAndNotification(): void
    {
        $suffix = $this->uniqueSuffix();
        $senderClient = new ApiClient(TEST_BASE_URL);
        $senderClient->register("phpunit_msg_a_$suffix", "phpunit_msg_a_$suffix@test.com", 'sifre123456');

        $recipientClient = new ApiClient(TEST_BASE_URL);
        $recipientReg = $recipientClient->register("phpunit_msg_b_$suffix", "phpunit_msg_b_$suffix@test.com", 'sifre123456');
        $recipientId = $recipientReg['data']['user']['id'];

        $send = $senderClient->post('/api/messages.php', ['to_user_id' => $recipientId, 'text' => 'Merhaba PHPUnit']);
        $this->assertTrue($send['data']['success'] ?? false);
        $conversationId = $send['data']['conversation_id'];

        $inbox = $recipientClient->get('/api/messages.php');
        $conversationIds = array_column($inbox['data']['conversations'] ?? [], 'conversation_id');
        $this->assertContains($conversationId, $conversationIds);

        $thread = $recipientClient->get("/api/messages.php?conversation_id=$conversationId");
        $this->assertSame('Merhaba PHPUnit', $thread['data']['messages'][0]['text'] ?? null);

        $notifications = $recipientClient->get('/api/notifications.php');
        $types = array_column($notifications['data']['notifications'] ?? [], 'type');
        $this->assertContains('message', $types);
    }

    public function testChangePasswordRequiresCorrectCurrentPassword(): void
    {
        $suffix = $this->uniqueSuffix();
        $email = "phpunit_pwd_$suffix@test.com";
        $client = new ApiClient(TEST_BASE_URL);
        $client->register("phpunit_pwd_$suffix", $email, 'ilkSifre123');

        $wrong = $client->post('/api/change-password.php', ['current_password' => 'yanlis', 'new_password' => 'yeniSifre123']);
        $this->assertSame(401, $wrong['status']);

        $right = $client->post('/api/change-password.php', ['current_password' => 'ilkSifre123', 'new_password' => 'yeniSifre123']);
        $this->assertTrue($right['data']['success'] ?? false);

        $reLogin = new ApiClient(TEST_BASE_URL);
        $login = $reLogin->login($email, 'yeniSifre123');
        $this->assertTrue($login['data']['success'] ?? false, 'Yeni şifreyle giriş yapılabilmeli');
    }
}
