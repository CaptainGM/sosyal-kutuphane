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

function supportIso($utcDateTime): ?string {
    return $utcDateTime ? $utcDateTime->toDateTime()->format(DATE_ATOM) : null;
}

// Kullanıcıya dönen biçim: yöneticinin kim olduğu gösterilmez, yalnızca durum ve not.
function supportTicketRow($ticket): array {
    $category = $ticket['category'] ?? 'other';
    return [
        'id' => (int) $ticket['_id'],
        'category' => $category,
        'category_label' => SUPPORT_CATEGORIES[$category] ?? SUPPORT_CATEGORIES['other'],
        'message' => $ticket['message'],
        'page' => $ticket['page'] ?? null,
        'status' => ($ticket['status'] ?? 'open') === 'resolved' ? 'resolved' : 'open',
        'admin_note' => $ticket['admin_note'] ?? null,
        'created_at' => supportIso($ticket['created_at']),
        'resolved_at' => supportIso($ticket['resolved_at'] ?? null),
    ];
}
