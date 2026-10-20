<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ApiHelpers.php';
require_once __DIR__ . '/../api/lib/admins.php';

// Yöneticilerin yapılandırmayla belirlenmesi: ADMIN_EMAILS ayrıştırması ve veritabanı eşitleme (kilitlenme koruması dahil).
// Eşitleme test veritabanındaki tüm yöneticilerin yetkisini alır; her test kendi yöneticisini kendisi oluşturduğu için sorun olmaz.
final class AdminSyncTest extends TestCase
{
    use ApiHelpers;

    private function insertUser(string $email, bool $admin = false): int
    {
        $id = $this->nextId('users');
        $this->mongo()->users->insertOne([
            '_id' => $id,
            'username' => 'phpunit_sync_' . $this->suffix(),
            'email' => $email,
            'password' => password_hash('Sifre123456!', PASSWORD_DEFAULT),
            'is_admin' => $admin,
            'created_at' => new MongoDB\BSON\UTCDateTime(),
        ]);
        return $id;
    }

    private function isAdmin(int $id): bool
    {
        return !empty($this->mongo()->users->findOne(['_id' => $id])['is_admin']);
    }

    public function testEnvListIsParsedCleanedAndFallsBackToTheDefault(): void
    {
        $this->assertSame(ADMIN_DEFAULT_EMAILS, adminEmailsFromEnv(''));
        $this->assertSame(ADMIN_DEFAULT_EMAILS, adminEmailsFromEnv('   '));
        $this->assertSame(ADMIN_DEFAULT_EMAILS, adminEmailsFromEnv('gecersiz, baska-gecersiz'), 'Hiçbiri geçerli değilse varsayılana dönülür');
        $this->assertSame(['a@ornek.com', 'b@ornek.com', 'c@ornek.com'], adminEmailsFromEnv(' A@Ornek.com, b@ornek.com ;c@ornek.com  A@ORNEK.com gecersiz'));
    }

    public function testListedAccountBecomesTheOnlyAdminEvenWithMixedCaseEmail(): void
    {
        $suffix = $this->suffix();
        $target = $this->insertUser("Sync.Hedef.$suffix@Ornek.com");
        $oldAdmin = $this->insertUser("eski.$suffix@ornek.com", true);
        $normal = $this->insertUser("normal.$suffix@ornek.com");

        $result = syncAdmins($this->mongo(), ["sync.hedef.$suffix@ornek.com"]);

        $this->assertSame('ok', $result['status']);
        $this->assertTrue($this->isAdmin($target), 'Listedeki hesap (farklı harf büyüklüğüyle kayıtlı olsa da) yönetici olur');
        $this->assertFalse($this->isAdmin($oldAdmin), 'Listede olmayan eski yöneticiden yetki alınır');
        $this->assertFalse($this->isAdmin($normal));
        $this->assertSame(0, $this->mongo()->users->countDocuments(['is_admin' => true, '_id' => ['$ne' => $target]]), 'Tek yönetici kalır');
    }

    public function testNothingChangesWhenNoListedEmailHasAnAccount(): void
    {
        $admin = $this->insertUser('hayatta.' . $this->suffix() . '@ornek.com', true);

        $result = syncAdmins($this->mongo(), ['kimse.' . $this->suffix() . '@ornek.com']);

        $this->assertSame('missing', $result['status']);
        $this->assertSame(0, $result['demoted']);
        $this->assertTrue($this->isAdmin($admin), 'Hesap bulunamadıysa mevcut yöneticiler korunur (kilitlenme olmaz)');
    }

    public function testSyncIsIdempotentAndSupportsSeveralAdmins(): void
    {
        $suffix = $this->suffix();
        $first = $this->insertUser("bir.$suffix@ornek.com");
        $second = $this->insertUser("iki.$suffix@ornek.com");
        $emails = ["bir.$suffix@ornek.com", "iki.$suffix@ornek.com", "yok.$suffix@ornek.com"];

        $run = syncAdmins($this->mongo(), $emails);
        $this->assertSame(2, $run['promoted']);
        $this->assertSame(2, $run['matched']);
        $this->assertTrue($this->isAdmin($first) && $this->isAdmin($second));

        $again = syncAdmins($this->mongo(), $emails);
        $this->assertSame(['promoted' => 0, 'demoted' => 0], ['promoted' => $again['promoted'], 'demoted' => $again['demoted']], 'İkinci çalıştırma bir şey değiştirmez');
    }
}
