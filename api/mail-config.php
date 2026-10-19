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

// Günlüğe yazılacak e-posta adresini kısaltır: ornek@gmail.com -> o***@gmail.com
function maskEmailForLog(string $email): string {
    return preg_replace('/^(.).*(@.*)$/', '$1***$2', $email) ?? '***';
}
?>
