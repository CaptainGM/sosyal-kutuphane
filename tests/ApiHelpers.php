<?php

// HTTP testlerinin ortak yardımcıları: her test kendi kullanıcılarını oluşturur, böylece yorum oran sınırları ve
// kütüphane verileri testler arasında karışmaz. Çalışan bir sunucu ve MongoDB gerekir (bkz. ApiTest).
trait ApiHelpers
{
    private static ?MongoDB\Database $mongoHandle = null;

    private function mongo(): MongoDB\Database
    {
        return self::$mongoHandle ??= (new MongoDB\Client(TEST_MONGO_URI))->selectDatabase(TEST_MONGO_DB);
    }

    private function suffix(): string
    {
        return substr(bin2hex(random_bytes(4)), 0, 8);
    }

    // [ApiClient, kullanıcı kimliği, e-posta, kullanıcı adı]
    private function newUser(string $prefix): array
    {
        $suffix = $this->suffix();
        $username = "{$prefix}_$suffix";
        $email = "$username@test.com";
        $client = new ApiClient(TEST_BASE_URL);
        $client->register($username, $email, 'Sifre123456!');
        $id = (int) $this->mongo()->users->findOne(['email' => $email])['_id'];
        return [$client, $id, $email, $username];
    }

    // Uygulamadaki nextSequence() ile aynı sayaç: elle eklenen kayıtlar gerçek kimliklerle çakışmaz.
    private function nextId(string $name): int
    {
        $result = $this->mongo()->counters->findOneAndUpdate(
            ['_id' => $name],
            ['$inc' => ['seq' => 1]],
            ['upsert' => true, 'returnDocument' => MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER]
        );
        return (int) $result['seq'];
    }

    private function makeAdmin(int $userId): void
    {
        $this->mongo()->users->updateOne(['_id' => $userId], ['$set' => ['is_admin' => true]]);
    }

    private function newAdmin(string $prefix = 'phpunit_admin'): array
    {
        $user = $this->newUser($prefix);
        $this->makeAdmin($user[1]);
        return $user;
    }

    // Yorum metinlerini tüm listelerde aramak için düz metin listesi.
    private function texts(array $response): array
    {
        return array_map(static fn (array $comment): string => $comment['comment_text'], $response['data']['comments'] ?? []);
    }
}
