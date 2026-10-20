<?php
// Tarayıcı (Playwright) testleri için geçici kullanıcı oluşturur ve bağlı tüm kayıtlarıyla siler.
//   php scripts/e2e-user.php create e2e_ad [admin]   -> kullanıcıyı ekler (e-posta: e2e_ad@test.com, şifre: Flow1234!)
//   php scripts/e2e-user.php delete e2e_ad           -> kullanıcıyı, yorumlarını, listelerini, mesajlarını... siler
// Yalnızca komut satırından çalışır ve yalnızca "e2e_" önekli kullanıcılara dokunur: gerçek veya demo hesaplar silinemez.
// Bağlantı MONGO_URI / MONGO_DB ortam değişkenlerinden (yoksa .env dosyasından) okunur.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        if ($key !== '' && getenv($key) === false) {
            putenv("$key=$value");
        }
    }
}

[$script, $action, $username] = array_pad($argv, 3, null);
if (!in_array($action, ['create', 'delete'], true) || !is_string($username) || !preg_match('/^e2e_[a-z0-9_]{1,40}$/', $username)) {
    fwrite(STDERR, "Kullanım: php scripts/e2e-user.php create|delete e2e_ad [admin]\n");
    exit(2);
}

$db = (new MongoDB\Client(getenv('MONGO_URI') ?: 'mongodb://localhost:27017'))->selectDatabase(getenv('MONGO_DB') ?: 'social_library');
$email = "$username@test.com";

if ($action === 'create') {
    if ($db->users->findOne(['email' => $email])) {
        echo "zaten var: $username\n";
        exit(0);
    }
    $counter = $db->counters->findOneAndUpdate(
        ['_id' => 'users'],
        ['$inc' => ['seq' => 1]],
        ['upsert' => true, 'returnDocument' => MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER]
    );
    $id = (int) $counter['seq'];
    $db->users->insertOne([
        '_id' => $id,
        'username' => $username,
        'email' => $email,
        'password' => password_hash('Flow1234!', PASSWORD_DEFAULT),
        'bio' => null,
        'avatar_url' => null,
        'is_admin' => ($argv[3] ?? '') === 'admin',
        'created_at' => new MongoDB\BSON\UTCDateTime(),
        'updated_at' => new MongoDB\BSON\UTCDateTime(),
    ]);
    echo "eklendi: $username (id=$id)\n";
    exit(0);
}

$user = $db->users->findOne(['username' => $username]);
if (!$user) {
    echo "yok: $username\n";
    exit(0);
}
// Kullanıcının tüm kişisel verisi tek işlevle silinir (api/lib/account.php): kullanıcı hesap silince de aynı işlev çalışır.
require_once __DIR__ . '/../api/lib/account.php';
deleteAccountData($db, (int) $user['_id']);

// Tarayıcı testleri 987000-987999 aralığındaki uydurma TMDB kimlikleriyle içerik kaydı açabilir; bunlar da temizlenir.
foreach (['movies', 'series'] as $collection) {
    $db->$collection->deleteMany(['tmdb_id' => ['$gte' => 987000, '$lt' => 988000]]);
}
echo "silindi: $username\n";
