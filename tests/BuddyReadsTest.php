<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ApiHelpers.php';

// Ortak okuma: karşılıklı takip kuralı, davet/kabul/ilerleme akışı, tekrar engeli ve demo kısıtı.
final class BuddyReadsTest extends TestCase
{
    use ApiHelpers;

    private function mutualFollow(int $a, int $b): void
    {
        foreach ([[$a, $b], [$b, $a]] as [$follower, $following]) {
            $this->mongo()->follows->insertOne([
                '_id' => $this->nextId('follows'),
                'follower_id' => $follower,
                'following_id' => $following,
                'created_at' => new MongoDB\BSON\UTCDateTime(),
            ]);
        }
    }

    private function movie(): array
    {
        return ['content_type' => 'movie', 'content_id' => 900000000 + random_int(1, 99999999), 'title' => 'Ortak Film', 'poster' => null];
    }

    private function listFor(ApiClient $client): array
    {
        return $client->get('/api/buddy-reads.php')['data']['items'] ?? [];
    }

    public function testOnlyMutualFollowsCanInvite(): void
    {
        [$a, $aId] = $this->newUser('byone');
        [, $bId] = $this->newUser('bytwo');
        $res = $a->post('/api/buddy-reads.php', ['action' => 'create', 'partner_id' => $bId] + $this->movie());
        $this->assertSame(403, $res['status'], 'Takip yokken davet edilemez');

        // Yalnızca bir yönlü takip yetmez
        $this->mongo()->follows->insertOne(['_id' => $this->nextId('follows'), 'follower_id' => $aId, 'following_id' => $bId, 'created_at' => new MongoDB\BSON\UTCDateTime()]);
        $this->assertSame(403, $a->post('/api/buddy-reads.php', ['action' => 'create', 'partner_id' => $bId] + $this->movie())['status']);
    }

    public function testInviteAcceptProgressAndLeave(): void
    {
        [$a, $aId] = $this->newUser('byowner');
        [$b, $bId] = $this->newUser('bypartner');
        $this->mutualFollow($aId, $bId);
        $content = $this->movie();

        $invite = $a->post('/api/buddy-reads.php', ['action' => 'create', 'partner_id' => $bId] + $content);
        $this->assertSame(200, $invite['status'], json_encode($invite['data']));
        $id = (int) $invite['data']['id'];

        // Davet kabul edilene kadar karşı taraf ilerlemeyi göremez
        $pendingForA = $this->listFor($a);
        $this->assertSame('pending', $pendingForA[0]['status']);
        $this->assertNull($pendingForA[0]['them']);

        $incoming = $this->listFor($b);
        $this->assertSame('partner', $incoming[0]['role']);

        $accept = $b->post('/api/buddy-reads.php', ['action' => 'respond', 'id' => $id, 'accept' => true]);
        $this->assertSame(200, $accept['status']);

        // Kabul sonrası ilerleme karşılıklı görünür; film için sayı yok, yalnızca tamamlandı işareti
        $this->assertSame(200, $a->post('/api/buddy-reads.php', ['action' => 'done', 'id' => $id, 'done' => true])['status']);
        $this->assertSame(400, $a->post('/api/buddy-reads.php', ['action' => 'progress', 'id' => $id, 'value' => 3])['status'], 'Filmde sayfa/bölüm yok');
        $this->assertSame(200, $b->post('/api/buddy-reads.php', ['action' => 'done', 'id' => $id, 'done' => false])['status']);

        $seenByA = $this->listFor($a)[0];
        $this->assertSame('active', $seenByA['status']);
        $this->assertTrue($seenByA['me']['done']);
        $this->assertFalse($seenByA['them']['done']);

        // Ayrılınca kayıt silinir
        $this->assertSame(200, $b->post('/api/buddy-reads.php', ['action' => 'leave', 'id' => $id])['status']);
        $this->assertSame([], $this->listFor($a));
    }

    public function testBookProgressIsPerParticipantAndPartnerCannotEditOthers(): void
    {
        [$a, $aId] = $this->newUser('bybookone');
        [$b, $bId] = $this->newUser('bybooktwo');
        $this->mutualFollow($aId, $bId);
        $book = ['content_type' => 'book', 'content_id' => 'abc_DEF-123', 'title' => 'Ortak Kitap', 'poster' => null];

        $id = (int) $a->post('/api/buddy-reads.php', ['action' => 'create', 'partner_id' => $bId] + $book)['data']['id'];
        $b->post('/api/buddy-reads.php', ['action' => 'respond', 'id' => $id, 'accept' => true]);

        $this->assertSame(200, $a->post('/api/buddy-reads.php', ['action' => 'progress', 'id' => $id, 'value' => 120])['status']);
        $this->assertSame(200, $b->post('/api/buddy-reads.php', ['action' => 'progress', 'id' => $id, 'value' => 45])['status']);
        $this->assertSame(400, $b->post('/api/buddy-reads.php', ['action' => 'progress', 'id' => $id, 'value' => -5])['status']);

        $seenByB = $this->listFor($b)[0];
        $this->assertSame(45, $seenByB['me']['progress']);
        $this->assertSame(120, $seenByB['them']['progress']);
    }

    public function testDeclineDuplicateAndWrongRole(): void
    {
        [$a, $aId] = $this->newUser('bydeclineowner');
        [$b, $bId] = $this->newUser('bydeclinepartner');
        $this->mutualFollow($aId, $bId);
        $content = $this->movie();

        $id = (int) $a->post('/api/buddy-reads.php', ['action' => 'create', 'partner_id' => $bId] + $content)['data']['id'];
        $this->assertSame(409, $a->post('/api/buddy-reads.php', ['action' => 'create', 'partner_id' => $bId] + $content)['status'], 'Aynı yapım için tekrar davet yok');
        $this->assertSame(403, $a->post('/api/buddy-reads.php', ['action' => 'respond', 'id' => $id, 'accept' => true])['status'], 'Daveti yalnızca karşı taraf yanıtlar');

        $this->assertSame(200, $b->post('/api/buddy-reads.php', ['action' => 'respond', 'id' => $id, 'accept' => false])['status']);
        $this->assertSame([], $this->listFor($a), 'Reddedilen davet kalmaz');
    }

    public function testFriendsListIsMutualAndDemoCannotInviteOrAccept(): void
    {
        [$a, $aId] = $this->newUser('byfriendowner');
        [, $bId] = $this->newUser('byfriendmutual');
        [, $cId] = $this->newUser('byfriendoneway');
        $this->mutualFollow($aId, $bId);
        $this->mongo()->follows->insertOne(['_id' => $this->nextId('follows'), 'follower_id' => $aId, 'following_id' => $cId, 'created_at' => new MongoDB\BSON\UTCDateTime()]);

        $friends = array_column($a->get('/api/buddy-reads.php?action=friends')['data']['friends'], 'id');
        $this->assertContains($bId, $friends);
        $this->assertNotContains($cId, $friends, 'Tek yönlü takip arkadaş sayılmaz');

        // Demo hesap: davet gönderemez
        [$demo, $demoId, $demoEmail] = $this->newUser('bydemo');
        $this->mutualFollow($aId, $demoId);
        $this->mongo()->users->updateOne(['_id' => $demoId], ['$set' => ['is_demo' => true]]);
        $demo->login($demoEmail, 'Sifre123456!');
        $this->assertSame(403, $demo->post('/api/buddy-reads.php', ['action' => 'create', 'partner_id' => $aId] + $this->movie())['status']);
    }
}
