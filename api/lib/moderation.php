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

// Ham metinde aranan kalıplar (küçük harfe çevrilmiş, ama harfleri henüz sadeleşmemiş metinde): ayraçlı ya da rakamlı
// yazımlar ("s.i.k", "s1k", "o.ç") ve kendine zarar çağrısı. Sadeleştirmeden önce bakılır, çünkü "1" ve "ç" orada kaybolur.
// "sık sık" ya da "sıkıldım" gibi olağan kullanımlar buraya girmez: aralarında ayraç ya da rakam yoktur.
const MODERATION_RAW_PATTERNS = [
    '~(?<!\p{L})s[\p{P}\p{S}\s]{1,3}[ıi1!|][\p{P}\p{S}\s]{0,3}k(?!\p{L})~u',
    '~(?<!\p{L})s[1!|]k(?!\p{L})~u',
    '~(?<!\p{L})o[\p{P}\p{S}\s]{0,2}ç(?!\p{L})~u',
    '~(?<!\p{L})göt(?!\p{L})~u',
    '~(?<!\p{L})kendini[\s\p{P}]*[oö]ld[üu]r(?!\p{L})~u',
];

// Tam sözcük: yalnızca bu biçimde yazılmış sözcükleri yakalar, başka bir sözcüğün içinde geçerse yakalamaz.
// "sık" bilerek yoktur: "sık sık" çok yaygın bir Türkçe kullanımdır; "s.i.k" gibi açık yazımlar ham metin kuralında yakalanır.
const MODERATION_EXACT_WORDS = [
    // Türkçe kısaltmalar ve kısa küfürler
    'amk', 'amq', 'aq', 'sikim', 'ibne', 'bok', 'pust', 'gavat', 'yavsak', 'esek', 'gerzek', 'tasak', 'tassak', 'pic', 'cuk',
    'sktr', 'skt', 'sksn', 'siktr',
    // İngilizce
    'shit', 'fuck', 'fck', 'fuk', 'fuq', 'phuck', 'cunt', 'slut', 'whore', 'bitch', 'dick', 'cock', 'pussy', 'twat',
    'bollocks', 'prick', 'nigger', 'nigga', 'fag', 'ass', 'stfu', 'gtfo', 'wtf',
    // Almanca, İtalyanca, İspanyolca ve Rusça (Latin harfli) kısa sözcükler
    'hure', 'arsch', 'fick', 'schwanz', 'troia', 'porra', 'suka',
];

// Önek eşleşmesi: kökle başlayan çekimli biçimleri yakalar ("orospunun", "sikeceğim", "shitty").
// Kökler harfler arasında ayraç ya da maske karakteri taşısa da eşleşir ("o r o s p u", "s*ktir").
// Yaygın sözcükleri bozmamak için "sikke", "Dickens", "pussycat", "ananas", "Amina" gibi adlar ve sözcükler dışarıda tutuldu.
const MODERATION_PREFIX_STEMS = [
    // Türkçe: küfür ve cinsel hakaret
    'orospu', 'orosbu', 'orusbu', 'orspu', 'orospo', 'oruspu', 'amcik', 'amciga', 'amcigi', 'amcuk', 'aminakoy',
    'siktir', 'sktir', 'sikerim', 'sikeyim', 'sikiyim', 'sikecek', 'sikeceg', 'sikik', 'siktim', 'sokay', 'sicayim', 'sicarim',
    'pezevenk', 'kahpe', 'kaltak', 'surtuk', 'fahise', 'gotveren', 'gotlek', 'gotun', 'serefsiz', 'ibnel', 'anani', 'yarak',
    'yarag', 'dalyarak', 'dallam', 'dallama', 'kodugumun', 'kodumun', 'sakso', 'bokum', 'boktan', 'bokun', 'picin', 'picler',
    'pici', 'pice', 'mudak',
    // Türkçe: hakaret (bağlama göre yanlış alarm verebilir, ör. "Budala" adlı bir kitap)
    'aptal', 'salak', 'ahmak', 'embesil', 'gerizekali', 'dangalak', 'mankafa', 'budala', 'dingil', 'haysiyetsiz',
    'namussuz', 'ahlaksiz', 'alcak', 'pislik', 'asagilik',
    // İngilizce
    'fuck', 'fck', 'motherf', 'bitch', 'asshole', 'arsehole', 'bullshit', 'shitty', 'shithead', 'shitface', 'dickhead',
    'cocksucker', 'cunt', 'bastard', 'wanker', 'faggot', 'retard', 'whore', 'slut', 'dumbass', 'jackass', 'douche',
    'moron', 'imbecile',
    // Almanca
    'scheiss', 'arschloch', 'wichser', 'hurensohn', 'fotze', 'ficken', 'fickt', 'dummkopf',
    // Fransızca
    'putain', 'merde', 'connard', 'connass', 'encul', 'salope', 'salaud', 'batard', 'filsdepute',
    // İspanyolca
    'puta', 'puto', 'mierda', 'cabron', 'joder', 'pendejo', 'maricon', 'hijueputa', 'gilipoll', 'chingad', 'culero',
    // İtalyanca
    'cazz', 'stronz', 'puttan', 'vaffan', 'coglion', 'troiac',
    // Portekizce
    'caralh', 'buceta', 'fodase', 'fodasse', 'fodid', 'filhodaputa',
    // Rusça (Latin harflerine çevrilmiş; Kiril yazımı da bu biçime iner)
    'blya', 'pizd', 'pidor', 'ebal', 'huyn',
    // Lehçe, Felemenkçe ve Arapça (Latin harfli)
    'kurw', 'chuj', 'pierdol', 'klootzak', 'sharmout', 'sharmut', 'kahba',
];

