<?php

declare(strict_types=1);

namespace Waaseyaa\Attachment\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Attachment\Tests\Support\ForkChildDiagnostics;

#[CoversClass(ForkChildDiagnostics::class)]
final class ForkChildDiagnosticsTest extends TestCase
{
    #[Test]
    public function waitForPidUsingRetriesInterruptedWait(): void
    {
        $status = 0;
        $attempts = 0;

        $waitedPid = ForkChildDiagnostics::waitForPidUsing(
            42,
            $status,
            function (int $pid, int &$childStatus) use (&$attempts): int {
                ++$attempts;
                if ($attempts === 1) {
                    return -1;
                }

                $childStatus = 0;

                return $pid;
            },
            function () use (&$attempts): int {
                return $attempts === 1 ? \PCNTL_EINTR : 0;
            },
        );

        self::assertSame(42, $waitedPid);
        self::assertSame(2, $attempts);
    }

    #[Test]
    public function boundUtf8MessageRemainsJsonEncodableAtByteLimit(): void
    {
        $message = str_repeat('a', 499) . 'é';
        $bounded = ForkChildDiagnostics::boundMessageForTesting($message);

        self::assertLessThanOrEqual(503, strlen($bounded));
        json_encode(
            ['message' => $bounded],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
        self::assertStringEndsWith('…', $bounded);
    }

    #[Test]
    public function invalidUtf8MessageAtOrBelowByteLimitSurvivesStrictReportEncoding(): void
    {
        $message = 'prefix' . "\xFF\xFE";
        self::assertLessThanOrEqual(500, strlen($message));

        $bounded = ForkChildDiagnostics::boundMessageForTesting($message);
        self::assertSame($message, $bounded);

        $encoded = ForkChildDiagnostics::encodeReportPayloadForTesting([
            'child' => 0,
            'stage' => 'save',
            'class' => \RuntimeException::class,
            'message' => $bounded,
        ]);

        $decoded = json_decode($encoded, associative: true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame('save', $decoded['stage']);
        self::assertStringContainsString('prefix', (string) $decoded['message']);
    }
}
