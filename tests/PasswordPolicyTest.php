<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../api/lib/password.php';

final class PasswordPolicyTest extends TestCase
{
    public function testAcceptsPasswordWithAllRequiredClasses(): void
    {
        $this->assertNull(passwordPolicyError('Sifre123!'));
        $this->assertNull(passwordPolicyError('Aa1!aaaa'));
    }

    public function testRejectsShortPassword(): void
    {
        $this->assertNotNull(passwordPolicyError('Ab1!xyz'));
    }

    public function testRequiresUppercase(): void
    {
        $this->assertStringContainsString('büyük harf', passwordPolicyError('sifre123!'));
    }

    public function testRequiresLowercase(): void
    {
        $this->assertStringContainsString('küçük harf', passwordPolicyError('SIFRE123!'));
    }

    public function testRequiresSpecialCharacter(): void
    {
        $this->assertStringContainsString('özel karakter', passwordPolicyError('Sifre1234'));
    }

    public function testSpacesDoNotCountAsSpecialCharacters(): void
    {
        $this->assertStringContainsString('özel karakter', passwordPolicyError('Sifre 1234'));
    }

    public function testTurkishLettersCountAsLetters(): void
    {
        $this->assertNull(passwordPolicyError('Çiçek123!'));
        $this->assertNull(passwordPolicyError('ÖğrenciŞifre1?'));
        $this->assertStringContainsString('büyük harf', passwordPolicyError('çiçek123!'));
    }

    public function testRejectsOverlongPassword(): void
    {
        $this->assertStringContainsString('en fazla', passwordPolicyError('Aa1!' . str_repeat('x', 200)));
    }

    public function testRejectsInvalidUtf8WithoutCrashing(): void
    {
        $this->assertNotNull(passwordPolicyError("\xC3\x28\xFF\xFE"));
    }
}
