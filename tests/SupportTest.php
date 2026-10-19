<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ApiHelpers.php';
require_once __DIR__ . '/../api/lib/support.php';

// Destek talepleri: doğrulama kuralları (saf) ve kullanıcı / yönetici akışı (çalışan sunucu ve MongoDB gerekir, bkz. ApiTest).
final class SupportTest extends TestCase
{
    use ApiHelpers;

    private function ticket(array $overrides = []): array
    {
        return $overrides + ['category' => 'bug', 'message' => 'Detay sayfasında afiş yüklenmiyor, sayfayı yenilemek de işe yaramadı.', 'page' => '/detail.php?type=movie&id=12'];
    }

    public function testValidTicketIsCleaned(): void
    {
        $clean = supportCleanTicket($this->ticket(['message' => "  Merhaba\r\ndünya, bu bir deneme mesajıdır.\x07\x00  "]));
        $this->assertArrayNotHasKey('error', $clean);
        $this->assertSame('bug', $clean['category']);
        $this->assertSame("Merhaba\ndünya, bu bir deneme mesajıdır.", $clean['message'], 'Denetim karakterleri atılır, satır sonları \\n olur');
        $this->assertSame('/detail.php?type=movie&id=12', $clean['page']);
    }

    public function testInvalidTicketsAreRejected(): void
    {
        $this->assertArrayHasKey('error', supportCleanTicket(null));
        $this->assertArrayHasKey('error', supportCleanTicket($this->ticket(['category' => 'bilinmeyen'])));
        $this->assertArrayHasKey('error', supportCleanTicket($this->ticket(['category' => ['bug']])));
        $this->assertArrayHasKey('error', supportCleanTicket($this->ticket(['message' => 'kısa'])));
        $this->assertArrayHasKey('error', supportCleanTicket($this->ticket(['message' => str_repeat('a', SUPPORT_MESSAGE_MIN - 1) . "   \n"])), 'Boşluklar sayılmaz');
        $this->assertArrayHasKey('error', supportCleanTicket($this->ticket(['message' => str_repeat('a', SUPPORT_MESSAGE_MAX + 1)])));
        $this->assertArrayHasKey('error', supportCleanTicket($this->ticket(['message' => ['dizi']])));
        $this->assertArrayHasKey('error', supportCleanTicket($this->ticket(['message' => "bozuk \xC3\x28 utf-8 mesaj metni burada"])), 'Geçersiz UTF-8 reddedilir');
        $this->assertArrayNotHasKey('error', supportCleanTicket($this->ticket(['message' => str_repeat('ş', SUPPORT_MESSAGE_MAX)])), 'Uzunluk karakterle sayılır, bayt ile değil');
    }

    public function testPageIsKeptOnlyWhenItLooksLikeASitePath(): void
    {
        $this->assertSame('/detail.php?type=movie&id=12', supportCleanPage('/detail.php?type=movie&id=12'));
        $this->assertSame('/', supportCleanPage('/'));
        foreach (['//evil.example/x', 'javascript:alert(1)', 'https://evil.example', '/a b', '/<script>', '', str_repeat('/a', 150)] as $bad) {
            $this->assertNull(supportCleanPage($bad), "atılmalı: $bad");
        }
        $this->assertNull(supportCleanPage(['/x']));
        $clean = supportCleanTicket($this->ticket(['page' => 'javascript:alert(1)']));
        $this->assertArrayNotHasKey('error', $clean, 'Geçersiz sayfa talebi düşürmez');
        $this->assertNull($clean['page']);
    }

    public function testNoteIsOptionalAndLimited(): void
    {
        $this->assertNull(supportCleanNote(null));
        $this->assertNull(supportCleanNote("   \n"));
        $this->assertSame('Düzeltildi', supportCleanNote("  Düzeltildi \x07"));
        $this->assertSame(SUPPORT_NOTE_MAX, mb_strlen(supportCleanNote(str_repeat('ğ', SUPPORT_NOTE_MAX + 50)), 'UTF-8'));
    }

    public function testUserCreatesTicketAndSeesItInTheirList(): void
    {
        [$user] = $this->newUser('phpunit_support');

        $created = $user->post('/api/support-tickets.php', $this->ticket());
        $this->assertSame(201, $created['status'], json_encode($created['data']));
        $ticket = $created['data']['ticket'];
        $this->assertSame('open', $ticket['status']);
        $this->assertSame('Hata bildirimi', $ticket['category_label']);
        $this->assertNull($ticket['admin_note']);

        $list = $user->get('/api/support-tickets.php');
        $this->assertSame(200, $list['status']);
        $this->assertSame([$ticket['id']], array_column($list['data']['tickets'], 'id'));
        $this->assertSame(['bug', 'account', 'content', 'suggestion', 'other'], array_column($list['data']['categories'], 'key'));
    }

    public function testTicketsAreOnlyVisibleToTheirOwner(): void
    {
        [$owner] = $this->newUser('phpunit_support');
        [$other] = $this->newUser('phpunit_support');
        $owner->post('/api/support-tickets.php', $this->ticket());

        $this->assertSame([], $other->get('/api/support-tickets.php')['data']['tickets']);
    }

    public function testValidationErrorsAndLoginAreEnforced(): void
    {
        [$user] = $this->newUser('phpunit_support');
        $this->assertSame(422, $user->post('/api/support-tickets.php', $this->ticket(['category' => 'x']))['status']);
        $this->assertSame(422, $user->post('/api/support-tickets.php', $this->ticket(['message' => 'kısa']))['status']);
        $this->assertSame([], $user->get('/api/support-tickets.php')['data']['tickets'], 'Hatalı talep kaydedilmez');

        $anonymous = new ApiClient(TEST_BASE_URL);
        $this->assertSame(401, $anonymous->get('/api/support-tickets.php')['status']);
        $this->assertSame(401, $anonymous->post('/api/support-tickets.php', $this->ticket())['status']);

        $user->csrfToken = null;
        $this->assertSame(403, $user->post('/api/support-tickets.php', $this->ticket())['status'], 'CSRF belirteci olmadan talep açılamaz');
    }

