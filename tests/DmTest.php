<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../api/lib/dm.php';
require_once __DIR__ . '/ApiHelpers.php';

// DM sel koruması: karar saf fonksiyondur, HTTP katmanı çalışan sunucu ve MongoDB gerektirir (bkz. ApiTest).
final class DmTest extends TestCase
{
    use ApiHelpers;

    private function recent(int $count, int $now, int $secondsAgo = 5, string $text = 'merhaba'): array
    {
        return array_fill(0, $count, ['at' => $now - $secondsAgo, 'text' => $text]);
    }

    public function testModerationBlocksIbanAndProfanityButAllowsLinks(): void
    {
        $this->assertNotNull(dmModerationError('Paranı şuraya yolla: TR33 0006 1005 1978 6457 8413 26'));
        $this->assertNotNull(dmModerationError('Sen tam bir aptal birisin'));
        $this->assertNotNull(dmModerationError('s1ktir git'));
        $this->assertNull(dmModerationError('Bu filmi izle: https://www.themoviedb.org/movie/603'));
        $this->assertNull(dmModerationError('Akşam sinemaya gidelim mi? Gotham dizisi de iyiymiş.'));
    }

    public function testNormalConversationIsAllowed(): void
    {
        $now = 1_700_000_000;
        $this->assertNull(dmRateError([], 'selam', $now));
        $this->assertNull(dmRateError($this->recent(5, $now), 'başka bir mesaj', $now));
    }

    public function testBurstIsLimited(): void
    {
        $now = 1_700_000_000;
        $recent = array_map(static fn (int $i): array => ['at' => $now - 5, 'text' => "mesaj $i"], range(1, DM_BURST_LIMIT));
        $this->assertNotNull(dmRateError($recent, 'yeni', $now));
        $this->assertNull(dmRateError(array_slice($recent, 1), 'yeni', $now), 'Sınırın bir altında izin verilmeli');
    }

    public function testOldMessagesDoNotCountTowardsBurst(): void
    {
        $now = 1_700_000_000;
        $old = array_map(static fn (int $i): array => ['at' => $now - 120, 'text' => "eski $i"], range(1, 30));
        $this->assertNull(dmRateError($old, 'yeni', $now));
    }

    public function testRepeatedIdenticalTextIsBlockedCaseInsensitively(): void
    {
        $now = 1_700_000_000;
        $recent = $this->recent(DM_DUPLICATE_LIMIT, $now, 100, 'Satın Al');
        $this->assertNotNull(dmRateError($recent, 'satın al ', $now));
        $this->assertNull(dmRateError($recent, 'başka metin', $now));
        $this->assertNull(dmRateError($this->recent(DM_DUPLICATE_LIMIT, $now, DM_DUPLICATE_WINDOW + 1, 'Satın Al'), 'satın al', $now), 'Pencere dışındaki tekrarlar sayılmaz');
    }

    public function testShortWindowAndDayLimits(): void
    {
        $now = 1_700_000_000;
        $short = array_map(static fn (int $i): array => ['at' => $now - 300, 'text' => "m$i"], range(1, DM_SHORT_LIMIT));
        $this->assertNotNull(dmRateError($short, 'yeni', $now));

        $day = array_map(static fn (int $i): array => ['at' => $now - 3600, 'text' => "g$i"], range(1, DM_DAY_LIMIT));
        $this->assertNotNull(dmRateError($day, 'yeni', $now));
    }

    public function testHttpBurstReturnsTooManyRequestsAndOneNoticePerConversation(): void
    {
        [$sender, $senderId] = $this->newUser('phpunit_dm_s');
        [, $receiverId] = $this->newUser('phpunit_dm_r');

        for ($i = 1; $i <= DM_BURST_LIMIT; $i++) {
            $res = $sender->post('/api/messages.php', ['to_user_id' => $receiverId, 'text' => "hızlı mesaj $i"]);
            $this->assertTrue($res['data']['success'] ?? false, "$i. mesaj kabul edilmeli: " . json_encode($res['data']));
        }
        $blocked = $sender->post('/api/messages.php', ['to_user_id' => $receiverId, 'text' => 'sınırı aşan mesaj']);
        $this->assertSame(429, $blocked['status']);
        $this->assertFalse($blocked['data']['success'] ?? true);

        $notices = $this->mongo()->notifications->countDocuments(['user_id' => $receiverId, 'actor_id' => $senderId, 'type' => 'message']);
        $this->assertSame(1, $notices, 'Okunmamış bildirim varken her mesaj yeni bildirim üretmemeli');
    }

    public function testHttpRejectsProfanityAndIbanButAcceptsLinks(): void
    {
        [$sender] = $this->newUser('phpunit_dm_m');
        [, $receiverId] = $this->newUser('phpunit_dm_n');

        $profanity = $sender->post('/api/messages.php', ['to_user_id' => $receiverId, 'text' => 'Sen aptalın tekisin']);
        $this->assertSame(422, $profanity['status']);
        $iban = $sender->post('/api/messages.php', ['to_user_id' => $receiverId, 'text' => 'IBAN: TR33 0006 1005 1978 6457 8413 26']);
        $this->assertSame(422, $iban['status']);
        $this->assertSame(0, $this->mongo()->messages->countDocuments(['text' => 'Sen aptalın tekisin']), 'Reddedilen mesaj kaydedilmemeli');

        $link = $sender->post('/api/messages.php', ['to_user_id' => $receiverId, 'text' => 'Şunu izle: https://example.com/film']);
        $this->assertTrue($link['data']['success'] ?? false);
    }

    public function testNormalMessagingStillWorksBothWays(): void
    {
        [$a, , , ] = $this->newUser('phpunit_dm_a');
        [$b, $bId] = $this->newUser('phpunit_dm_b');

        $sent = $a->post('/api/messages.php', ['to_user_id' => $bId, 'text' => 'Merhaba!']);
        $this->assertTrue($sent['data']['success'] ?? false);
        $conversationId = $sent['data']['conversation_id'];

        $reply = $b->post('/api/messages.php', ['conversation_id' => $conversationId, 'text' => 'Selam']);
        $this->assertTrue($reply['data']['success'] ?? false);

        $thread = $a->get('/api/messages.php?conversation_id=' . $conversationId);
        $this->assertSame(['Merhaba!', 'Selam'], array_column($thread['data']['messages'], 'text'));
    }
}
