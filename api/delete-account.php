<?php
// Kullanıcı kendi hesabını ve tüm kişisel verisini kalıcı olarak siler (geri alınamaz). Neler silindiği: lib/account.php ve gizlilik sayfası.
//   POST { password, code? (iki adımlı doğrulama açıksa), confirm: true }
// Güvenlik: oturum + CSRF, şifre yeniden girilir, iki adımlı doğrulama açıksa kod da istenir, yanlış denemeler saatte 5 ile sınırlı,
// demo hesaplar ve yönetici hesapları silinemez (yönetici önce ADMIN_EMAILS listesinden çıkarılmalı; yönetici sayısı sıfıra düşmesin).
require_once 'config.php';
require_once __DIR__ . '/lib/demo.php';
require_once __DIR__ . '/lib/account.php';
require_once __DIR__ . '/lib/rate-limit.php';
require_once __DIR__ . '/totp.php';
requireLogin();
requireCsrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Geçersiz istek'], 405);
}
requireNotDemo();

$userId = (int) getCurrentUserId();
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input) || ($input['confirm'] ?? false) !== true) {
    jsonResponse(['success' => false, 'message' => 'Silmeyi onaylaman gerekiyor.'], 400);
}

$user = $db->users->findOne(['_id' => $userId]);
if (!$user) {
    jsonResponse(['success' => false, 'message' => 'Hesap bulunamadı'], 404);
}
if (!empty($user['is_admin'])) {
    jsonResponse(['success' => false, 'message' => 'Yönetici hesabı buradan silinemez. Önce yöneticilikten çıkarılması gerekir.'], 403);
}
if (rateLimitHit($db, "delete-account:$userId", 5, 3600)) {
    jsonResponse(['success' => false, 'message' => 'Çok fazla deneme yaptın. Bir saat sonra tekrar dene.'], 429);
}

$password = is_string($input['password'] ?? null) ? $input['password'] : '';
if (!password_verify($password, (string) ($user['password'] ?? ''))) {
    jsonResponse(['success' => false, 'message' => 'Şifre yanlış'], 401);
}

if (!empty($user['totp_enabled'])) {
    $code = is_string($input['code'] ?? null) ? trim($input['code']) : '';
    $verified = $code !== '' && verifyTotp((string) $user['totp_secret'], $code);
    if (!$verified && $code !== '') {
        // Authenticator'a erişemiyorsa yedek kodlardan biri de kabul edilir (hesap silineceği için tüketilmesi gerekmez).
        foreach ((array) ($user['totp_backup_codes'] ?? []) as $hashed) {
            if (password_verify($code, $hashed)) {
                $verified = true;
                break;
            }
        }
    }
    if (!$verified) {
        jsonResponse(['success' => false, 'message' => 'İki adımlı doğrulama kodu hatalı'], 401);
    }
}

deleteAccountData($db, $userId);

// Oturum kapatılır; bu istekten sonra kullanıcı kimliği geçersizdir.
$_SESSION = [];
session_destroy();

jsonResponse(['success' => true, 'message' => 'Hesabın ve verilerin silindi.']);
