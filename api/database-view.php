<?php
require_once 'config.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Veritabanı - Sosyal Kütüphane</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
        }
        h1 {
            color: #333;
            margin-bottom: 10px;
        }
        .subtitle {
            color: #666;
            margin-bottom: 30px;
            font-size: 14px;
        }
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }
        .stat-box {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
        }
        .stat-number {
            font-size: 32px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        .stat-label {
            font-size: 14px;
            opacity: 0.9;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
        }
        th {
            background: #f5f5f5;
            padding: 12px;
            text-align: left;
            border-bottom: 2px solid #ddd;
            font-weight: 600;
            color: #333;
        }
        td {
            padding: 12px;
            border-bottom: 1px solid #eee;
            color: #666;
        }
        tr:hover {
            background: #f9f9f9;
        }
        .back-btn {
            display: inline-block;
            padding: 10px 20px;
            background: #667eea;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .back-btn:hover {
            background: #764ba2;
        }
        .section-title {
            font-size: 18px;
            color: #333;
            margin-top: 30px;
            margin-bottom: 15px;
            border-bottom: 2px solid #667eea;
            padding-bottom: 10px;
        }
        .empty-message {
            text-align: center;
            color: #999;
            padding: 20px;
            background: #f9f9f9;
            border-radius: 5px;
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="../index.html" class="back-btn">← Geri Dön</a>
        
        <h1>📊 Veritabanı Kontrol Paneli</h1>
        <p class="subtitle">Sistem verilerini görmek için bu sayfayı kullanabilirsin</p>

        <?php
        $user_count = $conn->query("SELECT COUNT(*) as count FROM users")->fetch_assoc()['count'];
        $movie_count = $conn->query("SELECT COUNT(*) as count FROM user_movie_status")->fetch_assoc()['count'];
        $book_count = $conn->query("SELECT COUNT(*) as count FROM user_book_status")->fetch_assoc()['count'];
        $comment_count = $conn->query("SELECT COUNT(*) as count FROM comments")->fetch_assoc()['count'];
        ?>

        <div class="stats">
            <div class="stat-box">
                <div class="stat-number"><?php echo $user_count; ?></div>
                <div class="stat-label">Toplam Kullanıcı</div>
            </div>
            <div class="stat-box">
                <div class="stat-number"><?php echo $movie_count; ?></div>
                <div class="stat-label">İzlenen Film</div>
            </div>
            <div class="stat-box">
                <div class="stat-number"><?php echo $book_count; ?></div>
                <div class="stat-label">Okunan Kitap</div>
            </div>
            <div class="stat-box">
                <div class="stat-number"><?php echo $comment_count; ?></div>
                <div class="stat-label">Toplam Yorum</div>
            </div>
        </div>

        <div class="section-title">👥 Tüm Kullanıcılar</div>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Kullanıcı Adı</th>
                    <th>E-posta</th>
                    <th>Şifre</th>
                    <th>Bio</th>
                    <th>Kaydolma Tarihi</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $result = $conn->query("SELECT * FROM users ORDER BY created_at DESC");
                if ($result->num_rows > 0) {
                    while ($row = $result->fetch_assoc()) {
                        echo "<tr>";
                        echo "<td>" . htmlspecialchars($row['id']) . "</td>";
                        echo "<td>" . htmlspecialchars($row['username']) . "</td>";
                        echo "<td>" . htmlspecialchars($row['email']) . "</td>";
                        echo "<td>" . htmlspecialchars($row['password']) . "</td>";
                        echo "<td>" . htmlspecialchars($row['bio'] ?? '-') . "</td>";
                        echo "<td>" . htmlspecialchars($row['created_at']) . "</td>";
                        echo "</tr>";
                    }
                } else {
                    echo "<tr><td colspan='6' class='empty-message'>Kullanıcı yok</td></tr>";
                }
                ?>
            </tbody>
        </table>

        <div class="section-title">🎬 İzlenen Filmler</div>
        <table>
            <thead>
                <tr>
                    <th>Film Adı</th>
                    <th>Kullanıcı</th>
                    <th>Durum</th>
                    <th>Puan</th>
                    <th>Tarih</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $result = $conn->query("
                    SELECT m.title, u.username, ums.status, ums.rating, ums.updated_at
                    FROM user_movie_status ums
                    JOIN movies m ON ums.movie_id = m.id
                    JOIN users u ON ums.user_id = u.id
                    ORDER BY ums.updated_at DESC
                    LIMIT 20
                ");
                if ($result->num_rows > 0) {
                    while ($row = $result->fetch_assoc()) {
                        echo "<tr>";
                        echo "<td>" . htmlspecialchars($row['title']) . "</td>";
                        echo "<td>" . htmlspecialchars($row['username']) . "</td>";
                        echo "<td>" . htmlspecialchars($row['status']) . "</td>";
                        echo "<td>" . ($row['rating'] ? htmlspecialchars($row['rating']) . "/10" : "-") . "</td>";
                        echo "<td>" . htmlspecialchars($row['updated_at']) . "</td>";
                        echo "</tr>";
                    }
                } else {
                    echo "<tr><td colspan='5' class='empty-message'>Henüz film izlenmedi</td></tr>";
                }
                ?>
            </tbody>
        </table>

        <div class="section-title">📚 Okunan Kitaplar</div>
        <table>
            <thead>
                <tr>
                    <th>Kitap Adı</th>
                    <th>Kullanıcı</th>
                    <th>Durum</th>
                    <th>Puan</th>
                    <th>Tarih</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $result = $conn->query("
                    SELECT b.title, u.username, ubs.status, ubs.rating, ubs.updated_at
                    FROM user_book_status ubs
                    JOIN books b ON ubs.book_id = b.id
                    JOIN users u ON ubs.user_id = u.id
                    ORDER BY ubs.updated_at DESC
                    LIMIT 20
                ");
                if ($result->num_rows > 0) {
                    while ($row = $result->fetch_assoc()) {
                        echo "<tr>";
                        echo "<td>" . htmlspecialchars($row['title']) . "</td>";
                        echo "<td>" . htmlspecialchars($row['username']) . "</td>";
                        echo "<td>" . htmlspecialchars($row['status']) . "</td>";
                        echo "<td>" . ($row['rating'] ? htmlspecialchars($row['rating']) . "/10" : "-") . "</td>";
                        echo "<td>" . htmlspecialchars($row['updated_at']) . "</td>";
                        echo "</tr>";
                    }
                } else {
                    echo "<tr><td colspan='5' class='empty-message'>Henüz kitap okunmadı</td></tr>";
                }
                ?>
            </tbody>
        </table>

        <!-- YORUMLAR -->
        <div class="section-title">💬 Son Yorumlar</div>
        <table>
            <thead>
                <tr>
                    <th>Kullanıcı</th>
                    <th>Yorum</th>
                    <th>Tür</th>
                    <th>Tarih</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $result = $conn->query("
                    SELECT u.username, c.comment_text, c.content_type, c.created_at
                    FROM comments c
                    JOIN users u ON c.user_id = u.id
                    WHERE c.parent_comment_id IS NULL
                    ORDER BY c.created_at DESC
                    LIMIT 20
                ");
                if ($result->num_rows > 0) {
                    while ($row = $result->fetch_assoc()) {
                        $short_text = strlen($row['comment_text']) > 100 
                            ? substr($row['comment_text'], 0, 100) . "..." 
                            : $row['comment_text'];
                        echo "<tr>";
                        echo "<td>" . htmlspecialchars($row['username']) . "</td>";
                        echo "<td>" . htmlspecialchars($short_text) . "</td>";
                        echo "<td>" . ($row['content_type'] === 'movie' ? '🎬 Film' : '📚 Kitap') . "</td>";
                        echo "<td>" . htmlspecialchars($row['created_at']) . "</td>";
                        echo "</tr>";
                    }
                } else {
                    echo "<tr><td colspan='4' class='empty-message'>Henüz yorum yapılmadı</td></tr>";
                }
                ?>
            </tbody>
        </table>

        <div style="text-align: center; margin-top: 30px; color: #999; font-size: 12px;">
            <p>Bu sayfa sadece veri kontrol için kullanılır.</p>
        </div>
    </div>
</body>
</html>

