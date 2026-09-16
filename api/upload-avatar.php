<?php
require_once 'config.php';
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

$ext = $ALLOWED_MIME_TO_EXT[$mimeType];
$filename = bin2hex(random_bytes(16)) . '.' . $ext;
$uploadDir = __DIR__ . '/../uploads/avatars/';
$destination = $uploadDir . $filename;

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

if (!move_uploaded_file($file['tmp_name'], $destination)) {
    jsonResponse(['success' => false, 'message' => 'Dosya kaydedilemedi'], 500);
}

// Kullanıcının önceki yüklediği (bizim uploads/avatars altındaki) dosyayı temizle.
$existing = $db->users->findOne(['_id' => $userId], ['projection' => ['avatar_url' => 1]]);
if ($existing && $existing->avatar_url && str_starts_with($existing->avatar_url, '/uploads/avatars/')) {
    $oldPath = __DIR__ . '/..' . $existing->avatar_url;
    if (is_file($oldPath)) {
        @unlink($oldPath);
    }
}

$avatarUrl = '/uploads/avatars/' . $filename;
$db->users->updateOne(
    ['_id' => $userId],
    ['$set' => ['avatar_url' => $avatarUrl, 'updated_at' => new MongoDB\BSON\UTCDateTime()]]
);

jsonResponse(['success' => true, 'message' => 'Avatar güncellendi', 'avatar_url' => $avatarUrl]);
?>
