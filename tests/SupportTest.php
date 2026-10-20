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

    public function testReplyAndGuestRulesArePure(): void
    {
        $this->assertArrayHasKey('error', supportCleanReply(null));
        $this->assertArrayHasKey('error', supportCleanReply(' '));
        $this->assertArrayHasKey('error', supportCleanReply(str_repeat('a', SUPPORT_MESSAGE_MAX + 1)));
        $this->assertSame('Tamam, bakıyorum.', supportCleanReply("  Tamam, bakıyorum.\x07 ")['message']);

        $secret = 'gizli';
        $now = 1_800_000_000;
        $this->assertNull(supportGuestTokenError($secret, supportGuestToken($secret, $now - 10), $now), 'Yeterince eski ve geçerli belirteç');
        $this->assertStringContainsString('hızlı', supportGuestTokenError($secret, supportGuestToken($secret, $now - 1), $now), 'Form 3 saniyeden önce gönderilemez');
        $this->assertNotNull(supportGuestTokenError($secret, supportGuestToken($secret, $now - SUPPORT_GUEST_TOKEN_MAX_AGE - 5), $now), 'Çok eski belirteç');
        $this->assertNotNull(supportGuestTokenError('baska', supportGuestToken($secret, $now - 10), $now), 'Başka anahtarla imzalanmış belirteç');
        $this->assertNotNull(supportGuestTokenError($secret, ($now - 10) . '.' . str_repeat('a', 64), $now), 'Sahte imza');
        $this->assertNotNull(supportGuestTokenError($secret, null, $now));

        $ok = supportCleanGuestTicket(['email' => ' Kisi@Ornek.COM ', 'category' => 'account', 'message' => 'Doğrulama kodu e-postam hiç gelmiyor.']);
        $this->assertSame('kisi@ornek.com', $ok['email']);
        $this->assertTrue(supportCleanGuestTicket(['website' => 'http://bot.example', 'email' => 'a@b.com', 'category' => 'bug', 'message' => 'x'])['spam'] ?? false, 'Bal küpü dolu: bot');
        foreach (['', 'gecersiz', 'a@b', str_repeat('a', 130) . '@ornek.com'] as $bad) {
            $this->assertArrayHasKey('error', supportCleanGuestTicket(['email' => $bad, 'category' => 'bug', 'message' => 'Yeterince uzun bir mesaj yazdım.']), "geçersiz e-posta: $bad");
        }
        $links = 'Bak http://a.example ve http://b.example ve https://c.example ve www.d.example lütfen';
        $this->assertArrayHasKey('error', supportCleanGuestTicket(['email' => 'a@b.com', 'category' => 'bug', 'message' => $links]), 'Çok bağlantı reddedilir');
    }

    public function testNewTicketNotifiesEveryAdminAndAnAdminReplyNotifiesTheOwner(): void
    {
        [$user, $userId] = $this->newUser('phpunit_support');
        [$adminA] = $this->newAdmin();
        [$adminB] = $this->newAdmin();
        $id = $user->post('/api/support-tickets.php', $this->ticket(['message' => 'Profil fotoğrafım yüklenmiyor, ne yapmalıyım?']))['data']['ticket']['id'];

        foreach ([$adminA, $adminB] as $admin) {
            $mine = array_values(array_filter($admin->get('/api/notifications.php')['data']['notifications'], static fn ($n) => $n['type'] === 'ticket_admin' && (int) $n['content_id'] === $id));
            $this->assertCount(1, $mine, 'Her yönetici yeni talep bildirimi alır');
            $this->assertStringStartsWith('Yeni talep:', $mine[0]['content_title']);
        }

        $reply = $adminA->post('/api/admin.php', ['action' => 'reply_ticket', 'ticket_id' => $id, 'message' => 'Hangi dosya türünü denedin?']);
        $this->assertSame(200, $reply['status'], json_encode($reply['data']));

        $ticket = $user->get('/api/support-tickets.php')['data']['tickets'][0];
        $this->assertSame('open', $ticket['status'], 'Yanıt talebi çözmez');
        $this->assertSame([['by' => 'admin', 'text' => 'Hangi dosya türünü denedin?']], array_map(static fn ($r) => ['by' => $r['by'], 'text' => $r['text']], $ticket['replies']));
        $this->assertFalse($ticket['awaiting_admin'], 'Son söz yöneticide: kullanıcı bekleniyor');
        $this->assertArrayNotHasKey('admin_id', $ticket['replies'][0], 'Hangi yöneticinin yazdığı kullanıcıya gösterilmez');
        $replies = array_filter($user->get('/api/notifications.php')['data']['notifications'], static fn ($n) => $n['type'] === 'ticket_reply');
        $this->assertCount(1, $replies);

        // Yanıtla ve çöz: tek bildirim ("çözüldü"), yanıt metni konuşmada kalır
        $adminA->post('/api/admin.php', ['action' => 'reply_ticket', 'ticket_id' => $id, 'message' => 'Sorun giderildi, tekrar dene.', 'resolve' => true]);
        $ticket = $user->get('/api/support-tickets.php')['data']['tickets'][0];
        $this->assertSame('resolved', $ticket['status']);
        $this->assertCount(2, $ticket['replies']);
        $resolved = array_filter($user->get('/api/notifications.php')['data']['notifications'], static fn ($n) => $n['type'] === 'ticket');
        $this->assertCount(1, $resolved);

        $this->assertSame(422, $adminA->post('/api/admin.php', ['action' => 'reply_ticket', 'ticket_id' => $id, 'message' => ' '])['status']);
        $this->assertSame(404, $adminA->post('/api/admin.php', ['action' => 'reply_ticket', 'ticket_id' => 999999999, 'message' => 'merhaba'])['status']);
    }

    public function testUserReplyReopensAResolvedTicketAndAlertsTheAdmins(): void
    {
        [$user] = $this->newUser('phpunit_support');
        [$other] = $this->newUser('phpunit_support');
        [$admin] = $this->newAdmin();
        $id = $user->post('/api/support-tickets.php', $this->ticket())['data']['ticket']['id'];
        $admin->post('/api/admin.php', ['action' => 'resolve_ticket', 'ticket_id' => $id, 'note' => 'Düzeltildi.']);

        $reply = $user->post('/api/support-tickets.php', ['action' => 'reply', 'ticket_id' => $id, 'message' => 'Hâlâ aynı sorun var, tekrar bakar mısınız?']);
        $this->assertSame(200, $reply['status'], json_encode($reply['data']));
        $this->assertSame('open', $reply['data']['ticket']['status'], 'Kullanıcı yazınca çözülmüş talep yeniden açılır');
        $this->assertNull($reply['data']['ticket']['resolved_at']);
        $this->assertTrue($reply['data']['ticket']['awaiting_admin']);

        $mine = array_values(array_filter($admin->get('/api/notifications.php')['data']['notifications'], static fn ($n) => $n['type'] === 'ticket_admin' && str_starts_with($n['content_title'], 'Yanıt:')));
        $this->assertNotEmpty($mine, 'Yöneticiye "yanıt geldi" bildirimi gider');

        $this->assertSame(404, $other->post('/api/support-tickets.php', ['action' => 'reply', 'ticket_id' => $id, 'message' => 'Başkasının talebi'])['status'], 'Yalnızca kendi talebine yazılır');
        $this->assertSame(422, $user->post('/api/support-tickets.php', ['action' => 'reply', 'ticket_id' => $id, 'message' => ''])['status']);
    }

    private function guestToken(ApiClient $client): array
    {
        $res = $client->get('/api/support-guest.php');
        $this->assertSame(200, $res['status']);
        $this->assertArrayNotHasKey('csrf_token', $res['data']);
        // Form en az 3 sn açık kalmış gibi: imza anahtarı veritabanından okunup geçmiş bir zamanla imzalanır.
        $secret = (string) $this->mongo()->settings->findOne(['_id' => 'support_guest_secret'])['value'];
        return [supportGuestToken($secret, time() - 10), $res['data']];
    }

    public function testGuestCanOpenATicketAndAdminSeesTheEmail(): void
    {
        [$admin] = $this->newAdmin(); // bildirim, talep açıldığı anda var olan yöneticilere gider
        $guest = new ApiClient(TEST_BASE_URL);
        [$token] = $this->guestToken($guest);
        $email = 'misafir_' . $this->suffix() . '@ornek.com';
        $message = 'Doğrulama kodu e-postası gelmiyor, spam klasörüne de baktım (' . $this->suffix() . ').';

        $res = $guest->post('/api/support-guest.php', ['email' => $email, 'category' => 'account', 'message' => $message, 'token' => $token]);
        $this->assertSame(201, $res['status'], json_encode($res['data']));

        $stored = $this->mongo()->support_tickets->findOne(['contact_email' => $email]);
        $this->assertNotNull($stored);
        $this->assertNull($stored['user_id'], 'Misafir talebinin kullanıcısı yoktur');

        $queue = $admin->get('/api/admin.php?action=tickets&status=open&per_page=50')['data']['tickets'];
        $mine = array_values(array_filter($queue, static fn ($t) => $t['contact_email'] === $email));
        $this->assertCount(1, $mine);
        $this->assertTrue($mine[0]['guest']);
        $this->assertNull($mine[0]['user']);
        $this->assertTrue($mine[0]['awaiting_admin']);

        $notified = array_filter($admin->get('/api/notifications.php')['data']['notifications'], static fn ($n) => $n['type'] === 'ticket_admin' && (int) $n['content_id'] === $mine[0]['id']);
        $this->assertCount(1, $notified, 'Misafir talebi de yöneticiye bildirilir');

        // Yönetici yanıtı kaydedilir; e-posta ayarlı olmayan ortamda gönderilemez (emailed=false) ama işlem başarılıdır.
        $reply = $admin->post('/api/admin.php', ['action' => 'reply_ticket', 'ticket_id' => $mine[0]['id'], 'message' => 'Spam klasörüne baktınız mı? Bir kod daha gönderdik.', 'resolve' => true]);
        $this->assertSame(200, $reply['status'], json_encode($reply['data']));
        $this->assertFalse($reply['data']['emailed']);
        $after = $this->mongo()->support_tickets->findOne(['_id' => $mine[0]['id']]);
        $this->assertSame('resolved', $after['status']);
        $this->assertCount(1, $after['replies']);
    }

    public function testGuestFormRejectsBotsFastSubmissionsAndAbuse(): void
    {
        $guest = new ApiClient(TEST_BASE_URL);
        [$token] = $this->guestToken($guest);
        $base = ['category' => 'bug', 'message' => 'Sayfa yüklenirken beyaz ekran görüyorum, ne yapmalıyım?', 'token' => $token];
        $count = fn (string $email) => $this->mongo()->support_tickets->countDocuments(['contact_email' => $email]);

        // Bal küpü: başarılı gibi görünür ama kaydedilmez
        $botEmail = 'bot_' . $this->suffix() . '@ornek.com';
        $this->assertSame(201, $guest->post('/api/support-guest.php', $base + ['email' => $botEmail, 'website' => 'http://spam.example'])['status']);
        $this->assertSame(0, $count($botEmail));

        // Belirteç yok / sahte / çok taze
        $email = 'misafir_' . $this->suffix() . '@ornek.com';
        $this->assertSame(422, $guest->post('/api/support-guest.php', ['email' => $email] + array_diff_key($base, ['token' => 1]))['status']);
        $this->assertSame(422, $guest->post('/api/support-guest.php', ['email' => $email, 'token' => '1800000000.' . str_repeat('0', 64)] + $base)['status']);
        $fresh = (string) $guest->get('/api/support-guest.php')['data']['token'];
        $tooFast = $guest->post('/api/support-guest.php', ['email' => $email, 'token' => $fresh] + $base);
        $this->assertSame(422, $tooFast['status']);
        $this->assertStringContainsString('hızlı', $tooFast['data']['message']);
        $this->assertSame(0, $count($email));

        // Geçersiz e-posta, çok bağlantı
        $this->assertSame(422, $guest->post('/api/support-guest.php', ['email' => 'gecersiz'] + $base)['status']);
        $this->assertSame(422, $guest->post('/api/support-guest.php', ['email' => $email, 'message' => 'a http://1.example b http://2.example c http://3.example d http://4.example'] + $base)['status']);

        // Aynı adresten günde 3 talep
        for ($i = 1; $i <= SUPPORT_GUEST_EMAIL_DAILY_LIMIT; $i++) {
            $this->assertSame(201, $guest->post('/api/support-guest.php', ['email' => $email] + $base)['status'], "$i. talep geçmeli");
        }
        $limited = $guest->post('/api/support-guest.php', ['email' => $email] + $base);
        $this->assertSame(429, $limited['status']);
        $this->assertSame(SUPPORT_GUEST_EMAIL_DAILY_LIMIT, $count($email));
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
