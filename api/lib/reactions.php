<?php
// Yorumlara tepkiler: beğeniden farklı olarak duygu ifadesi. Her kullanıcı bir yoruma en fazla bir tepki verir.
// Tepki metin içermediği için küfür taraması gerekmez; spoiler kuralları yorumun kendisiyle birlikte geçerlidir.
const COMMENT_REACTIONS = ['laugh' => 'Güldüm', 'cry' => 'Ağladım', 'wow' => 'Şaşırdım', 'think' => 'Düşündürdü'];

// Bir yorumun tepki sayıları: tüm türler (sıfır olanlar dahil) sabit sırayla.
function commentReactionView(array $counts): array {
    $view = [];
    foreach (array_keys(COMMENT_REACTIONS) as $type) {
        $view[$type] = (int) ($counts[$type] ?? 0);
    }
    return $view;
}

// Tek bir yorumun tepki sayılarını veritabanından okur (tepki verme yanıtında kullanılır).
function commentReactionCounts($db, int $commentId): array {
    $counts = [];
    foreach ($db->comment_reactions->find(['comment_id' => $commentId]) as $r) {
        $counts[$r['type']] = ($counts[$r['type']] ?? 0) + 1;
    }
    return commentReactionView($counts);
}
