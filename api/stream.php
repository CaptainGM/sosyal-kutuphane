<?php
require_once 'config.php';

// Canlı akış (Server-Sent Events). Her bağlantı en fazla 25 saniye açık kalır; tarayıcı
// kendiliğinden yeniden bağlanır. Uzun süren bir istek PHP sunucusunun bir sürecini tutar,
// bu yüzden yalnızca ENABLE_SSE=1 ve PHP_CLI_SERVER_WORKERS ile çok süreçli çalışan
// sunucuda açılır. Kapalıyken 404 döner ve istemci eski yoklamaya geçer.
if (getenv('ENABLE_SSE') !== '1') {
    http_response_code(404);
    exit;
}
if (!isLoggedIn()) {
    http_response_code(401);
    exit;
}

$userId = (int) getCurrentUserId();
$conversationId = isset($_GET['conversation_id']) ? (int) $_GET['conversation_id'] : 0;

// Oturum kilidini bırak: aksi halde bu uzun istek aynı kullanıcının diğer istekleri bitene kadar bekler.
session_write_close();

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
set_time_limit(0);
while (ob_get_level() > 0) {
    ob_end_flush();
}

$deadline = time() + 25;
$lastCounts = null;
$lastTyping = null;

echo "retry: 3000\n\n";
flush();

while (time() < $deadline) {
    if (connection_aborted()) {
        break;
    }

    $counts = ['notifications' => $db->notifications->countDocuments(['user_id' => $userId, 'is_read' => false])];
    if ($counts !== $lastCounts) {
        echo "event: counts\ndata: " . json_encode($counts) . "\n\n";
        $lastCounts = $counts;
    }

    if ($conversationId > 0) {
        $conversation = $db->conversations->findOne(['_id' => $conversationId]);
        $isParticipant = $conversation && ($conversation->participant_a === $userId || $conversation->participant_b === $userId);
        if ($isParticipant) {
            $otherId = $conversation->participant_a === $userId ? $conversation->participant_b : $conversation->participant_a;
            // typing-status.php ile aynı kural: son 4 saniyede yazdıysa "yazıyor".
            $cutoff = new MongoDB\BSON\UTCDateTime((time() - 4) * 1000);
            $typing = $db->typing_status->findOne([
                'conversation_id' => $conversationId,
                'user_id' => $otherId,
                'updated_at' => ['$gte' => $cutoff],
            ]) !== null;
            if ($typing !== $lastTyping) {
                echo "event: typing\ndata: " . json_encode(['typing' => $typing]) . "\n\n";
                $lastTyping = $typing;
            }
        }
    }

    // Kalp atışı: bağlantıyı canlı tutar ve kopmayı en geç 2 saniyede algılar.
    echo ": ping\n\n";
    flush();
    sleep(2);
}
