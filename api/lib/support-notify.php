<?php
// Destek talepleri için bildirimler (uygulama içi, tarayıcı bildirimi, e-posta). Talep kuralları: lib/support.php.
//  - Yeni talep ya da kullanıcı yanıtı gelince tüm yöneticilere zil bildirimi + (ayarlıysa) e-posta gider.
//  - Yönetici yanıtlayınca ya da çözünce talep sahibine zil bildirimi gider; misafir talebinde yanıt e-postayla gönderilir.
//  - Misafirin yazdığı adrese kendiliğinden e-posta ASLA gitmez (form bir spam aracına dönüşmesin); yalnızca yönetici yanıtı gider.
// Her gönderim en iyi çabayla yapılır: bir hata talebin kaydedilmesini bozmaz.
require_once __DIR__ . '/push.php';
require_once __DIR__ . '/rate-limit.php';
require_once __DIR__ . '/support.php';
require_once __DIR__ . '/../mail-config.php';

// İlk 60 karakter, tek satır. Bildirim başlığı olarak kullanılır.
function supportSnippet(string $text, int $length = 60): string {
    return mb_substr((string) preg_replace('/\s+/u', ' ', $text), 0, $length, 'UTF-8');
}

// Yöneticiler: [['id' => int, 'email' => string], ...]
function supportAdminUsers($db): array {
    $admins = [];
    foreach ($db->users->find(['is_admin' => true], ['projection' => ['email' => 1]]) as $admin) {
        $admins[] = ['id' => (int) $admin['_id'], 'email' => (string) ($admin['email'] ?? '')];
    }
    return $admins;
}

// Misafir formunun imza anahtarı: ilk kullanımda rastgele üretilip veritabanında saklanır.
function supportGuestSecret($db): string {
    $doc = $db->settings->findOneAndUpdate(
        ['_id' => 'support_guest_secret'],
        ['$setOnInsert' => ['value' => bin2hex(random_bytes(32))]],
        ['upsert' => true, 'returnDocument' => MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER]
    );
    return (string) $doc['value'];
}

function supportInsertNotification($db, int $userId, ?int $actorId, string $type, int $ticketId, string $title): void {
    $db->notifications->insertOne([
        '_id' => nextSequence($db, 'notifications'),
        'user_id' => $userId,
        'actor_id' => $actorId,
        'type' => $type,
        'content_type' => 'ticket',
        'content_id' => $ticketId,
        'content_title' => $title,
        'is_read' => false,
        'created_at' => new MongoDB\BSON\UTCDateTime(),
    ]);
}

// Yeni talep ($kind 'new') ya da kullanıcının yanıtı ($kind 'reply') yöneticilere bildirilir. $who: kullanıcı adı ya da misafir e-postası.
function supportNotifyAdmins($db, $ticket, string $kind, string $who, ?int $actorId): void {
    try {
        $ticketId = (int) $ticket['_id'];
        $replies = (array) ($ticket['replies'] ?? []);
        $last = $replies ? end($replies) : null;
        $text = $kind === 'reply' && $last ? (string) $last['text'] : (string) $ticket['message'];
        $title = ($kind === 'reply' ? 'Yanıt: ' : 'Yeni talep: ') . supportSnippet($text, 50);
        $admins = supportAdminUsers($db);
        foreach ($admins as $admin) {
            supportInsertNotification($db, $admin['id'], $actorId, 'ticket_admin', $ticketId, $title);
            pushNotifyUser($db, $admin['id'], $kind === 'reply' ? 'Destek talebine yanıt geldi' : 'Yeni destek talebi', 'admin.html#tickets');
        }
        supportEmailAdmins($db, $admins, $ticket, $kind, $who, $text);
    } catch (Throwable $e) {
        error_log('Destek bildirimi yöneticilere gönderilemedi: ' . $e->getMessage());
    }
}

// Talep sahibine: yönetici yanıt yazdı ($kind 'reply') ya da talebi çözdü ($kind 'resolved'). Misafir talebinde zil yoktur.
function supportNotifyOwner($db, $ticket, string $kind): void {
    try {
        $ownerId = $ticket['user_id'] ?? null;
        if ($ownerId === null) {
            return;
        }
        $ticketId = (int) $ticket['_id'];
        $title = supportSnippet((string) $ticket['message']);
        supportInsertNotification($db, (int) $ownerId, null, $kind === 'resolved' ? 'ticket' : 'ticket_reply', $ticketId, $title);
        pushNotifyUser($db, (int) $ownerId, $kind === 'resolved' ? 'Destek talebin çözüldü' : 'Destek talebine yanıt geldi', 'profile.html#destek');
    } catch (Throwable $e) {
        error_log('Destek bildirimi talep sahibine gönderilemedi: ' . $e->getMessage());
    }
}

