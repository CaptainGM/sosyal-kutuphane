<?php
// Destek talepleri: oturum açmış kullanıcı yönetime yazar, yönetici talebi "çözüldü" ya da "çözülmedi" olarak işaretler.
// Doğrulama kuralları saftır (veritabanı gerektirmez); tests/SupportTest.php doğrudan çağırır.

const SUPPORT_CATEGORIES = [
    'bug' => 'Hata bildirimi',
    'account' => 'Hesap veya giriş sorunu',
    'content' => 'İçerik ya da yorum şikâyeti',
    'suggestion' => 'Öneri veya istek',
    'other' => 'Diğer',
];
const SUPPORT_MESSAGE_MIN = 10;
const SUPPORT_MESSAGE_MAX = 2000;
const SUPPORT_NOTE_MAX = 500;
const SUPPORT_MAX_OPEN_PER_USER = 5;   // çözülmemiş talebi bu kadar olan yenisini açamaz
const SUPPORT_HOURLY_LIMIT = 5;        // saatte en fazla bu kadar talep
const SUPPORT_REPLY_MAX_PER_TICKET = 40;   // bir talebe yazılabilecek en fazla yanıt (kullanıcı + yönetici)
const SUPPORT_REPLY_HOURLY_LIMIT = 20;     // kullanıcı başına saatte en fazla yanıt
const SUPPORT_GUEST_EMAIL_DAILY_LIMIT = 3; // aynı e-posta adresinden günde en fazla misafir talebi
const SUPPORT_GUEST_GLOBAL_HOURLY_LIMIT = 30; // tüm misafirlerden saatte en fazla talep (devre kesici)
const SUPPORT_GUEST_MIN_FILL_SECONDS = 3;  // form açıldıktan en az bu kadar sonra gönderilebilir (botları yavaşlatır)
const SUPPORT_GUEST_TOKEN_MAX_AGE = 7200;  // form belirteci 2 saat geçerli
const SUPPORT_ADMIN_MAIL_HOURLY_LIMIT = 20; // yöneticiye giden bildirim e-postası üst sınırı (saatte)
const SUPPORT_MAX_LINKS = 3;               // misafir mesajında en fazla bu kadar bağlantı

function supportCategoryList(): array {
    $list = [];
    foreach (SUPPORT_CATEGORIES as $key => $label) {
        $list[] = ['key' => $key, 'label' => $label];
    }
    return $list;
}

// Denetim karakterlerini atar (satır sonu ve sekme kalır), satır sonlarını \n yapar. Geçersiz UTF-8 ise null döner.
function supportCleanText($raw): ?string {
    if (!is_string($raw)) {
        return null;
    }
    $text = preg_replace('/[^\P{C}\n\t]+/u', '', str_replace(["\r\n", "\r"], "\n", $raw));
    return $text === null ? null : trim($text);
}

// Talebin verildiği sayfa (yol + sorgu). Güvenli karakterlerden oluşmuyorsa sessizce atılır, talep reddedilmez.
function supportCleanPage($raw): ?string {
    if (!is_string($raw)) {
        return null;
    }
    $page = trim($raw);
    return preg_match('~^/(?!/)[A-Za-z0-9_\-./%?=&+,:@]{0,199}$~', $page) ? $page : null;
}

function supportCleanNote($raw): ?string {
    $note = supportCleanText($raw);
    return $note === null || $note === '' ? null : mb_substr($note, 0, SUPPORT_NOTE_MAX, 'UTF-8');
}

// Gelen talebi doğrular. Dönüş: ['error' => mesaj] ya da ['category' => ..., 'message' => ..., 'page' => ...|null].
function supportCleanTicket($input): array {
    if (!is_array($input)) {
        return ['error' => 'Geçersiz istek'];
    }
    $category = $input['category'] ?? null;
    if (!is_string($category) || !isset(SUPPORT_CATEGORIES[$category])) {
        return ['error' => 'Bir konu seç.'];
    }
    $message = supportCleanText($input['message'] ?? null);
    if ($message === null) {
        return ['error' => 'Mesaj geçersiz.'];
    }
    $length = mb_strlen($message, 'UTF-8');
    if ($length < SUPPORT_MESSAGE_MIN) {
        return ['error' => 'Mesajın en az ' . SUPPORT_MESSAGE_MIN . ' karakter olmalı. Sorunu biraz daha anlat.'];
    }
    if ($length > SUPPORT_MESSAGE_MAX) {
        return ['error' => 'Mesajın en fazla ' . SUPPORT_MESSAGE_MAX . ' karakter olabilir.'];
    }
    return ['category' => $category, 'message' => $message, 'page' => supportCleanPage($input['page'] ?? null)];
}

