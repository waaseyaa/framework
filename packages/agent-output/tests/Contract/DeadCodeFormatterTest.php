<?php

declare(strict_types=1);

namespace Waaseyaa\AgentOutput\Tests\Contract;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AgentOutput\Formatter\DeadCodeFormatter;

#[CoversClass(DeadCodeFormatter::class)]
final class DeadCodeFormatterTest extends TestCase
{
    #[Test]
    public function supportsCheckDeadCodeIdentifier(): void
    {
        $formatter = new DeadCodeFormatter();
        self::assertTrue($formatter->supports('check-dead-code'));
        self::assertFalse($formatter->supports('dead-code'));
    }

    #[Test]
    public function formatsPassingRunAsPassResult(): void
    {
        $line = (new DeadCodeFormatter())->format([
            'baseline_count' => 66,
            'new_count' => 0,
            'findings' => [],
        ]);

        $envelope = $this->decode($line);
        self::assertSame('check-dead-code', $envelope['tool']);
        self::assertSame('pass', $envelope['result']);
        self::assertSame(66, $envelope['baseline_count']);
        self::assertSame(0, $envelope['new_count']);
    }

    #[Test]
    public function formatsFailingRunAsFailResultWithFailureDetail(): void // FR-008
    {
        $line = (new DeadCodeFormatter())->format([
            'baseline_count' => 66,
            'new_count' => 2,
            'findings' => [
                ['fqcn' => 'Foo\\Bar::unused', 'file' => 'packages/foo/src/Bar.php', 'line' => 17, 'kind' => 'unused-method'],
                ['fqcn' => 'Foo\\Baz::abandoned', 'file' => 'packages/foo/src/Baz.php', 'line' => 42, 'kind' => 'unused-method'],
            ],
        ]);

        $envelope = $this->decode($line);
        self::assertSame('fail', $envelope['result']);
        self::assertSame(2, $envelope['new_count']);
        self::assertCount(2, $envelope['findings']);
        self::assertSame('Foo\\Bar::unused', $envelope['findings'][0]['fqcn']);
        self::assertSame('packages/foo/src/Bar.php', $envelope['findings'][0]['file']);
    }

    #[Test]
    public function baselineFindingsAreSilent(): void
    {
        // 50 baselined findings, zero new — the envelope must still PASS.
        $line = (new DeadCodeFormatter())->format([
            'baseline_count' => 50,
            'new_count' => 0,
            'findings' => [],
        ]);

        $envelope = $this->decode($line);
        self::assertSame('pass', $envelope['result']);
        self::assertSame(50, $envelope['baseline_count'], 'Baseline count is surfaced for trend tracking.');
    }

    #[Test]
    public function formatsEmptyRunAsPassResult(): void
    {
        $line = (new DeadCodeFormatter())->format([]);

        $envelope = $this->decode($line);
        self::assertSame('pass', $envelope['result']);
        self::assertSame(0, $envelope['new_count']);
    }

    #[Test]
    public function outputIsValidSingleLineNdjson(): void
    {
        $line = (new DeadCodeFormatter())->format(['new_count' => 0]);

        self::assertStringEndsWith("\n", $line);
        self::assertSame(1, substr_count($line, "\n"));
        $decoded = json_decode(rtrim($line, "\n"), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
    }

    #[Test]
    public function passingEnvelopeStaysUnder500Bytes(): void // NFR-003
    {
        $line = (new DeadCodeFormatter())->format([
            'baseline_count' => 9999,
            'new_count' => 0,
            'findings' => [],
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
