<?php
require_once 'config.php';
require_once __DIR__ . '/lib/password.php';
require_once 'mail-config.php';
require_once __DIR__ . '/lib/rate-limit.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Geçersiz istek'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? 'register';
$CODE_TTL_SECONDS = 600; // 10 dakika
$MAX_ATTEMPTS = 5;

function generateRegCode(): string {
    return (string) random_int(100000, 999999);
}

function sendRegistrationCodeEmail(string $toEmail, string $username, string $code): bool {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        // Sunucu 10 sn içinde yanıt vermeli: uzayan SMTP bağlantısı isteği Render'ın zaman aşımına düşürmesin.
        $mail->Timeout = 10;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USERNAME;
        $mail->Password = SMTP_PASSWORD;
        $mail->SMTPSecure = 'tls';
        $mail->Port = SMTP_PORT;
        $mail->CharSet = 'UTF-8';

        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($toEmail);
        $mail->isHTML(true);
        $mail->Subject = 'Sosyal Kütüphane - Hesap Doğrulama Kodu';
        $mail->Body = '
        <div style="font-family: Arial, sans-serif; max-width: 500px; margin: 0 auto;">
            <h2 style="color:#667eea;">🎬 Sosyal Kütüphane</h2>
            <p>Merhaba <strong>' . htmlspecialchars($username) . '</strong>,</p>
            <p>Hesabını oluşturmak için doğrulama kodun:</p>
            <p style="font-size: 32px; font-weight: bold; letter-spacing: 6px; text-align: center; padding: 20px; background: #f8f9fa; border-radius: 8px;">' . htmlspecialchars($code) . '</p>
            <p>Bu kod 10 dakika geçerlidir. Bu kaydı siz başlatmadıysanız bu e-postayı yok sayabilirsiniz.</p>
        </div>';
        $mail->AltBody = "Doğrulama kodunuz: $code (10 dakika geçerlidir)";
        if (!sendMailPrepared($mail)) {
            throw new Exception($mail->ErrorInfo);
        }
        // Gönderim Brevo tarafından kabul edildi. Teslimat ayrıca Brevo panelinde izlenir.
        error_log('Kayıt doğrulama e-postası kabul edildi: ' . maskEmailForLog($toEmail));
        return true;
    } catch (Exception $e) {
        // Kod burada günlüğe yazılmaz: günlük erişimi olan biri hesabı doğrulayamasın.
        error_log('Kayıt doğrulama e-postası gönderilemedi (' . maskEmailForLog($toEmail) . '): ' . $mail->ErrorInfo);
        return false;
    }
}

