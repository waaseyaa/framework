<?php

declare(strict_types=1);

namespace Waaseyaa\AgentOutput\Tests\Contract;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AgentOutput\Formatter\PhpStanFormatter;

#[CoversClass(PhpStanFormatter::class)]
final class PhpStanFormatterTest extends TestCase
{
    #[Test]
    public function supportsPhpstanToolIdentifier(): void
    {
        $formatter = new PhpStanFormatter();
        self::assertTrue($formatter->supports('phpstan'));
        self::assertFalse($formatter->supports('psalm'));
    }

    #[Test]
    public function formatsPassingRunAsPassResult(): void
    {
        $line = (new PhpStanFormatter())->format([
            'level' => 5,
            'files_scanned' => 1478,
            'errors' => 0,
            'failures' => [],
        ]);

        $envelope = $this->decode($line);
        self::assertSame('phpstan', $envelope['tool']);
        self::assertSame('pass', $envelope['result']);
        self::assertSame(5, $envelope['level']);
        self::assertSame(1478, $envelope['files_scanned']);
        self::assertSame(0, $envelope['errors']);
    }

    #[Test]
    public function formatsFailingRunAsFailResultWithFailureDetail(): void // FR-008
    {
        $line = (new PhpStanFormatter())->format([
            'level' => 5,
            'files_scanned' => 200,
            'errors' => 2,
            'failures' => [
                ['file' => 'src/Foo.php', 'line' => 12, 'identifier' => 'argument.type', 'message' => 'Parameter #1 expects int, string given.'],
                ['file' => 'src/Bar.php', 'line' => 45, 'identifier' => 'method.notFound', 'message' => 'Call to undefined method.'],
            ],
        ]);

        $envelope = $this->decode($line);
        self::assertSame('fail', $envelope['result']);
        self::assertSame(2, $envelope['errors']);
        self::assertCount(2, $envelope['failures']);
        self::assertSame('src/Foo.php', $envelope['failures'][0]['file']);
        self::assertSame('argument.type', $envelope['failures'][0]['identifier']);
    }

    #[Test]
    public function formatsEmptyRunAsPassResult(): void
    {
        $line = (new PhpStanFormatter())->format([]);

        $envelope = $this->decode($line);
        self::assertSame('pass', $envelope['result']);
        self::assertSame(0, $envelope['errors']);
        self::assertNull($envelope['level']);
        self::assertSame([], $envelope['failures']);
    }

    #[Test]
    public function outputIsValidSingleLineNdjson(): void
    {
        $line = (new PhpStanFormatter())->format(['errors' => 0]);

        self::assertStringEndsWith("\n", $line);
        self::assertSame(1, substr_count($line, "\n"));
        $decoded = json_decode(rtrim($line, "\n"), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
    }

    #[Test]
    public function passingEnvelopeStaysUnder500Bytes(): void // NFR-003
    {
        $line = (new PhpStanFormatter())->format([
            'level' => 5,
            'files_scanned' => 99999,
            'errors' => 0,
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
