<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Geçersiz istek'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);
$token = trim($input['token'] ?? '');
$password = $input['password'] ?? '';

if (empty($token)) {
    jsonResponse(['success' => false, 'message' => 'Token gereklidir!'], 400);
}

if (empty($password) || strlen($password) < 6) {
    jsonResponse(['success' => false, 'message' => 'Şifre en az 6 karakter olmalıdır!'], 400);
}

$reset = $db->password_resets->findOne(['token' => $token]);

if (!$reset) {
    jsonResponse(['success' => false, 'message' => 'Geçersiz veya kullanılmış token!'], 400);
}

if ($reset->expires_at->toDateTime()->getTimestamp() < time()) {
    $db->password_resets->deleteOne(['token' => $token]);
    jsonResponse(['success' => false, 'message' => 'Token süresi dolmuş! Lütfen yeni bir sıfırlama talebi oluşturun.'], 400);
}

$email = $reset->email;

$db->users->updateOne(
    ['email' => $email],
    ['$set' => ['password' => password_hash($password, PASSWORD_DEFAULT), 'updated_at' => new MongoDB\BSON\UTCDateTime()]]
);
$db->password_resets->deleteMany(['email' => $email]);

jsonResponse(['success' => true, 'message' => 'Şifreniz başarıyla güncellendi!']);
?>
