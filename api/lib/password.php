<?php
// Şifre politikası: en az 8 karakter, en az bir büyük harf, bir küçük harf ve bir özel karakter.
// Türkçe harfler (Ç Ğ İ Ö Ş Ü ve küçükleri) harf sayılır. Yan etkisi yok; birim testlidir.
// İstemci tarafındaki kurallar (js/password-rules.js) aynı desenleri kullanır.

const PASSWORD_MIN_LENGTH = 8;
const PASSWORD_MAX_LENGTH = 128;

// Politikaya uymayan şifre için Türkçe hata mesajı döner; uyuyorsa null.
function passwordPolicyError(string $password): ?string {
    $length = mb_strlen($password, 'UTF-8');
    if ($length < PASSWORD_MIN_LENGTH) {
        return 'Şifre en az 8 karakter olmalıdır!';
    }
    if ($length > PASSWORD_MAX_LENGTH) {
        return 'Şifre en fazla 128 karakter olabilir!';
    }
    if (preg_match('/\p{Lu}/u', $password) !== 1) {
        return 'Şifre en az bir büyük harf içermelidir!';
    }
    if (preg_match('/\p{Ll}/u', $password) !== 1) {
        return 'Şifre en az bir küçük harf içermelidir!';
    }
    // Özel karakter: harf, rakam ve boşluk dışındaki her karakter (örn. ! @ # ? _ -).
    if (preg_match('/[^\p{L}\p{N}\s]/u', $password) !== 1) {
        return 'Şifre en az bir özel karakter içermelidir (örn. ! @ # ?)!';
    }
    return null;
}
