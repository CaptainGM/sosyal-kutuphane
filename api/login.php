<?php
require_once 'config.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Geçersiz istek'], 405);
}
$input = json_decode(file_get_contents('php://input'), true);
$email = trim($input['email'] ?? '');
$password = trim($input['password'] ?? '');
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

$_SESSION['user_id'] = $user->_id;
$_SESSION['username'] = $user->username;
$_SESSION['email'] = $user->email;

jsonResponse([
    'success' => true,
    'message' => 'Giriş başarılı!',
    'user' => [
        'id' => $user->_id,
        'username' => $user->username,
        'email' => $user->email
    ],
    'csrf_token' => csrfToken()
], 200);
?>
