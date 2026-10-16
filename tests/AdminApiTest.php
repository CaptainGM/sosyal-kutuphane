<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ApiHelpers.php';

// Yönetim konsolu: yetki, geçmiş (düzenleme sürümleri, silinen yorum arşivi), moderasyon işlemleri, askıya alma ve susturma.
final class AdminApiTest extends TestCase
{
    use ApiHelpers;

    public function testGuestsAndNormalUsersAreRejected(): void
    {
        $guest = new ApiClient(TEST_BASE_URL);
        $this->assertSame(401, $guest->get('/api/admin.php?action=overview')['status']);

        [$user] = $this->newUser('phpunit_plain');
        $this->assertSame(403, $user->get('/api/admin.php?action=overview')['status']);
        $this->assertSame(403, $user->get('/api/admin.php?action=users')['status']);
    }

    public function testAdminSeesOverviewUsersAndSpoilerComments(): void
    {
        [$admin, , , $adminName] = $this->newAdmin();
        [$author, , , $authorName] = $this->newUser('phpunit_author');
        $needle = 'gizli-bulgu-' . $this->suffix();
        $author->post('/api/comments.php', ['content_type' => 'movie', 'content_id' => 999301, 'comment_text' => "Sonu: $needle", 'has_spoiler' => true]);

        $overview = $admin->get('/api/admin.php?action=overview');
        $this->assertTrue($overview['data']['success']);
        $this->assertGreaterThanOrEqual(2, $overview['data']['totals']['users']);
        $this->assertCount(14, $overview['data']['daily']);

        $users = $admin->get('/api/admin.php?action=users&q=' . urlencode($authorName));
        $this->assertSame($authorName, $users['data']['users'][0]['username']);
        $this->assertSame(1, $users['data']['users'][0]['comments']);
        $this->assertSame(1, $admin->get('/api/admin.php?action=users&status=admin&q=' . urlencode($adminName))['data']['total']);

        $comments = $admin->get('/api/admin.php?action=comments&filter=spoiler&q=' . urlencode($needle));
        $this->assertSame(1, $comments['data']['total']);
        $this->assertTrue($comments['data']['comments'][0]['has_spoiler']);
        $this->assertSame($authorName, $comments['data']['comments'][0]['author']['username']);
    }

    public function testEditHistoryDeletionArchiveAndRestore(): void
    {
        [$admin] = $this->newAdmin();
        [$author, , , $authorName] = $this->newUser('phpunit_history');
        $contentId = random_int(1_000_000, 2_000_000_000);

        $created = $author->post('/api/comments.php', ['content_type' => 'movie', 'content_id' => $contentId, 'comment_text' => 'ilk metin']);
        $commentId = $created['data']['id'];
        $author->put('/api/comments.php', ['comment_id' => $commentId, 'comment_text' => 'ikinci metin']);

        $detail = $admin->get("/api/admin.php?action=comment&comment_id=$commentId");
        $this->assertSame(['ilk metin', 'ikinci metin'], array_column($detail['data']['versions'], 'text'));
        $this->assertTrue($detail['data']['versions'][1]['current']);
        $this->assertSame(1, $detail['data']['comment']['revisions']);

        $edited = $admin->get('/api/admin.php?action=comments&filter=edited&user_id=' . $detail['data']['comment']['author']['id']);
        $this->assertSame(1, $edited['data']['total']);

        // Yazarın kendi silmesi de arşive düşer ve yöneticiye görünür.
        $author->delete('/api/comments.php', ['comment_id' => $commentId]);
        $this->assertSame([], $this->texts($author->get("/api/comments.php?content_type=movie&content_id=$contentId")));
        $archive = $admin->get('/api/admin.php?action=deleted')['data']['deleted'][0];
        $this->assertSame($commentId, $archive['comment_id']);
        $this->assertSame('author', $archive['via']);
        $this->assertSame('ikinci metin', $archive['text']);
        $this->assertSame($authorName, $archive['author']['username']);

        $restored = $admin->post('/api/admin.php', ['action' => 'restore_comment', 'archive_id' => $archive['archive_id']]);
        $this->assertTrue($restored['data']['success'] ?? false);
        $this->assertSame(['ikinci metin'], $this->texts($author->get("/api/comments.php?content_type=movie&content_id=$contentId")));

        $again = $admin->post('/api/admin.php', ['action' => 'restore_comment', 'archive_id' => $archive['archive_id']]);
        $this->assertSame(409, $again['status'], 'Aynı arşiv kaydı iki kez geri yüklenemez');
    }

