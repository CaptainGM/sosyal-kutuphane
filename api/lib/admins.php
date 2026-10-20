<?php
// Yöneticiler yapılandırmayla belirlenir: ADMIN_EMAILS ortam değişkeni (virgülle ayrılmış e-posta listesi; yoksa aşağıdaki varsayılan).
// api/setup-db.php her açılışta (Render'da her dağıtım ve yeniden başlatmada) veritabanını bu listeye eşitler:
// listedeki hesaplar yönetici olur, listede olmayan herkesten yönetici yetkisi alınır.
// Bu yüzden scripts/make-admin.php ile verilen yetki bir sonraki açılışta, e-posta listede değilse geri alınır.

const ADMIN_DEFAULT_EMAILS = ['sahinberatbatuhan41@gmail.com'];

// ADMIN_EMAILS değerini temiz bir e-posta listesine çevirir (küçük harf, geçersizler atılır). Boşsa varsayılan döner.
function adminEmailsFromEnv(?string $raw = null): array {
    $raw ??= (string) getenv('ADMIN_EMAILS');
    if (trim($raw) === '') {
        return ADMIN_DEFAULT_EMAILS;
    }
    $emails = [];
    foreach (preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) as $candidate) {
        $email = strtolower($candidate);
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $emails[$email] = true;
        }
    }
    return $emails ? array_keys($emails) : ADMIN_DEFAULT_EMAILS;
}

// Veritabanını listeye eşitler. Dönüş: ['status' => 'ok' | 'missing', 'promoted' => int, 'demoted' => int, 'matched' => int].
// Kilitlenme koruması: listedeki hiçbir e-posta bir kullanıcıyla eşleşmezse (hesap henüz açılmamış ya da doğrulanmamış)
// hiçbir şey değiştirilmez; böylece yönetici sayısı sıfıra düşmez.
function syncAdmins($db, array $emails): array {
    $ids = [];
    foreach ($emails as $email) {
        // E-posta büyük/küçük harf duyarsız eşleşir (kayıtta girilen biçim değişebilir).
        $user = $db->users->findOne(['email' => new MongoDB\BSON\Regex('^' . preg_quote($email, '/') . '$', 'i')], ['projection' => ['_id' => 1]]);
        if ($user) {
            $ids[] = $user['_id'];
        }
    }
    if (!$ids) {
        return ['status' => 'missing', 'promoted' => 0, 'demoted' => 0, 'matched' => 0];
    }

    $promoted = $db->users->updateMany(['_id' => ['$in' => $ids], 'is_admin' => ['$ne' => true]], ['$set' => ['is_admin' => true]])->getModifiedCount();
    $demoted = $db->users->updateMany(['_id' => ['$nin' => $ids], 'is_admin' => true], ['$set' => ['is_admin' => false]])->getModifiedCount();
    return ['status' => 'ok', 'promoted' => (int) $promoted, 'demoted' => (int) $demoted, 'matched' => count($ids)];
}
