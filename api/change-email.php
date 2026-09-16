<?php
require_once 'config.php';
require_once 'mail-config.php';
requireLogin();
requireCsrf();

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Geçersiz istek'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? null;
$userId = (int) getCurrentUserId();
$MAX_ATTEMPTS = 5;
$CODE_TTL_SECONDS = 600; // 10 dakika

function generateCode(): string {
    return (string) random_int(100000, 999999);
}

function sendVerificationCodeEmail(string $toEmail, string $username, string $code, string $context): bool {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USERNAME;
        $mail->Password = SMTP_PASSWORD;
        $mail->SMTPSecure = 'tls';
        $mail->Port = SMTP_PORT;
        $mail->CharSet = 'UTF-8';

        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($toEmail);
        $mail->isHTML(true);
        $mail->Subject = 'Sosyal Kütüphane - E-posta Doğrulama Kodu';
        $mail->Body = '
        <div style="font-family: Arial, sans-serif; max-width: 500px; margin: 0 auto;">
            <h2 style="color:#667eea;">🎬 Sosyal Kütüphane</h2>
            <p>Merhaba <strong>' . htmlspecialchars($username) . '</strong>,</p>
            <p>' . htmlspecialchars($context) . '</p>
            <p style="font-size: 32px; font-weight: bold; letter-spacing: 6px; text-align: center; padding: 20px; background: #f8f9fa; border-radius: 8px;">' . htmlspecialchars($code) . '</p>
            <p>Bu kod 10 dakika geçerlidir. Bu talebi siz yapmadıysanız bu e-postayı yok sayabilirsiniz.</p>
        </div>';
        $mail->AltBody = "Doğrulama kodunuz: $code (10 dakika geçerlidir)";
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("E-posta gönderilemedi: " . $mail->ErrorInfo);
        error_log("Doğrulama kodu (gelişim amaçlı log): $code -> $toEmail");
        return false;
    }
}

$user = $db->users->findOne(['_id' => $userId]);
if (!$user) {
    jsonResponse(['success' => false, 'message' => 'Kullanıcı bulunamadı'], 404);
}

