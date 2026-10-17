<?php
// Demo hesaplar (ahmet, ayse, test): herkesin deneyebildiği tanıtım hesapları.
// Kütüphane, listeler ve izleme durumu kullanılabilir; ama başkalarını etkileyen hiçbir şey yapılamaz:
// yorum, yanıt, beğeni, puan, şikâyet, takip, engelleme ve mesaj. Her girişte kişisel veriler sıfırlanır,
// böylece herkes boş bir kütüphaneyle başlar.

const DEMO_EMAILS = ['test@test.com', 'ahmet@test.com', 'ayse@test.com'];

function isDemoUser($db, $userId): bool {
    if ($userId === null) {
        return false;
    }
    $user = $db->users->findOne(['_id' => (int) $userId], ['projection' => ['is_demo' => 1]]);
    return !empty($user['is_demo']);
}

// Başkalarını etkileyen işlemlerin başında çağrılır (giriş yapmış olmak yeterli değildir).
function requireNotDemo(): void {
    global $db;
    if (isDemoUser($db, getCurrentUserId())) {
        jsonResponse(['success' => false, 'message' => 'Demo hesaplar bu işlemi yapamaz. Kendi hesabını oluşturarak deneyebilirsin.'], 403);
    }
}

// Demo kullanıcının kişisel verilerini siler. Yalnızca bu kullanıcının kayıtlarına dokunur.
function resetDemoAccount($db, int $userId): void {
    $listIds = [];
    foreach ($db->custom_lists->find(['user_id' => $userId], ['projection' => ['_id' => 1]]) as $list) {
        $listIds[] = $list['_id'];
    }
    if ($listIds) {
        $db->custom_list_items->deleteMany(['list_id' => ['$in' => $listIds]]);
    }
    $db->custom_lists->deleteMany(['user_id' => $userId]);

    foreach (['user_movie_status', 'user_series_status', 'user_book_status', 'user_episode_progress', 'release_reminders'] as $collection) {
        $db->$collection->deleteMany(['user_id' => $userId]);
    }
    $db->notifications->deleteMany(['user_id' => $userId]);
}
