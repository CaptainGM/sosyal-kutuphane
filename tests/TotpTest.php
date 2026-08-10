<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../api/totp.php';

// RFC 6238 Ek B'deki resmi test vektörleri (HMAC-SHA1, 8 haneli, secret = ASCII "12345678901234567890").
// https://datatracker.ietf.org/doc/html/rfc6238#appendix-B
final class TotpTest extends TestCase
{
    private function officialSecret(): string
    {
        return totpBase32Encode('12345678901234567890');
    }

    #[DataProvider('rfc6238Vectors')]
    public function testMatchesOfficialRfc6238Vectors(int $unixTime, string $expectedCode): void
    {
        $secret = $this->officialSecret();
        $this->assertSame($expectedCode, totpCodeAt($secret, $unixTime, 30, 8));
    }

    public static function rfc6238Vectors(): array
    {
        return [
            [59, '94287082'],
            [1111111109, '07081804'],
            [1111111111, '14050471'],
            [1234567890, '89005924'],
            [2000000000, '69279037'],
        ];
    }

    public function testVerifyTotpAcceptsCurrentCodeAndRejectsWrongCode(): void
    {
        $secret = generateBase32Secret();
        $now = time();
        $validCode = totpCodeAt($secret, $now);

        $this->assertTrue(verifyTotp($secret, $validCode, 1, $now));
        $this->assertFalse(verifyTotp($secret, '000000', 1, $now));
        $this->assertFalse(verifyTotp($secret, '', 1, $now));
    }

    public function testVerifyTotpToleratesOneStepClockDrift(): void
    {
        $secret = generateBase32Secret();
        $now = time();
        $codeFromPreviousStep = totpCodeAt($secret, $now - 30);

        $this->assertTrue(verifyTotp($secret, $codeFromPreviousStep, 1, $now));
    }

    public function testBase32RoundTrip(): void
    {
        $original = random_bytes(20);
        $encoded = totpBase32Encode($original);
        $this->assertSame($original, totpBase32Decode($encoded));
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $encoded);
    }

    public function testGenerateBackupCodesProducesUniqueFormattedCodes(): void
    {
        $codes = generateBackupCodes(8);
        $this->assertCount(8, $codes);
        $this->assertCount(8, array_unique($codes));
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^[0-9A-F]{4}-[0-9A-F]{4}$/', $code);
        }
    }
}