// Harf sadeleştirme tablosu: Türkçe ve Latin aksanlı harfler, Kiril ve Yunan harflerinin Latin karşılığı (ya da benzeri),
// rakam ve sembol yazımları. "şiktir", "ѕiktir", "s1ktir" gibi yazımlar aynı köke iner.
const MODERATION_LETTER_MAP = [
    'ı' => 'i', 'ş' => 's', 'ğ' => 'g', 'ç' => 'c', 'ö' => 'o', 'ü' => 'u',
    'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'æ' => 'ae',
    'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
    'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ø' => 'o', 'ù' => 'u', 'ú' => 'u', 'û' => 'u',
    'ý' => 'y', 'ÿ' => 'y', 'ß' => 'ss', 'œ' => 'oe', 'ł' => 'l', 'ś' => 's', 'š' => 's', 'ż' => 'z', 'ź' => 'z', 'ž' => 'z',
    'ć' => 'c', 'č' => 'c', 'ń' => 'n', 'ň' => 'n', 'ą' => 'a', 'ę' => 'e', 'ě' => 'e', 'ő' => 'o', 'ű' => 'u', 'ř' => 'r',
    'ů' => 'u', 'ď' => 'd', 'ť' => 't', 'ľ' => 'l', 'ĺ' => 'l', 'ŕ' => 'r',
    'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'ґ' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'yo', 'є' => 'e', 'ж' => 'zh',
    'з' => 'z', 'и' => 'i', 'і' => 'i', 'ї' => 'i', 'й' => 'y', 'ј' => 'j', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n',
    'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ү' => 'u', 'ф' => 'f', 'х' => 'h', 'һ' => 'h',
    'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch', 'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
    'ѕ' => 's',
    'α' => 'a', 'β' => 'b', 'γ' => 'g', 'δ' => 'd', 'ε' => 'e', 'ζ' => 'z', 'η' => 'i', 'ι' => 'i', 'κ' => 'k', 'λ' => 'l',
    'μ' => 'm', 'ν' => 'v', 'ξ' => 'x', 'ο' => 'o', 'π' => 'p', 'ρ' => 'p', 'σ' => 's', 'ς' => 's', 'τ' => 't', 'υ' => 'u',
    'φ' => 'f', 'χ' => 'x', 'ψ' => 'ps', 'ω' => 'o',
    '0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a', '5' => 's', '7' => 't', '@' => 'a', '$' => 's', '€' => 'e', '¡' => 'i', '¥' => 'y',
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

// Küfür ve hakaret. Ham metin önce ayraçlı/rakamlı kalıplarla, sonra sadeleştirilmiş biçimiyle köklerle aranır.
function commentProfanityFound(string $text): bool {
    static $patterns = null;
    $patterns ??= moderationProfanityPatterns();
    $plain = moderationStripInvisible(mb_strtolower($text, 'UTF-8'));
    foreach (MODERATION_RAW_PATTERNS as $pattern) {
        if (preg_match($pattern, $plain) === 1) {
            return true;
        }
    }
    $normalized = moderationNormalize($plain);
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $normalized) === 1) {
            return true;
        }
    }
    return false;
}

// Her sözcük ve kök için bir kalıp. Harf tekrarları serbest ("siiiktir"), bir harfin yerine maske karakteri
// geçebilir ("s*ktir"), harfler arasında birkaç ayraç olabilir ("s.i.k.t.i.r", "s i k t i r").
// Kısa kökte ayraç en fazla bir karakterdir; böylece "a.q" yakalanır ama uzak sözcükler birleşmez.
function moderationProfanityPatterns(): array {
    $patterns = [];
    foreach (MODERATION_EXACT_WORDS as $word) {
        $patterns[] = moderationStemPattern($word, true);
    }
    foreach (MODERATION_PREFIX_STEMS as $stem) {
        $patterns[] = moderationStemPattern($stem, false);
    }
    return $patterns;
}

function moderationStemPattern(string $stem, bool $exact): string {
    $gap = strlen($stem) <= 3 ? '[^\p{L}]?' : '[^\p{L}]{0,3}';
    $letters = [];
    foreach (preg_split('//u', $stem, -1, PREG_SPLIT_NO_EMPTY) as $letter) {
        $letters[] = '(?:' . preg_quote($letter, '~') . '+|[*#])';
    }
    return '~(?<!\p{L})' . implode($gap, $letters) . ($exact ? '(?!\p{L})' : '') . '~u';
}

// Birleşik işaretler (İ'nin noktası dahil) atılır, görünmez karakterler boşluk sayılır (araya konan sıfır genişlikli
// karakter bir ayraçtır), tam genişlikli harfler ASCII'ye iner ("ｓｉｋ").
function moderationStripInvisible(string $text): string {
    $text = (string) preg_replace('~[\x{00AD}\x{200B}-\x{200D}\x{2060}\x{FEFF}]~u', ' ', $text);
    $text = (string) preg_replace('~\p{Mn}~u', '', $text);
    return (string) preg_replace_callback(
        '~[\x{FF01}-\x{FF5E}]~u',
        static fn (array $m): string => mb_chr(mb_ord($m[0], 'UTF-8') - 0xFEE0, 'UTF-8'),
        $text
    );
}

// Harf benzeri yazımları harfe çevirir. "!" ve "|" yalnızca iki harf arasındaysa harf sayılır ("s!k");
// "amk!" gibi cümle sonları sözcüğü bozmamalı.
function moderationNormalize(string $plain): string {
    $text = (string) preg_replace('~(?<=\p{L})[!|](?=\p{L})~u', 'i', $plain);
    return strtr($text, MODERATION_LETTER_MAP);
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
