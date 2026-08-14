<?php
require_once 'config.php';

header('Content-Type: text/html; charset=utf-8');
echo "<h2>📋 MongoDB İndeksleri Oluşturuluyor...</h2>";

// schema.sql'deki UNIQUE / FK-index'lerin MongoDB karşılıkları.
$indexPlans = [
    'users' => [
        ['key' => ['username' => 1], 'unique' => true],
        ['key' => ['email' => 1], 'unique' => true],
    ],
    'movies' => [
        ['key' => ['tmdb_id' => 1], 'unique' => true],
    ],
    'books' => [
        ['key' => ['google_books_id' => 1], 'unique' => true],
    ],
    'series' => [
        ['key' => ['tmdb_id' => 1], 'unique' => true],
    ],
    'user_movie_status' => [
        ['key' => ['user_id' => 1, 'movie_id' => 1], 'unique' => true],
    ],
    'user_book_status' => [
        ['key' => ['user_id' => 1, 'book_id' => 1], 'unique' => true],
    ],
    'user_series_status' => [
        ['key' => ['user_id' => 1, 'series_id' => 1], 'unique' => true],
    ],
    'comments' => [
        ['key' => ['content_type' => 1, 'content_id' => 1]],
        ['key' => ['parent_comment_id' => 1]],
        ['key' => ['user_id' => 1]],
    ],
    'comment_likes' => [
        ['key' => ['user_id' => 1, 'comment_id' => 1], 'unique' => true],
        ['key' => ['comment_id' => 1]],
    ],
    'follows' => [
        ['key' => ['follower_id' => 1, 'following_id' => 1], 'unique' => true],
        ['key' => ['following_id' => 1]],
    ],
    'blocks' => [
        ['key' => ['blocker_id' => 1, 'blocked_id' => 1], 'unique' => true],
        ['key' => ['blocked_id' => 1]],
    ],
    'comment_reports' => [
        ['key' => ['comment_id' => 1, 'reporter_id' => 1], 'unique' => true],
        ['key' => ['status' => 1, 'created_at' => -1]],
    ],
    'notifications' => [
        ['key' => ['user_id' => 1, 'created_at' => -1]],
    ],
    'password_resets' => [
        ['key' => ['token' => 1], 'unique' => true],
        ['key' => ['email' => 1]],
        ['key' => ['expires_at' => 1], 'expireAfterSeconds' => 0],
    ],
    'pending_registrations' => [
        ['key' => ['email' => 1], 'unique' => true],
        ['key' => ['username' => 1]],
        ['key' => ['expires_at' => 1], 'expireAfterSeconds' => 0],
    ],
    'custom_lists' => [
        ['key' => ['user_id' => 1]],
    ],
    'custom_list_items' => [
        ['key' => ['list_id' => 1, 'content_type' => 1, 'content_id' => 1], 'unique' => true],
    ],
    'login_attempts' => [
        ['key' => ['last_attempt' => 1], 'expireAfterSeconds' => 3600],
    ],
    'email_change_requests' => [
        ['key' => ['created_at' => 1], 'expireAfterSeconds' => 3600],
    ],
    'conversations' => [
        // participant_a < participant_b her zaman sıralı — iki skaler alan, tek dizi değil.
        ['key' => ['participant_a' => 1, 'participant_b' => 1], 'unique' => true],
        ['key' => ['last_message_at' => -1]],
    ],
    'messages' => [
        ['key' => ['conversation_id' => 1, 'created_at' => 1]],
    ],
    'api_cache' => [
        ['key' => ['cached_at' => 1], 'expireAfterSeconds' => 259200], // 3 gün — yedek temizlik, asıl TTL cacheGet()'te
    ],
];

$successCount = 0;
$errorCount = 0;

foreach ($indexPlans as $collectionName => $indexes) {
    try {
        $db->selectCollection($collectionName)->createIndexes($indexes);
        echo "✅ <b>$collectionName</b> indeksleri oluşturuldu<br>";
        $successCount++;
    } catch (Throwable $e) {
        echo "❌ <b>$collectionName</b> indeks hatası: " . htmlspecialchars($e->getMessage()) . "<br>";
        $errorCount++;
    }
}

echo "<hr><h3>👥 Demo Kullanıcıları Ekleniyor...</h3>";

$demoUsers = [
    ['admin', 'admin@test.com', 'Admin kullanıcısı'],
    ['test', 'test@test.com', 'Test kullanıcısı'],
    ['ahmet', 'ahmet@test.com', 'Film severim'],
    ['ayse', 'ayse@test.com', 'Kitap kurdu'],
];

foreach ($demoUsers as [$username, $email, $bio]) {
    $isAdmin = $username === 'admin';
    $existing = $db->users->findOne(['email' => $email]);
    if ($existing) {
        if ($isAdmin && empty($existing->is_admin)) {
            $db->users->updateOne(['_id' => $existing->_id], ['$set' => ['is_admin' => true]]);
            echo "🛡️ Admin yetkisi verildi: $username<br>";
        } else {
            echo "⏭️ Zaten var: $username<br>";
        }
        continue;
    }
    $id = nextSequence($db, 'users');
    $db->users->insertOne([
        '_id' => $id,
        'username' => $username,
        'email' => $email,
        'password' => password_hash('123456', PASSWORD_DEFAULT),
        'bio' => $bio,
        'avatar_url' => null,
        'is_admin' => $isAdmin,
        'created_at' => new MongoDB\BSON\UTCDateTime(),
        'updated_at' => new MongoDB\BSON\UTCDateTime(),
    ]);
    echo "✅ Kullanıcı eklendi: $username (şifre: 123456)<br>";
}

echo "<hr><h3>📊 Özet: $successCount koleksiyon indekslendi, $errorCount hata</h3>";
if ($errorCount === 0) {
    echo "<p style='color:green;font-size:18px;'><strong>🎉 MongoDB hazır!</strong></p>";
    echo "<p><a href='../index.html'>Giriş sayfasına dön →</a></p>";
}
?>
