<?php
// php -S için yönlendirici (Docker imajı ve START betikleri bunu kullanır).
//  - Statik dosyaları kendimiz gönderiyoruz ki önbellek başlıkları (Cache-Control) silinmesin: Cloudflare ve tarayıcı
//    CSS, JS ve görselleri tekrar indirmez.
//  - Gizli ve iç dosyalar (.env, .git, vendor, tests, scripts, api/lib, partials ...) hiçbir koşulda sunulmaz.
//  - Diğer her şey normal akışa döner: PHP dosyaları ve API uçları çalışır.

// Statik HTML sayfaları da burada: kullanıcıya özel veri içermezler (oturum tarayıcıda, JavaScript ile kontrol edilir).
const STATIC_TYPES = [
    'html' => 'text/html; charset=UTF-8',
    'css' => 'text/css; charset=UTF-8',
    'js' => 'application/javascript; charset=UTF-8',
    'svg' => 'image/svg+xml',
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'webp' => 'image/webp',
    'ico' => 'image/x-icon',
    'txt' => 'text/plain; charset=UTF-8',
    'woff2' => 'font/woff2',
    'json' => 'application/json; charset=UTF-8',
];

// Bu klasörler ve nokta ile başlayan her yol (.env, .git/config, .dockerignore ...) dışarıya kapalıdır.
const HIDDEN_DIRECTORIES = ['vendor', 'tests', 'scripts', 'partials', 'node_modules', 'test-results', 'playwright-report', '.github', 'api/lib'];

// Tarayıcıya temel korumalar: içerik türü tahmini yapma, siteyi başka bir sitenin çerçevesine koyma, adres geçmişini sızdırma.
// Render gibi bir vekilin ardında HTTPS ise bu siteyi hep HTTPS ile açması da söylenir.
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
if (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
    header('Strict-Transport-Security: max-age=15552000');
}

$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
$segments = array_values(array_filter(explode('/', $path), static fn ($s) => $s !== ''));

foreach ($segments as $segment) {
    if ($segment === '..' || str_starts_with($segment, '.') || str_contains($segment, "\0")) {
        http_response_code(404);
        exit;
    }
}
foreach (HIDDEN_DIRECTORIES as $hidden) {
    $prefix = implode('/', array_slice($segments, 0, substr_count($hidden, '/') + 1));
    if ($prefix === $hidden) {
        http_response_code(404);
        exit;
    }
}

$file = __DIR__ . '/' . implode('/', $segments);
if ($segments === []) {
    $file = __DIR__ . '/index.html';
}
$extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

if (isset(STATIC_TYPES[$extension]) && is_file($file)) {
    header('Content-Type: ' . STATIC_TYPES[$extension]);
    // Kısa taze süre + bayat içeriği arka planda yenileme: sayfalar hemen açılır, değişiklikler birkaç dakikada gelir.
    header('Cache-Control: public, max-age=300, stale-while-revalidate=86400');
    header('Content-Length: ' . filesize($file));
    readfile($file);
    return true;
}

// Var olan başka dosyalar (PHP uç noktaları, yüklenen GIF avatarlar...) normal akışa döner.
if (is_file($file)) {
    return false;
}

// Yüklenmiş profil fotoğrafı diskte yoksa (barındırıcının diski her dağıtımda sıfırlanır) MongoDB'deki kopyadan sunulur.
if (preg_match('~^/uploads/avatars/([a-f0-9]{32}\.(?:jpg|png|webp|gif))$~', $path, $avatarMatch)) {
    require_once __DIR__ . '/api/lib/avatar-store.php';
    if (avatarStoreServe($avatarMatch[1])) {
        return true;
    }
}

// Bulunamadı. PHP'nin yerleşik sunucusu bilinmeyen yollarda giriş sayfasını 200 ile döner (yanlış yazılan adres giriş ekranı
// gibi görünür, arama motorları "yumuşak 404" sayar); bunun yerine gerçek bir 404 verilir.
http_response_code(404);
if (str_starts_with($path, '/api/')) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => false, 'message' => 'Bulunamadı']);
    return true;
}
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
readfile(__DIR__ . '/404.html');
return true;