    public function testAdminDeletionResolvesReportsAndIsAudited(): void
    {
        [$admin, $adminId] = $this->newAdmin();
        [$author] = $this->newUser('phpunit_target');
        [$reporter] = $this->newUser('phpunit_reporter');

        $commentId = $author->post('/api/comments.php', ['content_type' => 'movie', 'content_id' => 999303, 'comment_text' => 'şüpheli yorum'])['data']['id'];
        $reporter->post('/api/report-comment.php', ['comment_id' => $commentId, 'reason' => 'Rahatsız edici']);

        $pending = $admin->get('/api/admin.php?action=reports&status=pending');
        $mine = array_values(array_filter($pending['data']['reports'], static fn (array $r): bool => $r['comment_id'] === $commentId));
        $this->assertCount(1, $mine);
        $this->assertSame('şüpheli yorum', $mine[0]['comment']['text']);

        $deleted = $admin->post('/api/admin.php', ['action' => 'delete_comment', 'comment_id' => $commentId, 'reason' => 'Kurallara aykırı']);
        $this->assertTrue($deleted['data']['success'] ?? false);

        $resolved = $admin->get('/api/admin.php?action=reports&status=resolved');
        $row = array_values(array_filter($resolved['data']['reports'], static fn (array $r): bool => $r['comment_id'] === $commentId))[0];
        $this->assertSame('delete_comment', $row['resolution']);
        $this->assertNull($row['comment'], 'Silinen yorum canlı yorumlarda görünmez');

        $archive = $admin->get('/api/admin.php?action=deleted')['data']['deleted'][0];
        $this->assertSame('admin', $archive['via']);
        $this->assertSame('Kurallara aykırı', $archive['reason']);
        $this->assertSame('şüpheli yorum', $archive['text']);

        $audit = $admin->get('/api/admin.php?action=audit&target_type=comment')['data']['entries'][0];
        $this->assertSame('comment.delete', $audit['action']);
        $this->assertSame($adminId, $audit['actor']['id']);
        $this->assertSame($commentId, $audit['target_id']);
    }

    public function testSuspensionCutsEveryAuthenticatedEndpointAndLogin(): void
    {
        [$admin] = $this->newAdmin();
        [$victim, $victimId, $email] = $this->newUser('phpunit_victim');

        $suspend = $admin->post('/api/admin.php', ['action' => 'suspend_user', 'user_id' => $victimId, 'reason' => 'Spam']);
        $this->assertTrue($suspend['data']['success'] ?? false);

        $status = $victim->get('/api/auth-status.php');
        $this->assertNull($status['data']['user']);
        $this->assertTrue($status['data']['suspended']);

        // Oturum kapandığı için artık giriş gerekir; giriş de engellenir.
        $login = $victim->login($email, 'Sifre123456!');
        $this->assertSame(403, $login['status']);
        $this->assertTrue($login['data']['suspended'] ?? false);

        $detail = $admin->get("/api/admin.php?action=user&user_id=$victimId");
        $this->assertSame('suspended', $detail['data']['user']['status']);
        $this->assertSame('Spam', $detail['data']['user']['suspended_reason']);

        $admin->post('/api/admin.php', ['action' => 'unsuspend_user', 'user_id' => $victimId]);
        $this->assertSame(200, $victim->login($email, 'Sifre123456!')['status']);
        $this->assertNotNull($victim->get('/api/auth-status.php')['data']['user']);
    }

    public function testSuspendedUserWithLiveSessionIsStoppedByRequireLogin(): void
    {
        [$admin] = $this->newAdmin();
        [$victim, $victimId] = $this->newUser('phpunit_live');

        $this->mongo()->users->updateOne(['_id' => $victimId], ['$set' => ['status' => 'suspended']]);
        $res = $victim->post('/api/comments.php', ['content_type' => 'movie', 'content_id' => 999304, 'comment_text' => 'askıdayken']);
        $this->assertSame(403, $res['status']);
        $this->assertTrue($res['data']['suspended'] ?? false);

        // Beğeni, mesaj ve takip gibi diğer giriş gerektiren uçlar da aynı merkezi kontrolden geçer.
        $follow = $victim->post('/api/follow.php', ['target_user_id' => 1]);
        $this->assertContains($follow['status'], [401, 403]);
    }

    public function testMutedUserCannotCommentUntilUnmuted(): void
    {
        [$admin] = $this->newAdmin();
        [$user, $userId] = $this->newUser('phpunit_muted');

        $admin->post('/api/admin.php', ['action' => 'mute_user', 'user_id' => $userId, 'hours' => 2]);
        $blocked = $user->post('/api/comments.php', ['content_type' => 'movie', 'content_id' => 999305, 'comment_text' => 'susturulmuşken']);
        $this->assertSame(403, $blocked['status']);
        $this->assertStringContainsString('kısıtlandı', $blocked['data']['message']);

        $admin->post('/api/admin.php', ['action' => 'unmute_user', 'user_id' => $userId]);
        $this->assertTrue($user->post('/api/comments.php', ['content_type' => 'movie', 'content_id' => 999305, 'comment_text' => 'susturma kalktı'])['data']['success'] ?? false);
    }

