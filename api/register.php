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

if (strlen($username) < 3) {
    jsonResponse([
        'success' => false,
        'message' => 'Kullanıcı adı en az 3 karakter olmalıdır!'
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
$stmt = $conn->prepare("SELECT id FROM users WHERE email = ? OR username = ?");
$stmt->bind_param("ss", $email, $username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $existingUser = $result->fetch_assoc();
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    
    if ($stmt->get_result()->num_rows > 0) {
        jsonResponse([
            'success' => false,
            'message' => 'Bu e-posta adresi zaten kullanımda!'
        ], 400);
    } else {
        jsonResponse([
            'success' => false,
            'message' => 'Bu kullanıcı adı zaten alınmış!'
        ], 400);
    }
}
$stmt = $conn->prepare("INSERT INTO users (username, email, password) VALUES (?, ?, ?)");
$stmt->bind_param("sss", $username, $email, $password);

if ($stmt->execute()) {
    $newUserId = $conn->insert_id;
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
        ]
    ], 201);
} else {
    jsonResponse([
        'success' => false,
        'message' => 'Kayıt sırasında bir hata oluştu!'
    ], 500);
}
?>
