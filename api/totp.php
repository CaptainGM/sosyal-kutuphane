<?php
// RFC 6238 (TOTP) + RFC 4648 (Base32) — saf PHP, harici bağımlılık yok.
// Google Authenticator / Microsoft Authenticator / Authy ile uyumludur.

const TOTP_BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

function totpBase32Encode(string $binary): string
{
    $bits = '';
    foreach (str_split($binary) as $byte) {
        $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
    }
    $output = '';
    foreach (str_split($bits, 5) as $chunk) {
        $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
        $output .= TOTP_BASE32_ALPHABET[bindec($chunk)];
    }
    return $output;
}

function totpBase32Decode(string $base32): string
{
    $base32 = strtoupper(preg_replace('/[^A-Z2-7]/', '', $base32));
    $bits = '';
    foreach (str_split($base32) as $char) {
        $pos = strpos(TOTP_BASE32_ALPHABET, $char);
        if ($pos === false) {
            continue;
        }
        $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }
    $binary = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) < 8) {
            break;
        }
        $binary .= chr(bindec($byte));
    }
    return $binary;
}

// 160-bit (20 byte) rastgele secret — TOTP için önerilen standart uzunluk.
function generateBase32Secret(): string
{
    return totpBase32Encode(random_bytes(20));
}

function totpCodeAt(string $base32Secret, int $unixTime, int $timeStep = 30, int $digits = 6): string
{
    $counter = intdiv($unixTime, $timeStep);
    $counterBinary = pack('N*', 0, $counter); // 8 byte big-endian sayaç (RFC 6238)
    $secretBinary = totpBase32Decode($base32Secret);
    $hash = hash_hmac('sha1', $counterBinary, $secretBinary, true);

    $offset = ord($hash[19]) & 0x0f;
    $truncated = ((ord($hash[$offset]) & 0x7f) << 24)
        | ((ord($hash[$offset + 1]) & 0xff) << 16)
        | ((ord($hash[$offset + 2]) & 0xff) << 8)
        | (ord($hash[$offset + 3]) & 0xff);

    return str_pad((string) ($truncated % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
}

// $window: kabul edilen zaman kayması (1 = önceki/şu anki/sonraki 30sn'lik dilim — saat senkron sorunlarına tolerans).
function verifyTotp(string $base32Secret, string $code, int $window = 1, ?int $now = null): bool
{
    $now ??= time();
    $code = trim($code);
    if ($code === '') {
        return false;
    }
    for ($i = -$window; $i <= $window; $i++) {
        $expected = totpCodeAt($base32Secret, $now + ($i * 30));
        if (hash_equals($expected, $code)) {
            return true;
        }
    }
    return false;
}

// Elle giriş için 4'erli gruplar halinde okunabilir format (ör. "ABCD EFGH ...").
function formatSecretForManualEntry(string $base32Secret): string
{
    return trim(chunk_split($base32Secret, 4, ' '));
}

function generateBackupCodes(int $count = 8): array
{
    $codes = [];
    for ($i = 0; $i < $count; $i++) {
        $codes[] = strtoupper(bin2hex(random_bytes(2))) . '-' . strtoupper(bin2hex(random_bytes(2)));
    }
    return $codes;
}
?>
