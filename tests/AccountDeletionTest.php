<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ApiHelpers.php';
require_once __DIR__ . '/../api/totp.php';
require_once __DIR__ . '/../api/lib/image-moderation.php';

// Hesap silme: kimlik doğrulama kuralları, kişisel verinin her koleksiyondan gitmesi ve başkalarının verisine dokunulmaması.
// Çalışan bir sunucu ve MongoDB gerekir (bkz. ApiTest).
final class AccountDeletionTest extends TestCase
{
    use ApiHelpers;

    private const PASSWORD = 'Sifre123456!';

    // Kullanıcıya bağlanabilen alanlar. audit_log'da yalnızca HEDEF alanlar sayılır (yöneticinin yaptığı işlemler kalır).
    private const REFERENCE_FIELDS = ['user_id', 'actor_id', 'follower_id', 'following_id', 'blocker_id', 'blocked_id', 'reporter_id', 'participant_a', 'participant_b', 'owner_id', 'partner_id', 'sender_id', 'comment.user_id', 'replies.user_id'];

    // Koleksiyon adı => kullanıcıya bağlı belge sayısı (silinen kullanıcı için tüm koleksiyonlar taranır).
    private function references(int $userId, string $email): array
    {
        $db = $this->mongo();
        $found = [];
        foreach ($db->listCollections() as $info) {
            $name = $info->getName();
            if (in_array($name, ['users', 'counters', 'api_cache', 'rate_limits', 'settings', 'movies', 'series', 'books'], true)) {
                continue;
            }
            if ($name === 'audit_log') {
                $filter = ['target_type' => 'user', 'target_id' => $userId];
            } else {
                $conditions = array_map(static fn ($field) => [$field => $userId], self::REFERENCE_FIELDS);
                $conditions[] = ['email' => $email];
                if ($name === 'email_change_requests') {
                    $conditions[] = ['_id' => $userId];
                }
                $filter = ['$or' => $conditions];
            }
            $count = $db->$name->countDocuments($filter);
            if ($count > 0) {
                $found[$name] = $count;
            }
        }
        ksort($found);
        return $found;
    }

    private function imageCheckIsOn(): bool
    {
        return imageModerationConfig() !== null;
    }