    public function testAdminCannotTargetSelfOrOtherAdminsAndNeedsCsrf(): void
    {
        [$admin, $adminId] = $this->newAdmin();
        [, $otherAdminId] = $this->newAdmin('phpunit_admin2');

        $this->assertSame(400, $admin->post('/api/admin.php', ['action' => 'suspend_user', 'user_id' => $adminId])['status']);
        $this->assertSame(400, $admin->post('/api/admin.php', ['action' => 'mute_user', 'user_id' => $otherAdminId, 'hours' => 1])['status']);
        $this->assertSame(400, $admin->post('/api/admin.php', ['action' => 'bilinmeyen'])['status']);

        $token = $admin->csrfToken;
        $admin->csrfToken = null;
        $this->assertSame(403, $admin->post('/api/admin.php', ['action' => 'unmute_user', 'user_id' => 1])['status']);
        $admin->csrfToken = $token;
    }

    public function testEmailIsHiddenFromPublicProfileButVisibleToOwnerAndAdmin(): void
    {
        [$admin] = $this->newAdmin();
        [$owner, $ownerId, $email] = $this->newUser('phpunit_private');
        [$stranger] = $this->newUser('phpunit_stranger');

        $this->assertNull((new ApiClient(TEST_BASE_URL))->get("/api/user-profile.php?id=$ownerId")['data']['user']['email']);
        $this->assertNull($stranger->get("/api/user-profile.php?id=$ownerId")['data']['user']['email']);
        $this->assertSame($email, $owner->get("/api/user-profile.php?id=$ownerId")['data']['user']['email']);
        $this->assertSame($email, $admin->get("/api/user-profile.php?id=$ownerId")['data']['user']['email']);
    }

    public function testLoginHistoryIsRecordedForAdminView(): void
    {
        [$admin] = $this->newAdmin();
        [$user, $userId, $email] = $this->newUser('phpunit_logins');
        $user->login($email, 'Sifre123456!');

        $detail = $admin->get("/api/admin.php?action=user&user_id=$userId");
        $this->assertGreaterThanOrEqual(1, $detail['data']['user']['login_count']);
        $this->assertNotEmpty($detail['data']['logins']);
        $this->assertNotNull($detail['data']['user']['last_login_at']);
    }

    public function testUserDetailShowsLibraryHistory(): void
    {
        [$admin] = $this->newAdmin();
        [$user, $userId] = $this->newUser('phpunit_activity');
        $movieId = 780000 + random_int(1, 99999);
        $user->post('/api/user-status.php', ['content_type' => 'movie', 'content_id' => $movieId, 'status' => 'watched', 'rating' => 8,
            'content_data' => ['title' => 'Geçmiş Filmi']]);
        $user->post('/api/user-status.php', ['content_type' => 'book', 'content_id' => 'phpunit_act_' . $this->suffix(), 'status' => 'read',
            'content_data' => ['title' => 'Geçmiş Kitabı']]);

        $detail = $admin->get("/api/admin.php?action=user&user_id=$userId");
        $titles = array_column($detail['data']['activity'], 'title');
        $this->assertContains('Geçmiş Filmi', $titles);
        $this->assertContains('Geçmiş Kitabı', $titles);

        $movie = array_values(array_filter($detail['data']['activity'], static fn (array $a): bool => $a['type'] === 'movie'))[0];
        $this->assertSame(8, $movie['rating']);
        $this->assertSame('watched', $movie['status']);
        $this->assertSame($movieId, $movie['content_id']);
    }

    public function testCommentSearchMatchesAuthorNameToo(): void
    {
        [$admin] = $this->newAdmin();
        [$author, $authorId, , $authorName] = $this->newUser('phpunit_byname');
        [$other] = $this->newUser('phpunit_other');
        $contentId = random_int(1_000_000, 2_000_000_000);
        $token = $this->suffix();
        $author->post('/api/comments.php', ['content_type' => 'movie', 'content_id' => $contentId, 'comment_text' => "Yazar adıyla bulunacak yorum $token"]);
        $other->post('/api/comments.php', ['content_type' => 'movie', 'content_id' => $contentId, 'comment_text' => "Başka birinin yorumu $token"]);

        $byName = $admin->get('/api/admin.php?action=comments&q=' . urlencode($authorName));
        $this->assertSame(1, $byName['data']['total']);
        $this->assertSame($authorId, $byName['data']['comments'][0]['author']['id']);

        $byText = $admin->get('/api/admin.php?action=comments&q=' . urlencode("Başka birinin yorumu $token"));
        $this->assertSame(1, $byText['data']['total']);
    }
}
