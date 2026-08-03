<?php
// Kullanim: fix-user-password.php?email=kullanici@ornek.com
require_once 'config.php';

$email = $_GET['email'] ?? '';
if (!$email) {
    die("Kullanim: fix-user-password.php?email=kullanici@ornek.com");
}

$stmt = $conn->prepare("UPDATE users SET password = ? WHERE email = ?");
$newPass = "123456";
$stmt->bind_param("ss", $newPass, $email);
$stmt->execute();

echo $stmt->affected_rows . " kullanıcı güncellendi\n";
echo "Yeni şifre: 123456";
?>
