<?php
require_once 'config.php';
require_once 'totp.php';
requireLogin();
requireCsrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Geçersiz istek'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? null;
$userId = (int) getCurrentUserId();

$user = $db->users->findOne(['_id' => $userId]);
if (!$user) {
    jsonResponse(['success' => false, 'message' => 'Kullanıcı bulunamadı'], 404);
}

if ($action === 'start') {
    if ($user->totp_enabled ?? false) {
        jsonResponse(['success' => false, 'message' => 'İki adımlı doğrulama zaten etkin'], 400);
    }

    $secret = generateBase32Secret();
    $db->users->updateOne(['_id' => $userId], ['$set' => ['totp_pending_secret' => $secret]]);

    jsonResponse([
        'success' => true,
        'secret' => formatSecretForManualEntry($secret),
        'account_label' => $user->email,
        'issuer' => 'Sosyal Kütüphane',
    ]);

} elseif ($action === 'confirm') {
    $code = trim($input['code'] ?? '');
    $pendingSecret = $user->totp_pending_secret ?? null;

    if (!$pendingSecret) {
        jsonResponse(['success' => false, 'message' => 'Önce kurulumu başlatmalısınız'], 400);
    }
    if (!verifyTotp($pendingSecret, $code)) {
        jsonResponse(['success' => false, 'message' => 'Kod hatalı, tekrar deneyin'], 400);
    }

    $backupCodes = generateBackupCodes(8);
    $hashedCodes = array_map(fn($c) => password_hash($c, PASSWORD_DEFAULT), $backupCodes);

    $db->users->updateOne(
        ['_id' => $userId],
        [
            '$set' => [
                'totp_secret' => $pendingSecret,
                'totp_enabled' => true,
                'totp_backup_codes' => $hashedCodes,
            ],
            '$unset' => ['totp_pending_secret' => ''],
        ]
    );

    jsonResponse([
        'success' => true,
        'message' => 'İki adımlı doğrulama etkinleştirildi!',
        'backup_codes' => $backupCodes,
    ]);

} elseif ($action === 'disable') {
    $password = $input['current_password'] ?? '';

    if (!password_verify($password, $user->password)) {
        jsonResponse(['success' => false, 'message' => 'Şifre yanlış'], 401);
    }

    $db->users->updateOne(
        ['_id' => $userId],
        [
            '$set' => ['totp_enabled' => false],
            '$unset' => ['totp_secret' => '', 'totp_pending_secret' => '', 'totp_backup_codes' => ''],
        ]
    );

    jsonResponse(['success' => true, 'message' => 'İki adımlı doğrulama devre dışı bırakıldı']);

} else {
    jsonResponse(['success' => false, 'message' => 'Geçersiz işlem'], 400);
}
?>
