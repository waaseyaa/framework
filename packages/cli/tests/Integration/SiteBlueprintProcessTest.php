<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Waaseyaa\CLI\Site\SiteInitializationService;
use Waaseyaa\SiteContract\Blueprint\BlueprintDecisionReceipt;
use Waaseyaa\SiteContract\CanonicalJson;
use Waaseyaa\SiteContract\SiteManifestParser;

/** The real console process must carry the reviewed plan and approval unchanged. */
#[CoversNothing]
final class SiteBlueprintProcessTest extends TestCase
{
    private string $root;
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 4);
        $this->root = sys_get_temp_dir() . '/waaseyaa_blueprint_process_' . bin2hex(random_bytes(8));
        mkdir($this->root, 0o700, true);
        foreach (['composer.json', 'composer.lock'] as $path) {
            file_put_contents($this->root . '/' . $path, "{}\n");
            chmod($this->root . '/' . $path, 0o644);
        }
        $this->writeAnswers('minimal.yaml', str_repeat('a', 64));
    }

    /**
     * Bind the checked fixture's placeholder lock digest to this project's
     * real `composer.lock` bytes. The minimal fixture additionally carries a
     * console-markup name so the process proves literal artifact bytes.
     */
    private function writeAnswers(string $fixture, string $lockPlaceholder): void
    {
        $yaml = (string) file_get_contents($this->repoRoot . '/packages/site-contract/tests/Fixtures/Blueprint/valid/' . $fixture);
        $yaml = str_replace($lockPlaceholder, hash('sha256', "{}\n"), $yaml);
        $yaml = str_replace('Minimal Blueprint Application', 'Minimal <info>Blueprint</info> Application', $yaml);
        file_put_contents($this->root . '/answers.yaml', $yaml);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
    }

    public function test_approved_cli_preview_apply_replay_and_strict_doctor(): void
    {
        $receipt = $this->writeReceipt();
        $planned = $this->runInit(['--dry-run', '--decision-receipt=decision.json']);
        self::assertSame('planned', $planned['result']['outcome']);
        self::assertDirectoryDoesNotExist($this->root . '/.waaseyaa');
        self::assertSame(
            hash('sha256', CanonicalJson::encode($planned['evaluation']['plan']) . "\n"),
            $planned['evaluation']['plan_digest'],
        );
        self::assertStringContainsString('<info>Blueprint</info>', CanonicalJson::encode($planned));
        $this->assertFixture('planned', $planned);

        $applied = $this->runInit(['--yes', '--decision-receipt=decision.json']);
        self::assertSame('applied', $applied['result']['outcome']);
        self::assertSame($planned['evaluation']['plan_digest'], $applied['evaluation']['plan_digest']);
        self::assertSame($receipt->digest(), $applied['receipts'][0]['decision_receipt_id']);
        $this->assertFixture('applied', $applied);

        $before = $this->snapshot();
        $replay = $this->runInit(['--yes', '--decision-receipt=decision.json']);
        self::assertSame('no_changes', $replay['result']['outcome']);
        self::assertSame($receipt->digest(), $replay['receipts'][0]['decision_receipt_id']);
        $this->assertFixture('no-changes', $replay);
        self::assertSame($before, $this->snapshot());

        $doctor = $this->runProcess(['site:doctor', '--strict', '--format=json'], 0);
        self::assertSame([], $doctor['findings']);
        self::assertSame($before, $this->snapshot(), 'Strict doctor is a read-only process boundary.');
        self::assertFileDoesNotExist($this->root . '/probe.sqlite');
    }

    /**
     * The complete governance-rich blueprint through the same real console
     * boundary (#2788, FW-SITE-BLUEPRINT-01E): preview, apply, idempotent
     * replay and read-only strict doctor, proving the compiled permission
     * catalogue, discovered policy, governance provider, workflow definition
     * and assignment, and every companion test (including the real
     * JsonApiController proof) are published byte-for-byte as the golden
     * emitter fixtures, bound to the plan digest and the approval receipt.
     */
    public function test_complete_blueprint_console_flow_publishes_governance_and_replays_idempotently(): void
    {
        $this->writeAnswers('complete.yaml', str_repeat('b', 64));
        $receipt = $this->writeReceipt();
        $governancePaths = [
            'config/sync/workflows.assignments.yml',
            'config/waaseyaa-blueprint/relationships.php',
            'src/Access/ApplicationBlueprintPermissions.php',
            'src/Access/ArticlePolicy.php',
            'src/Entity/Article.php',
            'src/Entity/Enum/ArticleStage.php',
            'src/Entity/Person.php',
            'src/Provider/ApplicationBlueprintGovernanceServiceProvider.php',
            'src/Provider/ApplicationBlueprintServiceProvider.php',
            'src/Workflow/EditorialWorkflowDefinition.php',
            'tests/Blueprint/EntityAccessChecksTest.php',
            'tests/Blueprint/GovernanceDefaultDenyTest.php',
            'tests/Blueprint/JsonApiGovernanceChecksTest.php',
            'tests/Blueprint/RolePermissionChecksTest.php',
            'tests/Blueprint/WorkflowTransitionChecksTest.php',
        ];
        $companionTests = array_values(array_filter($governancePaths, static fn(string $path): bool => str_starts_with($path, 'tests/Blueprint/')));

        // Preview: the complete plan is reviewable before any write.
        $planned = $this->runInit(['--dry-run', '--decision-receipt=decision.json']);
        self::assertSame('planned', $planned['result']['outcome']);
        self::assertDirectoryDoesNotExist($this->root . '/.waaseyaa');
        self::assertSame(
            hash('sha256', CanonicalJson::encode($planned['evaluation']['plan']) . "\n"),
            $planned['evaluation']['plan_digest'],
        );
        $plannedArtifacts = [];
        foreach ($planned['evaluation']['plan']['artifacts'] as $artifact) {
            $plannedArtifacts[$artifact['path']] = $artifact['content'];
        }
        foreach ($governancePaths as $path) {
            self::assertArrayHasKey($path, $plannedArtifacts, "planned artifact {$path}");
            self::assertSame($this->golden($path), $plannedArtifacts[$path], "planned bytes for {$path} equal the golden emitter fixture");
            self::assertSame('created', $planned['evaluation']['status'][$path]);
        }
        self::assertSame($companionTests, $planned['evaluation']['plan']['companion_tests']);
        self::assertSame(
            ['App\\Provider\\ApplicationBlueprintGovernanceServiceProvider', 'App\\Provider\\ApplicationBlueprintServiceProvider'],
            array_column($planned['evaluation']['plan']['registrations'], 'fqcn'),
        );
        self::assertStringContainsString('#[PolicyAttribute(entityType: \'article\')]', $plannedArtifacts['src/Access/ArticlePolicy.php'], 'discoverable policy');
        self::assertStringContainsString('implements AccessPolicyInterface, FieldAccessPolicyInterface', $plannedArtifacts['src/Access/ArticlePolicy.php'], 'authorization inputs are edit-sealed');
        self::assertStringContainsString('implements ProvidesRolesInterface, ProvidesPermissionsInterface', $plannedArtifacts['src/Provider/ApplicationBlueprintGovernanceServiceProvider.php'], 'shared permission catalogue contribution');
        self::assertSame("article.article: editorial\n", $plannedArtifacts['config/sync/workflows.assignments.yml'], 'authored binding awaits a verified config:import');
        self::assertStringContainsString('use Waaseyaa\\Api\\JsonApiController;', $plannedArtifacts['tests/Blueprint/JsonApiGovernanceChecksTest.php']);
        $this->assertFixture('complete-planned', $planned);

        // Apply: the identical plan is published under the same approval.
        $applied = $this->runInit(['--yes', '--decision-receipt=decision.json']);
        self::assertSame('applied', $applied['result']['outcome']);
        self::assertSame($planned['evaluation']['plan_digest'], $applied['evaluation']['plan_digest']);
        self::assertSame($planned['evaluation']['plan_digest'], $applied['receipts'][0]['plan_digest']);
        self::assertSame($receipt->digest(), $applied['receipts'][0]['decision_receipt_id']);
        self::assertSame('site.init', $applied['receipts'][0]['operation']);
        foreach ($governancePaths as $path) {
            self::assertContains($path, $applied['result']['changed']);
            self::assertSame($this->golden($path), file_get_contents($this->root . '/' . $path), "published bytes for {$path}");
            self::assertSame(0o644, fileperms($this->root . '/' . $path) & 0o777, "generated source mode for {$path}");
        }
        $evidence = json_decode((string) file_get_contents($this->root . '/.waaseyaa/generated.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('site-application-blueprint-v1', $evidence['application_blueprint']['generator_feature']);
        self::assertSame(
            json_decode($receipt->canonicalJson(), true, flags: JSON_THROW_ON_ERROR),
            $evidence['application_blueprint']['decision_receipt'],
            'applied evidence carries the exact canonical approval',
        );
        self::assertSame(['decision_receipt', 'generator_feature'], array_keys($evidence['application_blueprint']), 'closed evidence shape');
        self::assertSame(0o644, fileperms($this->root . '/.waaseyaa/generated.json') & 0o777, 'transaction-owned evidence carries the managed artifact mode');
        $this->assertFixture('complete-applied', $applied);

        // Replay: an unchanged approved blueprint is a no-op with no byte drift.
        $before = $this->snapshot();
        $replay = $this->runInit(['--yes', '--decision-receipt=decision.json']);
        self::assertSame('no_changes', $replay['result']['outcome']);
        self::assertSame($planned['evaluation']['plan_digest'], $replay['result']['plan_digest']);
        self::assertSame($receipt->digest(), $replay['receipts'][0]['decision_receipt_id']);
        $this->assertFixture('complete-no-changes', $replay);
        self::assertSame($before, $this->snapshot());

        // Strict doctor: green, read-only, and never a database write.
        $doctor = $this->runProcess(['site:doctor', '--strict', '--format=json'], 0);
        self::assertSame([], $doctor['findings']);
        self::assertSame($before, $this->snapshot(), 'Strict doctor is a read-only process boundary.');
        self::assertFileDoesNotExist($this->root . '/probe.sqlite');
    }

    /** The golden emitter fixture bytes for a compiled `complete.yaml` artifact path. */
    private function golden(string $path): string
    {
        $file = $this->repoRoot . '/packages/cli/tests/Fixtures/Blueprint/expected/complete/' . $path;
        self::assertFileExists($file);

        return (string) file_get_contents($file);
    }

    #[DataProvider('refusals')]
    public function test_cli_refusals_preserve_the_entire_project(string $case, string $mode): void
    {
        $options = [$mode];
        if ($case !== 'missing') {
            $options[] = '--decision-receipt=decision.json';
            if ($case === 'malformed') {
                file_put_contents($this->root . '/decision.json', '{');
            } else {
                $this->writeReceipt($case === 'rejected' ? ['decision' => 'rejected'] : ['manifest_digest' => str_repeat('b', 64)]);
            }
        }
        $before = $this->snapshot();
        // The command handler's refusal code 2 is normalized by the real
        // console application to its existing process failure code 1.
        $document = $this->runInit($options, 1);
        self::assertSame(null, $document['evaluation']);
        self::assertSame(null, $document['result']);
        self::assertSame([], $document['receipts']);
        self::assertSame($case === 'malformed' ? 'SITE050_DECISION_RECEIPT_INVALID' : 'GEN011_UNAUTHORIZED_SET_DELTA', $document['errors'][0]['code']);
        $this->assertFixture($case, $document);
        self::assertSame($before, $this->snapshot());
    }

    public static function refusals(): iterable
    {
        foreach (['missing', 'rejected', 'mismatched', 'malformed'] as $case) {
            yield $case . ' preview' => [$case, '--dry-run'];
            yield $case . ' apply' => [$case, '--yes'];
        }
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
        file_put_contents($this->root . '/decision.json', $receipt->canonicalJson() . "\n");

        return $receipt;
    }

    /** @param list<string> $options @return array<string, mixed> */
    private function runInit(array $options, int $exit = 0): array
    {
        return $this->runProcess(['site:init', '--json', '--answers=answers.yaml', ...$options], $exit);
    }

    /** @param list<string> $arguments @return array<string, mixed> */
    private function runProcess(array $arguments, int $exit): array
    {
        $process = new Process(
            [PHP_BINARY, $this->repoRoot . '/packages/cli/bin/waaseyaa', ...$arguments, '--project-root=' . $this->root],
            $this->repoRoot,
            ['APP_ENV' => 'local', 'WAASEYAA_DB' => $this->root . '/probe.sqlite'],
            timeout: 30,
        );
        self::assertSame($exit, $process->run(), $process->getOutput() . $process->getErrorOutput());
        self::assertSame('', $process->getErrorOutput());

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $document */
    private function assertFixture(string $name, array $document): void
    {
        foreach ($document['receipts'] ?? [] as $index => $receipt) {
            self::assertSame(SiteInitializationService::CONTRACT_VERSION, $receipt['authority_version']);
            self::assertNotSame('', $receipt['receipt_id']);
            self::assertNotSame('', $receipt['correlation_id']);
            self::assertSame($receipt['issued_at'], new \DateTimeImmutable($receipt['issued_at'])->format(\DateTimeInterface::RFC3339));
            foreach (['receipt_id', 'correlation_id', 'issued_at'] as $field) {
                $document['receipts'][$index][$field] = '<' . $field . '>';
            }
        }
        $expected = (string) file_get_contents(dirname(__DIR__) . '/Fixtures/SiteInit/BlueprintProcess/' . $name . '.json');
        self::assertSame($expected, CanonicalJson::encode($document) . "\n");
    }

    /** @return array<string, array{sha256: string, mode: int}> */
    private function snapshot(): array
    {
        $snapshot = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $snapshot[substr($file->getPathname(), strlen($this->root) + 1)] = ['sha256' => hash_file('sha256', $file->getPathname()), 'mode' => $file->getPerms() & 0o777];
        }
        ksort($snapshot);

        return $snapshot;
    }
}
