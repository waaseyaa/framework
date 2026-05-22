<?php

declare(strict_types=1);

namespace Waaseyaa\AgentOutput\Tests\Contract;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AgentOutput\Formatter\DriftDetectorFormatter;

#[CoversClass(DriftDetectorFormatter::class)]
final class DriftDetectorFormatterTest extends TestCase
{
    #[Test]
    public function supportsDriftDetectorIdentifier(): void
    {
        $formatter = new DriftDetectorFormatter();
        self::assertTrue($formatter->supports('drift-detector'));
        self::assertFalse($formatter->supports('drift'));
    }

    #[Test]
    public function formatsPassingRunAsPassResult(): void
    {
        $line = (new DriftDetectorFormatter())->format([
            'stale' => [],
            'ok_specs' => 5,
        ]);

        $envelope = $this->decode($line);
        self::assertSame('drift-detector', $envelope['tool']);
        self::assertSame('pass', $envelope['result']);
        self::assertSame(5, $envelope['ok_specs']);
        self::assertSame(0, $envelope['stale_count']);
        self::assertSame([], $envelope['stale']);
    }

    #[Test]
    public function formatsFailingRunAsFailResultWithFailureDetail(): void // FR-008
    {
        $line = (new DriftDetectorFormatter())->format([
            'stale' => [
                ['spec' => 'docs/specs/foo.md', 'last_touch_commit' => 'abc1234', 'changed_files' => ['packages/foo/src/Bar.php']],
            ],
            'ok_specs' => 3,
        ]);

        $envelope = $this->decode($line);
        self::assertSame('fail', $envelope['result']);
        self::assertSame(1, $envelope['stale_count']);
        self::assertCount(1, $envelope['stale']);
        self::assertSame('docs/specs/foo.md', $envelope['stale'][0]['spec']);
        self::assertSame(['packages/foo/src/Bar.php'], $envelope['stale'][0]['changed_files']);
    }

    #[Test]
    public function parseRawOutputExtractsStaleAndOkSpecs(): void
    {
        $raw = <<<'OUT'
        === Drift Detector ===
        Checking last 5 commits for spec drift...

        Affected specs:

          STALE: docs/specs/foo.md
            Changed files:
                packages/foo/src/Bar.php
                packages/foo/src/Baz.php
          OK: docs/specs/qux.md
            Changed files:
                packages/qux/src/A.php

        Stale specs: 1
        OUT;

        $event = (new DriftDetectorFormatter())->parseRawOutput($raw);

        self::assertSame(1, $event['ok_specs']);
        self::assertCount(1, $event['stale']);
        self::assertSame('docs/specs/foo.md', $event['stale'][0]['spec']);
        self::assertSame(
            ['packages/foo/src/Bar.php', 'packages/foo/src/Baz.php'],
            $event['stale'][0]['changed_files'],
        );
    }

    #[Test]
    public function formatsEmptyRunAsPassResult(): void
    {
        $line = (new DriftDetectorFormatter())->format([]);

        $envelope = $this->decode($line);
        self::assertSame('pass', $envelope['result']);
        self::assertSame(0, $envelope['stale_count']);
    }

    #[Test]
    public function outputIsValidSingleLineNdjson(): void
    {
        $line = (new DriftDetectorFormatter())->format(['stale' => []]);

        self::assertStringEndsWith("\n", $line);
        self::assertSame(1, substr_count($line, "\n"));
        $decoded = json_decode(rtrim($line, "\n"), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
    }

    #[Test]
    public function passingEnvelopeStaysUnder500Bytes(): void // NFR-003
    {
        $line = (new DriftDetectorFormatter())->format([
            'stale' => [],
            'ok_specs' => 100,
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
