<?php
// Ortak okuma / izleme: karşılıklı takip eden iki kullanıcı aynı yapımı seçer; her biri kendi ilerlemesini işaretler.
// Davet yalnızca karşılıklı takipler arasında gönderilir. Davet kabul edilene kadar karşı tarafın ilerlemesi görünmez.
// Demo hesaplar davet gönderemez ve kabul edemez (başkalarını etkileyen işlemler).
require_once 'config.php';
require_once __DIR__ . '/lib/demo.php';
require_once __DIR__ . '/lib/push.php';

requireLogin();

$method = $_SERVER['REQUEST_METHOD'];
$userId = (int) getCurrentUserId();
$input = json_decode(file_get_contents('php://input'), true);
$input = is_array($input) ? $input : [];

const BUDDY_TYPES = ['movie', 'series', 'book'];
const BUDDY_MAX_PROGRESS = 100000;

// Kullanıcı kayıtlarını (ad ve avatar) tek sorguda getirir.
function buddyUsers($db, array $ids): array {
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $users = [];
    if (!$ids) {
        return $users;
    }
    foreach ($db->users->find(['_id' => ['$in' => $ids]], ['projection' => ['username' => 1, 'avatar_url' => 1, 'is_demo' => 1]]) as $u) {
        $users[(int) $u['_id']] = [
            'id' => (int) $u['_id'],
            'username' => (string) ($u['username'] ?? ''),
            'avatar_url' => $u['avatar_url'] ?? null,
            'is_demo' => !empty($u['is_demo']),
        ];
    }
    return $users;
}

// İki yönlü takip var mı, engel yok mu?
function buddyCanPair($db, int $a, int $b): bool {
    $both = $db->follows->countDocuments(['$or' => [
        ['follower_id' => $a, 'following_id' => $b],
        ['follower_id' => $b, 'following_id' => $a],
    ]]);
    if ($both < 2) {
        return false;
    }
    $blocked = $db->blocks->findOne(['$or' => [
        ['blocker_id' => $a, 'blocked_id' => $b],
        ['blocker_id' => $b, 'blocked_id' => $a],
    ]]);
    return $blocked === null;
}

function buddyContentFromInput(array $input): ?array {
    $type = $input['content_type'] ?? null;
    if (!is_string($type) || !in_array($type, BUDDY_TYPES, true)) {
        return null;
    }
    $rawId = $input['content_id'] ?? null;
    if ($type === 'book') {
        $id = is_string($rawId) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $rawId) ? $rawId : null;
    } else {
        $id = filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $id = $id === false ? null : (int) $id;
    }
    $title = is_string($input['title'] ?? null) ? mb_substr(trim($input['title']), 0, 200) : '';
    if ($id === null || $title === '') {
        return null;
    }
    $poster = is_string($input['poster'] ?? null) && preg_match('~^https://\S{5,500}$~', $input['poster']) ? $input['poster'] : null;
    return ['content_type' => $type, 'content_id' => $id, 'title' => $title, 'poster' => $poster];
}

// Ortak okumanın bir katılımcısı mı? Döner: 'owner' | 'partner' | null.
function buddyRole($doc, int $userId): ?string {
    if ((int) $doc['owner_id'] === $userId) {
        return 'owner';
    }
    if ((int) $doc['partner_id'] === $userId) {
        return 'partner';
    }
    return null;
}

