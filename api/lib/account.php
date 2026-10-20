<?php
// Hesabın ve ona bağlı tüm kişisel verinin kalıcı silinmesi. api/delete-account.php (kullanıcı kendi hesabını siler) ve
// scripts/e2e-user.php (test kullanıcıları) bu tek işlevi kullanır; böylece "hangi koleksiyonlarda veri var" listesi iki yerde
// ayrı ayrı güncellenmez. Yeni bir koleksiyona kullanıcı verisi yazılırsa buraya eklenmeli (tests/AccountDeletionTest.php tarar).
//
// Bilinçli kararlar (gizlilik sayfasında da yazılıdır):
//  - Kullanıcının yorumları silinir; bu yorumlara başkalarının yazdığı yanıtlar da gider (üst yorum kalmaz).
//  - Özel mesaj konuşmaları iki tarafta da silinir.
//  - Yöneticilerin işlem günlüğünde YÖNETİCİNİN yaptığı işlemler kalır (hesap sorumluluğu); kullanıcıyı hedef alan kayıtlar silinir.
require_once __DIR__ . '/avatar-store.php';
require_once __DIR__ . '/profile.php';

function deleteAccountData($db, int $userId): void {
    $user = $db->users->findOne(['_id' => $userId]);
    if (!$user) {
        return;
    }
    $email = (string) ($user['email'] ?? '');

    // --- Yorumlar ---
    $commentIds = [];
    foreach ($db->comments->find(['user_id' => $userId], ['projection' => ['_id' => 1]]) as $c) {
        $commentIds[] = $c['_id'];
    }
    $archivedIds = [];
    foreach ($db->deleted_comments->find(['comment.user_id' => $userId], ['projection' => ['comment._id' => 1]]) as $d) {
        $archivedIds[] = $d['comment']['_id'];
    }
    $replyIds = [];
    if ($commentIds) {
        foreach ($db->comments->find(['parent_comment_id' => ['$in' => $commentIds]], ['projection' => ['_id' => 1]]) as $r) {
            $replyIds[] = $r['_id'];
        }
    }
    $allCommentIds = array_values(array_unique(array_merge($commentIds, $archivedIds, $replyIds)));

    $db->comment_likes->deleteMany(['$or' => [['user_id' => $userId], ['comment_id' => ['$in' => $allCommentIds]]]]);
    $db->comment_reactions->deleteMany(['$or' => [['user_id' => $userId], ['comment_id' => ['$in' => $allCommentIds]]]]);
    $db->comment_reports->deleteMany(['$or' => [['reporter_id' => $userId], ['comment_id' => ['$in' => $allCommentIds]]]]);
    $db->comment_revisions->deleteMany(['comment_id' => ['$in' => $allCommentIds]]);
    $db->deleted_comments->deleteMany(['comment.user_id' => $userId]);
    // Başkasının silinmiş yorumunun arşivinde bu kullanıcının yanıtı varsa o da çıkarılır.
    $db->deleted_comments->updateMany(['replies.user_id' => $userId], ['$pull' => ['replies' => ['user_id' => $userId]]]);
    $db->comments->deleteMany(['_id' => ['$in' => $replyIds]]);
    $db->comments->deleteMany(['user_id' => $userId]);

    // --- Destek talepleri (konuşmalarıyla) ---
    $ticketIds = [];
    foreach ($db->support_tickets->find(['user_id' => $userId], ['projection' => ['_id' => 1]]) as $t) {
        $ticketIds[] = $t['_id'];
    }
    $db->support_tickets->deleteMany(['_id' => ['$in' => $ticketIds]]);

    // --- Yönetici kayıtları: kullanıcıyı, yorumlarını ve taleplerini hedef alanlar ---
    $db->audit_log->deleteMany(['$or' => [
        ['target_type' => 'user', 'target_id' => $userId],
        ['target_type' => 'comment', 'target_id' => ['$in' => $allCommentIds]],
        ['target_type' => 'ticket', 'target_id' => ['$in' => $ticketIds]],
    ]]);
    $db->moderation_events->deleteMany(['user_id' => $userId]);

    // --- Kütüphane, takip, bildirim, cihaz ve güvenlik kayıtları ---
    foreach (['user_movie_status', 'user_series_status', 'user_book_status', 'user_episode_progress', 'release_reminders', 'episode_notifications', 'login_history', 'push_subscriptions', 'typing_status'] as $collection) {
        $db->$collection->deleteMany(['user_id' => $userId]);
    }
    $db->notifications->deleteMany(['$or' => [['user_id' => $userId], ['actor_id' => $userId]]]);
    $db->follows->deleteMany(['$or' => [['follower_id' => $userId], ['following_id' => $userId]]]);
    $db->blocks->deleteMany(['$or' => [['blocker_id' => $userId], ['blocked_id' => $userId]]]);
    $db->buddy_reads->deleteMany(['$or' => [['owner_id' => $userId], ['partner_id' => $userId]]]);
    $db->email_change_requests->deleteMany(['$or' => [['_id' => $userId], ['user_id' => $userId]]]);
    if ($email !== '') {
        $db->password_resets->deleteMany(['email' => $email]);
        $db->pending_registrations->deleteMany(['email' => $email]);
    }

    // --- Özel listeler ---
    $listIds = [];
    foreach ($db->custom_lists->find(['user_id' => $userId], ['projection' => ['_id' => 1]]) as $l) {
        $listIds[] = $l['_id'];
    }
    if ($listIds) {
        $db->custom_list_items->deleteMany(['list_id' => ['$in' => $listIds]]);
        $db->custom_lists->deleteMany(['_id' => ['$in' => $listIds]]);
    }

    // --- Mesajlaşma: konuşmalar iki tarafta da silinir ---
    $conversationIds = [];
    foreach ($db->conversations->find(['$or' => [['participant_a' => $userId], ['participant_b' => $userId]]], ['projection' => ['_id' => 1]]) as $c) {
        $conversationIds[] = $c['_id'];
    }
    if ($conversationIds) {
        $db->messages->deleteMany(['conversation_id' => ['$in' => $conversationIds]]);
        $db->typing_status->deleteMany(['conversation_id' => ['$in' => $conversationIds]]);
        $db->conversations->deleteMany(['_id' => ['$in' => $conversationIds]]);
    }

    // --- Profil fotoğrafı: disk kopyası ve veritabanındaki baytlar ---
    $avatarUrl = $user['avatar_url'] ?? null;
    $avatarPath = profileUploadedAvatarPath(is_string($avatarUrl) ? $avatarUrl : null);
    if ($avatarPath !== null && is_file($avatarPath)) {
        @unlink($avatarPath);
    }
    avatarStoreDelete($db, is_string($avatarUrl) ? $avatarUrl : null);
    $db->avatar_files->deleteMany(['user_id' => $userId]);

    $db->users->deleteOne(['_id' => $userId]);
}
