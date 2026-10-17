<?php
// Engellenen denemelerin kaydı. Yönetici kimin, neyi, ne zaman denediğini görür ve kararını
// (askıya alma, susturma) mevcut yönetici işlemleriyle verir.
// Yorum, yanıt ve biyografi denemelerinde metin saklanır (yorumlar zaten yöneticiye açıktır).
// Özel mesajlarda (DM) metin ASLA saklanmaz; yalnızca olay kaydı tutulur (gizlilik kuralı).

const MODERATION_WINDOW_DAYS = 30;

// Engellenen bir denemeyi kaydeder. $kind: comment | reply | bio | dm. $reason: profanity | iban | link | llm.
function moderationRecord($db, int $userId, string $kind, string $reason, ?string $text): void {
    $stored = ($kind === 'dm' || $text === null) ? null : mb_substr($text, 0, 1000, 'UTF-8');
    $db->moderation_events->insertOne([
        '_id' => nextSequence($db, 'moderation_events'),
        'user_id' => $userId,
        'kind' => $kind,
        'reason' => $reason,
        'text' => $stored,
        'created_at' => new MongoDB\BSON\UTCDateTime(),
    ]);
}

// Son 30 gündeki engellenen deneme sayısı (bu deneme dahil, kayıttan sonra çağrılmalı).
function moderationStrikeCount($db, int $userId): int {
    $since = new MongoDB\BSON\UTCDateTime((time() - MODERATION_WINDOW_DAYS * 86400) * 1000);
    return (int) $db->moderation_events->countDocuments(['user_id' => $userId, 'created_at' => ['$gte' => $since]]);
}

// Kullanıcıya gösterilecek metin: ilk ihlalde uyarı, tekrarlarda açık uyarı.
function moderationWarning(string $message, int $strikes): string {
    if ($strikes <= 1) {
        return $message . ' Bu bir uyarı. Tekrarlarsan hesabın yönetici incelemesine alınabilir.';
    }
    if ($strikes === 2) {
        return $message . ' Bu ikinci denemen engellendi. Bir daha tekrarlarsan hesabın kısıtlanabilir.';
    }
    return $message . " Son {$strikes} denemen engellendi. Hesabın yönetici incelemesine alınabilir.";
}

// Engellenen metni kaydeder, uyarıyla birlikte 422 yanıtı döner ve işlemi durdurur.
function rejectModeratedText($db, int $userId, string $kind, array $verdict, string $text): void {
    moderationRecord($db, $userId, $kind, $verdict['reason'], $text);
    $strikes = moderationStrikeCount($db, $userId);
    jsonResponse([
        'success' => false,
        'message' => moderationWarning($verdict['message'], $strikes),
        'strikes' => $strikes,
    ], 422);
}
