<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Site;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\CLI\Site\Blueprint\ApplicationBlueprintCompilerFactory;
use Waaseyaa\CLI\Site\Exception\SiteInitializationCollisionException;
use Waaseyaa\CLI\Site\Exception\SiteInitializationExecutionException;
use Waaseyaa\CLI\Site\SiteArtifactRendererFactory;
use Waaseyaa\CLI\Site\SiteInitializationService;
use Waaseyaa\SiteContract\Blueprint\BlueprintDecision;
use Waaseyaa\SiteContract\Blueprint\BlueprintDecisionReceipt;
use Waaseyaa\SiteContract\CanonicalJson;
use Waaseyaa\SiteContract\Generation\ArtifactApplyOutcome;
use Waaseyaa\SiteContract\Generation\ArtifactApplyRequest;
use Waaseyaa\SiteContract\Generation\ArtifactPlan;
use Waaseyaa\SiteContract\Generation\ArtifactSetEvolution;
use Waaseyaa\SiteContract\Generation\GeneratedArtifact;
use Waaseyaa\SiteContract\Generation\Exception\GenerationErrorCode;
use Waaseyaa\SiteContract\Generation\Exception\GenerationRefusalException;
use Waaseyaa\SiteContract\Generation\GenerationUnitDisposition;
use Waaseyaa\SiteContract\Generation\SiteArtifactRenderer;
use Waaseyaa\SiteContract\SiteManifest;
use Waaseyaa\SiteContract\SiteManifestParser;