if ($action === 'request_old_code') {
    $existing = $db->email_change_requests->findOne(['_id' => $userId]);
    if ($existing && $existing->created_at->toDateTime()->getTimestamp() > (time() - 60)) {
        jsonResponse(['success' => false, 'message' => 'Az önce bir kod gönderildi, lütfen biraz bekleyin.'], 429);
    }

    $code = generateCode();
    $db->email_change_requests->replaceOne(
        ['_id' => $userId],
        [
            '_id' => $userId,
            'old_code_hash' => password_hash($code, PASSWORD_DEFAULT),
            'old_expires_at' => new MongoDB\BSON\UTCDateTime((time() + $CODE_TTL_SECONDS) * 1000),
            'old_verified' => false,
            'old_attempts' => 0,
            'new_email' => null,
            'new_code_hash' => null,
            'new_expires_at' => null,
            'new_attempts' => 0,
            'created_at' => new MongoDB\BSON\UTCDateTime(),
        ],
        ['upsert' => true]
    );

    sendVerificationCodeEmail($user->email, $user->username, $code, 'E-posta adresinizi değiştirmek için önce mevcut e-postanızı doğrulamanız gerekiyor. Doğrulama kodunuz:');
    jsonResponse(['success' => true, 'message' => 'Doğrulama kodu mevcut e-posta adresinize gönderildi.']);

} elseif ($action === 'verify_old_code') {
    $code = trim($input['code'] ?? '');
    $req = $db->email_change_requests->findOne(['_id' => $userId]);

    if (!$req || $req->old_expires_at->toDateTime()->getTimestamp() < time()) {
        jsonResponse(['success' => false, 'message' => 'Kodun süresi dolmuş, lütfen yeniden isteyin.'], 400);
    }
    if ($req->old_attempts >= $MAX_ATTEMPTS) {
        jsonResponse(['success' => false, 'message' => 'Çok fazla hatalı deneme. Lütfen yeni bir kod isteyin.'], 429);
    }
    if (!password_verify($code, $req->old_code_hash)) {
        $db->email_change_requests->updateOne(['_id' => $userId], ['$inc' => ['old_attempts' => 1]]);
        jsonResponse(['success' => false, 'message' => 'Kod hatalı.'], 400);
    }

    $db->email_change_requests->updateOne(['_id' => $userId], ['$set' => ['old_verified' => true]]);
    jsonResponse(['success' => true, 'message' => 'Mevcut e-posta doğrulandı. Şimdi yeni e-posta adresinizi girin.']);

} elseif ($action === 'request_new_code') {
    $newEmail = trim($input['new_email'] ?? '');
    $req = $db->email_change_requests->findOne(['_id' => $userId]);

    if (!$req || !$req->old_verified) {
        jsonResponse(['success' => false, 'message' => 'Önce mevcut e-postanızı doğrulamalısınız.'], 403);
    }
    if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['success' => false, 'message' => 'Geçerli bir e-posta adresi giriniz!'], 400);
    }
    if (strtolower($newEmail) === strtolower($user->email)) {
        jsonResponse(['success' => false, 'message' => 'Yeni e-posta mevcut e-postayla aynı olamaz!'], 400);
    }
    if ($db->users->findOne(['email' => $newEmail])) {
        jsonResponse(['success' => false, 'message' => 'Bu e-posta adresi zaten kullanımda!'], 400);
    }

    $code = generateCode();
    $db->email_change_requests->updateOne(
        ['_id' => $userId],
        ['$set' => [
            'new_email' => $newEmail,
            'new_code_hash' => password_hash($code, PASSWORD_DEFAULT),
            'new_expires_at' => new MongoDB\BSON\UTCDateTime((time() + $CODE_TTL_SECONDS) * 1000),
            'new_attempts' => 0,
        ]]
    );

    sendVerificationCodeEmail($newEmail, $user->username, $code, 'Bu e-posta adresini hesabınıza bağlamak için doğrulama kodunuz:');
    jsonResponse(['success' => true, 'message' => 'Doğrulama kodu yeni e-posta adresine gönderildi.']);

} elseif ($action === 'verify_new_code') {
    $code = trim($input['code'] ?? '');
    $req = $db->email_change_requests->findOne(['_id' => $userId]);

    if (!$req || !$req->old_verified || !$req->new_email) {
        jsonResponse(['success' => false, 'message' => 'Geçersiz istek, baştan başlayın.'], 400);
    }
    if ($req->new_expires_at->toDateTime()->getTimestamp() < time()) {
        jsonResponse(['success' => false, 'message' => 'Kodun süresi dolmuş, lütfen yeniden isteyin.'], 400);
    }
    if ($req->new_attempts >= $MAX_ATTEMPTS) {
        jsonResponse(['success' => false, 'message' => 'Çok fazla hatalı deneme. Lütfen yeni bir kod isteyin.'], 429);
    }
    if (!password_verify($code, $req->new_code_hash)) {
        $db->email_change_requests->updateOne(['_id' => $userId], ['$inc' => ['new_attempts' => 1]]);
        jsonResponse(['success' => false, 'message' => 'Kod hatalı.'], 400);
    }

    $db->users->updateOne(
        ['_id' => $userId],
        ['$set' => ['email' => $req->new_email, 'updated_at' => new MongoDB\BSON\UTCDateTime()]]
    );
    $db->email_change_requests->deleteOne(['_id' => $userId]);
    $_SESSION['email'] = $req->new_email;

    jsonResponse(['success' => true, 'message' => 'E-posta adresiniz güncellendi!', 'new_email' => $req->new_email]);

} else {
    jsonResponse(['success' => false, 'message' => 'Geçersiz işlem'], 400);
}
?>
