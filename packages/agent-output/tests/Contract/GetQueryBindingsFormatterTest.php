<?php

declare(strict_types=1);

namespace Waaseyaa\AgentOutput\Tests\Contract;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AgentOutput\Formatter\GetQueryBindingsFormatter;

#[CoversClass(GetQueryBindingsFormatter::class)]
final class GetQueryBindingsFormatterTest extends TestCase
{
    #[Test]
    public function supportsCheckGetQueryBindingsIdentifier(): void
    {
        $formatter = new GetQueryBindingsFormatter();
        self::assertTrue($formatter->supports('check-getquery-bindings'));
        self::assertFalse($formatter->supports('getquery'));
    }

    #[Test]
    public function formatsPassingRunAsPassResult(): void
    {
        $line = (new GetQueryBindingsFormatter())->format([
            'baseline_count' => 2,
            'new_count' => 0,
            'offenders' => [],
        ]);

        $envelope = $this->decode($line);
        self::assertSame('check-getquery-bindings', $envelope['tool']);
        self::assertSame('pass', $envelope['result']);
        self::assertSame(2, $envelope['baseline_count']);
        self::assertSame(0, $envelope['new_count']);
    }

    #[Test]
    public function formatsFailingRunAsFailResultWithFailureDetail(): void // FR-008
    {
        $line = (new GetQueryBindingsFormatter())->format([
            'baseline_count' => 2,
            'new_count' => 1,
            'offenders' => [
                ['file' => 'packages/foo/src/Bar.php', 'line' => 42, 'snippet' => '$storage->getQuery()->condition(\'id\', $id)->execute();'],
            ],
        ]);

        $envelope = $this->decode($line);
        self::assertSame('fail', $envelope['result']);
        self::assertSame(1, $envelope['new_count']);
        self::assertCount(1, $envelope['offenders']);
        self::assertSame('packages/foo/src/Bar.php', $envelope['offenders'][0]['file']);
        self::assertSame(42, $envelope['offenders'][0]['line']);
    }

    #[Test]
    public function baselineOffendersAreSilent(): void
    {
        $line = (new GetQueryBindingsFormatter())->format([
            'baseline_count' => 12,
            'new_count' => 0,
            'offenders' => [],
        ]);

        $envelope = $this->decode($line);
        self::assertSame('pass', $envelope['result']);
        self::assertSame(12, $envelope['baseline_count']);
    }

    #[Test]
    public function formatsEmptyRunAsPassResult(): void
    {
        $line = (new GetQueryBindingsFormatter())->format([]);

        $envelope = $this->decode($line);
        self::assertSame('pass', $envelope['result']);
    }

    #[Test]
    public function outputIsValidSingleLineNdjson(): void
    {
        $line = (new GetQueryBindingsFormatter())->format(['new_count' => 0]);

        self::assertStringEndsWith("\n", $line);
        self::assertSame(1, substr_count($line, "\n"));
        $decoded = json_decode(rtrim($line, "\n"), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
    }

    #[Test]
    public function passingEnvelopeStaysUnder500Bytes(): void // NFR-003
    {
        $line = (new GetQueryBindingsFormatter())->format([
            'baseline_count' => 9999,
            'new_count' => 0,
            'offenders' => [],
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
