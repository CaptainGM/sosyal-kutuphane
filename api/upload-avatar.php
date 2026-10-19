<?php
require_once 'config.php';
require_once __DIR__ . '/lib/moderation-log.php';
require_once __DIR__ . '/lib/image-moderation.php';
require_once __DIR__ . '/lib/rate-limit.php';
require_once __DIR__ . '/lib/avatar-store.php';
require_once __DIR__ . '/lib/profile.php';
requireLogin();
requireCsrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Geçersiz istek'], 405);
}

$userId = (int) getCurrentUserId();
$MAX_BYTES = 2 * 1024 * 1024; // 2MB
$ALLOWED_MIME_TO_EXT = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    'image/gif' => 'gif',
];

if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
    jsonResponse(['success' => false, 'message' => 'Dosya yüklenemedi'], 400);
}

$file = $_FILES['avatar'];

if ($file['size'] > $MAX_BYTES) {
    jsonResponse(['success' => false, 'message' => 'Dosya çok büyük (maks. 2MB)'], 400);
}

// getimagesize() dosyanın gerçekten bir resim olup olmadığını doğrular (uzantıya güvenmez).
$imageInfo = @getimagesize($file['tmp_name']);
if ($imageInfo === false) {
    jsonResponse(['success' => false, 'message' => 'Geçersiz resim dosyası'], 400);
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($file['tmp_name']);

if (!isset($ALLOWED_MIME_TO_EXT[$mimeType])) {
    jsonResponse(['success' => false, 'message' => 'Sadece JPG, PNG, WEBP veya GIF yükleyebilirsiniz'], 400);
}

// Her deneme yapay zekâ kotasından yer. Fotoğraf değiştirmek için saatte 10 deneme fazlasıyla yeter.
if (rateLimitHit($db, "avatar-upload:$userId", 10, 3600)) {
    jsonResponse(['success' => false, 'message' => 'Çok fazla fotoğraf denedin. Bir saat sonra tekrar dene.'], 429);
}

// Cinsel içerik, şiddet / vahşet ve terör görselleri dosya diske yazılmadan reddedilir (bkz. lib/image-moderation.php).
$bytes = (string) file_get_contents($file['tmp_name']);
$check = imageModerationVerdict($db, $bytes, $mimeType);
if ($check['status'] === 'unsafe') {
    [$label, $message] = IMAGE_MODERATION_CATEGORIES[$check['category']] ?? IMAGE_MODERATION_CATEGORIES['other'];
    rejectModeratedImage($db, $userId, 'avatar', ['reason' => 'image', 'message' => $message], $label);
}
if ($check['status'] === 'animated') {
    jsonResponse(['success' => false, 'message' => 'Hareketli görseller (GIF, animasyonlu WebP/PNG) yüklenemez. Düz bir fotoğraf seç.'], 422);
}
if ($check['status'] === 'unavailable') {
    jsonResponse(['success' => false, 'message' => 'Fotoğraf şu an kontrol edilemedi. Birazdan tekrar dene.'], 503);
}

$ext = $ALLOWED_MIME_TO_EXT[$mimeType];
$filename = bin2hex(random_bytes(16)) . '.' . $ext;
$uploadDir = __DIR__ . '/../uploads/avatars/';
$destination = $uploadDir . $filename;

// Asıl kayıt MongoDB'dedir: barındırıcının diski her dağıtımda silinir (bkz. lib/avatar-store.php). Diske yazılan kopya
// yalnızca hız içindir; yazılamazsa fotoğraf yine depodan sunulur.
avatarStoreSave($db, $filename, $bytes, $userId);
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0755, true);
}
@move_uploaded_file($file['tmp_name'], $destination);

// Kullanıcının önceki yüklediği fotoğrafı (disk ve depo) temizle.
$existing = $db->users->findOne(['_id' => $userId], ['projection' => ['avatar_url' => 1]]);
if ($existing && $existing->avatar_url && str_starts_with($existing->avatar_url, '/uploads/avatars/')) {
    $oldPath = profileUploadedAvatarPath($existing->avatar_url);
    if ($oldPath !== null && is_file($oldPath)) {
        @unlink($oldPath);
    }
    avatarStoreDelete($db, $existing->avatar_url);
}

$avatarUrl = '/uploads/avatars/' . $filename;
$db->users->updateOne(
    ['_id' => $userId],
    ['$set' => ['avatar_url' => $avatarUrl, 'updated_at' => new MongoDB\BSON\UTCDateTime()]]
);

jsonResponse(['success' => true, 'message' => 'Avatar güncellendi', 'avatar_url' => $avatarUrl]);
?>
