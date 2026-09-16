<?php
require_once 'config.php';
requireLogin();
requireCsrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Geçersiz istek'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);
$currentPassword = $input['current_password'] ?? '';
$newPassword = $input['new_password'] ?? '';
$userId = (int) getCurrentUserId();

if (empty($currentPassword) || empty($newPassword)) {
    jsonResponse(['success' => false, 'message' => 'Mevcut ve yeni şifre gereklidir!'], 400);
}

if (strlen($newPassword) < 6) {
    jsonResponse(['success' => false, 'message' => 'Yeni şifre en az 6 karakter olmalıdır!'], 400);
}

$user = $db->users->findOne(['_id' => $userId]);
if (!$user || !password_verify($currentPassword, $user->password)) {
    jsonResponse(['success' => false, 'message' => 'Mevcut şifre yanlış!'], 401);
}

if (password_verify($newPassword, $user->password)) {
    jsonResponse(['success' => false, 'message' => 'Yeni şifre mevcut şifreyle aynı olamaz!'], 400);
}

$db->users->updateOne(
    ['_id' => $userId],
    ['$set' => ['password' => password_hash($newPassword, PASSWORD_DEFAULT), 'updated_at' => new MongoDB\BSON\UTCDateTime()]]
);

jsonResponse(['success' => true, 'message' => 'Şifreniz başarıyla güncellendi!']);
?>
