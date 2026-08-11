<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/ApiClient.php';

$envPath = __DIR__ . '/../.env';
if (file_exists($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
        $key = trim($key);
        $value = trim($value);
        if ($key !== '' && getenv($key) === false) {
            putenv("$key=$value");
        }
    }
}

define('TEST_BASE_URL', getenv('TEST_BASE_URL') ?: 'http://localhost:8000');
define('TEST_MONGO_URI', getenv('TEST_MONGO_URI') ?: (getenv('MONGO_URI') ?: 'mongodb://localhost:27017'));
// ÖNEMLİ: production MONGO_DB'ye asla düşme — aksi halde testler canlı/gerçek
// veritabanını sahte kullanıcı ve içeriklerle kirletir (bir kez oldu, temizlendi).
// Aynı cluster'da ayrı bir veritabanı kullanılır (Mongo bunu ilk yazımda otomatik oluşturur).
define('TEST_MONGO_DB', getenv('TEST_MONGO_DB') ?: 'social_library_test');
