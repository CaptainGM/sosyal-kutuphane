<?php
require_once 'config.php';
require_once 'totp.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Geçersiz istek'], 405);
}

$pendingUserId = $_SESSION['pending_2fa_user_id'] ?? null;
if (!$pendingUserId) {
    jsonResponse(['success' => false, 'message' => 'Bekleyen bir giriş yok, lütfen tekrar giriş yapın'], 400);
}

$input = json_decode(file_get_contents('php://input'), true);
$code = trim($input['code'] ?? '');
$rateLimitKey = '2fa_' . $pendingUserId;

checkLoginRateLimit($db, $rateLimitKey);

$user = $db->users->findOne(['_id' => $pendingUserId]);
if (!$user || !($user->totp_enabled ?? false)) {
    unset($_SESSION['pending_2fa_user_id']);
    jsonResponse(['success' => false, 'message' => 'Geçersiz istek, lütfen tekrar giriş yapın'], 400);
}

$verified = verifyTotp($user->totp_secret, $code);

if (!$verified) {
    // Authenticator'a erişemiyorsa tek kullanımlık yedek kodlardan biriyle giriş dene.
    // (array) cast şart: Mongo sürücüsü bu alanı BSONArray nesnesi olarak döner,
    // array_values() gibi çekirdek dizi fonksiyonları düz array bekler.
    $backupCodes = (array) ($user->totp_backup_codes ?? []);
    foreach ($backupCodes as $index => $hashedCode) {
        if (password_verify($code, $hashedCode)) {
            $verified = true;
            unset($backupCodes[$index]);
            $db->users->updateOne(
                ['_id' => $pendingUserId],
                ['$set' => ['totp_backup_codes' => array_values($backupCodes)]]
            );
            break;
        }
    }
}

if (!$verified) {
    recordLoginAttempt($db, $rateLimitKey);
    jsonResponse(['success' => false, 'message' => 'Kod hatalı'], 401);
}

clearLoginAttempts($db, $rateLimitKey);
unset($_SESSION['pending_2fa_user_id']);

$_SESSION['user_id'] = $user->_id;
$_SESSION['username'] = $user->username;
$_SESSION['email'] = $user->email;

jsonResponse([
    'success' => true,
    'message' => 'Giriş başarılı!',
    'user' => [
        'id' => $user->_id,
        'username' => $user->username,
        'email' => $user->email,
    ],
    'csrf_token' => csrfToken(),
]);
?>
