<?php
// Tarayıcı bildirimleri için VAPID anahtar çifti üretir. Yalnızca komut satırından çalışır.
//   php scripts/make-vapid.php
// Çıktıdaki iki değeri Render'daki servisin ortam değişkenlerine (VAPID_PUBLIC_KEY, VAPID_PRIVATE_KEY) ekle.
// Özel anahtarı kimseyle paylaşma ve depoya yazma. Aynı anahtarı yeniden üretmek, mevcut abonelikleri geçersiz kılar.
// Windows'ta "Unable to create the key" hatası alırsan OpenSSL yapılandırma dosyasını göster:
//   OPENSSL_CONF="C:/Program Files/Git/usr/ssl/openssl.cnf" php scripts/make-vapid.php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../vendor/autoload.php';

$keys = Minishlink\WebPush\VAPID::createVapidKeys();
echo "VAPID_PUBLIC_KEY={$keys['publicKey']}\n";
echo "VAPID_PRIVATE_KEY={$keys['privateKey']}\n";
