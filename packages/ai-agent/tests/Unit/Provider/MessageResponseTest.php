<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Tests\Unit\Provider;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Agent\Provider\MessageResponse;

/** Regression test for audit finding D-33. */
#[CoversClass(MessageResponse::class)]
final class MessageResponseTest extends TestCase
{
    #[Test]
    public function defaultUsageSatisfiesTheRequiredTokenShape(): void
    {
        $response = new MessageResponse(content: [], stopReason: 'end_turn');

        self::assertArrayHasKey('input_tokens', $response->usage);
        self::assertArrayHasKey('output_tokens', $response->usage);
        self::assertSame(0, $response->usage['input_tokens']);
        self::assertSame(0, $response->usage['output_tokens']);
    }

    #[Test]
    public function defaultUsageKeysReadWithoutUndefinedKeyWarning(): void
    {
        $response = new MessageResponse(content: [], stopReason: 'end_turn');

        self::assertSame(0, $response->usage['input_tokens'] + $response->usage['output_tokens']);
    }

    #[Test]
    public function explicitUsageIsPreserved(): void
    {
        $response = new MessageResponse(content: [], stopReason: 'end_turn', usage: ['input_tokens' => 12, 'output_tokens' => 34]);

        self::assertSame(12, $response->usage['input_tokens']);
        self::assertSame(34, $response->usage['output_tokens']);
    }
}
