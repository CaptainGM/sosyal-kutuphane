<?php
// Profil alanlarının (biyografi, avatar) doğrulama kuralları.
// Kurallar saftır (veritabanı gerektirmez), tests/ProfileTest.php doğrudan çağırır.
require_once __DIR__ . '/moderation.php';

const PROFILE_BIO_MAX_LENGTH = 280;
const PROFILE_AVATAR_MAX_EMOJI = 12; // bir "kişi" emojisi ZWJ ile 7-8 kod noktasına çıkabilir

// Biyografi: uzunluk sınırı, ardından yorumlarla aynı IBAN / bağlantı / küfür kuralları. Boş biyografi geçerlidir (silme).
function profileBioError(string $bio): ?string {
    if (mb_strlen($bio, 'UTF-8') > PROFILE_BIO_MAX_LENGTH) {
        return 'Biyografi en fazla ' . PROFILE_BIO_MAX_LENGTH . ' karakter olabilir.';
    }
    if (commentIbanFound($bio)) {
        return 'IBAN veya banka hesap bilgisi paylaşılamaz.';
    }
    if (commentLinkOrContactFound($bio)) {
        return 'Biyografide harici bağlantı veya iletişim bilgisi paylaşılamaz.';
    }
    if (commentProfanityFound($bio)) {
        return 'Biyografin küfür veya hakaret içeriyor. Lütfen saygılı bir dil kullan.';
    }
    return null;
}

// Avatar değeri: 'url' (http/https adresi), 'emoji' (yalnızca emoji karakterleri) ya da null (geçersiz).
// javascript:, data: ve HTML içeren değerler hiçbir şekilde kabul edilmez.
function profileAvatarKind(string $avatar): ?string {
    if ($avatar === '' || strlen($avatar) > 512) {
        return null;
    }
    if (str_starts_with($avatar, 'http://') || str_starts_with($avatar, 'https://')) {
        return filter_var($avatar, FILTER_VALIDATE_URL) ? 'url' : null;
    }
    $emoji = '[\x{1F000}-\x{1FAFF}\x{2190}-\x{21FF}\x{2300}-\x{23FF}\x{2600}-\x{27BF}\x{2900}-\x{297F}\x{2B00}-\x{2BFF}\x{200D}\x{FE0F}\x{20E3}\x{00A9}\x{00AE}]';
    if (preg_match('~^' . $emoji . '{1,' . PROFILE_AVATAR_MAX_EMOJI . '}$~u', $avatar)) {
        return 'emoji';
    }
    return null;
}

// Sunucuda saklanan (yüklenmiş) avatar dosyasının yolu. Başka bir yola işaret eden değer asla silinmez.
function profileUploadedAvatarPath(?string $avatarUrl): ?string {
    if ($avatarUrl === null || !preg_match('~^/uploads/avatars/[a-f0-9]{32}\.(?:jpg|png|webp|gif)$~', $avatarUrl)) {
        return null;
    }
    return dirname(__DIR__, 2) . $avatarUrl;
}