    public function testOpenTicketCapAndHourlyLimit(): void
    {
        [$user, $userId] = $this->newUser('phpunit_support');
        for ($i = 1; $i <= SUPPORT_MAX_OPEN_PER_USER; $i++) {
            $this->assertSame(201, $user->post('/api/support-tickets.php', $this->ticket())['status'], "$i. talep açılmalı");
        }
        $capped = $user->post('/api/support-tickets.php', $this->ticket());
        $this->assertSame(429, $capped['status']);
        $this->assertStringContainsString('Çözülmemiş', $capped['data']['message']);

        // Talepler çözülünce üst sınır kalkar, ama saatlik sınır devam eder.
        $this->mongo()->support_tickets->updateMany(['user_id' => $userId], ['$set' => ['status' => 'resolved']]);
        $limited = $user->post('/api/support-tickets.php', $this->ticket());
        $this->assertSame(429, $limited['status']);
        $this->assertStringContainsString('Çok fazla', $limited['data']['message']);
    }

    public function testAdminResolvesAndReopensAndOwnerIsNotified(): void
    {
        [$user, $userId] = $this->newUser('phpunit_support');
        [$admin, $adminId] = $this->newAdmin();
        $id = $user->post('/api/support-tickets.php', $this->ticket(['message' => 'Doğrulama kodu e-postası hiç gelmiyor, spam klasörüne de baktım.']))['data']['ticket']['id'];

        $queue = $admin->get('/api/admin.php?action=tickets&status=open');
        $this->assertSame(200, $queue['status']);
        $mine = array_values(array_filter($queue['data']['tickets'], static fn ($t) => $t['id'] === $id));
        $this->assertCount(1, $mine);
        $this->assertSame($userId, (int) $mine[0]['user']['id']);
        $this->assertSame('open', $mine[0]['status']);
        $this->assertGreaterThanOrEqual(1, $queue['data']['counts']['open']);

        $resolved = $admin->post('/api/admin.php', ['action' => 'resolve_ticket', 'ticket_id' => $id, 'note' => 'Brevo ayarı düzeltildi, tekrar dene.']);
        $this->assertSame(200, $resolved['status'], json_encode($resolved['data']));

        $seen = $user->get('/api/support-tickets.php')['data']['tickets'][0];
        $this->assertSame('resolved', $seen['status']);
        $this->assertSame('Brevo ayarı düzeltildi, tekrar dene.', $seen['admin_note']);
        $this->assertNotNull($seen['resolved_at']);
        $this->assertArrayNotHasKey('resolved_by', $seen, 'Kullanıcı hangi yöneticinin çözdüğünü görmez');

        $notifications = array_values(array_filter($user->get('/api/notifications.php')['data']['notifications'], static fn ($n) => $n['type'] === 'ticket'));
        $this->assertCount(1, $notifications, 'Çözülünce sahibine bildirim gider');
        $this->assertSame($id, (int) $notifications[0]['content_id']);

        $again = $admin->post('/api/admin.php', ['action' => 'resolve_ticket', 'ticket_id' => $id, 'note' => 'Not güncellendi.']);
        $this->assertSame(200, $again['status']);
        $notificationsAfter = array_filter($user->get('/api/notifications.php')['data']['notifications'], static fn ($n) => $n['type'] === 'ticket');
        $this->assertCount(1, $notificationsAfter, 'Aynı talep için ikinci bildirim gitmez');
        $this->assertSame('Not güncellendi.', $user->get('/api/support-tickets.php')['data']['tickets'][0]['admin_note']);

        $this->assertSame(200, $admin->post('/api/admin.php', ['action' => 'reopen_ticket', 'ticket_id' => $id])['status']);
        $reopened = $user->get('/api/support-tickets.php')['data']['tickets'][0];
        $this->assertSame('open', $reopened['status']);
        $this->assertNull($reopened['resolved_at']);

        $audit = $admin->get('/api/admin.php?action=audit&target_type=ticket');
        $actions = array_column(array_filter($audit['data']['entries'], static fn ($e) => (int) $e['target_id'] === $id), 'action');
        $this->assertContains('ticket.resolve', $actions);
        $this->assertContains('ticket.reopen', $actions);
    }

    public function testStatusFilterAndAdminOnlyAccess(): void
    {
        [$user] = $this->newUser('phpunit_support');
        [$admin] = $this->newAdmin();
        $id = $user->post('/api/support-tickets.php', $this->ticket())['data']['ticket']['id'];
        $admin->post('/api/admin.php', ['action' => 'resolve_ticket', 'ticket_id' => $id]);

        $ids = fn (string $status) => array_column($admin->get('/api/admin.php?action=tickets&status=' . $status . '&per_page=50')['data']['tickets'], 'id');
        $this->assertContains($id, $ids('resolved'));
        $this->assertNotContains($id, $ids('open'));
        $this->assertContains($id, $ids('all'));

        $this->assertSame(403, $user->get('/api/admin.php?action=tickets')['status'], 'Yönetici olmayan kuyruğu göremez');
        $this->assertSame(403, $user->post('/api/admin.php', ['action' => 'resolve_ticket', 'ticket_id' => $id])['status']);
        $this->assertSame(404, $admin->post('/api/admin.php', ['action' => 'resolve_ticket', 'ticket_id' => 999999999])['status']);
    }
}
