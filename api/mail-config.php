<?php
define('SMTP_HOST', getenv('SMTP_HOST') ?: 'smtp.gmail.com');
define('SMTP_PORT', getenv('SMTP_PORT') ?: 587);
define('SMTP_USERNAME', getenv('SMTP_USERNAME') ?: '');
define('SMTP_PASSWORD', getenv('SMTP_PASSWORD') ?: '');
define('SMTP_FROM_EMAIL', getenv('SMTP_USERNAME') ?: 'your-email@gmail.com');
define('SMTP_FROM_NAME', 'Sosyal Kütüphane');
?>
