<?php
// .env yükleyici (basit satır parser, harici paket yok)
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
            $_ENV[$key] = $value;
        }
    }
}

require_once __DIR__ . '/../vendor/autoload.php';

use MongoDB\Client;
use MongoDB\Operation\FindOneAndUpdate;

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? null) == 443);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_secure', $isHttps ? '1' : '0');
ini_set('session.cookie_httponly', '1');
ini_set('session.use_strict_mode', '1');

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

define('SITE_URL', rtrim(getenv('SITE_URL') ?: 'http://localhost:8000', '/'));

// Sadece kendi origin'imize CORS izni ver (önceden her origin yansıtılıyordu, açıktı).
$origin = $_SERVER['HTTP_ORIGIN'] ?? SITE_URL;
if (rtrim($origin, '/') === SITE_URL) {
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Credentials: true');
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-CSRF-Token');
header('Content-Type: application/json; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit();
}

define('MONGO_URI', getenv('MONGO_URI') ?: 'mongodb://localhost:27017');
define('MONGO_DB_NAME', getenv('MONGO_DB') ?: 'social_library');

try {
    $client = new Client(MONGO_URI);
    $db = $client->selectDatabase(MONGO_DB_NAME);
    $db->command(['ping' => 1]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Veritabanı bağlantı hatası']);
    exit;
}

function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data);
    exit;
}

function requireLogin() {
    if (!isset($_SESSION['user_id'])) {
        jsonResponse(['success' => false, 'message' => 'Giriş yapmalısınız'], 401);
    }
}

function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function requireAdmin($db) {
    requireLogin();
    $user = $db->users->findOne(['_id' => getCurrentUserId()], ['projection' => ['is_admin' => 1]]);
    if (empty($user->is_admin)) {
        jsonResponse(['success' => false, 'message' => 'Yetkiniz yok'], 403);
    }
}

// AUTO_INCREMENT yerine geçen sayaç — frontend tamsayı id bekliyor, ObjectId değil.
function nextSequence($db, $name) {
    $result = $db->counters->findOneAndUpdate(
        ['_id' => $name],
        ['$inc' => ['seq' => 1]],
        ['upsert' => true, 'returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER]
    );
    return $result->seq;
}

function csrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// POST/PUT/DELETE isteklerinde çağrılır.
function requireCsrf() {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
        return;
    }
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $expected = $_SESSION['csrf_token'] ?? '';
    if ($expected === '' || !hash_equals($expected, $sent)) {
        jsonResponse(['success' => false, 'message' => 'Geçersiz istek (CSRF token)'], 403);
    }
}

// IP+email başına login deneme sayacı (TTL indeksle otomatik temizlenir).
function checkLoginRateLimit($db, string $email) {
    $key = ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . '|' . strtolower($email);
    $windowStart = new MongoDB\BSON\UTCDateTime((time() - 600) * 1000); // 10 dakikalık pencere
    $doc = $db->login_attempts->findOne(['_id' => $key]);
    if ($doc && $doc->last_attempt >= $windowStart && $doc->count >= 8) {
        jsonResponse(['success' => false, 'message' => 'Çok fazla deneme yaptınız. Lütfen birkaç dakika sonra tekrar deneyin.'], 429);
    }
}

function recordLoginAttempt($db, string $email) {
    $key = ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . '|' . strtolower($email);
    $now = new MongoDB\BSON\UTCDateTime();
    $windowStart = new MongoDB\BSON\UTCDateTime((time() - 600) * 1000);
    $existing = $db->login_attempts->findOne(['_id' => $key]);
    if ($existing && $existing->last_attempt >= $windowStart) {
        $db->login_attempts->updateOne(['_id' => $key], ['$inc' => ['count' => 1], '$set' => ['last_attempt' => $now]]);
    } else {
        $db->login_attempts->updateOne(
            ['_id' => $key],
            ['$set' => ['count' => 1, 'last_attempt' => $now]],
            ['upsert' => true]
        );
    }
}

function clearLoginAttempts($db, string $email) {
    $key = ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . '|' . strtolower($email);
    $db->login_attempts->deleteOne(['_id' => $key]);
}

// TMDB/Google Books yanıtları için Atlas önbelleği — TTL cacheSet() çağrısında belirlenir.
function cacheGet($db, string $key) {
    $doc = $db->api_cache->findOne(['_id' => $key]);
    if (!$doc || $doc->expires_at->toDateTime()->getTimestamp() < time()) {
        return null;
    }
    return json_decode($doc->data, true);
}

function cacheSet($db, string $key, $data, int $ttlSeconds) {
    $db->api_cache->updateOne(
        ['_id' => $key],
        ['$set' => [
            'data' => json_encode($data),
            'cached_at' => new MongoDB\BSON\UTCDateTime(),
            'expires_at' => new MongoDB\BSON\UTCDateTime((time() + $ttlSeconds) * 1000),
        ]],
        ['upsert' => true]
    );
}
?>
