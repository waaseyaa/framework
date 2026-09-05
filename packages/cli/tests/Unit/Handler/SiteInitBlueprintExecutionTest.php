<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\CLI\Handler\SiteInitHandler;
use Waaseyaa\CLI\Provider\SiteServiceProvider;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\SiteContract\Blueprint\BlueprintDecisionReceipt;
use Waaseyaa\SiteContract\CanonicalJson;
use Waaseyaa\SiteContract\SiteManifestParser;

#[CoversClass(SiteInitHandler::class)]
final class SiteInitBlueprintExecutionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/waaseyaa_blueprint_cli_' . bin2hex(random_bytes(8));
        mkdir($this->root, 0o755, true);
        file_put_contents($this->root . '/composer.json', "{}\n");
        file_put_contents($this->root . '/composer.lock', "{}\n");
        $yaml = (string) file_get_contents(dirname(__DIR__, 4) . '/site-contract/tests/Fixtures/Blueprint/valid/minimal.yaml');
        $yaml = str_replace(str_repeat('a', 64), hash('sha256', "{}\n"), $yaml);
        $yaml = str_replace('Minimal Blueprint Application', 'Minimal <info>Blueprint</info> Application', $yaml);
        file_put_contents($this->root . '/answers.yaml', $yaml);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
    }

    public function test_approved_preview_apply_and_replay_share_the_existing_json_contract(): void
    {
        $receipt = $this->writeReceipt();
        $preview = $this->runCommand(['--dry-run', '--decision-receipt=decision.json']);
        self::assertSame(0, $preview->getExitCode(), $preview->getStdout() . $preview->getStderr());
        $planned = $this->document($preview);
        self::assertSame('planned', $planned['result']['outcome']);
        self::assertSame([], $planned['receipts']);
        self::assertDirectoryDoesNotExist($this->root . '/.waaseyaa');
        self::assertSame(
            $planned['evaluation']['plan_digest'],
            hash('sha256', CanonicalJson::encode($planned['evaluation']['plan']) . "\n"),
            'Presentation formatting must not alter transported plan bytes.',
        );
        self::assertStringContainsString('<info>Blueprint</info>', $preview->getStdout());

        $apply = $this->runCommand(['--yes', '--decision-receipt=decision.json']);
        self::assertSame(0, $apply->getExitCode(), $apply->getStdout() . $apply->getStderr());
        $applied = $this->document($apply);
        self::assertSame('applied', $applied['result']['outcome']);
        self::assertSame($planned['evaluation']['plan_digest'], $applied['evaluation']['plan_digest']);
        self::assertSame(hash('sha256', $receipt->canonicalJson()), $applied['receipts'][0]['decision_receipt_id']);
        self::assertFileExists($this->root . '/src/Entity/Article.php');
        self::assertFileExists($this->root . '/src/Provider/ApplicationBlueprintServiceProvider.php');
        $metadataBytes = (string) file_get_contents($this->root . '/.waaseyaa/generated.json');
        $metadata = json_decode($metadataBytes, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($receipt->canonicalJson(), CanonicalJson::encode($metadata['application_blueprint']['decision_receipt']));

        $replay = $this->runCommand(['--yes', '--decision-receipt=decision.json']);
        self::assertSame(0, $replay->getExitCode(), $replay->getStdout() . $replay->getStderr());
        self::assertSame('no_changes', $this->document($replay)['result']['outcome']);
        self::assertSame($metadataBytes, file_get_contents($this->root . '/.waaseyaa/generated.json'));
    }

    #[DataProvider('invalidApprovalCases')]
    public function test_unapproved_blueprint_refuses_before_any_generated_state(string $case, bool $dryRun): void
    {
        $options = [$dryRun ? '--dry-run' : '--yes'];
        if ($case !== 'missing') {
            $changes = $case === 'rejected'
                ? ['decision' => 'rejected']
                : ['manifest_digest' => str_repeat('b', 64)];
            $this->writeReceipt($changes);
            $options[] = '--decision-receipt=decision.json';
        }
        $before = file_get_contents($this->root . '/composer.json');
        $tester = $this->runCommand($options);

        self::assertSame(2, $tester->getExitCode());
        self::assertSame('', $tester->getStderr());
        self::assertSame('GEN011_UNAUTHORIZED_SET_DELTA', $this->document($tester)['errors'][0]['code']);
        self::assertDirectoryDoesNotExist($this->root . '/.waaseyaa');
        self::assertSame($before, file_get_contents($this->root . '/composer.json'));
    }

    public static function invalidApprovalCases(): iterable
    {
        foreach (['missing', 'rejected', 'mismatched'] as $case) {
            yield $case . ' dry-run' => [$case, true];
            yield $case . ' apply' => [$case, false];
        }
    }

    #[DataProvider('malformedReceiptCases')]
    public function test_malformed_decision_document_has_site050_and_no_writes(string $bytes): void
    {
        file_put_contents($this->root . '/decision.json', $bytes);
        foreach (['--dry-run', '--yes'] as $mode) {
            $tester = $this->runCommand([$mode, '--decision-receipt=decision.json']);
            self::assertSame(2, $tester->getExitCode());
            self::assertSame('', $tester->getStderr());
            self::assertSame('SITE050_DECISION_RECEIPT_INVALID', $this->document($tester)['errors'][0]['code']);
            self::assertDirectoryDoesNotExist($this->root . '/.waaseyaa');
        }
    }

    public static function malformedReceiptCases(): iterable
    {
        yield 'invalid json' => ['{'];
        yield 'array' => ['[]'];
        yield 'wrong closed shape' => ['{"approved":true}'];
        $base = [
            'schema' => BlueprintDecisionReceipt::SCHEMA_ID,
            'version' => BlueprintDecisionReceipt::CONTRACT_VERSION,
            'decision' => 'approved',
            'blueprint_digest' => str_repeat('a', 64),
            'manifest_digest' => str_repeat('b', 64),
            'actor' => 'operator',
            'decided_at' => '2026-09-05T12:00:00Z',
            'mechanism' => 'manual-review',
        ];
        yield 'unknown field in valid envelope' => [json_encode($base + ['trusted' => true], JSON_THROW_ON_ERROR)];
        yield 'invalid digest in valid envelope' => [json_encode(array_replace($base, ['manifest_digest' => 'invalid']), JSON_THROW_ON_ERROR)];
        yield 'wrong actor type in valid envelope' => [json_encode(array_replace($base, ['actor' => []]), JSON_THROW_ON_ERROR)];
        unset($base['actor']);
        yield 'missing actor in valid envelope' => [json_encode($base, JSON_THROW_ON_ERROR)];
    }

    /** @param array<string, string> $changes */
    private function writeReceipt(array $changes = []): BlueprintDecisionReceipt
    {
        $manifest = new SiteManifestParser()->parse((string) file_get_contents($this->root . '/answers.yaml'));
        $receipt = BlueprintDecisionReceipt::fromArray(array_replace([
            'schema' => BlueprintDecisionReceipt::SCHEMA_ID,
            'version' => BlueprintDecisionReceipt::CONTRACT_VERSION,
            'decision' => 'approved',
            'blueprint_digest' => $manifest->applicationBlueprint->digest,
            'manifest_digest' => $manifest->digest,
            'actor' => 'operator',
            'decided_at' => '2026-09-05T12:00:00Z',
            'mechanism' => 'manual-review',
        ], $changes));
        file_put_contents($this->root . '/decision.json', CanonicalJson::encode($receipt->toArray()) . "\n");

        return $receipt;
    }

    /** @param list<string> $options */
    private function runCommand(array $options): CliTester
    {
        $container = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \LogicException('The boot-free site command must not resolve services.');
            }

            public function has(string $id): bool
            {
                return false;
            }
        };
        $tester = CliTester::for(SiteServiceProvider::siteInitCommand($this->root), $container);
        $tester->execute(['--json', '--answers=answers.yaml', ...$options]);

        return $tester;
    }

    /** @return array<string, mixed> */
    private function document(CliTester $tester): array
    {
        return json_decode($tester->getStdout(), true, flags: JSON_THROW_ON_ERROR);
    }
}
