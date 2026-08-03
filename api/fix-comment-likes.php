<?php
require_once 'config.php';

echo "<h2>comment_likes tablosu düzeltiliyor...</h2>";
$conn->query("DROP TABLE IF EXISTS comment_likes");
echo "✅ Eski tablo silindi<br>";
$sql = "CREATE TABLE comment_likes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    comment_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (comment_id) REFERENCES comments(id) ON DELETE CASCADE,
    UNIQUE KEY unique_like (user_id, comment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($sql)) {
    echo "✅ Yeni tablo başarıyla oluşturuldu!<br>";
    echo "<p style='color: green; font-weight: bold;'>🎉 Tamamlandı! Artık beğeni sistemi çalışacak.</p>";
} else {
    echo "❌ Hata: " . $conn->error;
}
?>