function supportEscape(string $text): string {
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Yöneticilere e-posta (Brevo/SMTP ayarlıysa). Saatlik üst sınır: bir kullanıcı talep yağdırıp gelen kutusunu dolduramaz.
function supportEmailAdmins($db, array $admins, $ticket, string $kind, string $who, string $text): void {
    if (!mailIsConfigured() || rateLimitHit($db, 'support-admin-mail', SUPPORT_ADMIN_MAIL_HOURLY_LIMIT, 3600)) {
        return;
    }
    $ticketId = (int) $ticket['_id'];
    $category = SUPPORT_CATEGORIES[$ticket['category'] ?? 'other'] ?? SUPPORT_CATEGORIES['other'];
    $heading = $kind === 'reply' ? "Destek talebine yanıt geldi (#$ticketId)" : "Yeni destek talebi (#$ticketId)";
    $link = SITE_URL . '/admin.html#tickets';
    // Metin kullanıcıdan geldiği için her yerde kaçışlanır.
    $html = '<div style="font-family: Arial, sans-serif; max-width: 560px; margin: 0 auto;">'
        . '<h2 style="color:#6d28d9;">' . supportEscape($heading) . '</h2>'
        . '<p><strong>Konu:</strong> ' . supportEscape($category) . '<br><strong>Gönderen:</strong> ' . supportEscape($who) . '</p>'
        . '<p style="white-space: pre-wrap; background:#f5f3ff; border-left:4px solid #6d28d9; padding:12px;">' . supportEscape($text) . '</p>'
        . '<p><a href="' . supportEscape($link) . '" style="color:#6d28d9;">Yönetim konsolunda aç</a></p></div>';
    $plain = "$heading\nKonu: $category\nGönderen: $who\n\n$text\n\n$link";
    foreach ($admins as $admin) {
        if ($admin['email'] === '') {
            continue;
        }
        try {
            $mail = mailCompose($admin['email'], 'Sosyal Kütüphane - ' . $heading, $html, $plain);
            if (!sendMailPrepared($mail)) {
                error_log('Yönetici bildirim e-postası gönderilemedi (' . maskEmailForLog($admin['email']) . '): ' . $mail->ErrorInfo);
            }
        } catch (Throwable $e) {
            error_log('Yönetici bildirim e-postası hatası: ' . $e->getMessage());
        }
    }
}

// Misafir talebine yönetici yanıtı e-postayla gider. Gönderilebildiyse true.
function supportEmailGuest($ticket, string $replyText): bool {
    $to = (string) ($ticket['contact_email'] ?? '');
    if ($to === '' || !mailIsConfigured()) {
        return false;
    }
    try {
        $ticketId = (int) $ticket['_id'];
        $html = '<div style="font-family: Arial, sans-serif; max-width: 560px; margin: 0 auto;">'
            . '<h2 style="color:#6d28d9;">Sosyal Kütüphane destek yanıtı</h2>'
            . '<p style="white-space: pre-wrap; background:#f5f3ff; border-left:4px solid #6d28d9; padding:12px;">' . supportEscape($replyText) . '</p>'
            . '<p style="color:#555;">Yazdığın talep (#' . $ticketId . '):</p>'
            . '<p style="white-space: pre-wrap; color:#555; border-left:3px solid #ddd; padding-left:10px;">' . supportEscape((string) $ticket['message']) . '</p>'
            . '<p style="color:#777; font-size:13px;">Bu e-postayı destek talebin için aldın. Yeniden yazmak istersen sitedeki destek formunu kullanabilirsin.</p></div>';
        $plain = "Sosyal Kütüphane destek yanıtı\n\n$replyText\n\nYazdığın talep (#$ticketId):\n" . $ticket['message'];
        $mail = mailCompose($to, "Sosyal Kütüphane - Destek talebine yanıt (#$ticketId)", $html, $plain);
        if (sendMailPrepared($mail)) {
            return true;
        }
        error_log('Misafire destek yanıtı gönderilemedi (' . maskEmailForLog($to) . '): ' . $mail->ErrorInfo);
    } catch (Throwable $e) {
        error_log('Misafire destek yanıtı hatası: ' . $e->getMessage());
    }
    return false;
}
