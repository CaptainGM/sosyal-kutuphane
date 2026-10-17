<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ApiHelpers.php';
require_once __DIR__ . '/../api/lib/moderation.php';
require_once __DIR__ . '/../api/lib/dm.php';
require_once __DIR__ . '/../api/lib/moderation-log.php';

// Engellenen denemeler: uyarı kademeleri, kayıt (metin ve gizlilik kuralı) ve yönetici erişimi.
final class ModerationLogTest extends TestCase
{
    use ApiHelpers;

    public function testWarningEscalatesWithStrikes(): void
    {
        $base = 'Yorumunuz küfür veya hakaret içeriyor.';
        $this->assertStringContainsString('Bu bir uyarı', moderationWarning($base, 1));
        $this->assertStringContainsString('ikinci denemen', moderationWarning($base, 2));
        $this->assertStringContainsString('Son 4 denemen', moderationWarning($base, 4));
        $this->assertStringStartsWith($base, moderationWarning($base, 1));
    }

    public function testVerdictsCarryTheReason(): void
    {
        $this->assertSame('profanity', commentModerationVerdict('Bu film tam bir siktir')['reason']);
        $this->assertSame('link', commentModerationVerdict('Bakın www.ornek-site.com')['reason']);
        $this->assertNull(commentModerationVerdict('Bu film gerçekten güzeldi, herkese öneririm.'));
        $this->assertSame('profanity', dmModerationVerdict('siktir git')['reason']);
    }

    public function testCommentStrikesAreRecordedWithTextAndAdminCanSeeThem(): void
    {
        [$user, $userId] = $this->newUser('modcomment');
        [$admin, $adminId] = $this->newUser('modadmin');
        $this->mongo()->users->updateOne(['_id' => $adminId], ['$set' => ['is_admin' => true]]);
        $contentId = 900000000 + random_int(1, 99999999);

        $first = $user->post('/api/comments.php', ['content_type' => 'movie', 'content_id' => $contentId, 'comment_text' => 'Bu film tam bir siktir']);
        $this->assertSame(422, $first['status']);
        $this->assertStringContainsString('Bu bir uyarı', $first['data']['message']);
        $this->assertSame(1, $first['data']['strikes']);

        $second = $user->post('/api/comments.php', ['content_type' => 'movie', 'content_id' => $contentId, 'comment_text' => 'Yine aynı şey: orospu çocuğu']);
        $this->assertSame(2, $second['data']['strikes']);
        $this->assertStringContainsString('ikinci denemen', $second['data']['message']);

        $log = $admin->get('/api/admin.php?action=moderation');
        $this->assertSame(200, $log['status']);
        $mine = array_values(array_filter($log['data']['events'], static fn ($e) => (int) $e['user']['id'] === $userId));
        $this->assertCount(2, $mine);
        $this->assertSame('comment', $mine[0]['kind']);
        $this->assertSame('profanity', $mine[0]['reason']);
        $this->assertNotNull($mine[0]['text'], 'Yorum metni yöneticiye görünür');

        $summary = array_values(array_filter($log['data']['summary'], static fn ($s) => (int) $s['user']['id'] === $userId));
        $this->assertSame(2, $summary[0]['count']);
    }

    public function testDirectMessageStoresNoText(): void
    {
        [$sender, $senderId] = $this->newUser('moddm');
        [, $recipientId] = $this->newUser('moddmto');
        [$admin, $adminId] = $this->newUser('moddmadmin');
        $this->mongo()->users->updateOne(['_id' => $adminId], ['$set' => ['is_admin' => true]]);

        $res = $sender->post('/api/messages.php', ['to_user_id' => $recipientId, 'text' => 'siktir git buradan']);
        $this->assertSame(422, $res['status']);
        $this->assertStringContainsString('uyarı', $res['data']['message']);

        $event = $this->mongo()->moderation_events->findOne(['user_id' => $senderId, 'kind' => 'dm']);
        $this->assertNotNull($event, 'Mesaj denemesi kaydedilir');
        $this->assertNull($event['text'], 'Özel mesaj içeriği asla saklanmaz');

        $log = $admin->get('/api/admin.php?action=moderation');
        $mine = array_values(array_filter($log['data']['events'], static fn ($e) => (int) $e['user']['id'] === $senderId));
        $this->assertNull($mine[0]['text']);
    }

    public function testBioStrikeIsRecordedButLengthErrorIsNot(): void
    {
        [$client, $userId] = $this->newUser('modbio');
        $res = $client->post('/api/user-profile.php', ['bio' => 'Ben bir orospu çocuğuyum']);
        $this->assertSame(422, $res['status']);
        $this->assertNotNull($this->mongo()->moderation_events->findOne(['user_id' => $userId, 'kind' => 'bio']));

        $tooLong = $client->post('/api/user-profile.php', ['bio' => str_repeat('a', 5000)]);
        $this->assertSame(422, $tooLong['status']);
        $this->assertSame(1, $this->mongo()->moderation_events->countDocuments(['user_id' => $userId]), 'Uzunluk hatası ihlal sayılmaz');
    }

    public function testOnlyAdminsCanReadTheLog(): void
    {
        [$user] = $this->newUser('modplain');
        $this->assertSame(403, $user->get('/api/admin.php?action=moderation')['status']);
    }
}
