<?php
// Doğrudan mesaj (DM) koruması: gönderim hızı sınırlanır ve metin yalnızca dolandırıcılık (IBAN / banka hesabı) ile
// küfür/hakaret için otomatik taranır. Bağlantı paylaşımına izin verilir (arkadaşlar film bağlantısı gönderir).
// Yönetici özel mesajları okuyamaz; yönetim konsoluna DM içeriği hiç taşınmaz.
// Kararlar saftır (veritabanı gerektirmez, tests/DmTest.php doğrudan çağırır).
require_once __DIR__ . '/moderation.php';

const DM_BURST_WINDOW = 60;        // saniye
const DM_BURST_LIMIT = 15;
const DM_SHORT_WINDOW = 600;       // 10 dakika
const DM_SHORT_LIMIT = 60;
const DM_DAY_LIMIT = 500;
const DM_DUPLICATE_WINDOW = 600;
const DM_DUPLICATE_LIMIT = 3;      // aynı metin 10 dakikada en fazla 3 kez
const DM_HISTORY_WINDOW = 86400;

// Döndürdüğü metin doğrudan kullanıcıya gösterilir; null ise mesaja izin verilir.
function dmModerationVerdict(string $text): ?array {
    if (commentIbanFound($text)) {
        return ['reason' => 'iban', 'message' => 'Mesajlarda IBAN veya banka hesap bilgisi paylaşılamaz.'];
    }
    if (commentProfanityFound($text)) {
        return ['reason' => 'profanity', 'message' => 'Mesajın küfür veya hakaret içeriyor. Lütfen saygılı bir dil kullan.'];
    }
    return null;
}

function dmModerationError(string $text): ?string {
    $verdict = dmModerationVerdict($text);
    return $verdict === null ? null : $verdict['message'];
}

// $recent: göndericinin son 24 saatteki mesajları [['at' => unix, 'text' => string], ...].
// Döndürdüğü metin doğrudan kullanıcıya gösterilir; null ise gönderime izin verilir.
function dmRateError(array $recent, string $text, int $now): ?string {
    $normalized = mb_strtolower(trim($text), 'UTF-8');
    $inBurst = 0;
    $inShort = 0;
    $duplicates = 0;
    foreach ($recent as $item) {
        $age = $now - (int) $item['at'];
        if ($age < DM_BURST_WINDOW) {
            $inBurst++;
        }
        if ($age < DM_SHORT_WINDOW) {
            $inShort++;
        }
        if ($age < DM_DUPLICATE_WINDOW && mb_strtolower(trim((string) $item['text']), 'UTF-8') === $normalized) {
            $duplicates++;
        }
    }
    if ($duplicates >= DM_DUPLICATE_LIMIT) {
        return 'Aynı mesajı art arda gönderemezsin.';
    }
    if ($inBurst >= DM_BURST_LIMIT) {
        return 'Çok hızlı mesaj gönderiyorsun. Lütfen bir dakika bekle.';
    }
    if ($inShort >= DM_SHORT_LIMIT) {
        return 'Kısa sürede çok fazla mesaj gönderdin. Lütfen birkaç dakika bekle.';
    }
    if (count($recent) >= DM_DAY_LIMIT) {
        return 'Günlük mesaj sınırına ulaştın. Yarın tekrar deneyebilirsin.';
    }
    return null;
}

function dmRecentActivity($db, int $userId, int $now): array {
    $since = new MongoDB\BSON\UTCDateTime(($now - DM_HISTORY_WINDOW) * 1000);
    $rows = [];
    $cursor = $db->messages->find(
        ['sender_id' => $userId, 'created_at' => ['$gte' => $since]],
        ['projection' => ['text' => 1, 'created_at' => 1]]
    );
    foreach ($cursor as $doc) {
        $rows[] = ['at' => $doc['created_at']->toDateTime()->getTimestamp(), 'text' => (string) ($doc['text'] ?? '')];
    }
    return $rows;
}
