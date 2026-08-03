<?php
require_once 'config.php';

echo "<h2>⚙️ Veritabanı Düzeltme İşlemi</h2>";
$sql = "ALTER TABLE users CHANGE COLUMN password_hash password VARCHAR(255) NOT NULL";
if ($conn->query($sql) === TRUE) {
    echo "✅ users tablosu başarıyla güncellendi (password_hash → password)<br>";
} else {
    echo "⚠️ users tablosu zaten güncellenmiş olabilir: " . $conn->error . "<br>";
}

echo "<h2>✅ Veritabanı Hazır!</h2>";
echo "<p>Şimdi <a href='../index.html'>giriş sayfasına</a> dönebilirsin.</p>";

$conn->close();
?>

