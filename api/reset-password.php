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

$stmt = $conn->prepare("SELECT email, expires_at FROM password_resets WHERE token = ?");
$stmt->bind_param("s", $token);
$stmt->execute();
$result = $stmt->get_result();
$reset = $result->fetch_assoc();

if (!$reset) {
    jsonResponse(['success' => false, 'message' => 'Geçersiz veya kullanılmış token!'], 400);
}

if (strtotime($reset['expires_at']) < time()) {
    $stmt = $conn->prepare("DELETE FROM password_resets WHERE token = ?");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    jsonResponse(['success' => false, 'message' => 'Token süresi dolmuş! Lütfen yeni bir sıfırlama talebi oluşturun.'], 400);
}

$email = $reset['email'];

$stmt = $conn->prepare("UPDATE users SET password = ? WHERE email = ?");
$stmt->bind_param("ss", $password, $email);

if ($stmt->execute()) {
    $stmt = $conn->prepare("DELETE FROM password_resets WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    
    jsonResponse(['success' => true, 'message' => 'Şifreniz başarıyla güncellendi!']);
} else {
    jsonResponse(['success' => false, 'message' => 'Şifre güncellenirken hata oluştu!'], 500);
}
?>
