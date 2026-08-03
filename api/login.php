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
$stmt = $conn->prepare("SELECT id, username, email, password FROM users WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    jsonResponse([
        'success' => false,
        'message' => 'E-posta veya şifre hatalı!'
    ], 401);
}

$user = $result->fetch_assoc();
if ($password !== $user['password']) {
    jsonResponse([
        'success' => false,
        'message' => 'E-posta veya şifre hatalı!'
    ], 401);
}
$_SESSION['user_id'] = $user['id'];
$_SESSION['username'] = $user['username'];
$_SESSION['email'] = $user['email'];

jsonResponse([
    'success' => true,
    'message' => 'Giriş başarılı!',
    'user' => [
        'id' => $user['id'],
        'username' => $user['username'],
        'email' => $user['email']
    ]
], 200);
?>
