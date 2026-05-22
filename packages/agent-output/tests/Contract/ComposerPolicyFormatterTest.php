<?php

declare(strict_types=1);

namespace Waaseyaa\AgentOutput\Tests\Contract;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AgentOutput\Formatter\ComposerPolicyFormatter;

#[CoversClass(ComposerPolicyFormatter::class)]
final class ComposerPolicyFormatterTest extends TestCase
{
    #[Test]
    public function supportsCheckComposerPolicyIdentifier(): void
    {
        $formatter = new ComposerPolicyFormatter();
        self::assertTrue($formatter->supports('check-composer-policy'));
        self::assertFalse($formatter->supports('composer-policy'));
    }

    #[Test]
    public function formatsPassingRunAsPassResult(): void
    {
        $line = (new ComposerPolicyFormatter())->format([
            'files_scanned' => 62,
            'failures' => [],
        ]);

        $envelope = $this->decode($line);
        self::assertSame('check-composer-policy', $envelope['tool']);
        self::assertSame('pass', $envelope['result']);
        self::assertSame(62, $envelope['files_scanned']);
        self::assertSame([], $envelope['failures']);
    }

    #[Test]
    public function formatsFailingRunAsFailResultWithFailureDetail(): void // FR-008
    {
        $line = (new ComposerPolicyFormatter())->format([
            'files_scanned' => 62,
            'failures' => [
                ['file' => 'packages/foo/composer.json', 'rule_code' => 'CP002', 'explanation' => '@dev constraints are forbidden.'],
                ['file' => 'packages/bar/composer.json', 'rule_code' => 'CP006', 'explanation' => 'self.version allowed only in root composer.json.'],
            ],
        ]);

        $envelope = $this->decode($line);
        self::assertSame('fail', $envelope['result']);
        self::assertCount(2, $envelope['failures']);
        self::assertSame('CP002', $envelope['failures'][0]['rule_code']);
        self::assertSame('packages/foo/composer.json', $envelope['failures'][0]['file']);
        self::assertSame('@dev constraints are forbidden.', $envelope['failures'][0]['explanation']);
    }

    #[Test]
    public function formatsEmptyRunAsPassResult(): void
    {
        $line = (new ComposerPolicyFormatter())->format([]);

        $envelope = $this->decode($line);
        self::assertSame('pass', $envelope['result']);
        self::assertSame([], $envelope['failures']);
    }

    #[Test]
    public function outputIsValidSingleLineNdjson(): void
    {
        $line = (new ComposerPolicyFormatter())->format(['failures' => []]);

        self::assertStringEndsWith("\n", $line);
        self::assertSame(1, substr_count($line, "\n"));
        $decoded = json_decode(rtrim($line, "\n"), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
    }

    #[Test]
    public function passingEnvelopeStaysUnder500Bytes(): void // NFR-003
    {
        $line = (new ComposerPolicyFormatter())->format([
            'files_scanned' => 9999,
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