// Bir talebe yazılan yanıt: ['error' => mesaj] ya da ['message' => temiz metin].
function supportCleanReply($raw): array {
    $message = supportCleanText($raw);
    if ($message === null) {
        return ['error' => 'Mesaj geçersiz.'];
    }
    $length = mb_strlen($message, 'UTF-8');
    if ($length < 2) {
        return ['error' => 'Yanıtın boş olamaz.'];
    }
    if ($length > SUPPORT_MESSAGE_MAX) {
        return ['error' => 'Yanıtın en fazla ' . SUPPORT_MESSAGE_MAX . ' karakter olabilir.'];
    }
    return ['message' => $message];
}

function supportLinkCount(string $text): int {
    return preg_match_all('~(?:https?://|www\.)\S+~iu', $text);
}

// Misafir formunun belirteci: açılış zamanı + HMAC. Sunucuda durum tutmaz; çerez gerekmez.
function supportGuestToken(string $secret, int $issuedAt): string {
    return $issuedAt . '.' . hash_hmac('sha256', (string) $issuedAt, $secret);
}

// Belirteç geçerliyse null, değilse kullanıcıya gösterilecek hata. Çok erken gönderim (bot) ve çok eski belirteç reddedilir.
function supportGuestTokenError(string $secret, $token, int $now): ?string {
    if (!is_string($token) || !preg_match('/^(\d{9,11})\.([a-f0-9]{64})$/', $token, $m)) {
        return 'Form süresi doldu. Sayfayı yenileyip tekrar dene.';
    }
    if (!hash_equals(hash_hmac('sha256', $m[1], $secret), $m[2])) {
        return 'Form süresi doldu. Sayfayı yenileyip tekrar dene.';
    }
    $age = $now - (int) $m[1];
    if ($age > SUPPORT_GUEST_TOKEN_MAX_AGE) {
        return 'Form süresi doldu. Sayfayı yenileyip tekrar dene.';
    }
    if ($age < SUPPORT_GUEST_MIN_FILL_SECONDS) {
        return 'Çok hızlı gönderildi. Birkaç saniye bekleyip tekrar dene.';
    }
    return null;
}

// Giriş yapmamış kişinin talebi: ['spam' => true] (bal küpü dolu), ['error' => mesaj] ya da ['email','category','message','page'].
function supportCleanGuestTicket($input): array {
    if (!is_array($input)) {
        return ['error' => 'Geçersiz istek'];
    }
    // Bal küpü: insana görünmeyen alan. Doluysa bot sayılır ve sessizce yok sayılır.
    if (isset($input['website']) && trim((string) $input['website']) !== '') {
        return ['spam' => true];
    }
    $email = is_string($input['email'] ?? null) ? strtolower(trim($input['email'])) : '';
    if ($email === '' || strlen($email) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['error' => 'Yanıt gönderebilmemiz için geçerli bir e-posta adresi yaz.'];
    }
    $ticket = supportCleanTicket($input);
    if (isset($ticket['error'])) {
        return $ticket;
    }
    if (supportLinkCount($ticket['message']) > SUPPORT_MAX_LINKS) {
        return ['error' => 'Mesajda çok fazla bağlantı var. Sorunu bağlantı olmadan anlat.'];
    }
    return ['email' => $email] + $ticket;
}

function supportIso($utcDateTime): ?string {
    return $utcDateTime ? $utcDateTime->toDateTime()->format(DATE_ATOM) : null;
}

function supportReplyRow($reply): array {
    return [
        'by' => ($reply['by'] ?? '') === 'admin' ? 'admin' : 'user',
        'text' => (string) ($reply['text'] ?? ''),
        'at' => supportIso($reply['at'] ?? null),
    ];
}

// Kullanıcıya dönen biçim: yöneticinin kim olduğu gösterilmez; durum, yanıtlar ve (eski kayıtlar için) not.
// $forAdmin: yönetici görünümüne misafir e-postası da eklenir.
function supportTicketRow($ticket, bool $forAdmin = false): array {
    $category = $ticket['category'] ?? 'other';
    $replies = [];
    foreach ((array) ($ticket['replies'] ?? []) as $reply) {
        $replies[] = supportReplyRow($reply);
    }
    $status = ($ticket['status'] ?? 'open') === 'resolved' ? 'resolved' : 'open';
    $row = [
        'id' => (int) $ticket['_id'],
        'category' => $category,
        'category_label' => SUPPORT_CATEGORIES[$category] ?? SUPPORT_CATEGORIES['other'],
        'message' => $ticket['message'],
        'page' => $ticket['page'] ?? null,
        'status' => $status,
        'admin_note' => $ticket['admin_note'] ?? null,
        'replies' => $replies,
        // Son söz kullanıcıdaysa (ya da henüz yanıt yoksa) yönetici yanıtı bekleniyor.
        'awaiting_admin' => $status === 'open' && (!$replies || end($replies)['by'] === 'user'),
        'created_at' => supportIso($ticket['created_at']),
        'resolved_at' => supportIso($ticket['resolved_at'] ?? null),
    ];
    if ($forAdmin) {
        $row['guest'] = ($ticket['user_id'] ?? null) === null;
        $row['contact_email'] = $ticket['contact_email'] ?? null;
    }
    return $row;
}
