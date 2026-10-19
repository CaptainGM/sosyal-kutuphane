<?php
// Sabit pencereli hız sınırı: aynı kovadaki (ör. "avatar-upload:12") istekler pencere başına en fazla $max kez geçer.
// Sayaç tek bir atomik güncellemeyle artar; süresi dolan kayıtlar rate_limits.expires_at TTL indeksiyle silinir.
// Giriş denemeleri için config.php'deki IP + e-posta sayacı ayrı kalır.

// true = sınır aşıldı (bu istek reddedilmeli), false = izin verildi. Reddedilen denemeler de sayılır.
function rateLimitHit($db, string $bucket, int $max, int $windowSeconds): bool {
    $windowStart = intdiv(time(), $windowSeconds) * $windowSeconds;
    $doc = $db->rate_limits->findOneAndUpdate(
        ['_id' => $bucket . '|' . $windowStart],
        [
            '$inc' => ['count' => 1],
            '$setOnInsert' => ['expires_at' => new MongoDB\BSON\UTCDateTime(($windowStart + $windowSeconds) * 1000)],
        ],
        ['upsert' => true, 'returnDocument' => MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER]
    );
    return (int) $doc['count'] > $max;
}
