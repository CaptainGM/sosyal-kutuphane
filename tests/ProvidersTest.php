<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../api/lib/providers.php';
require_once __DIR__ . '/../api/lib/picker.php';

final class ProvidersTest extends TestCase
{
    public function testKnownPlatformAddsSubscriptionFilterForTurkey(): void
    {
        $this->assertSame(
            ['with_watch_providers' => 8, 'watch_region' => 'TR', 'with_watch_monetization_types' => 'flatrate'],
            streamingProviderParams('8')
        );
    }

    public function testUnknownOrEmptyPlatformAddsNothing(): void
    {
        $this->assertSame([], streamingProviderParams(''));
        $this->assertSame([], streamingProviderParams('99999'));
        $this->assertSame([], streamingProviderParams('8; DROP'));
        $this->assertSame([], streamingProviderParams(null));
    }

    public function testPickerKeepsOnlyKnownPlatforms(): void
    {
        $this->assertSame(119, pickerNormalizeParams(['provider' => '119'])['provider']);
        $this->assertSame(0, pickerNormalizeParams(['provider' => '5'])['provider']);
        $this->assertSame(0, pickerNormalizeParams(['provider' => 'netflix'])['provider']);
        $this->assertSame(0, pickerNormalizeParams([])['provider']);
    }

    public function testPickerQueryFiltersByPlatformOnlyWhenChosen(): void
    {
        $with = pickerDiscoverQuery(pickerNormalizeParams(['provider' => '8']), 1, '2026-10-17')['query'];
        $this->assertSame(8, $with['with_watch_providers']);
        $this->assertSame('TR', $with['watch_region']);
        $this->assertSame('flatrate', $with['with_watch_monetization_types']);

        $without = pickerDiscoverQuery(pickerNormalizeParams([]), 1, '2026-10-17')['query'];
        $this->assertArrayNotHasKey('with_watch_providers', $without);
    }
}
