<?php
require_once 'config.php';
require_once __DIR__ . '/lib/demo.php';
require_once __DIR__ . '/lib/audit.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Geçersiz istek'], 405);
}
$input = json_decode(file_get_contents('php://input'), true);
$email = (is_string($input['email'] ?? null) ? trim($input['email']) : '');
$password = (is_string($input['password'] ?? null) ? trim($input['password']) : '');
if (empty($email) || empty($password)) {
    jsonResponse([
        'success' => false,
        'message' => 'E-posta ve şifre gereklidir!'
    ], 400);
}

checkLoginRateLimit($db, $email);

$user = $db->users->findOne(['email' => $email]);

if (!$user || !password_verify($password, $user->password)) {
    recordLoginAttempt($db, $email);
    jsonResponse([
        'success' => false,
        'message' => 'E-posta veya şifre hatalı!'
    ], 401);
}

clearLoginAttempts($db, $email);

if (($user->status ?? 'active') === 'suspended') {
    jsonResponse(['success' => false, 'message' => 'Hesabınız askıya alındı. Destek için yönetici ile iletişime geçin.', 'suspended' => true], 403);
}

if ($user->totp_enabled ?? false) {
    $_SESSION['pending_2fa_user_id'] = $user->_id;
    jsonResponse([
        'success' => true,
        'requires_2fa' => true,
        'message' => 'Şifre doğru. Devam etmek için doğrulama kodunu girin.'
    ], 200);
}

if (!empty($user->is_demo)) {
    resetDemoAccount($db, (int) $user->_id);
}
$_SESSION['user_id'] = $user->_id;
$_SESSION['username'] = $user->username;
$_SESSION['email'] = $user->email;
recordLogin($db, $user->_id);

jsonResponse([
    'success' => true,
    'user' => [
        'id' => $user->_id,
        'username' => $user->username,
        'email' => $user->email
    ],
    'message' => 'Giriş başarılı!',
    'csrf_token' => csrfToken()
], 200);
?>
