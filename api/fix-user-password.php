<?php
require_once 'config.php';

$stmt = $conn->prepare("UPDATE users SET password = ? WHERE email = ?");
$newPass = "123456";
$email = "sahinberatbatuhan41@gmail.com";
$stmt->bind_param("ss", $newPass, $email);
$stmt->execute();

echo $stmt->affected_rows . " kullanıcı güncellendi\n";
echo "Yeni şifre: 123456";
?>
