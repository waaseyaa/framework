<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Tools\Resource\ContentResourceListResume;
use Waaseyaa\Mcp\Resource\ContentResourceListCursorCodec;
use Waaseyaa\Mcp\Tests\Support\McpContentResourceListCursorKeyring;

#[CoversClass(ContentResourceListCursorCodec::class)]
final class ContentResourceListCursorCodecTest extends TestCase
{
    #[Test]
    public function sealed_cursors_round_trip_and_refuse_tamper_expiry_and_binding_mismatch(): void
    {
        $now = 1_700_000_000;
        $codec = new ContentResourceListCursorCodec(
            McpContentResourceListCursorKeyring::create(),
            static fn(): int => $now,
        );
        $binding = hash('sha256', 'mcp-content-resource-list|7');
        $resume = new ContentResourceListResume('search', 'srv1token');

        $cursor = $codec->seal($resume, $binding);
        self::assertSame($resume->provider, $codec->open($cursor, $binding)->provider);
        self::assertSame($resume->token, $codec->open($cursor, $binding)->token);
        self::assertNotSame($resume->token, $cursor);

        foreach ([
            $cursor . 'x',
            substr($cursor, 0, -4) . 'AAAA',
            '',
        ] as $tampered) {
            try {
                $codec->open($tampered, $binding);
                self::fail('Expected tampered cursor refusal.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        try {
            $codec->open($cursor, hash('sha256', 'other'));
            self::fail('Expected principal-binding refusal.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        $expired = new ContentResourceListCursorCodec(
            McpContentResourceListCursorKeyring::create(),
            static fn(): int => $now + ContentResourceListCursorCodec::DEFAULT_TTL_SECONDS + 1,
        );
        try {
            $expired->open($cursor, $binding);
            self::fail('Expected expiry refusal.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }
}
