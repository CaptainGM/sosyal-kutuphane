<?php
require_once 'config.php';

echo "<h2>Custom Lists Tabloları Düzeltiliyor...</h2>";
$conn->query("SET FOREIGN_KEY_CHECKS = 0");
$conn->query("DROP TABLE IF EXISTS list_items");
$conn->query("DROP TABLE IF EXISTS custom_list_items");
$conn->query("DROP TABLE IF EXISTS custom_lists");
echo "✅ Eski tablolar silindi<br>";
$conn->query("SET FOREIGN_KEY_CHECKS = 1");
$sql1 = "CREATE TABLE custom_lists (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($sql1)) {
    echo "✅ custom_lists tablosu oluşturuldu<br>";
} else {
    echo "❌ custom_lists hatası: " . $conn->error . "<br>";
}
$sql2 = "CREATE TABLE custom_list_items (
    id INT PRIMARY KEY AUTO_INCREMENT,
    list_id INT NOT NULL,
    content_type ENUM('movie', 'book') NOT NULL,
    content_id VARCHAR(50) NOT NULL,
    content_title VARCHAR(255),
    added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (list_id) REFERENCES custom_lists(id) ON DELETE CASCADE,
    UNIQUE KEY unique_item (list_id, content_type, content_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($sql2)) {
    echo "✅ custom_list_items tablosu oluşturuldu<br>";
} else {
    echo "❌ custom_list_items hatası: " . $conn->error . "<br>";
}

echo "<p style='color: green; font-weight: bold;'>🎉 Tamamlandı! Şimdi profile sayfasına dön.</p>";
?>

