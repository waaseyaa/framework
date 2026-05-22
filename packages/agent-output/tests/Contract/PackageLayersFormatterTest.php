<?php

declare(strict_types=1);

namespace Waaseyaa\AgentOutput\Tests\Contract;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AgentOutput\Formatter\PackageLayersFormatter;

#[CoversClass(PackageLayersFormatter::class)]
final class PackageLayersFormatterTest extends TestCase
{
    #[Test]
    public function supportsCheckPackageLayersIdentifier(): void
    {
        $formatter = new PackageLayersFormatter();
        self::assertTrue($formatter->supports('check-package-layers'));
        self::assertFalse($formatter->supports('layers'));
    }

    #[Test]
    public function formatsPassingRunAsPassResult(): void
    {
        $line = (new PackageLayersFormatter())->format([
            'packages_scanned' => 62,
            'violations' => [],
        ]);

        $envelope = $this->decode($line);
        self::assertSame('check-package-layers', $envelope['tool']);
        self::assertSame('pass', $envelope['result']);
        self::assertSame(62, $envelope['packages_scanned']);
        self::assertSame([], $envelope['violations']);
    }

    #[Test]
    public function formatsFailingRunAsFailResultWithFailureDetail(): void // FR-008
    {
        $line = (new PackageLayersFormatter())->format([
            'packages_scanned' => 62,
            'violations' => [
                ['source' => 'waaseyaa/foundation', 'target' => 'waaseyaa/api', 'edge' => 'require'],
                ['source' => 'waaseyaa/access', 'target' => 'waaseyaa/user', 'edge' => 'use'],
            ],
        ]);

        $envelope = $this->decode($line);
        self::assertSame('fail', $envelope['result']);
        self::assertCount(2, $envelope['violations']);
        self::assertSame('waaseyaa/foundation', $envelope['violations'][0]['source']);
        self::assertSame('waaseyaa/api', $envelope['violations'][0]['target']);
        self::assertSame('require', $envelope['violations'][0]['edge']);
    }

    #[Test]
    public function formatsEmptyRunAsPassResult(): void
    {
        $line = (new PackageLayersFormatter())->format([]);

        $envelope = $this->decode($line);
        self::assertSame('pass', $envelope['result']);
        self::assertSame([], $envelope['violations']);
        self::assertSame(0, $envelope['packages_scanned']);
    }

    #[Test]
    public function outputIsValidSingleLineNdjson(): void
    {
        $line = (new PackageLayersFormatter())->format(['violations' => []]);

        self::assertStringEndsWith("\n", $line);
        self::assertSame(1, substr_count($line, "\n"));
        $decoded = json_decode(rtrim($line, "\n"), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
    }

    #[Test]
    public function passingEnvelopeStaysUnder500Bytes(): void // NFR-003
    {
        $line = (new PackageLayersFormatter())->format([
            'packages_scanned' => 99999,
            'violations' => [],
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
