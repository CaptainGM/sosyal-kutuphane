<?php
// Yorum moderasyonu ve spam sınırı.
// Metin kuralları ve oran kararı saftır (veritabanı gerektirmez, tests/ModerationTest.php doğrudan çağırır).
// Veritabanından yalnızca kullanıcının son 24 saatteki yorumlarını almak için commentRecentActivity() kullanılır.
require_once __DIR__ . '/search.php';

const COMMENT_MAX_LENGTH = 1000;
const COMMENT_BURST_WINDOW = 60;     // saniye: kısa aralıkta art arda yorum
const COMMENT_BURST_LIMIT = 5;
const COMMENT_SHORT_WINDOW = 600;    // 10 dakika
const COMMENT_SHORT_LIMIT = 20;
const COMMENT_DAY_LIMIT = 100;
const COMMENT_DUPLICATE_WINDOW = 86400;

// Bağlantı sayılan alan adı uzantıları. "film.net" gibi bitişik yazımlar yakalanır, "Film. Net" gibi cümleler yakalanmaz.
const MODERATION_LINK_TLDS = 'com|net|org|tr|io|co|me|ly|gg|app|dev|xyz|info|biz|site|online|store|shop|link|click|top|ru|cc|tk|tv|live|club|vip|website|space|blog|news';

// Normalize biçimde (küçük harf, Türkçe karakter sadeleşmiş, art arda tekrarlar tek harf) tam sözcük eşleşmesi.
const MODERATION_EXACT_WORDS = [
    'amk', 'amq', 'aq', 'sik', 'sikim', 'ibne', 'bok', 'pust', 'gavat', 'yavsak', 'esek', 'gerzek', 'tasak',
    'shit', 'fuck', 'fck', 'cunt', 'slut', 'whore', 'bitch',
];

// Normalize biçimde önek eşleşmesi (çekimli biçimler: "orospunun", "salaklık" gibi). En az 5 harf.
const MODERATION_PREFIX_STEMS = [
    'orospu', 'amina', 'amcik', 'amciga', 'amcigi', 'siktir', 'sikerim', 'sikeyim', 'sikiyim', 'sikeceg', 'sikis', 'sikik',
    'pezevenk', 'kahpe', 'kaltak', 'gotveren', 'gotlek', 'gotun', 'serefsiz', 'ibnel', 'anani', 'yarak', 'yarag', 'dingil',
    'dallam', 'aptal', 'salak', 'ahmak', 'embesil', 'gerizekali', 'dangalak', 'mankafa',
    'budala', 'haysiyetsiz', 'namussuz', 'ahlaksiz', 'alcak', 'pislik', 'boktan', 'bokun', 'sicayim', 'sicarim',
    'kodugumun', 'kodumun', 'dallama',
    // İngilizce
    'fuck', 'motherf', 'bitch', 'asshole', 'bullshit', 'shitty', 'dickhead',
];

// Metin kuralları: önce IBAN, sonra bağlantı/iletişim, en sonda küfür/hakaret.
// Döner: ['reason' => iban|link|profanity, 'message' => kullanıcıya gösterilecek metin] ya da null.
function commentModerationVerdict(string $text): ?array {
    if (commentIbanFound($text)) {
        return ['reason' => 'iban', 'message' => 'IBAN veya banka hesap bilgisi paylaşılamaz.'];
    }
    if (commentLinkOrContactFound($text)) {
        return ['reason' => 'link', 'message' => 'Yorumlarda harici bağlantı veya iletişim bilgisi paylaşılamaz.'];
    }
    if (commentProfanityFound($text)) {
        return ['reason' => 'profanity', 'message' => 'Yorumunuz küfür veya hakaret içeriyor. Lütfen saygılı bir dil kullanın.'];
    }
    return null;
}

function commentModerationError(string $text): ?string {
    $verdict = commentModerationVerdict($text);
    return $verdict === null ? null : $verdict['message'];
}

function commentIbanFound(string $text): bool {
    if (preg_match('~\biban\b~iu', $text)) {
        return true;
    }
    // Türkiye IBAN'ı: TR + 2 kontrol hanesi + 22 rakam, 4'erli gruplar halinde de yazılabilir.
    if (preg_match('~\bTR\s?\d{2}(?:\s?\d{4}){5}\s?\d{2}\b~iu', $text)) {
        return true;
    }
    // Diğer ülkeler: boşluksuz, sözcük sınırlı biçim (cümle içindeki kısa alfanümerikler eşleşmez).
    return (bool) preg_match('~\b[A-Z]{2}\d{2}[A-Z0-9]{11,30}\b~', strtoupper($text));
}

