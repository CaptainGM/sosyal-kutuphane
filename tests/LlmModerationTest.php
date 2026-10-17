<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../api/lib/llm-moderation.php';

final class LlmModerationTest extends TestCase
{
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

    private function enable(string $provider): void
    {
        putenv("LLM_MODERATION_PROVIDER=$provider");
        putenv('LLM_MODERATION_API_KEY=test-key-not-real');
    }

    public function testDisabledWithoutProviderOrKeyAndSendsNothing(): void
    {
        $calls = 0;
        $transport = function () use (&$calls) {
            $calls++;
            return null;
        };
        $this->assertNull(llmModerationVerdict(null, 'herhangi bir yorum', $transport));

        putenv('LLM_MODERATION_PROVIDER=gemini');
        $this->assertNull(llmModerationVerdict(null, 'anahtar yok', $transport));
        putenv('LLM_MODERATION_PROVIDER=bilinmeyen');
        putenv('LLM_MODERATION_API_KEY=x');
        $this->assertNull(llmModerationVerdict(null, 'sağlayıcı yok', $transport));
        $this->assertSame(0, $calls, 'Katman kapalıyken dışarıya hiçbir şey gönderilmemeli');
    }

    public function testGeminiRequestShapeKeepsKeyOutOfUrlAndTextAsData(): void
    {
        $this->enable('gemini');
        $request = llmModerationRequest(llmModerationConfig(), 'Bu film harikaydı');
        $this->assertStringStartsWith('https://generativelanguage.googleapis.com/v1beta/models/gemini-3.1-flash-lite:generateContent', $request['url']);
        $this->assertStringNotContainsString('test-key-not-real', $request['url']);
        $this->assertContains('x-goog-api-key: test-key-not-real', $request['headers']);
        $body = json_decode($request['body'], true);
        $this->assertSame('Bu film harikaydı', $body['contents'][0]['parts'][0]['text']);
        $this->assertSame('application/json', $body['generationConfig']['responseMimeType']);
        $this->assertSame(0, $body['generationConfig']['temperature']);
        $this->assertGreaterThanOrEqual(128, $body['generationConfig']['maxOutputTokens'], 'Düşük çıktı sınırı JSON yanıtını yarıda keser');
    }

    public function testDeepseekRequestShape(): void
    {
        $this->enable('deepseek');
        $request = llmModerationRequest(llmModerationConfig(), 'merhaba');
        $this->assertSame('https://api.deepseek.com/chat/completions', $request['url']);
        $this->assertContains('Authorization: Bearer test-key-not-real', $request['headers']);
        $body = json_decode($request['body'], true);
        $this->assertSame('deepseek-chat', $body['model']);
        $this->assertSame('user', $body['messages'][1]['role']);
        $this->assertSame('merhaba', $body['messages'][1]['content']);
        $this->assertSame('json_object', $body['response_format']['type']);
    }

    public function testLongTextIsTruncatedAndModelNameValidated(): void
    {
        $this->enable('gemini');
        putenv('LLM_MODERATION_MODEL=../../evil?x=1');
        $config = llmModerationConfig();
        $this->assertSame('gemini-3.1-flash-lite', $config['model'], 'Geçersiz model adı varsayılana düşmeli');
        $body = json_decode(llmModerationRequest($config, str_repeat('ş', 5000))['body'], true);
        $this->assertSame(LLM_MODERATION_MAX_CHARS, mb_strlen($body['contents'][0]['parts'][0]['text'], 'UTF-8'));
    }

    public function testParsesVerdictsAndRejectsGarbage(): void
    {
        $gemini = fn (string $text) => ['candidates' => [['content' => ['parts' => [['text' => $text]]]]]];
        $this->assertTrue(llmModerationParse('gemini', $gemini('{"abusive": true}')));
        $this->assertFalse(llmModerationParse('gemini', $gemini('{"abusive": false}')));
        $this->assertNull(llmModerationParse('gemini', $gemini('abusive: yes')), 'JSON olmayan çıktı karar sayılmaz');
        $this->assertNull(llmModerationParse('gemini', $gemini('{"abusive": "true"}')), 'Mantıksal olmayan değer karar sayılmaz');
        $this->assertNull(llmModerationParse('gemini', ['error' => ['code' => 429]]));
        $this->assertNull(llmModerationParse('deepseek', null));

        $deepseek = ['choices' => [['message' => ['content' => '{"abusive": true}']]]];
        $this->assertTrue(llmModerationParse('deepseek', $deepseek));
    }

    public function testVerdictUsesTransportAndFailsOpenOnErrors(): void
    {
        $this->enable('gemini');
        $abusive = fn () => ['candidates' => [['content' => ['parts' => [['text' => '{"abusive": true}']]]]]];
        $clean = fn () => ['candidates' => [['content' => ['parts' => [['text' => '{"abusive": false}']]]]]];
        $down = fn () => null;

        $this->assertTrue(llmModerationVerdict(null, 's.i.k.t.i.r', $abusive));
        $this->assertFalse(llmModerationVerdict(null, 'güzel film', $clean));
        $this->assertNull(llmModerationVerdict(null, 'sağlayıcı kapalı', $down), 'Hata durumunda açık geçilir');
        $this->assertNull(commentLlmModerationError(null, 'sağlayıcı kapalı'));
    }

    public function testBlankTextIsNeverSent(): void
    {
        $this->enable('deepseek');
        $calls = 0;
        $transport = function () use (&$calls) {
            $calls++;
            return null;
        };
        $this->assertNull(llmModerationVerdict(null, "   \n", $transport));
        $this->assertSame(0, $calls);
    }
}
