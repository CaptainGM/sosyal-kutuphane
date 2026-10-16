<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ApiHelpers.php';

// Paylaşılan (herkese açık) liste (çalışan sunucu ve MongoDB gerekir, bkz. ApiTest).
final class PublicListApiTest extends TestCase
{
    use ApiHelpers;

    private function makeList(ApiClient $client, string $name): int
    {
        $res = $client->post('/api/custom-lists.php', ['action' => 'create', 'name' => $name, 'description' => 'deneme listesi']);
        $this->assertTrue($res['data']['success'] ?? false, json_encode($res['data']));
        return (int) $res['data']['list_id'];
    }

    public function testPrivateListIsNotVisibleAndPublicListCarriesPostersAndOwner(): void
    {
        [$owner, $ownerId, , $ownerName] = $this->newUser('phpunit_pub_o');
        $movieId = 770000 + random_int(1, 99999);
        $owner->post('/api/user-status.php', ['content_type' => 'movie', 'content_id' => $movieId, 'status' => 'watched',
            'content_data' => ['title' => 'Liste Filmi', 'poster_path' => '/liste.jpg']]);

        $listId = $this->makeList($owner, 'Paylaşılacak liste');
        $owner->post('/api/custom-lists.php', ['action' => 'add_item', 'list_id' => $listId, 'content_type' => 'movie', 'content_id' => $movieId, 'content_title' => 'Liste Filmi']);
        $owner->post('/api/custom-lists.php', ['action' => 'add_item', 'list_id' => $listId, 'content_type' => 'book', 'content_id' => 'bilinmeyen_kitap', 'content_title' => 'Afişsiz Kitap']);

        $anonymous = new ApiClient(TEST_BASE_URL);
        $this->assertSame(404, $anonymous->get('/api/public-list.php?id=' . $listId)['status'], 'Gizli liste görünmemeli');

        $owner->post('/api/custom-lists.php', ['action' => 'toggle_public', 'list_id' => $listId, 'is_public' => true]);
        $res = $anonymous->get('/api/public-list.php?id=' . $listId);
        $this->assertTrue($res['data']['success'] ?? false);

        $list = $res['data']['list'];
        $this->assertSame('Paylaşılacak liste', $list['name']);
        $this->assertSame($ownerId, $list['owner']['id']);
        $this->assertSame($ownerName, $list['owner']['username']);
        $this->assertSame(2, $list['item_count']);

        $byTitle = array_column($list['items'], null, 'content_title');
        $this->assertSame('https://image.tmdb.org/t/p/w342/liste.jpg', $byTitle['Liste Filmi']['poster']);
        $this->assertNull($byTitle['Afişsiz Kitap']['poster'], 'Bilinmeyen içerik için afiş boş döner, istemci yedek sorgu yapar');
    }

    public function testListItemsRejectUnknownTypesAndTrimTitle(): void
    {
        [$owner] = $this->newUser('phpunit_pub_v');
        $listId = $this->makeList($owner, 'Doğrulama listesi');

        $bad = $owner->post('/api/custom-lists.php', ['action' => 'add_item', 'list_id' => $listId, 'content_type' => '<script>', 'content_id' => '1', 'content_title' => 'x']);
        $this->assertSame(400, $bad['status']);

        $owner->post('/api/custom-lists.php', ['action' => 'add_item', 'list_id' => $listId, 'content_type' => 'movie', 'content_id' => '1', 'content_title' => str_repeat('A', 500)]);
        $list = $owner->get('/api/custom-lists.php?id=' . $listId);
        $this->assertSame(200, mb_strlen($list['data']['list']['items'][0]['content_title']));
    }
}
