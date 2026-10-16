<?php
// Sosyal grafik yardımcıları. Takip akışı ve yorum akışı aynı kuralı kullanır:
// yalnızca takip edilenler, engellenen (iki yönde) kullanıcılar dışarıda bırakılır.

// Kullanıcının takip ettiği kullanıcıların kimlikleri.
function followedUserIds($db, int $userId): array {
    $ids = [];
    foreach ($db->follows->find(['follower_id' => $userId], ['projection' => ['following_id' => 1]]) as $follow) {
        $ids[(int) $follow['following_id']] = true;
    }
    foreach ($db->blocks->find(['$or' => [['blocker_id' => $userId], ['blocked_id' => $userId]]]) as $block) {
        $other = (int) $block['blocker_id'] === $userId ? (int) $block['blocked_id'] : (int) $block['blocker_id'];
        unset($ids[$other]);
    }
    return array_keys($ids);
}

// Akış satırlarını yeniden eskiye sıralar ve sınırlar. Her satırda 'at' (ISO 8601) bulunmalıdır.
function feedSortAndLimit(array $rows, int $limit): array {
    usort($rows, static fn (array $a, array $b): int => strcmp($b['at'], $a['at']));
    return array_slice($rows, 0, $limit);
}
