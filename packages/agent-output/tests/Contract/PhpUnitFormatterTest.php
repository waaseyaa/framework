<?php

declare(strict_types=1);

namespace Waaseyaa\AgentOutput\Tests\Contract;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AgentOutput\Formatter\PhpUnitFormatter;

#[CoversClass(PhpUnitFormatter::class)]
final class PhpUnitFormatterTest extends TestCase
{
    #[Test]
    public function supportsPhpunitToolIdentifier(): void
    {
        $formatter = new PhpUnitFormatter();
        self::assertTrue($formatter->supports('phpunit'));
        self::assertFalse($formatter->supports('phpstan'));
        self::assertFalse($formatter->supports(''));
    }

    #[Test]
    public function formatsPassingRunAsPassResult(): void
    {
        $line = (new PhpUnitFormatter())->format([
            'suite' => 'bimaaji',
            'passed' => 47,
            'failed' => 0,
            'skipped' => 2,
            'duration_ms' => 8123,
            'failures' => [],
        ]);

        $envelope = $this->decode($line);
        self::assertSame('phpunit', $envelope['tool']);
        self::assertSame('pass', $envelope['result']);
        self::assertSame('bimaaji', $envelope['suite']);
        self::assertSame(47, $envelope['passed']);
        self::assertSame(0, $envelope['failed']);
        self::assertSame(2, $envelope['skipped']);
        self::assertSame(8123, $envelope['duration_ms']);
        self::assertSame([], $envelope['failures']);
    }

    #[Test]
    public function formatsFailingRunAsFailResultWithFailureDetail(): void
    {
        $line = (new PhpUnitFormatter())->format([
            'suite' => 'integration',
            'passed' => 12,
            'failed' => 1,
            'skipped' => 0,
            'duration_ms' => 2400,
            'failures' => [
                [
                    'file' => 'tests/Integration/Foo.php',
                    'line' => 42,
                    'test' => 'Waaseyaa\\Tests\\Foo::shouldDoTheThing',
                    'message' => 'Failed asserting that …',
                ],
            ],
        ]);

        $envelope = $this->decode($line);
        self::assertSame('fail', $envelope['result']);
        self::assertCount(1, $envelope['failures']);
        self::assertSame('tests/Integration/Foo.php', $envelope['failures'][0]['file']);
        self::assertSame(42, $envelope['failures'][0]['line']);
    }

    #[Test]
    public function formatsEmptyRunAsPassResult(): void
    {
        $line = (new PhpUnitFormatter())->format([
            'passed' => 0,
            'failed' => 0,
            'skipped' => 0,
        ]);

        $envelope = $this->decode($line);
        self::assertSame('pass', $envelope['result']);
        self::assertSame(0, $envelope['passed']);
        self::assertNull($envelope['suite']);
        self::assertNull($envelope['duration_ms']);
        self::assertSame([], $envelope['failures']);
    }

    #[Test]
    public function outputIsValidSingleLineNdjson(): void
    {
        $line = (new PhpUnitFormatter())->format(['passed' => 1, 'failed' => 0]);

        self::assertStringEndsWith("\n", $line);
        self::assertSame(1, substr_count($line, "\n"), 'NDJSON envelope must contain exactly one newline.');
        // Round-trip through json_decode(JSON_THROW_ON_ERROR) cleanly.
        $decoded = json_decode(rtrim($line, "\n"), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
    }

    #[Test]
    public function passingEnvelopeStaysUnder500Bytes(): void // NFR-003
    {
        $line = (new PhpUnitFormatter())->format([
            'suite' => 'bimaaji',
            'passed' => 100,
            'failed' => 0,
            'skipped' => 0,
            'duration_ms' => 9999,
            'failures' => [],
        ]);

        self::assertLessThanOrEqual(500, strlen($line), 'NFR-003: passing envelopes must be ≤ 500 bytes.');
    }

    #[Test]
    public function coercesNonIntegerCountsToZero(): void
    {
        $line = (new PhpUnitFormatter())->format([
            'passed' => 'not-an-int',
            'failed' => null,
        ]);

        $envelope = $this->decode($line);
        self::assertSame(0, $envelope['passed']);
        self::assertSame(0, $envelope['failed']);
        self::assertSame('pass', $envelope['result']);
    }

    /** @return array<string, mixed> */
    private function decode(string $ndjsonLine): array
    {
        $decoded = json_decode(rtrim($ndjsonLine, "\n"), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