    private function tinyPng(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'del_test_');
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
        return $path;
    }

    public function testDeletionRemovesEveryTraceOfTheUserAndLeavesOthersAlone(): void
    {
        [$u, $uId, $uEmail] = $this->newUser('phpunit_del');
        [$v, $vId] = $this->newUser('phpunit_del_other');
        [$admin] = $this->newAdmin();
        $db = $this->mongo();
        $content = 900000000 + random_int(1, 99999999);

        // Sosyal: karşılıklı takip, yorum + düzenleme, başkasının yanıtı / beğenisi / tepkisi / şikâyeti, mesajlaşma
        $u->post('/api/follow.php', ['target_user_id' => $vId]);
        $v->post('/api/follow.php', ['target_user_id' => $uId]);
        $comment = $u->post('/api/comments.php', ['content_type' => 'movie', 'content_id' => $content, 'comment_text' => 'Silinecek yorum ' . $this->suffix()]);
        $this->assertTrue($comment['data']['success'], json_encode($comment['data']));
        $commentId = (int) $comment['data']['id'];
        $u->request('PUT', '/api/comments.php', ['comment_id' => $commentId, 'comment_text' => 'Düzenlenmiş silinecek yorum ' . $this->suffix()]);
        $reply = $v->post('/api/comments.php', ['content_type' => 'movie', 'content_id' => $content, 'comment_text' => 'Başkasının yanıtı ' . $this->suffix(), 'parent_comment_id' => $commentId]);
        $this->assertTrue($reply['data']['success'], json_encode($reply['data']));
        $v->post('/api/like-comment.php', ['comment_id' => $commentId]);
        $v->post('/api/comment-reaction.php', ['comment_id' => $commentId, 'type' => 'laugh']);
        $v->post('/api/report-comment.php', ['comment_id' => $commentId, 'reason' => 'Deneme şikâyeti']);
        $vOwn = $v->post('/api/comments.php', ['content_type' => 'movie', 'content_id' => $content + 1, 'comment_text' => 'V kendi yorumu ' . $this->suffix()]);
        $u->post('/api/messages.php', ['to_user_id' => $vId, 'text' => 'Merhaba V']);
        $v->post('/api/messages.php', ['to_user_id' => $uId, 'text' => 'Merhaba U']);

        // Kütüphane: durum, liste ve öğesi, bekleyen kayıtlar
        $u->post('/api/save-content-status.php', ['content_type' => 'movie', 'content_id' => $content, 'rating' => 7, 'content_data' => ['title' => 'Silme Testi Filmi', 'poster_path' => '/x.jpg']]);
        $list = $u->post('/api/custom-lists.php', ['action' => 'create', 'name' => 'Silinecek liste', 'description' => 'test']);
        $listId = (int) $list['data']['list_id'];
        $u->post('/api/custom-lists.php', ['action' => 'add_item', 'list_id' => $listId, 'content_type' => 'movie', 'content_id' => $content, 'content_title' => 'Öğe']);

        // Destek: talep, yönetici yanıtı (U'ya bildirim gider), U'nun yanıtı
        $ticket = $u->post('/api/support-tickets.php', ['category' => 'bug', 'message' => 'Silinecek destek talebi ' . $this->suffix()])['data']['ticket']['id'];
        $admin->post('/api/admin.php', ['action' => 'reply_ticket', 'ticket_id' => $ticket, 'message' => 'Yanıt']);
        $u->post('/api/support-tickets.php', ['action' => 'reply', 'ticket_id' => $ticket, 'message' => 'Teşekkürler, bir bakar mısınız?']);

        // Güvenlik ve diğer kayıtlar
        $u->login($uEmail, self::PASSWORD);
        $u->post('/api/comments.php', ['content_type' => 'movie', 'content_id' => $content + 2, 'comment_text' => 'Bu film tam bir siktir']); // engellenen deneme kaydı
        $anonymous = new ApiClient(TEST_BASE_URL);
        $anonymous->post('/api/forgot-password.php', ['email' => $uEmail]);
        $admin->post('/api/admin.php', ['action' => 'mute_user', 'user_id' => $uId, 'hours' => 1]); // yöneticinin U'yu hedef alan işlemi
        $db->push_subscriptions->insertOne(['_id' => $this->nextId('push_subscriptions'), 'user_id' => $uId, 'endpoint' => 'https://push.example/' . $this->suffix(), 'created_at' => new MongoDB\BSON\UTCDateTime()]);
        $db->typing_status->insertOne(['conversation_id' => 1, 'user_id' => $uId, 'updated_at' => new MongoDB\BSON\UTCDateTime()]);
        $db->release_reminders->insertOne(['user_id' => $uId, 'tmdb_id' => $content, 'title' => 'Hatırlatıcı', 'notified' => false, 'release_date' => '2099-01-01']);
        $db->buddy_reads->insertOne(['_id' => $this->nextId('buddy_reads'), 'owner_id' => $uId, 'partner_id' => $vId, 'status' => 'pending']);
        $db->blocks->insertOne(['_id' => $this->nextId('blocks'), 'blocker_id' => $vId, 'blocked_id' => $uId]);
        $db->pending_registrations->insertOne(['email' => $uEmail, 'username' => 'bekleyen_' . $this->suffix(), 'expires_at' => new MongoDB\BSON\UTCDateTime((time() + 600) * 1000)]);
        $db->episode_notifications->insertOne(['user_id' => $uId, 'tmdb_id' => $content, 'season' => 1, 'episode' => 1]);
        if (!$this->imageCheckIsOn()) {
            $png = $this->tinyPng();
            $this->assertSame(200, $u->upload('/api/upload-avatar.php', 'avatar', $png, 'image/png', 'a.png')['status']);
            @unlink($png);
        }

        $before = $this->references($uId, $uEmail);
        foreach (['comments', 'conversations', 'custom_lists', 'follows', 'login_history', 'messages', 'moderation_events', 'notifications', 'password_resets', 'pending_registrations', 'push_subscriptions', 'support_tickets', 'typing_status', 'user_movie_status', 'blocks', 'buddy_reads', 'release_reminders', 'episode_notifications', 'audit_log'] as $collection) {
            $this->assertArrayHasKey($collection, $before, "Sınama anlamlı olsun diye $collection koleksiyonunda U'ya bağlı kayıt olmalı");
        }
        $avatarUrl = (string) $db->users->findOne(['_id' => $uId])['avatar_url'];

        $res = $u->post('/api/delete-account.php', ['password' => self::PASSWORD, 'confirm' => true]);
        $this->assertSame(200, $res['status'], json_encode($res['data']));

        // Hiçbir koleksiyonda iz kalmaz
        $this->assertSame([], $this->references($uId, $uEmail), 'Silinen kullanıcıya bağlı kayıt kalmamalı');
        $this->assertNull($db->users->findOne(['_id' => $uId]));
        $this->assertSame(0, $db->custom_list_items->countDocuments(['list_id' => $listId]), 'Liste öğeleri de gider');
        $this->assertSame(0, $db->comments->countDocuments(['_id' => $commentId]));
        $this->assertSame(0, $db->comments->countDocuments(['parent_comment_id' => $commentId]), 'Silinen yoruma gelen yanıtlar da gider');
        $this->assertSame(0, $db->comment_likes->countDocuments(['comment_id' => $commentId]));
        $this->assertSame(0, $db->comment_reactions->countDocuments(['comment_id' => $commentId]));
        $this->assertSame(0, $db->comment_reports->countDocuments(['comment_id' => $commentId]));
        $this->assertSame(0, $db->comment_revisions->countDocuments(['comment_id' => $commentId]));
        $this->assertSame(0, $db->audit_log->countDocuments(['target_type' => 'ticket', 'target_id' => $ticket]));
        if (str_starts_with($avatarUrl, '/uploads/avatars/')) {
            $this->assertFileDoesNotExist(dirname(__DIR__) . $avatarUrl, 'Profil fotoğrafı diskten silinir');
            $this->assertSame(0, $db->avatar_files->countDocuments(['_id' => basename($avatarUrl)]), 'Fotoğrafın veritabanı kopyası da silinir');
        }

        // Oturum kapanır, giriş yapılamaz, e-posta yeniden kullanılabilir
        $this->assertEmpty($u->get('/api/auth-status.php')['data']['user'] ?? null, 'Silinen hesabın oturumu geçersizdir');
        $this->assertSame(401, (new ApiClient(TEST_BASE_URL))->login($uEmail, self::PASSWORD)['status']);
        $again = new ApiClient(TEST_BASE_URL);
        $this->assertSame(201, $again->register('yeniden_' . $this->suffix(), $uEmail, self::PASSWORD)['status'], 'Silinen e-posta adresiyle yeniden kayıt olunabilir');

        // Başkasının verisi yerinde: V ve kendi yorumu duruyor, V'nin U'yla ilgili kayıtları gitti
        $this->assertNotNull($db->users->findOne(['_id' => $vId]));
        $this->assertTrue($vOwn['data']['success']);
        $this->assertSame(1, $db->comments->countDocuments(['user_id' => $vId, 'content_id' => $content + 1]), 'V\'nin kendi yorumu silinmez');
        $this->assertSame(0, $db->follows->countDocuments(['follower_id' => $vId, 'following_id' => $uId]));
    }

    public function testWrongPasswordMissingConfirmationAndRateLimit(): void
    {
        [$user, $id] = $this->newUser('phpunit_del');

        $this->assertSame(400, $user->post('/api/delete-account.php', ['password' => self::PASSWORD])['status'], 'Onay (confirm) olmadan silinmez');
        $wrong = $user->post('/api/delete-account.php', ['password' => 'YanlisSifre1!', 'confirm' => true]);
        $this->assertSame(401, $wrong['status']);
        $this->assertNotNull($this->mongo()->users->findOne(['_id' => $id]), 'Yanlış şifreyle hesap silinmez');

        for ($i = 2; $i <= 5; $i++) {
            $this->assertSame(401, $user->post('/api/delete-account.php', ['password' => 'YanlisSifre1!', 'confirm' => true])['status']);
        }
        $this->assertSame(429, $user->post('/api/delete-account.php', ['password' => self::PASSWORD, 'confirm' => true])['status'], 'Saatte 5 denemeden sonrası (doğru şifre bile) reddedilir');
        $this->assertNotNull($this->mongo()->users->findOne(['_id' => $id]));

        $anonymous = new ApiClient(TEST_BASE_URL);
        $this->assertSame(401, $anonymous->post('/api/delete-account.php', ['password' => 'x', 'confirm' => true])['status']);
    }

    public function testAdminAndDemoAccountsCannotBeDeleted(): void
    {
        [$admin, $adminId] = $this->newAdmin();
        $this->assertSame(403, $admin->post('/api/delete-account.php', ['password' => self::PASSWORD, 'confirm' => true])['status']);
        $this->assertNotNull($this->mongo()->users->findOne(['_id' => $adminId]), 'Yönetici hesabı silinemez (yönetici sayısı sıfıra düşmesin)');

        $demo = new ApiClient(TEST_BASE_URL);
        $demo->login('ahmet@test.com', '123456');
        $this->assertSame(403, $demo->post('/api/delete-account.php', ['password' => '123456', 'confirm' => true])['status'], 'Demo hesaplar silinemez');
        $this->assertNotNull($this->mongo()->users->findOne(['email' => 'ahmet@test.com']));
    }

    public function testTwoFactorCodeIsRequiredWhenEnabled(): void
    {
        [$user, $id] = $this->newUser('phpunit_del');
        $secret = generateBase32Secret();
        $backup = 'YEDEK-' . $this->suffix();
        $this->mongo()->users->updateOne(['_id' => $id], ['$set' => ['totp_enabled' => true, 'totp_secret' => $secret, 'totp_backup_codes' => [password_hash($backup, PASSWORD_DEFAULT)]]]);

        $this->assertSame(401, $user->post('/api/delete-account.php', ['password' => self::PASSWORD, 'confirm' => true])['status'], 'Kod olmadan silinmez');
        $this->assertSame(401, $user->post('/api/delete-account.php', ['password' => self::PASSWORD, 'confirm' => true, 'code' => '000000'])['status'], 'Yanlış kod');
        $this->assertNotNull($this->mongo()->users->findOne(['_id' => $id]));

        $ok = $user->post('/api/delete-account.php', ['password' => self::PASSWORD, 'confirm' => true, 'code' => totpCodeAt($secret, time())]);
        $this->assertSame(200, $ok['status'], json_encode($ok['data']));
        $this->assertNull($this->mongo()->users->findOne(['_id' => $id]));
    }
}
