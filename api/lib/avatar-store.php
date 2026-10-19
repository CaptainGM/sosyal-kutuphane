<?php
// Yüklenen profil fotoğraflarının kalıcı deposu. Render gibi barındırıcılarda uygulamanın diski her dağıtımda ve yeniden
// başlatmada silinir; bu yüzden fotoğraf MongoDB'de de tutulur (avatar_files) ve diskte yoksa yönlendirici (router.php)
// buradan sunar. Adres biçimi (/uploads/avatars/<32 hex>.<uzantı>) değişmedi; disk yalnızca bir önbellektir.
require_once __DIR__ . '/env.php';

const AVATAR_STORE_NAME_PATTERN = '~^[a-f0-9]{32}\.(?:jpg|png|webp|gif)$~';
const AVATAR_STORE_MIME = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];

// Uygulama içinden ($db verilir) ya da yönlendiriciden (verilmez; bağlantı ortam değişkenlerinden kurulur) kullanılır.
function avatarStoreDb($db = null) {
    static $own = null;
    if ($db !== null) {
        return $db;
    }
    if ($own === null) {
        loadDotEnv(__DIR__ . '/../../.env');
        require_once __DIR__ . '/../../vendor/autoload.php';
        $own = (new MongoDB\Client(getenv('MONGO_URI') ?: 'mongodb://localhost:27017'))->selectDatabase(getenv('MONGO_DB') ?: 'social_library');
    }
    return $own;
}

// /uploads/avatars/<ad> adresinden dosya adını çıkarır; bizim biçimimizde değilse null.
function avatarStoreNameFromUrl(?string $url): ?string {
    if ($url === null || !str_starts_with($url, '/uploads/avatars/')) {
        return null;
    }
    $name = substr($url, strlen('/uploads/avatars/'));
    return preg_match(AVATAR_STORE_NAME_PATTERN, $name) ? $name : null;
}

function avatarStoreSave($db, string $name, string $bytes, int $userId): void {
    if (!preg_match(AVATAR_STORE_NAME_PATTERN, $name)) {
        throw new InvalidArgumentException('Geçersiz avatar adı');
    }
    avatarStoreDb($db)->avatar_files->replaceOne(
        ['_id' => $name],
        [
            '_id' => $name,
            'user_id' => $userId,
            'data' => new MongoDB\BSON\Binary($bytes, MongoDB\BSON\Binary::TYPE_GENERIC),
            'size' => strlen($bytes),
            'created_at' => new MongoDB\BSON\UTCDateTime(),
        ],
        ['upsert' => true]
    );
}

// Kullanıcının eski fotoğrafı değiştirilince ya da silinince depodaki kopya da gider.
function avatarStoreDelete($db, ?string $avatarUrl): void {
    $name = avatarStoreNameFromUrl($avatarUrl);
    if ($name !== null) {
        avatarStoreDb($db)->avatar_files->deleteOne(['_id' => $name]);
    }
}

// Yönlendirici: dosya diskte yoksa depodan gönderir. Gönderdiyse true, bulamadıysa false döner.
function avatarStoreServe(string $name): bool {
    if (!preg_match(AVATAR_STORE_NAME_PATTERN, $name)) {
        return false;
    }
    try {
        $doc = avatarStoreDb()->avatar_files->findOne(['_id' => $name]);
    } catch (Throwable $e) {
        error_log('Avatar deposu okunamadı: ' . $e->getMessage());
        return false;
    }
    if (!$doc || !isset($doc['data'])) {
        return false;
    }
    $bytes = $doc['data']->getData();
    http_response_code(200);
    // Adlar rastgeledir ve bir kez yazılır: tarayıcı bir yıl saklayabilir.
    header('Content-Type: ' . AVATAR_STORE_MIME[pathinfo($name, PATHINFO_EXTENSION)]);
    header('Cache-Control: public, max-age=31536000, immutable');
    header('Content-Length: ' . strlen($bytes));
    echo $bytes;
    return true;
}