function commentLinkOrContactFound(string $text): bool {
    $lower = mb_strtolower($text, 'UTF-8');
    if (preg_match('~\b(?:https?|ftp)\s*:|\bwww\.~iu', $lower)) {
        return true;
    }
    if (preg_match('~(?<![\p{L}\p{N}])[\p{L}\p{N}-]+(?:\.[\p{L}\p{N}-]+)*\.(?:' . MODERATION_LINK_TLDS . ')(?![\p{L}\p{N}])~iu', $lower)) {
        return true;
    }
    return (bool) preg_match('~[\p{L}\p{N}._%+\-]+@[\p{L}\p{N}\-]+\.\p{L}{2,}~iu', $lower);
}

// Küfür ve hakaret: leetspeak harfe çevrilir, Türkçe karakterler sadeleşir, harf harf yazımlar birleştirilir.
function commentProfanityFound(string $text): bool {
    // Jetonlarda art arda tekrarlar tek harfe indirildiği için listeler de aynı biçime getirilir ("namussuz" -> "namusuz").
    static $exactWords = null;
    static $stems = null;
    if ($exactWords === null) {
        $exactWords = array_map('moderationCollapseRepeats', MODERATION_EXACT_WORDS);
        $stems = array_map('moderationCollapseRepeats', MODERATION_PREFIX_STEMS);
    }
    foreach (moderationTokens($text) as $token) {
        if (in_array($token, $exactWords, true)) {
            return true;
        }
        foreach ($stems as $stem) {
            if (str_starts_with($token, $stem)) {
                return true;
            }
        }
    }
    return false;
}

// Sözcük listesi: "s1k", "@mk" gibi yazımlar harfe çevrilir; "s i k" gibi tek harfli parçalar birleşir.
function moderationTokens(string $text): array {
    $text = strtr(mb_strtolower($text, 'UTF-8'), [
        '0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a', '5' => 's', '7' => 't',
        '@' => 'a', '$' => 's',
    ]);
    $normalized = searchNormalize($text);
    if ($normalized === '') {
        return [];
    }

    $tokens = [];
    $run = '';
    foreach (explode(' ', $normalized) as $part) {
        if (mb_strlen($part) === 1) {
            $run .= $part;
            continue;
        }
        if ($run !== '') {
            $tokens[] = $run;
            $run = '';
        }
        $tokens[] = $part;
    }
    if ($run !== '') {
        $tokens[] = $run;
    }

    return array_map('moderationCollapseRepeats', $tokens);
}

// "siiiiik" -> "sik", "namussuz" -> "namusuz": art arda aynı harfler tek harfe iner.
function moderationCollapseRepeats(string $text): string {
    return (string) preg_replace('/(.)\1+/u', '$1', $text);
}

// $recent: kullanıcının son 24 saatteki yorumları [['at' => unix, 'text' => string, 'content' => 'tür:kimlik'], ...].
// Döndürdüğü metin doğrudan kullanıcıya gösterilir; null ise yoruma izin verilir.
function commentRateError(array $recent, string $text, string $contentKey, int $now): ?string {
    $normalized = mb_strtolower(trim($text), 'UTF-8');
    $inBurst = 0;
    $inShort = 0;
    foreach ($recent as $item) {
        $age = $now - (int) $item['at'];
        if ($age < COMMENT_BURST_WINDOW) {
            $inBurst++;
        }
        if ($age < COMMENT_SHORT_WINDOW) {
            $inShort++;
        }
        if ($item['content'] === $contentKey && mb_strtolower(trim((string) $item['text']), 'UTF-8') === $normalized) {
            return 'Bu içeriğe aynı yorumu bir gün içinde tekrar gönderemezsiniz.';
        }
    }
    if ($inBurst >= COMMENT_BURST_LIMIT) {
        return 'Çok hızlı yorum yapıyorsunuz. Lütfen bir dakika bekleyin.';
    }
    if ($inShort >= COMMENT_SHORT_LIMIT) {
        return 'Kısa sürede çok fazla yorum yaptınız. Lütfen birkaç dakika bekleyin.';
    }
    if (count($recent) >= COMMENT_DAY_LIMIT) {
        return 'Günlük yorum sınırına ulaştınız. Yarın tekrar deneyebilirsiniz.';
    }
    return null;
}

function commentRecentActivity($db, int $userId, int $now): array {
    $since = new MongoDB\BSON\UTCDateTime(($now - COMMENT_DUPLICATE_WINDOW) * 1000);
    $rows = [];
    $cursor = $db->comments->find(
        ['user_id' => $userId, 'created_at' => ['$gte' => $since]],
        ['projection' => ['comment_text' => 1, 'created_at' => 1, 'content_type' => 1, 'content_id' => 1]]
    );
    foreach ($cursor as $doc) {
        $rows[] = [
            'at' => $doc['created_at']->toDateTime()->getTimestamp(),
            'text' => (string) ($doc['comment_text'] ?? ''),
            'content' => ($doc['content_type'] ?? '') . ':' . ($doc['content_id'] ?? ''),
        ];
    }
    return $rows;
}