#[CoversClass(SiteInitializationService::class)]
final class BlueprintExecutionAdmissionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/waaseyaa_blueprint_execution_' . bin2hex(random_bytes(8));
        mkdir($this->root, 0o700);
        file_put_contents($this->root . '/composer.json', "{\"extra\":{\"waaseyaa\":{\"providers\":[]}}}\n");
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
    }

    #[Test]
    #[DataProvider('invalidApprovals')]
    public function evaluationRefusesInvalidApprovalWithoutWriting(string $case): void
    {
        $manifest = $this->manifest();
        $plan = ApplicationBlueprintCompilerFactory::create()->compile($manifest);
        $before = $this->snapshot();

        try {
            new SiteInitializationService($this->root)->evaluate($plan, decisionReceipt: $this->receiptForCase($manifest, $case));
            self::fail('Expected blueprint execution admission to refuse.');
        } catch (GenerationRefusalException $exception) {
            self::assertSame(GenerationErrorCode::UnauthorizedSetDelta, $exception->violations[0]->code);
        }

        self::assertSame($before, $this->snapshot());
        self::assertDirectoryDoesNotExist($this->root . '/.waaseyaa');
    }

    /** @return iterable<string, array{string}> */
    public static function invalidApprovals(): iterable
    {
        yield 'missing' => ['missing'];
        yield 'rejected' => ['rejected'];
        yield 'manifest mismatch' => ['mismatch'];
        yield 'malformed typed value' => ['malformed'];
    }

    #[Test]
    #[DataProvider('invalidApprovals')]
    public function controlledApplyRefusesInvalidApprovalBeforeCreatingTheLock(string $case): void
    {
        $manifest = $this->manifest();
        $plan = ApplicationBlueprintCompilerFactory::create()->compile($manifest);
        $valid = $this->receipt($manifest);
        $service = new SiteInitializationService($this->root);
        $evaluation = $service->evaluate($plan, decisionReceipt: $valid);
        $request = new ArtifactApplyRequest($plan, $plan->digest, $evaluation->projectStateDigest);
        $before = $this->snapshot();

        $invocation = $service->apply($request, decisionReceipt: $this->receiptForCase($manifest, $case));

        self::assertSame(ArtifactApplyOutcome::Refused, $invocation->result->outcome);
        self::assertSame(GenerationErrorCode::UnauthorizedSetDelta, $invocation->result->errors[0]->code);
        self::assertSame($before, $this->snapshot());
        self::assertDirectoryDoesNotExist($this->root . '/.waaseyaa');
        self::assertNull($invocation->receipts[0]->decisionReceiptId);
    }

    #[Test]
    public function legacyRendererCannotCarryABlueprintPastTheReceiptGate(): void
    {
        $manifest = $this->manifest();
        $rendered = SiteArtifactRendererFactory::create()->render($manifest);
        $plan = new ArtifactPlan(
            SiteArtifactRenderer::class,
            $rendered->generatorVersion,
            'site',
            GenerationUnitDisposition::Managed,
            $rendered->manifestDigest,
            array_values(array_filter(
                $rendered->artifacts,
                static fn($artifact): bool => $artifact->path !== '.waaseyaa/generated.json',
            )),
            setEvolution: ArtifactSetEvolution::Additive,
        );

        $this->expectGen011();
        new SiteInitializationService($this->root)->evaluate($plan, decisionReceipt: $this->receipt($manifest));
    }

    #[Test]
    public function approvedBlueprintPublishesCanonicalEvidenceAndDecisionIdentity(): void
    {
        $manifest = $this->manifest();
        $receipt = $this->receipt($manifest);
        $plan = ApplicationBlueprintCompilerFactory::create()->compile($manifest);
        $service = new SiteInitializationService($this->root);
        $evaluation = $service->evaluate($plan, decisionReceipt: $receipt);

        $invocation = $service->apply(
            new ArtifactApplyRequest($plan, $plan->digest, $evaluation->projectStateDigest),
            decisionReceipt: $receipt,
        );

        self::assertSame(ArtifactApplyOutcome::Applied, $invocation->result->outcome);
        self::assertSame($receipt->digest(), $invocation->receipts[array_key_last($invocation->receipts)]->decisionReceiptId);
        $metadata = json_decode((string) file_get_contents($this->root . '/.waaseyaa/generated.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertEquals([
            'generator_feature' => 'site-application-blueprint-v1',
            'decision_receipt' => $receipt->toArray(),
        ], $metadata['application_blueprint']);
        self::assertArrayNotHasKey('units', $metadata, 'The blueprint compiler still publishes the implicit root site unit.');
    }

    #[Test]
    public function explicitDecisionIdentityCannotConflictWithTheVerifiedReceipt(): void
    {
        $manifest = $this->manifest();
        $receipt = $this->receipt($manifest);
        $plan = ApplicationBlueprintCompilerFactory::create()->compile($manifest);
        $service = new SiteInitializationService($this->root);
        $evaluation = $service->evaluate($plan, decisionReceipt: $receipt);

        $invocation = $service->apply(
            new ArtifactApplyRequest($plan, $plan->digest, $evaluation->projectStateDigest),
            decisionReceiptId: 'different-decision',
            decisionReceipt: $receipt,
        );

        self::assertSame(ArtifactApplyOutcome::Refused, $invocation->result->outcome);
        self::assertSame(GenerationErrorCode::UnauthorizedSetDelta, $invocation->result->errors[0]->code);
        self::assertDirectoryDoesNotExist($this->root . '/.waaseyaa');
        self::assertNull($invocation->receipts[0]->decisionReceiptId);
    }

    #[Test]
    public function initializeAcceptsTheCompiledPlanForAMutationFreeApprovedPreview(): void
    {
        $manifest = $this->manifest();
        $receipt = $this->receipt($manifest);
        $plan = ApplicationBlueprintCompilerFactory::create()->compile($manifest);
        $before = $this->snapshot();

        $result = new SiteInitializationService($this->root)->initialize(
            $plan,
            dryRun: true,
            decisionReceipt: $receipt,
        );

        self::assertSame(ArtifactApplyOutcome::Planned, $result->applyResult->outcome);
        self::assertContains('.waaseyaa/generated.json', $result->changedPaths);
        self::assertContains('src/Entity/Article.php', $result->changedPaths);
        self::assertSame($before, $this->snapshot());
        self::assertDirectoryDoesNotExist($this->root . '/.waaseyaa');
    }

    #[Test]
    public function approvedPlainToBlueprintTransitionSucceedsButReverseTransitionIsGen011(): void
    {
        $service = new SiteInitializationService($this->root);
        $plainManifest = new SiteManifestParser()->parse($this->blueprintFreeYaml(), '<plain>');
        $plainSite = SiteArtifactRendererFactory::create()->render($plainManifest);
        $service->initialize($plainSite);
        $plainMetadata = json_decode((string) file_get_contents($this->root . '/.waaseyaa/generated.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('application_blueprint', $plainMetadata);

        $manifest = $this->manifest();
        $receipt = $this->receipt($manifest);
        $plan = ApplicationBlueprintCompilerFactory::create()->compile($manifest);
        $result = $service->initialize($plan, decisionReceipt: $receipt);

        self::assertSame(ArtifactApplyOutcome::Applied, $result->applyResult->outcome);
        self::assertFileExists($this->root . '/src/Entity/Article.php');
        self::assertArrayHasKey(
            'application_blueprint',
            json_decode((string) file_get_contents($this->root . '/.waaseyaa/generated.json'), true, flags: JSON_THROW_ON_ERROR),
        );

        $this->expectGen011();
        $service->initialize($plainSite, dryRun: true);
    }

    #[Test]
    public function aDifferentMatchingApprovalBecomesTheRecordedEvidenceAndThenReplaysExactly(): void
    {
        $manifest = $this->manifest();
        $first = $this->receipt($manifest);
        $second = BlueprintDecisionReceipt::fromArray([
            'schema' => BlueprintDecisionReceipt::SCHEMA_ID,
            'version' => BlueprintDecisionReceipt::CONTRACT_VERSION,
            'decision' => 'approved',
            'blueprint_digest' => $manifest->applicationBlueprint->digest,
            'manifest_digest' => $manifest->digest,
            'actor' => 'second-reviewer',
            'decided_at' => '2026-09-05T13:00:00Z',
            'mechanism' => 'second-focused-review',
        ]);
        $plan = ApplicationBlueprintCompilerFactory::create()->compile($manifest);
        $service = new SiteInitializationService($this->root);
        $service->initialize($plan, decisionReceipt: $first);

        $preview = $service->initialize($plan, dryRun: true, decisionReceipt: $second);
        self::assertSame(['.waaseyaa/generated.json'], $preview->changedPaths);

        $applied = $service->initialize($plan, decisionReceipt: $second);
        self::assertSame(ArtifactApplyOutcome::Applied, $applied->applyResult->outcome);
        self::assertSame($second->digest(), $applied->receipts[array_key_last($applied->receipts)]->decisionReceiptId);
        $metadata = json_decode((string) file_get_contents($this->root . '/.waaseyaa/generated.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('second-reviewer', $metadata['application_blueprint']['decision_receipt']['actor']);

        $replay = $service->initialize($plan, dryRun: true, decisionReceipt: $second);
        self::assertSame([], $replay->changedPaths);
    }

    #[Test]
    public function missingApprovalCannotRecoverAnInterruptedBlueprintApplyAndRecoveryNeverBorrowsTheNextApproval(): void
    {
        $manifest = $this->manifest();
        $first = $this->receipt($manifest);
        $second = BlueprintDecisionReceipt::fromArray([
            'schema' => BlueprintDecisionReceipt::SCHEMA_ID,
            'version' => BlueprintDecisionReceipt::CONTRACT_VERSION,
            'decision' => 'approved',
            'blueprint_digest' => $manifest->applicationBlueprint->digest,
            'manifest_digest' => $manifest->digest,
            'actor' => 'recovery-reviewer',
            'decided_at' => '2026-09-05T14:00:00Z',
            'mechanism' => 'recovery-focused-review',
        ]);
        $plan = ApplicationBlueprintCompilerFactory::create()->compile($manifest);
        $evaluation = new SiteInitializationService($this->root)->evaluate($plan, decisionReceipt: $first);
        $request = new ArtifactApplyRequest($plan, $plan->digest, $evaluation->projectStateDigest);
        $fault = static function (string $stage): void {
            if ($stage === 'after-replace') {
                throw new \Error('simulated blueprint process death');
            }
        };

        try {
            new SiteInitializationService($this->root, $fault)->initialize($plan, decisionReceipt: $first);
            self::fail('Expected simulated blueprint process death.');
        } catch (\Error $error) {
            self::assertSame('simulated blueprint process death', $error->getMessage());
        }
        self::assertFileExists($this->root . '/.waaseyaa/site-init.transaction.json');
        $interrupted = $this->snapshot();

        $missing = new SiteInitializationService($this->root)->apply($request);
        self::assertSame(ArtifactApplyOutcome::Refused, $missing->result->outcome);
        self::assertSame(GenerationErrorCode::UnauthorizedSetDelta, $missing->result->errors[0]->code);
        self::assertSame($interrupted, $this->snapshot(), 'Missing approval must not enter journal recovery.');
        self::assertNull($missing->receipts[0]->decisionReceiptId);

        $recovered = new SiteInitializationService($this->root)->apply($request, decisionReceipt: $second);
        self::assertSame(ArtifactApplyOutcome::Applied, $recovered->result->outcome);
        self::assertTrue($recovered->result->recoveredInterruptedTransaction);
        self::assertCount(2, $recovered->receipts);
        self::assertSame('site.recover', $recovered->receipts[0]->operation);
        self::assertNull($recovered->receipts[0]->decisionReceiptId);
        self::assertSame($second->digest(), $recovered->receipts[1]->decisionReceiptId);
        self::assertFileDoesNotExist($this->root . '/.waaseyaa/site-init.transaction.json');
    }

    #[Test]
    public function aPriorBlueprintManifestCannotLoseItsEvidenceBeforeANonRootUpdate(): void
    {
        $manifest = $this->manifest();
        $receipt = $this->receipt($manifest);
        $service = new SiteInitializationService($this->root);
        $service->initialize(
            ApplicationBlueprintCompilerFactory::create()->compile($manifest),
            decisionReceipt: $receipt,
        );
        $metadataPath = $this->root . '/.waaseyaa/generated.json';
        $metadata = json_decode((string) file_get_contents($metadataPath), true, flags: JSON_THROW_ON_ERROR);
        unset($metadata['application_blueprint']);
        file_put_contents($metadataPath, CanonicalJson::encode($metadata) . "\n");
        $before = $this->snapshot();
        $unitPlan = new ArtifactPlan(
            'ExampleCompiler',
            1,
            'scaffold:extra',
            GenerationUnitDisposition::Managed,
            str_repeat('b', 64),
            [new GeneratedArtifact('src/Extra.php', "<?php\n")],
        );

        try {
            $service->evaluate($unitPlan);
            self::fail('Expected missing prior blueprint evidence to refuse the non-root update.');
        } catch (GenerationRefusalException $exception) {
            self::assertSame(GenerationErrorCode::UnauthorizedSetDelta, $exception->violations[0]->code);
        }

        self::assertSame($before, $this->snapshot());
        self::assertFileDoesNotExist($this->root . '/src/Extra.php');
    }

    #[Test]
    public function aBlueprintNoChangeAfterResidueRecoveryRetainsCurrentApprovalAttribution(): void
    {
        $manifest = $this->manifest();
        $receipt = $this->receipt($manifest);
        $plan = ApplicationBlueprintCompilerFactory::create()->compile($manifest);
        $service = new SiteInitializationService($this->root);
        $service->initialize($plan, decisionReceipt: $receipt);
        $evaluation = $service->evaluate($plan, decisionReceipt: $receipt);
        $request = new ArtifactApplyRequest($plan, $plan->digest, $evaluation->projectStateDigest);
        $residue = $this->root . '/.waaseyaa/site-init-stage-' . str_repeat('a', 24);
        mkdir($residue, 0o700);
        file_put_contents($residue . '/0000.artifact', "orphaned stage bytes\n");

        $invocation = $service->apply($request, decisionReceipt: $receipt);

        self::assertSame(ArtifactApplyOutcome::NoChanges, $invocation->result->outcome);
        self::assertTrue($invocation->result->recoveredInterruptedTransaction);
        self::assertCount(2, $invocation->receipts);
        self::assertSame('site.recover', $invocation->receipts[0]->operation);
        self::assertNull($invocation->receipts[0]->decisionReceiptId);
        self::assertSame('site.init', $invocation->receipts[1]->operation);
        self::assertSame($receipt->digest(), $invocation->receipts[1]->decisionReceiptId);
        self::assertSame($invocation->receipts[0]->receiptId, $invocation->receipts[1]->causationReceiptId);
    }
    #[Test]
    public function aBlueprintRollbackReceiptIsFollowedByATerminalFailureWithCurrentApproval(): void
    {
        $manifest = $this->manifest();
        $receipt = $this->receipt($manifest);
        $fault = static function (string $stage): void {
            if ($stage === 'after-replace') {
                throw new \RuntimeException('simulated blueprint rollback');
            }
        };

        try {
            new SiteInitializationService($this->root, $fault)->initialize(
                ApplicationBlueprintCompilerFactory::create()->compile($manifest),
                decisionReceipt: $receipt,
            );
            self::fail('Expected the blueprint publication fault.');
        } catch (SiteInitializationExecutionException $exception) {
            self::assertCount(2, $exception->receipts);
            self::assertSame('site.recover', $exception->receipts[0]->operation);
            self::assertSame('recovered', $exception->receipts[0]->outcome->value);
            self::assertNull($exception->receipts[0]->decisionReceiptId);
            self::assertSame('site.init', $exception->receipts[1]->operation);
            self::assertSame('failed', $exception->receipts[1]->outcome->value);
            self::assertSame($receipt->digest(), $exception->receipts[1]->decisionReceiptId);
            self::assertSame($exception->receipts[0]->receiptId, $exception->receipts[1]->causationReceiptId);
        }
    }
    #[Test]
    public function plainRecoveryRetainsItsLegacyScalarDecisionContextAndReceiptShape(): void
    {
        $manifest = new SiteManifestParser()->parse($this->blueprintFreeYaml(), '<plain>');
        $site = SiteArtifactRendererFactory::create()->render($manifest);
        $plan = new ArtifactPlan(
            SiteArtifactRenderer::class,
            $site->generatorVersion,
            'site',
            GenerationUnitDisposition::Managed,
            $site->manifestDigest,
            array_values(array_filter(
                $site->artifacts,
                static fn(GeneratedArtifact $artifact): bool => $artifact->path !== '.waaseyaa/generated.json',
            )),
            setEvolution: ArtifactSetEvolution::Additive,
        );
        $service = new SiteInitializationService($this->root);
        $service->initialize($site);
        $evaluation = $service->evaluate($plan);
        $request = new ArtifactApplyRequest($plan, $plan->digest, $evaluation->projectStateDigest);
        $residue = $this->root . '/.waaseyaa/site-init-stage-' . str_repeat('b', 24);
        mkdir($residue, 0o700);
        file_put_contents($residue . '/0000.artifact', "orphaned plain stage bytes\n");

        $invocation = $service->apply($request, decisionReceiptId: 'legacy-decision-7');

        self::assertSame(ArtifactApplyOutcome::NoChanges, $invocation->result->outcome);
        self::assertCount(1, $invocation->receipts);
        self::assertSame('site.recover', $invocation->receipts[0]->operation);
        self::assertSame('legacy-decision-7', $invocation->receipts[0]->decisionReceiptId);
    }
    #[Test]
    public function metadataReaderRejectsInvalidBlueprintEvidence(): void
    {
        $manifest = $this->manifest();
        $receipt = $this->receipt($manifest);
        $plan = ApplicationBlueprintCompilerFactory::create()->compile($manifest);
        $service = new SiteInitializationService($this->root);
        $service->initialize($plan, decisionReceipt: $receipt);

        $path = $this->root . '/.waaseyaa/generated.json';
        $metadata = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $metadata['application_blueprint']['generator_feature'] = 'site-application-blueprint-v2';
        file_put_contents($path, CanonicalJson::encode($metadata) . "\n");

        $this->expectException(SiteInitializationCollisionException::class);
        $this->expectExceptionMessage('invalid blueprint evidence');
        $service->readUnitMetadata();
    }

    private function blueprintFreeYaml(): string
    {
        return strstr(
            (string) file_get_contents(dirname(__DIR__, 4) . '/site-contract/tests/Fixtures/Blueprint/valid/minimal.yaml'),
            "application_blueprint:\n",
            true,
        );
    }

    private function manifest(): SiteManifest
    {
        $yaml = (string) file_get_contents(
            dirname(__DIR__, 4) . '/site-contract/tests/Fixtures/Blueprint/valid/minimal.yaml',
        );

        return new SiteManifestParser()->parse($yaml, 'minimal.yaml');
    }

    private function receipt(SiteManifest $manifest, string $decision = 'approved', ?string $manifestDigest = null): BlueprintDecisionReceipt
    {
        return BlueprintDecisionReceipt::fromArray([
            'schema' => BlueprintDecisionReceipt::SCHEMA_ID,
            'version' => BlueprintDecisionReceipt::CONTRACT_VERSION,
            'decision' => $decision,
            'blueprint_digest' => $manifest->applicationBlueprint->digest,
            'manifest_digest' => $manifestDigest ?? $manifest->digest,
            'actor' => 'engine-reviewer',
            'decided_at' => '2026-09-05T12:00:00Z',
            'mechanism' => 'focused-engine-test',
        ]);
    }

    private function receiptForCase(SiteManifest $manifest, string $case): ?BlueprintDecisionReceipt
    {
        return match ($case) {
            'missing' => null,
            'rejected' => $this->receipt($manifest, 'rejected'),
            'mismatch' => $this->receipt($manifest, manifestDigest: str_repeat('a', 64)),
            'malformed' => new BlueprintDecisionReceipt(
                BlueprintDecision::Approved,
                $manifest->applicationBlueprint->digest,
                $manifest->digest,
                '',
                'not-a-time',
                '',
            ),
            default => throw new \LogicException("Unknown approval case: {$case}"),
        };
    }

    private function expectGen011(): void
    {
        $this->expectException(GenerationRefusalException::class);
        $this->expectExceptionMessage('GEN011');
    }

    /** @return array<string, string> */
    private function snapshot(): array
    {
        $snapshot = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $item) {
            if ($item->isFile() && !$item->isLink()) {
                $snapshot[substr($item->getPathname(), strlen($this->root) + 1)] = (string) file_get_contents($item->getPathname());
            }
        }
        ksort($snapshot, SORT_STRING);

        return $snapshot;
    }
}
