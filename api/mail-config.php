<?php
define('SMTP_HOST', getenv('SMTP_HOST') ?: 'smtp.gmail.com');
define('SMTP_PORT', getenv('SMTP_PORT') ?: 587);
define('SMTP_USERNAME', getenv('SMTP_USERNAME') ?: '');
define('SMTP_PASSWORD', getenv('SMTP_PASSWORD') ?: '');
// Gönderen adresi ayrı ayarlanabilir: Brevo gibi servislerde SMTP giriş adresi (…@smtp-brevo.com) gönderen olamaz.
define('SMTP_FROM_EMAIL', getenv('SMTP_FROM_EMAIL') ?: (getenv('SMTP_USERNAME') ?: 'your-email@gmail.com'));
define('SMTP_FROM_NAME', getenv('SMTP_FROM_NAME') ?: 'Sosyal Kütüphane');
// Gönderim yolu: BREVO_API_KEY varsa HTTPS API, yoksa SMTP (bkz. lib/mail.php).
require_once __DIR__ . '/lib/mail.php';

// E-posta gönderimi için bir yol ayarlı mı? (Brevo anahtarı ya da SMTP kullanıcı/şifre). Ayarsız ortamda (test, yerel)
// isteğe bağlı bildirim e-postaları hiç denenmez; böylece istekler bağlantı zaman aşımını beklemez.
function mailIsConfigured(): bool {
    return (string) getenv('BREVO_API_KEY') !== '' || ((string) getenv('SMTP_USERNAME') !== '' && (string) getenv('SMTP_PASSWORD') !== '');
}

// Tek alıcılı, HTML + düz metin gövdeli e-posta hazırlar; gönderimi sendMailPrepared() yapar.
function mailCompose(string $toEmail, string $subject, string $html, string $text): PHPMailer\PHPMailer\PHPMailer {
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = SMTP_HOST;
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
    $mail->Subject = $subject;
    $mail->Body = $html;
    $mail->AltBody = $text;
    return $mail;
}

// Günlüğe yazılacak e-posta adresini kısaltır: ornek@gmail.com -> o***@gmail.com
function maskEmailForLog(string $email): string {
    return preg_replace('/^(.).*(@.*)$/', '$1***$2', $email) ?? '***';
}
?>
