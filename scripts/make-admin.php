<?php
// Yönetici yetkisi vermek için yalnızca komut satırından çalışır:
//   php scripts/make-admin.php kullanici@ornek.com
// Web sunucusu üzerinden (php -S dahil) erişilirse 404 döner ve hiçbir şey yapmaz.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../vendor/autoload.php';

$email = strtolower(trim($argv[1] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Kullanım: php scripts/make-admin.php kullanici@ornek.com\n");
    exit(1);
}

$client = new MongoDB\Client(getenv('MONGO_URI') ?: 'mongodb://localhost:27017');
$db = $client->selectDatabase(getenv('MONGO_DB') ?: 'social_library');

// E-posta büyük/küçük harf duyarsız eşleşir (kayıtta girilen biçim değişebilir).
$result = $db->users->updateOne(
    ['email' => new MongoDB\BSON\Regex('^' . preg_quote($email, '/') . '$', 'i')],
    ['$set' => ['is_admin' => true]]
);

if ($result->getMatchedCount() === 0) {
    fwrite(STDERR, "Bu e-posta ile bir kullanıcı bulunamadı: $email\n");
    exit(1);
}

echo "Yönetici yetkisi verildi: $email\n";
