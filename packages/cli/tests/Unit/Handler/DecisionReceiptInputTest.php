<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\CLI\Handler\DecisionReceiptInput;
use Waaseyaa\SiteContract\Blueprint\BlueprintDecision;
use Waaseyaa\SiteContract\Blueprint\BlueprintDecisionReceipt;
use Waaseyaa\SiteContract\Exception\SiteManifestValidationException;

#[CoversClass(DecisionReceiptInput::class)]
final class DecisionReceiptInputTest extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            new Filesystem()->remove($root);
        }
    }

    #[Test]
    public function load_decodes_a_valid_project_relative_receipt(): void
    {
        $root = $this->fixture();
        $receipt = $this->writeReceipt($root, 'decision.json');
        $sourceLabel = 'decision.json';

        $loaded = DecisionReceiptInput::load($sourceLabel, $root);

        self::assertSame($receipt->canonicalJson(), $loaded->canonicalJson());
        self::assertSame(BlueprintDecision::Approved, $loaded->decision);
    }

    #[Test]
    public function load_decodes_a_valid_absolute_receipt_path(): void
    {
        $root = $this->fixture();
        $absolutePath = $root . '/receipts/approved.json';
        $receipt = $this->writeReceipt($root, $absolutePath);

        $loaded = DecisionReceiptInput::load($absolutePath, $root);

        self::assertSame($receipt->canonicalJson(), $loaded->canonicalJson());
    }

    #[Test]
    public function load_reads_the_receipt_exactly_once_per_invocation(): void
    {
        $root = $this->fixture();
        $sourceLabel = 'decision.json';
        $receipt = $this->writeReceipt($root, $sourceLabel);
        $digest = $receipt->digest();

        $loaded = DecisionReceiptInput::load($sourceLabel, $root);

        file_put_contents($root . '/decision.json', '{');

        self::assertSame($digest, $loaded->digest(), 'The returned receipt must reflect the first read, not a later replacement.');
        $this->assertSite050($sourceLabel, $root);
    }

    #[Test]
    public function load_treats_drive_letter_paths_as_absolute(): void
    {
        $root = $this->fixture();
        $sourceLabel = 'Z:/absent-receipt.json';

        $this->assertSite050($sourceLabel, $root);
    }

    #[Test]
    #[DataProvider('unreadableReceiptPaths')]
    public function load_refuses_missing_or_unreadable_receipt_paths(string $setup): void
    {
        $root = $this->fixture();
        $sourceLabel = 'decision.json';
        if ($setup === 'directory') {
            mkdir($root . '/decision.json');
        }

        $this->assertSite050($sourceLabel, $root);
    }

    /** @return iterable<string, array{string}> */
    public static function unreadableReceiptPaths(): iterable
    {
        yield 'missing file' => ['missing'];
        yield 'path replaced by directory' => ['directory'];
    }

    #[Test]
    #[DataProvider('invalidReceiptDocuments')]
    public function load_refuses_invalid_receipt_documents(string $bytes, string $sourceLabel): void
    {
        $root = $this->fixture();
        $path = str_starts_with($sourceLabel, '/') ? $sourceLabel : $root . '/' . $sourceLabel;
        file_put_contents($path, $bytes);

        $this->assertSite050($sourceLabel, $root);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidReceiptDocuments(): iterable
    {
        yield 'malformed json' => ['{', 'decision.json'];
        yield 'json array' => ['[]', 'decision.json'];
        yield 'json scalar' => ['"approved"', 'decision.json'];
        yield 'non-closed object' => ['{"approved":true}', 'decision.json'];
        yield 'closed validation failure' => [
            json_encode([
                'schema' => 'waaseyaa.something_else',
                'version' => 1,
                'decision' => 'approved',
                'blueprint_digest' => str_repeat('a', 64),
                'manifest_digest' => str_repeat('b', 64),
                'actor' => 'operator',
                'decided_at' => '2026-09-05T12:00:00Z',
                'mechanism' => 'manual-review',
            ], JSON_THROW_ON_ERROR),
            'decision.json',
        ];
    }

    private function fixture(): string
    {
        $root = sys_get_temp_dir() . '/waaseyaa_decision_receipt_input_' . bin2hex(random_bytes(8));
        mkdir($root, 0o700, true);
        $this->roots[] = $root;

        return $root;
    }

    /** @param array<string, string> $changes */
    private function writeReceipt(string $root, string $receiptPath, array $changes = []): BlueprintDecisionReceipt
    {
        $receipt = BlueprintDecisionReceipt::fromArray(array_replace([
            'schema' => BlueprintDecisionReceipt::SCHEMA_ID,
            'version' => BlueprintDecisionReceipt::CONTRACT_VERSION,
            'decision' => 'approved',
            'blueprint_digest' => str_repeat('a', 64),
            'manifest_digest' => str_repeat('b', 64),
            'actor' => 'operator',
            'decided_at' => '2026-09-05T12:00:00Z',
            'mechanism' => 'manual-review',
        ], $changes));
        $target = self::isAbsoluteReceiptPath($receiptPath)
            ? $receiptPath
            : rtrim($root, '/\\') . '/' . $receiptPath;
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0o700, true);
        }
        file_put_contents($target, $receipt->canonicalJson() . "\n");

        return $receipt;
    }

    private static function isAbsoluteReceiptPath(string $receiptPath): bool
    {
        return str_starts_with($receiptPath, '/')
            || preg_match('/^[A-Za-z]:[\\\\\/]/D', $receiptPath) === 1;
    }

    private function assertSite050(string $sourceLabel, string $projectRoot): void
    {
        try {
            DecisionReceiptInput::load($sourceLabel, $projectRoot);
            self::fail('Expected SiteManifestValidationException.');
        } catch (SiteManifestValidationException $exception) {
            self::assertSame($sourceLabel, $exception->source, 'The source label must preserve the operator-supplied receipt path.');
            self::assertCount(1, $exception->violations);
            self::assertSame('SITE050_DECISION_RECEIPT_INVALID', $exception->violations[0]->code);
            self::assertSame('/decision_receipt', $exception->violations[0]->path);
            self::assertSame(
                'Expected a valid closed blueprint decision receipt JSON document.',
                $exception->violations[0]->message,
            );
            self::assertNotNull($exception->getPrevious());
        }
    }
}