if ($action === 'verify') {
    $email = (is_string($input['email'] ?? null) ? trim($input['email']) : '');
    $code = (is_string($input['code'] ?? null) ? trim($input['code']) : '');

    if (!$email || !$code) {
        jsonResponse(['success' => false, 'message' => 'E-posta ve kod gereklidir'], 400);
    }

    $pending = $db->pending_registrations->findOne(['email' => $email]);
    if (!$pending) {
        jsonResponse(['success' => false, 'message' => 'Bekleyen bir kayıt bulunamadı, lütfen yeniden kayıt olun'], 404);
    }
    if ($pending->expires_at->toDateTime()->getTimestamp() < time()) {
        jsonResponse(['success' => false, 'message' => 'Kodun süresi dolmuş, lütfen yeniden kayıt olun'], 400);
    }
    if ($pending->attempts >= $MAX_ATTEMPTS) {
        jsonResponse(['success' => false, 'message' => 'Çok fazla hatalı deneme, lütfen yeniden kayıt olun'], 429);
    }
    if (!password_verify($code, $pending->code_hash)) {
        $db->pending_registrations->updateOne(['email' => $email], ['$inc' => ['attempts' => 1]]);
        jsonResponse(['success' => false, 'message' => 'Kod hatalı'], 400);
    }

    // Kod doğru — ama bekleme sırasında biri aynı kullanıcı adı/e-postayla kayıt
    // olmuş olabilir, hesabı gerçekten oluşturmadan hemen önce tekrar kontrol et.
    if ($db->users->findOne(['email' => $pending->email])) {
        $db->pending_registrations->deleteOne(['email' => $email]);
        jsonResponse(['success' => false, 'message' => 'Bu e-posta adresi bu sırada başka biri tarafından kullanılmış'], 400);
    }
    if ($db->users->findOne(['username' => $pending->username])) {
        $db->pending_registrations->deleteOne(['email' => $email]);
        jsonResponse(['success' => false, 'message' => 'Bu kullanıcı adı bu sırada başka biri tarafından alınmış'], 400);
    }

    $newUserId = nextSequence($db, 'users');
    $db->users->insertOne([
        '_id' => $newUserId,
        'username' => $pending->username,
        'email' => $pending->email,
        'password' => $pending->password_hash,
        'bio' => null,
        'avatar_url' => null,
        'created_at' => new MongoDB\BSON\UTCDateTime(),
        'updated_at' => new MongoDB\BSON\UTCDateTime(),
    ]);
    $db->pending_registrations->deleteOne(['email' => $email]);

    startAuthenticatedSession($newUserId, $pending->username, $pending->email);

    jsonResponse([
        'success' => true,
        'message' => 'Hesap doğrulandı!',
        'user' => ['id' => $newUserId, 'username' => $pending->username, 'email' => $pending->email],
        'csrf_token' => csrfToken()
    ], 201);

} elseif ($action === 'resend') {
    $email = (is_string($input['email'] ?? null) ? trim($input['email']) : '');
    $pending = $db->pending_registrations->findOne(['email' => $email]);
    if (!$pending) {
        jsonResponse(['success' => false, 'message' => 'Bekleyen bir kayıt bulunamadı, lütfen yeniden kayıt olun'], 404);
    }
    if ($pending->created_at->toDateTime()->getTimestamp() > (time() - 60)) {
        jsonResponse(['success' => false, 'message' => 'Az önce bir kod gönderildi, lütfen biraz bekleyin'], 429);
    }

    // Dakikada bir sınırına ek olarak saatte 10 kod: başkasının kutusunu doldurmak için kullanılamasın.
    if (rateLimitHit($db, 'register-resend:' . sha1(strtolower($email)), 10, 3600)) {
        jsonResponse(['success' => false, 'message' => 'Bu adres için çok fazla kod istendi. Bir saat sonra tekrar dene.'], 429);
    }

    $code = generateRegCode();
    $db->pending_registrations->updateOne(
        ['email' => $email],
        ['$set' => [
            'code' => $code,
            'code_hash' => password_hash($code, PASSWORD_DEFAULT),
            'expires_at' => new MongoDB\BSON\UTCDateTime((time() + $CODE_TTL_SECONDS) * 1000),
            'attempts' => 0,
            'created_at' => new MongoDB\BSON\UTCDateTime(),
        ]]
    );
    if (!sendRegistrationCodeEmail($pending->email, $pending->username, $code)) {
        jsonResponse(['success' => false, 'message' => 'E-posta şu an gönderilemedi, birkaç dakika sonra tekrar dene.'], 503);
    }
    jsonResponse(['success' => true, 'message' => 'Yeni kod gönderildi']);

} else {
    $username = (is_string($input['username'] ?? null) ? trim($input['username']) : '');
    $email = (is_string($input['email'] ?? null) ? trim($input['email']) : '');
    $password = (is_string($input['password'] ?? null) ? trim($input['password']) : '');

    if (empty($username) || empty($email) || empty($password)) {
        jsonResponse(['success' => false, 'message' => 'Tüm alanlar gereklidir!'], 400);
    }
    if (strlen($username) < 3 || strlen($username) > 30) {
        jsonResponse(['success' => false, 'message' => 'Kullanıcı adı 3-30 karakter olmalıdır!'], 400);
    }
    if (!preg_match('/^[\p{L}0-9_.]+$/u', $username)) {
        jsonResponse(['success' => false, 'message' => 'Kullanıcı adı yalnızca harf, rakam, alt çizgi ve nokta içerebilir!'], 400);
    }
    $passwordError = passwordPolicyError($password);
    if ($passwordError !== null) {
        jsonResponse(['success' => false, 'message' => $passwordError], 400);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['success' => false, 'message' => 'Geçerli bir e-posta adresi giriniz!'], 400);
    }
    if ($db->users->findOne(['email' => $email])) {
        jsonResponse(['success' => false, 'message' => 'Bu e-posta adresi zaten kullanımda!'], 400);
    }
    if ($db->users->findOne(['username' => $username])) {
        jsonResponse(['success' => false, 'message' => 'Bu kullanıcı adı zaten alınmış!'], 400);
    }
    // Aynı kullanıcı adını başka bir e-postayla doğrulama bekleyen biri olabilir.
    if ($db->pending_registrations->findOne(['username' => $username, 'email' => ['$ne' => $email]])) {
        jsonResponse(['success' => false, 'message' => 'Bu kullanıcı adı için doğrulama bekleyen bir kayıt var, birkaç dakika sonra tekrar deneyin'], 400);
    }

    // Aynı adrese saatte en fazla 5 kayıt denemesi: başkasının kutusuna art arda doğrulama e-postası yağdırılamasın.
    if (rateLimitHit($db, 'register-email:' . sha1(strtolower($email)), 5, 3600)) {
        jsonResponse(['success' => false, 'message' => 'Bu e-posta adresi için çok fazla deneme yapıldı. Bir saat sonra tekrar dene.'], 429);
    }

    $code = generateRegCode();
    $db->pending_registrations->updateOne(
        ['email' => $email],
        ['$set' => [
            'username' => $username,
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'code' => $code,
            'code_hash' => password_hash($code, PASSWORD_DEFAULT),
            'expires_at' => new MongoDB\BSON\UTCDateTime((time() + $CODE_TTL_SECONDS) * 1000),
            'attempts' => 0,
            'created_at' => new MongoDB\BSON\UTCDateTime(),
        ]],
        ['upsert' => true]
    );

    // Gönderilemezse kayıt yine de bekler (kod sunucu loguna yazılır); istemci bunu açıkça gösterir.
    $emailSent = sendRegistrationCodeEmail($email, $username, $code);

    jsonResponse([
        'success' => true,
        'pending_verification' => true,
        'email_sent' => $emailSent,
        'message' => $emailSent ? 'Doğrulama kodu e-postana gönderildi.' : 'Doğrulama kodu hazırlandı ama e-posta gönderilemedi.',
        'email' => $email
    ]);
}
?>
