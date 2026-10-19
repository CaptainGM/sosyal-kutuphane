<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ApiHelpers.php';
require_once __DIR__ . '/../api/lib/image-moderation.php';
require_once __DIR__ . '/../api/lib/rate-limit.php';

// Profil fotoğrafı moderasyonu: istek biçimi, yanıt okuma, kapalı/açık katman davranışı ve hareketli dosya tespiti.
// Gerçek bir ağ isteği yapılmaz; sağlayıcı yerine sahte taşıyıcı verilir.
final class ImageModerationTest extends TestCase
{
    use ApiHelpers;

    protected function setUp(): void
    {
        putenv('LLM_MODERATION_PROVIDER');
        putenv('LLM_MODERATION_API_KEY');
        putenv('LLM_MODERATION_MODEL');
        putenv('LLM_MODERATION_TIMEOUT');
    }

    protected function tearDown(): void
    {
        $this->setUp();
    }

    private function enable(string $provider = 'gemini'): void
    {
        putenv("LLM_MODERATION_PROVIDER=$provider");
        putenv('LLM_MODERATION_API_KEY=test-key-not-real');
    }

    private function reply(bool $unsafe, string $category = 'none'): array
    {
        return ['candidates' => [['content' => ['parts' => [['text' => json_encode(['unsafe' => $unsafe, 'category' => $category])]]], 'finishReason' => 'STOP']]];
    }

    private function counting(array $responses, int &$calls): callable
    {
        return function () use ($responses, &$calls) {
            $response = $responses[min($calls, count($responses) - 1)];
            $calls++;
            return $response;
        };
    }

    // --- Biçimler: küçük sahte dosyalar (kod çözülebilir olmaları gerekmez, yalnızca yapı) ---

    private function gif(int $frames): string
    {
        $header = 'GIF89a' . pack('v2', 1, 1) . "\x80\x00\x00" . "\x00\x00\x00\xff\xff\xff"; // 1x1, 2 renkli genel tablo
        $frame = "\x21\xF9\x04\x00\x0A\x00\x00\x00" . "\x2C" . pack('v4', 0, 0, 1, 1) . "\x00" . "\x02\x02\x44\x01\x00";
        return $header . str_repeat($frame, $frames) . "\x3B";
    }

