<?php

require_once 'config.php';

$sql_queries = [
    "CREATE TABLE IF NOT EXISTS users (
        id INT PRIMARY KEY AUTO_INCREMENT,
        username VARCHAR(100) UNIQUE NOT NULL,
        email VARCHAR(100) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        bio TEXT,
        avatar_url VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS movies (
        id INT PRIMARY KEY AUTO_INCREMENT,
        tmdb_id INT UNIQUE NOT NULL,
        title VARCHAR(255) NOT NULL,
        poster_path VARCHAR(255),
        overview TEXT,
        release_date DATE,
        genres JSON,
        rating DECIMAL(3,1),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS books (
        id INT PRIMARY KEY AUTO_INCREMENT,
        google_books_id VARCHAR(255) UNIQUE NOT NULL,
        title VARCHAR(255) NOT NULL,
        authors JSON,
        cover_url VARCHAR(255),
        description TEXT,
        published_date DATE,
        categories JSON,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS user_movie_status (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        movie_id INT NOT NULL,
        status ENUM('watched', 'watching', 'watchlist') DEFAULT 'watched',
        rating INT CHECK (rating >= 1 AND rating <= 10),
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (movie_id) REFERENCES movies(id) ON DELETE CASCADE,
        UNIQUE KEY unique_user_movie (user_id, movie_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS user_book_status (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        book_id INT NOT NULL,
        status ENUM('read', 'reading', 'to_read') DEFAULT 'read',
        rating INT CHECK (rating >= 1 AND rating <= 10),
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE,
        UNIQUE KEY unique_user_book (user_id, book_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS comments (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        content_type ENUM('movie', 'book') NOT NULL,
        content_id INT NOT NULL,
        comment_text TEXT NOT NULL,
        parent_comment_id INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_content (content_type, content_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS comment_likes (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        comment_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (comment_id) REFERENCES comments(id) ON DELETE CASCADE,
        UNIQUE KEY unique_like (user_id, comment_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS follows (
        id INT PRIMARY KEY AUTO_INCREMENT,
        follower_id INT NOT NULL,
        following_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (follower_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (following_id) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE KEY unique_follow (follower_id, following_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS notifications (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        actor_id INT,
        type VARCHAR(50),
        content_type VARCHAR(50),
        content_id INT,
        is_read BOOLEAN DEFAULT FALSE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS password_resets (
        id INT PRIMARY KEY AUTO_INCREMENT,
        email VARCHAR(100) NOT NULL,
        token VARCHAR(255) NOT NULL,
        expires_at DATETIME NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
];

$success_count = 0;
$error_count = 0;

foreach ($sql_queries as $index => $query) {
    if ($conn->query($query) === TRUE) {
        $success_count++;
        echo "✅ Tablo $index başarıyla oluşturuldu<br>";
    } else {
        $error_count++;
        echo "❌ Tablo $index hatası: " . $conn->error . "<br>";
    }
}

echo "<hr>";
echo "<h3>Özet: $success_count başarılı, $error_count hata</h3>";

if ($error_count === 0) {
    echo "<p style='color: green; font-size: 18px;'><strong>🎉 Tüm tablolar başarıyla oluşturuldu!</strong></p>";
} else {
    echo "<p style='color: red;'>Bazı tablolar oluşturulamadı. Hataları kontrol edin.</p>";
}

$conn->close();
?>