if ($method === 'GET') {
    if (($_GET['action'] ?? '') === 'friends') {
        // Davet edilebilecek kişiler: karşılıklı takip, engel yok, demo ve askıdaki hesaplar hariç.
        $following = array_map(static fn ($f) => (int) $f['following_id'], iterator_to_array($db->follows->find(['follower_id' => $userId], ['projection' => ['following_id' => 1]]), false));
        $followers = array_map(static fn ($f) => (int) $f['follower_id'], iterator_to_array($db->follows->find(['following_id' => $userId], ['projection' => ['follower_id' => 1]]), false));
        $mutual = array_values(array_intersect($following, $followers));
        $users = buddyUsers($db, $mutual);
        $friends = [];
        foreach ($mutual as $friendId) {
            $u = $users[$friendId] ?? null;
            if ($u === null || $u['is_demo'] || !buddyCanPair($db, $userId, $friendId)) {
                continue;
            }
            $friends[] = ['id' => $u['id'], 'username' => $u['username'], 'avatar_url' => $u['avatar_url']];
        }
        jsonResponse(['success' => true, 'friends' => $friends]);
    }

    $docs = iterator_to_array($db->buddy_reads->find(
        ['$or' => [['owner_id' => $userId], ['partner_id' => $userId]]],
        ['sort' => ['updated_at' => -1], 'limit' => 100]
    ), false);
    $participantIds = [];
    foreach ($docs as $d) {
        $participantIds[] = (int) $d['owner_id'];
        $participantIds[] = (int) $d['partner_id'];
    }
    $users = buddyUsers($db, $participantIds);

    $items = [];
    foreach ($docs as $doc) {
        $role = buddyRole($doc, $userId);
        $otherRole = $role === 'owner' ? 'partner' : 'owner';
        $otherId = (int) $doc[$otherRole . '_id'];
        $items[] = [
            'id' => (int) $doc['_id'],
            'status' => $doc['status'],
            'role' => $role,
            'content_type' => $doc['content_type'],
            'content_id' => $doc['content_id'],
            'title' => $doc['title'],
            'poster' => $doc['poster'] ?? null,
            'partner' => $users[$otherId] ?? ['id' => $otherId, 'username' => '', 'avatar_url' => null],
            // Karşı taraf daveti kabul etmeden onun ilerlemesi gösterilmez.
            'me' => ['progress' => (int) ($doc['progress_' . $role] ?? 0), 'done' => !empty($doc['done_' . $role])],
            'them' => $doc['status'] === 'active'
                ? ['progress' => (int) ($doc['progress_' . $otherRole] ?? 0), 'done' => !empty($doc['done_' . $otherRole])]
                : null,
        ];
    }
    jsonResponse(['success' => true, 'items' => $items]);
}

if ($method !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Geçersiz istek'], 405);
}

requireCsrf();
$action = $input['action'] ?? '';
if (!in_array($action, ['create', 'respond', 'progress', 'done', 'leave'], true)) {
    jsonResponse(['success' => false, 'message' => 'Bilinmeyen işlem'], 400);
}
requireNotDemo();

$me = buddyUsers($db, [$userId])[$userId] ?? ['username' => 'Birisi'];

