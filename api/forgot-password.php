<?php
require_once 'config.php';
require_once 'mail-config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Geçersiz istek'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);
$email = trim($input['email'] ?? '');

if (empty($email)) {
    jsonResponse(['success' => false, 'message' => 'E-posta adresi gereklidir!'], 400);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(['success' => false, 'message' => 'Geçerli bir e-posta adresi giriniz!'], 400);
}

$user = $db->users->findOne(['email' => $email]);

// Kullanıcı bulunamasa bile aynı mesajı döndürüyoruz — böylece bu uç nokta
// hangi e-postaların kayıtlı olduğunu dışarıya sızdırmıyor (enumeration koruması).
if ($user) {
    $token = bin2hex(random_bytes(32));
    $expiresAt = new MongoDB\BSON\UTCDateTime((time() + 3600) * 1000);

    $db->password_resets->deleteMany(['email' => $email]);
    $db->password_resets->insertOne([
        'email' => $email,
        'token' => $token,
        'expires_at' => $expiresAt,
        'created_at' => new MongoDB\BSON\UTCDateTime(),
    ]);

    $resetLink = SITE_URL . '/reset-password.html?token=' . $token;
    sendPasswordResetEmail($email, $user->username, $resetLink);
    // Not: mail gönderimi başarısız olsa bile linki API yanıtında döndürmüyoruz —
    // bu, SMTP yapılandırılmamışken sıfırlama token'ının herkese açık sızmasını önler.
    // Yerel geliştirme sırasında linke ihtiyacın olursa PHP hata loguna bakabilirsin.
}

jsonResponse([
    'success' => true,
    'message' => 'Eğer bu e-posta sistemde kayıtlıysa, şifre sıfırlama linki gönderildi.'
], 200);

function sendPasswordResetEmail($toEmail, $username, $resetLink) {
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
        $mail->Subject = 'Sosyal Kütüphane - Şifre Sıfırlama';
        $mail->Body = '
        <html>
        <head>
            <meta charset="UTF-8">
        </head>
        <body style="font-family: Arial, sans-serif; background: #f5f5f5; padding: 20px; margin: 0;">
            <div style="max-width: 600px; margin: 0 auto; background: white; border-radius: 10px; padding: 30px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
                <div style="text-align: center; padding-bottom: 20px; border-bottom: 2px solid #667eea;">
                    <h1 style="color: #667eea; margin: 0;">🎬 Sosyal Kütüphane</h1>
                </div>
                <div style="padding: 30px 0;">
                    <p>Merhaba <strong>' . htmlspecialchars($username) . '</strong>,</p>
                    <p>Hesabınız için bir şifre sıfırlama talebi aldık. Şifrenizi sıfırlamak için aşağıdaki butona tıklayın:</p>
                    <p style="text-align: center; margin: 30px 0;">
                        <a href="' . $resetLink . '" style="display: inline-block; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 15px 30px; text-decoration: none; border-radius: 8px; font-weight: bold;">Şifremi Sıfırla</a>
                    </p>
                    <p>Bu link 1 saat geçerlidir.</p>
                    <p>Eğer bu talebi siz yapmadıysanız, bu e-postayı görmezden gelebilirsiniz.</p>
                    <p style="margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 5px; font-size: 12px; color: #666;">
                        Link çalışmazsa bu URL\'yi tarayıcınıza kopyalayın:<br>
                        <code style="word-break: break-all;">' . $resetLink . '</code>
                    </p>
                </div>
                <div style="text-align: center; padding-top: 20px; border-top: 1px solid #eee; color: #999; font-size: 12px;">
                    <p>Bu e-posta otomatik olarak gönderilmiştir.</p>
                    <p>© 2026 Sosyal Kütüphane</p>
                </div>
            </div>
        </body>
        </html>';

        $mail->AltBody = "Merhaba $username, Şifrenizi sıfırlamak için bu linki tarayıcınıza yapıştırın: $resetLink";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Mail gönderilemedi: " . $mail->ErrorInfo);
        error_log("Şifre sıfırlama linki (SMTP başarısız, gelişim amaçlı log): " . $resetLink);
        return false;
    }
}
?>