    private function pngChunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . "\0\0\0\0";
    }

    private function png(bool $animated, string $idatData = "\x78\x9c\x63\x00\x00\x00\x02\x00\x01"): string
    {
        return "\x89PNG\r\n\x1a\n"
            . $this->pngChunk('IHDR', pack('N2', 1, 1) . "\x08\x02\x00\x00\x00")
            . ($animated ? $this->pngChunk('acTL', pack('N2', 2, 0)) : '')
            . $this->pngChunk('IDAT', $idatData)
            . $this->pngChunk('IEND', '');
    }

    private function webp(int $flags, string $tail = ''): string
    {
        return 'RIFF' . pack('V', 22) . 'WEBP' . 'VP8X' . pack('V', 10) . chr($flags) . str_repeat("\0", 9) . $tail;
    }

    public function testLayerIsSkippedWithoutKeyAndForProvidersThatCannotSeeImages(): void
    {
        $calls = 0;
        $transport = $this->counting([null], $calls);
        $this->assertSame('skipped', imageModerationVerdict(null, $this->png(false), 'image/png', $transport)['status']);

        $this->enable('deepseek');
        $this->assertSame('skipped', imageModerationVerdict(null, $this->png(false), 'image/png', $transport)['status'], 'DeepSeek görsel anlamaz');
        $this->assertNull(imageModerationConfig());
        $this->assertSame(0, $calls, 'Katman kapalıyken dışarıya hiçbir şey gönderilmemeli');
    }

    public function testRequestCarriesImageAndSafetySettingsAndKeepsKeyOutOfBody(): void
    {
        $this->enable();
        $bytes = $this->png(false);
        $request = imageModerationRequest(imageModerationConfig(), 'image/png', $bytes);

        $this->assertStringStartsWith('https://generativelanguage.googleapis.com/v1beta/models/gemini-3.1-flash-lite:generateContent', $request['url']);
        $this->assertContains('x-goog-api-key: test-key-not-real', $request['headers']);
        $this->assertStringNotContainsString('test-key-not-real', $request['url'] . $request['body']);

        $body = json_decode($request['body'], true);
        $image = $body['contents'][0]['parts'][0]['inlineData'];
        $this->assertSame('image/png', $image['mimeType']);
        $this->assertSame($bytes, base64_decode($image['data'], true));
        $this->assertCount(4, $body['safetySettings']);
        foreach ($body['safetySettings'] as $setting) {
            $this->assertSame('BLOCK_NONE', $setting['threshold'], 'Model uygunsuz görseli reddetmek yerine sınıflandırmalı');
        }
        $this->assertSame('application/json', $body['generationConfig']['responseMimeType']);
        $this->assertGreaterThanOrEqual(128, $body['generationConfig']['maxOutputTokens']);
        $this->assertGreaterThanOrEqual(IMAGE_MODERATION_TIMEOUT, imageModerationConfig()['timeout'], 'Görsel için süre metinden uzun olmalı');
    }

    public function testParsesVerdictsAndNormalisesCategories(): void
    {
        $this->assertSame(['unsafe' => false, 'category' => null], imageModerationParse($this->reply(false)));
        $this->assertSame(['unsafe' => true, 'category' => 'sexual'], imageModerationParse($this->reply(true, 'sexual')));
        $this->assertSame(['unsafe' => true, 'category' => 'violence'], imageModerationParse($this->reply(true, 'violence')));
        $this->assertSame(['unsafe' => true, 'category' => 'terror'], imageModerationParse($this->reply(true, 'terror')));
        $this->assertSame(['unsafe' => true, 'category' => 'other'], imageModerationParse($this->reply(true, 'bilinmeyen')), 'Tanınmayan kategori "other" olur');
        $this->assertSame(['unsafe' => true, 'category' => 'other'], imageModerationParse($this->reply(true, 'none')));

        $text = fn (string $t) => ['candidates' => [['content' => ['parts' => [['text' => $t]]]]]];
        $this->assertNull(imageModerationParse($text('unsafe: yes')));
        $this->assertNull(imageModerationParse($text('{"unsafe": "true"}')), 'Mantıksal olmayan değer karar sayılmaz');
        $this->assertNull(imageModerationParse(['error' => ['code' => 429]]));
        $this->assertNull(imageModerationParse(null));
    }

    public function testGeminiSafetyBlocksCountAsUnsafe(): void
    {
        $blocked = ['promptFeedback' => ['blockReason' => 'PROHIBITED_CONTENT']];
        $this->assertSame(['unsafe' => true, 'category' => 'other'], imageModerationParse($blocked));
        $this->assertSame(['unsafe' => true, 'category' => 'other'], imageModerationParse(['promptFeedback' => ['blockReason' => 'SAFETY']]));
        $this->assertSame(['unsafe' => true, 'category' => 'other'], imageModerationParse(['candidates' => [['finishReason' => 'SAFETY']]]));
        $this->assertNull(imageModerationParse(['promptFeedback' => ['blockReason' => 'OTHER']]), 'Nedeni belirsiz engel karar sayılmaz');
    }

    public function testVerdictMapsToStatusAndFailsClosedWhenUndecided(): void
    {
        $this->enable();
        $png = $this->png(false);
        $calls = 0;
        $this->assertSame(['status' => 'unsafe', 'category' => 'violence'], imageModerationVerdict(null, $png, 'image/png', $this->counting([$this->reply(true, 'violence')], $calls)));
        $this->assertSame(['status' => 'safe', 'category' => null], imageModerationVerdict(null, $png, 'image/png', $this->counting([$this->reply(false)], $calls)));

        $calls = 0;
        $this->assertSame('unavailable', imageModerationVerdict(null, $png, 'image/png', $this->counting([null], $calls), 0)['status'], 'Ağ yoksa açık geçilmez');
        $this->assertSame(1 + IMAGE_MODERATION_MAX_RETRIES, $calls, 'Zaman aşımı / ağ hatası sınırlı sayıda yeniden denenir');

        $calls = 0;
        $badRequest = ['error' => ['code' => 400, 'message' => 'bad']];
        $this->assertSame('unavailable', imageModerationVerdict(null, $png, 'image/png', $this->counting([$badRequest], $calls))['status']);
        $this->assertSame(1, $calls, '400 geçici hata değildir, yeniden denenmez');
    }

    public function testTransientErrorsAreRetriedOnce(): void
    {
        $this->enable();
        $png = $this->png(false);
        $busy = ['error' => ['code' => 503, 'message' => 'This model is currently experiencing high demand.']];

        $calls = 0;
        $verdict = imageModerationVerdict(null, $png, 'image/png', $this->counting([$busy, $this->reply(false)], $calls), 0);
        $this->assertSame('safe', $verdict['status'], 'Yoğunluk hatasından sonra ikinci deneme sonuç verir');
        $this->assertSame(2, $calls);

        $calls = 0;
        $verdict = imageModerationVerdict(null, $png, 'image/png', $this->counting([$busy, $busy, $this->reply(false)], $calls), 0);
        $this->assertSame('safe', $verdict['status'], 'İkinci yeniden deneme de sonuç verebilir');
        $this->assertSame(3, $calls);

        $calls = 0;
        $verdict = imageModerationVerdict(null, $png, 'image/png', $this->counting([$busy, $busy, $busy, $this->reply(false)], $calls), 0);
        $this->assertSame('unavailable', $verdict['status'], 'Üç denemede de hata varsa karar verilemez');
        $this->assertSame(1 + IMAGE_MODERATION_MAX_RETRIES, $calls, 'En fazla iki yeniden deneme');

        $calls = 0;
        $quota = ['error' => ['code' => 429, 'message' => 'quota']];
        $this->assertSame('safe', imageModerationVerdict(null, $png, 'image/png', $this->counting([$quota, $this->reply(false)], $calls), 0)['status']);

        $calls = 0;
        $this->assertSame('safe', imageModerationVerdict(null, $png, 'image/png', $this->counting([null, $this->reply(false)], $calls), 0)['status'], 'Zaman aşımından sonra ikinci deneme sonuç verir');
    }

    public function testAnimatedFilesAreRejectedWithoutAskingTheModel(): void
    {
        $this->enable();
        $calls = 0;
        $transport = $this->counting([$this->reply(false)], $calls);

        $this->assertSame('animated', imageModerationVerdict(null, $this->gif(2), 'image/gif', $transport)['status']);
        $this->assertSame('animated', imageModerationVerdict(null, $this->png(true), 'image/png', $transport)['status']);
        $this->assertSame('animated', imageModerationVerdict(null, $this->webp(0x12), 'image/webp', $transport)['status']);
        $this->assertSame(0, $calls, 'Kontrol edilemeyen dosya modele gönderilmez');

        $this->assertSame('safe', imageModerationVerdict(null, $this->gif(1), 'image/gif', $transport)['status'], 'Tek kareli GIF normal fotoğraftır');
        $this->assertSame(1, $calls);
    }

    public function testAnimationDetection(): void
    {
        $this->assertSame(0, imageGifFrameCount('düz metin'));
        $this->assertSame(1, imageGifFrameCount($this->gif(1)));
        $this->assertSame(2, imageGifFrameCount($this->gif(5)), 'Birden fazla kare görülünce sayım durur');
        $this->assertFalse(imageIsAnimated($this->gif(1), 'image/gif'));
        $this->assertTrue(imageIsAnimated($this->gif(3), 'image/gif'));
        $this->assertFalse(imageIsAnimated(substr($this->gif(2), 0, 40), 'image/gif'), 'Yarım kalmış dosyada çökmemeli');

        $this->assertFalse(imageIsAnimated($this->png(false), 'image/png'));
        $this->assertTrue(imageIsAnimated($this->png(true), 'image/png'));
        $this->assertFalse(imageIsAnimated($this->png(false, 'xxacTLxx'), 'image/png'), 'Piksel verisindeki rastgele "acTL" animasyon sayılmaz');
        $this->assertFalse(imageIsAnimated('PNG değil', 'image/png'));

        $this->assertFalse(imageIsAnimated($this->webp(0x10), 'image/webp'));
        $this->assertTrue(imageIsAnimated($this->webp(0x02), 'image/webp'));
        $this->assertTrue(imageIsAnimated($this->webp(0x10, 'ANMF....'), 'image/webp'), 'Bayrağı gizlenmiş animasyon da yakalanır');
        $this->assertFalse(imageIsAnimated('RIFF', 'image/webp'));

        $this->assertFalse(imageIsAnimated("\xFF\xD8\xFF\xE0", 'image/jpeg'));
    }

    public function testRateLimitAllowsUpToMaxPerWindowThenBlocks(): void
    {
        $db = $this->mongo();
        $bucket = 'test-limit-' . bin2hex(random_bytes(4));
        $this->assertFalse(rateLimitHit($db, $bucket, 3, 3600));
        $this->assertFalse(rateLimitHit($db, $bucket, 3, 3600));
        $this->assertFalse(rateLimitHit($db, $bucket, 3, 3600));
        $this->assertTrue(rateLimitHit($db, $bucket, 3, 3600), 'Dördüncü istek sınırı aşar');
        $this->assertTrue(rateLimitHit($db, $bucket, 3, 3600));
        $this->assertFalse(rateLimitHit($db, $bucket . '-baska', 3, 3600), 'Her kova ayrı sayılır');
    }
}