if ($action === 'create') {
    $content = buddyContentFromInput($input);
    $partnerId = filter_var($input['partner_id'] ?? null, FILTER_VALIDATE_INT);
    if ($content === null || !$partnerId || (int) $partnerId === $userId) {
        jsonResponse(['success' => false, 'message' => 'Geçersiz davet'], 400);
    }
    $partnerId = (int) $partnerId;
    $partner = buddyUsers($db, [$partnerId])[$partnerId] ?? null;
    if ($partner === null || $partner['is_demo'] || !buddyCanPair($db, $userId, $partnerId)) {
        jsonResponse(['success' => false, 'message' => 'Bu kişi ile ortak okuma başlatamazsın. Karşılıklı takip gerekir.'], 403);
    }
    $existing = $db->buddy_reads->findOne(['$or' => [
        ['owner_id' => $userId, 'partner_id' => $partnerId],
        ['owner_id' => $partnerId, 'partner_id' => $userId],
    ], 'content_type' => $content['content_type'], 'content_id' => $content['content_id']]);
    if ($existing) {
        jsonResponse(['success' => false, 'message' => 'Bu yapım için zaten bir ortak okuma var'], 409);
    }

    $now = new MongoDB\BSON\UTCDateTime();
    $id = nextSequence($db, 'buddy_reads');
    $db->buddy_reads->insertOne($content + [
        '_id' => $id,
        'owner_id' => $userId,
        'partner_id' => $partnerId,
        'status' => 'pending',
        'progress_owner' => 0,
        'progress_partner' => 0,
        'done_owner' => false,
        'done_partner' => false,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $db->notifications->insertOne([
        '_id' => nextSequence($db, 'notifications'),
        'user_id' => $partnerId,
        'actor_id' => $userId,
        'type' => 'buddy',
        'content_type' => $content['content_type'],
        'content_id' => $content['content_id'],
        'content_title' => $me['username'] . ' seni «' . $content['title'] . '» için ortak okumaya davet etti',
        'is_read' => false,
        'created_at' => $now,
    ]);
    pushNotifyUser($db, $partnerId, $me['username'] . ' seni ortak okumaya davet etti', 'profile.html#buddyCard');
    jsonResponse(['success' => true, 'id' => $id, 'message' => 'Davet gönderildi']);
}

$doc = $db->buddy_reads->findOne(['_id' => (int) ($input['id'] ?? 0)]);
$role = $doc ? buddyRole($doc, $userId) : null;
if (!$doc || $role === null) {
    jsonResponse(['success' => false, 'message' => 'Ortak okuma bulunamadı'], 404);
}
$now = new MongoDB\BSON\UTCDateTime();

if ($action === 'respond') {
    if ($role !== 'partner' || $doc['status'] !== 'pending') {
        jsonResponse(['success' => false, 'message' => 'Bu daveti yanıtlayamazsın'], 403);
    }
    $accept = filter_var($input['accept'] ?? false, FILTER_VALIDATE_BOOLEAN);
    if (!$accept) {
        $db->buddy_reads->deleteOne(['_id' => (int) $doc['_id']]);
        jsonResponse(['success' => true, 'message' => 'Davet reddedildi']);
    }
    $db->buddy_reads->updateOne(['_id' => (int) $doc['_id']], ['$set' => ['status' => 'active', 'updated_at' => $now]]);
    $ownerId = (int) $doc['owner_id'];
    $db->notifications->insertOne([
        '_id' => nextSequence($db, 'notifications'),
        'user_id' => $ownerId,
        'actor_id' => $userId,
        'type' => 'buddy',
        'content_type' => $doc['content_type'],
        'content_id' => $doc['content_id'],
        'content_title' => $me['username'] . ' «' . $doc['title'] . '» ortak okumasını kabul etti',
        'is_read' => false,
        'created_at' => $now,
    ]);
    pushNotifyUser($db, $ownerId, $me['username'] . ' ortak okumanı kabul etti', 'profile.html#buddyCard');
    jsonResponse(['success' => true, 'message' => 'Davet kabul edildi']);
}

if ($action === 'leave') {
    $db->buddy_reads->deleteOne(['_id' => (int) $doc['_id']]);
    jsonResponse(['success' => true, 'message' => $doc['status'] === 'pending' ? 'Davet geri çekildi' : 'Ortak okumadan ayrıldın']);
}

// İlerleme ve tamamlama yalnızca kabul edilmiş ortak okumada, yalnızca kendi kaydında değişir.
if ($doc['status'] !== 'active') {
    jsonResponse(['success' => false, 'message' => 'Davet henüz kabul edilmedi'], 409);
}

if ($action === 'progress') {
    if ($doc['content_type'] === 'movie') {
        jsonResponse(['success' => false, 'message' => 'Filmlerde bölüm ya da sayfa yok; tamamladım işaretini kullan'], 400);
    }
    $value = filter_var($input['value'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => BUDDY_MAX_PROGRESS]]);
    if ($value === false) {
        jsonResponse(['success' => false, 'message' => 'Geçersiz ilerleme'], 400);
    }
    $db->buddy_reads->updateOne(['_id' => (int) $doc['_id']], ['$set' => ['progress_' . $role => (int) $value, 'updated_at' => $now]]);
    jsonResponse(['success' => true, 'message' => 'İlerleme kaydedildi']);
}

// action === 'done'
$done = filter_var($input['done'] ?? false, FILTER_VALIDATE_BOOLEAN);
$db->buddy_reads->updateOne(['_id' => (int) $doc['_id']], ['$set' => ['done_' . $role => $done, 'updated_at' => $now]]);
jsonResponse(['success' => true, 'message' => $done ? 'Tamamladın olarak işaretlendi' : 'İşaret kaldırıldı']);
