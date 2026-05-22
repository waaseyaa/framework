<?php

declare(strict_types=1);

namespace Waaseyaa\AgentOutput\Tests\Contract;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AgentOutput\Formatter\PestFormatter;

#[CoversClass(PestFormatter::class)]
final class PestFormatterTest extends TestCase
{
    #[Test]
    public function supportsPestToolIdentifier(): void
    {
        $formatter = new PestFormatter();
        self::assertTrue($formatter->supports('pest'));
        self::assertFalse($formatter->supports('phpunit'));
        self::assertFalse($formatter->supports(''));
    }

    #[Test]
    public function formatsPassingRunAsPassResult(): void
    {
        $line = (new PestFormatter())->format([
            'suite' => 'feature',
            'passed' => 32,
            'failed' => 0,
            'skipped' => 1,
            'duration_ms' => 4200,
            'failures' => [],
        ]);

        $envelope = $this->decode($line);
        self::assertSame('pest', $envelope['tool']);
        self::assertSame('pass', $envelope['result']);
        self::assertSame('feature', $envelope['suite']);
        self::assertSame(32, $envelope['passed']);
        self::assertSame(0, $envelope['failed']);
        self::assertSame([], $envelope['failures']);
    }

    #[Test]
    public function formatsFailingRunAsFailResultWithFailureDetail(): void // FR-008
    {
        $line = (new PestFormatter())->format([
            'suite' => 'unit',
            'passed' => 8,
            'failed' => 2,
            'skipped' => 0,
            'duration_ms' => 1100,
            'failures' => [
                ['file' => 'tests/Unit/AdderTest.php', 'line' => 17, 'test' => 'adds two numbers', 'message' => 'Expected 3, got 4'],
                ['file' => 'tests/Unit/AdderTest.php', 'line' => 25, 'test' => 'handles negatives', 'message' => 'Expected -1, got 1'],
            ],
        ]);

        $envelope = $this->decode($line);
        self::assertSame('fail', $envelope['result']);
        self::assertCount(2, $envelope['failures']);
        self::assertSame('tests/Unit/AdderTest.php', $envelope['failures'][0]['file']);
        self::assertSame(17, $envelope['failures'][0]['line']);
        self::assertSame('Expected 3, got 4', $envelope['failures'][0]['message']);
    }

    #[Test]
    public function formatsEmptyRunAsPassResult(): void
    {
        $line = (new PestFormatter())->format([
            'passed' => 0,
            'failed' => 0,
            'skipped' => 0,
        ]);

        $envelope = $this->decode($line);
        self::assertSame('pass', $envelope['result']);
        self::assertNull($envelope['suite']);
        self::assertNull($envelope['duration_ms']);
    }

    #[Test]
    public function outputIsValidSingleLineNdjson(): void
    {
        $line = (new PestFormatter())->format(['passed' => 1, 'failed' => 0]);

        self::assertStringEndsWith("\n", $line);
        self::assertSame(1, substr_count($line, "\n"));
        $decoded = json_decode(rtrim($line, "\n"), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
    }

    #[Test]
    public function passingEnvelopeStaysUnder500Bytes(): void // NFR-003
    {
        $line = (new PestFormatter())->format([
            'suite' => 'feature',
            'passed' => 200,
            'failed' => 0,
            'skipped' => 5,
            'duration_ms' => 19999,
            'failures' => [],
        ]);

        self::assertLessThanOrEqual(500, strlen($line));
    }

    /** @return array<string, mixed> */
    private function decode(string $ndjsonLine): array
    {
        $decoded = json_decode(rtrim($ndjsonLine, "\n"), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
