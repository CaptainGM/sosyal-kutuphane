<?php
require_once 'config.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Geçersiz istek'], 405);
}
$input = json_decode(file_get_contents('php://input'), true);
$username = trim($input['username'] ?? '');
$email = trim($input['email'] ?? '');
$password = trim($input['password'] ?? '');
if (empty($username) || empty($email) || empty($password)) {
    jsonResponse([
        'success' => false,
        'message' => 'Tüm alanlar gereklidir!'
    ], 400);
}

if (strlen($username) < 3 || strlen($username) > 30) {
    jsonResponse([
        'success' => false,
        'message' => 'Kullanıcı adı 3-30 karakter olmalıdır!'
    ], 400);
}

if (!preg_match('/^[\p{L}0-9_.]+$/u', $username)) {
    jsonResponse([
        'success' => false,
        'message' => 'Kullanıcı adı yalnızca harf, rakam, alt çizgi ve nokta içerebilir!'
    ], 400);
}

if (strlen($password) < 6) {
    jsonResponse([
        'success' => false,
        'message' => 'Şifre en az 6 karakter olmalıdır!'
    ], 400);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse([
        'success' => false,
        'message' => 'Geçerli bir e-posta adresi giriniz!'
    ], 400);
}

if ($db->users->findOne(['email' => $email])) {
    jsonResponse([
        'success' => false,
        'message' => 'Bu e-posta adresi zaten kullanımda!'
    ], 400);
}

if ($db->users->findOne(['username' => $username])) {
    jsonResponse([
        'success' => false,
        'message' => 'Bu kullanıcı adı zaten alınmış!'
    ], 400);
}

$newUserId = nextSequence($db, 'users');
$db->users->insertOne([
    '_id' => $newUserId,
    'username' => $username,
    'email' => $email,
    'password' => password_hash($password, PASSWORD_DEFAULT),
    'bio' => null,
    'avatar_url' => null,
    'created_at' => new MongoDB\BSON\UTCDateTime(),
    'updated_at' => new MongoDB\BSON\UTCDateTime(),
]);

$_SESSION['user_id'] = $newUserId;
$_SESSION['username'] = $username;
$_SESSION['email'] = $email;

jsonResponse([
    'success' => true,
    'message' => 'Kayıt başarılı!',
    'user' => [
        'id' => $newUserId,
        'username' => $username,
        'email' => $email
    ],
    'csrf_token' => csrfToken()
], 201);
?>
