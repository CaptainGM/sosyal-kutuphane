<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../api/lib/moderation.php';

final class ModerationTest extends TestCase
{
    public function testExternalLinksAndContactDetailsAreBlocked(): void
    {
        foreach (['https://spam.example/x', 'www.kumar.com', 'bit.ly/abc', 'Bana t.me/grup üzerinden yaz', 'ahmet@gmail.com', 'film.net'] as $text) {
            $this->assertNotNull(commentModerationError($text), "Bağlantı/iletişim yakalanmalı: $text");
        }
    }

    public function testIbanAndBankDetailsAreBlocked(): void
    {
        foreach (['TR33 0006 1005 1978 6457 8413 26', 'TR330006100519786457841326', 'iban atar mısın', 'IBAN: 1234'] as $text) {
            $this->assertSame('IBAN veya banka hesap bilgisi paylaşılamaz.', commentModerationError($text), $text);
        }
    }

    public function testProfanityAndInsultsIncludingObfuscationAreBlocked(): void
    {
        foreach (['bu film tam bir siktir', 's1k', 's i k', 's.i.k', 'orospu çocuğu', 'siiiiiktir', 'amk!', 'aptallık yapma', 'SALAK', 'a.q'] as $text) {
            $this->assertNotNull(commentModerationError($text), "Küfür/hakaret yakalanmalı: $text");
        }
    }

    public function testExpandedListCatchesInflectedTurkishAndEnglishInsults(): void
    {
        foreach ([
            'amcığa koyayım', 'yarrağımı yersin', 'götünü sikeyim', 'sen bir budalasın', 'ahlaksız herif', 'namussuzun teki',
            'ne kadar alçak bir film', 'bu da ne boktan senaryo', 'siktir git', 'f u c k', 'fuck this movie', 'what a bitch', 'total bullshit',
            'gerzek misin', 'kodumun senaristi', 'pislik herif', 'shitty ending',
        ] as $text) {
            $this->assertNotNull(commentModerationError($text), "Yakalanmalı: $text");
        }
    }

    public function testInnocentWordsThatLookSimilarAreNotFlagged(): void
    {
        foreach ([
            'Gotham dizisini çok sevdim', 'Şikayet ettim ama iyiydi', 'The OC efsaneydi', 'Pickup kamyonet sahnesi güzeldi', 'Analiz yapmak lazım',
            'Amasya turu belgeseli', 'Bitcoin hakkında bir belgesel', 'Assassin\'s Creed filmi', 'Class ve Scunthorpe üzerine', 'Sıkıcı bir bölümdü',
            'Orada bir tasarım harikası var', 'Salatalık gibi serin bir komedi', 'Dickens uyarlaması', 'Sinemada gotik atmosfer',
        ] as $text) {
            $this->assertNull(commentModerationError($text), "Yakalanmamalı: $text");
        }
    }

    public function testNormalSentencesWithSimilarLettersPass(): void
    {
        foreach ([
            'Harika bir film, sonunu çok beğendim.',
            'Film. Net bir sonuç veriyor.',
            'Şikayet ettim ama iyiydi.',
            'Puan 9.5 verdim ve 2024 yılı dizisi',
            'Amerika merkezli bir yapım',
            'Kasık ağrısı yok',
        ] as $text) {
            $this->assertNull(commentModerationError($text), "Normal yorum engellenmemeli: $text");
        }
    }

    public function testBurstLimitBlocksFastRepeatedComments(): void
    {
        $now = 1_700_000_000;
        $recent = [];
        for ($i = 0; $i < COMMENT_BURST_LIMIT; $i++) {
            $recent[] = ['at' => $now - 5 - $i, 'text' => "yorum $i", 'content' => 'movie:1'];
        }
        $this->assertNotNull(commentRateError($recent, 'yeni yorum', 'movie:2', $now));
    }

    public function testShortWindowLimitAndDailyLimit(): void
    {
        $now = 1_700_000_000;
        $shortWindow = [];
        for ($i = 0; $i < COMMENT_SHORT_LIMIT; $i++) {
            $shortWindow[] = ['at' => $now - 120 - $i * 20, 'text' => "yorum $i", 'content' => 'movie:1'];
        }
        $this->assertNotNull(commentRateError($shortWindow, 'yeni yorum', 'movie:2', $now));

        $day = [];
        for ($i = 0; $i < COMMENT_DAY_LIMIT; $i++) {
            $day[] = ['at' => $now - 7200 - $i * 600, 'text' => "gün $i", 'content' => 'movie:1'];
        }
        $this->assertNotNull(commentRateError($day, 'yeni yorum', 'movie:2', $now));
    }

    public function testDuplicateOnSameContentIsBlockedButOtherContentIsAllowed(): void
    {
        $now = 1_700_000_000;
        $recent = [['at' => $now - 3600, 'text' => 'Harika film', 'content' => 'movie:1']];
        $this->assertNotNull(commentRateError($recent, '  harika FILM ', 'movie:1', $now));
        $this->assertNull(commentRateError($recent, 'Harika film', 'movie:2', $now));
    }

    public function testNormalActivityIsAllowed(): void
    {
        $now = 1_700_000_000;
        $recent = [
            ['at' => $now - 30, 'text' => 'ilk yorum', 'content' => 'movie:1'],
            ['at' => $now - 4000, 'text' => 'eski yorum', 'content' => 'book:abc'],
        ];
        $this->assertNull(commentRateError($recent, 'yeni yorum', 'movie:2', $now));
    }
}
