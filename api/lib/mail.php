<?php
// Uygulama e-postaları (doğrulama kodu, şifre sıfırlama, e-posta değişikliği) için gönderim.
// BREVO_API_KEY tanımlıysa Brevo HTTPS API'si (443. port) kullanılır. Render gibi barındırıcılar dışarı giden
// SMTP portunu (587) engelleyebildiği için bu yol tercih edilir. Anahtar yoksa PHPMailer ile SMTP'ye düşülür.
// Çağıran taraf PHPMailer nesnesini hazırlar (alıcı, konu, gövde); burada yalnızca gönderim yolu seçilir.

function sendMailPrepared(PHPMailer\PHPMailer\PHPMailer $mail): bool {
    $apiKey = getenv('BREVO_API_KEY') ?: '';
    if ($apiKey === '') {
        return $mail->send();
    }

    $to = [];
    foreach ($mail->getToAddresses() as [$address, $name]) {
        $to[] = $name !== '' ? ['email' => $address, 'name' => $name] : ['email' => $address];
    }
    $payload = [
        'sender' => ['email' => $mail->From, 'name' => $mail->FromName],
        'to' => $to,
        'subject' => $mail->Subject,
        'htmlContent' => $mail->Body,
        'textContent' => $mail->AltBody,
    ];

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['api-key: ' . $apiKey, 'Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($status === 201) {
        return true;
    }
    // Brevo 201 dışında bir cevap verdiyse (ör. 401 anahtar hatası, 400 gönderen doğrulanmamış) nedenini günlüğe yazılır.
    $mail->ErrorInfo = 'Brevo API HTTP ' . $status . ($curlError !== '' ? ' (' . $curlError . ')' : ': ' . substr((string) $response, 0, 300));
    return false;
}
