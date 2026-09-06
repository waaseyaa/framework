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
    #[DataProvider('generatorVersionTransitionModes')]
    public function approvedPlainToBlueprintTransitionRefusesGeneratorVersionChangeWithoutWriting(bool $dryRun): void
    {
        $service = new SiteInitializationService($this->root);
        $plainManifest = new SiteManifestParser()->parse($this->blueprintFreeYaml(), '<plain>');
        self::assertSame(1, $plainManifest->generatorVersion);
        $service->initialize(SiteArtifactRendererFactory::create()->render($plainManifest));
        unlink($this->root . '/.waaseyaa/site-init.lock');

        $yaml = (string) file_get_contents(
            dirname(__DIR__, 4) . '/site-contract/tests/Fixtures/Blueprint/valid/minimal.yaml',
        );
        $manifest = new SiteManifestParser()->parse(
            str_replace('generator_version: 1', 'generator_version: 2', $yaml),
            '<blueprint-v2>',
        );
        self::assertSame(2, $manifest->generatorVersion);
        $receipt = $this->receipt($manifest);
        self::assertTrue($receipt->matches($manifest));
        $plan = ApplicationBlueprintCompilerFactory::create()->compile($manifest);
        $before = $this->snapshot();

        try {
            $service->initialize($plan, dryRun: $dryRun, decisionReceipt: $receipt);
            self::fail('Expected approved compiler transition to retain the generator-version guard.');
        } catch (GenerationRefusalException $exception) {
            self::assertSame(GenerationErrorCode::UnitPathConflict, $exception->violations[0]->code);
        }

        self::assertSame($before, $this->snapshot(), 'Manifest, metadata, Composer and all existing artifact bytes must remain unchanged.');
        self::assertFileDoesNotExist($this->root . '/.waaseyaa/site-init.lock');
        self::assertFileDoesNotExist($this->root . '/.waaseyaa/site-init.transaction.json');
        self::assertSame([], glob($this->root . '/.waaseyaa/site-init-stage-*'));
        self::assertDirectoryDoesNotExist($this->root . '/src/Entity');
        self::assertDirectoryDoesNotExist($this->root . '/config/waaseyaa-blueprint');
    }

    /** @return iterable<string, array{bool}> */
    public static function generatorVersionTransitionModes(): iterable
    {
        yield 'preview' => [true];
        yield 'live apply' => [false];
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

    /**
     * The reviewed, literal path set `ApplicationBlueprintCompiler` compiles
     * for `minimal.yaml` today. Frozen here (not re-derived from the plan
     * under test) so a regression in the compiler's own output, or in the
     * engine's additive-delta computation, cannot cancel itself out against
     * a self-referential oracle. Sorted, matching {@see EvaluatedArtifactPlan}'s
     * sort invariant.
     *
     * @var list<string>
     */
    private const array MINIMAL_BLUEPRINT_PATHS = [
        '.waaseyaa/.gitignore',
        '.waaseyaa/site.schema.json',
        '.waaseyaa/site.yaml',
        'AGENTS.md',
        'bin/maintenance/site-verify',
        'config/waaseyaa-blueprint/relationships.php',
        'src/Entity/Article.php',
        'src/Provider/ApplicationBlueprintServiceProvider.php',
        'tests/Acceptance/SiteGoldenPathTest.php',
        'tests/Architecture/SiteContractTest.php',
        'tests/Blueprint/GovernanceDefaultDenyTest.php',
    ];

    /**
     * The reviewed, literal path set `complete.yaml` adds on top of
     * {@see self::MINIMAL_BLUEPRINT_PATHS}: the `person` entity plus every
     * governance emitter's output that `minimal.yaml`'s empty
     * relationships/permissions/roles/policies/workflows/checks sections
     * never trigger. No path in `MINIMAL_BLUEPRINT_PATHS` is repeated here —
     * that is the "no drops" half of the contract, asserted explicitly below
     * rather than assumed from this list's construction.
     *
     * @var list<string>
     */
    private const array COMPLETE_BLUEPRINT_ADDED_PATHS = [
        'config/sync/workflows.assignments.yml',
        'src/Access/ApplicationBlueprintPermissions.php',
        'src/Access/ArticlePolicy.php',
        'src/Entity/Enum/ArticleStage.php',
        'src/Entity/Person.php',
        'src/Provider/ApplicationBlueprintGovernanceServiceProvider.php',
        'src/Workflow/EditorialWorkflowDefinition.php',
        'tests/Blueprint/EntityAccessChecksTest.php',
        'tests/Blueprint/JsonApiGovernanceChecksTest.php',
        'tests/Blueprint/RolePermissionChecksTest.php',
        'tests/Blueprint/WorkflowTransitionChecksTest.php',
    ];

    #[Test]
    public function anAdditiveSuccessorBlueprintPublishesTheExactAddedPathSetWithoutDroppingPriorArtifacts(): void
    {
        $minimal = $this->manifest();
        $receiptA = $this->receipt($minimal);
        $planA = ApplicationBlueprintCompilerFactory::create()->compile($minimal);
        $service = new SiteInitializationService($this->root);
        $service->initialize($planA, decisionReceipt: $receiptA);

        $complete = $this->completeManifest();
        $receiptB = $this->receipt($complete);
        $planB = ApplicationBlueprintCompilerFactory::create()->compile($complete);
        $priorPaths = array_map(static fn($artifact): string => $artifact->path, $planA->artifacts);
        $nextPaths = array_map(static fn($artifact): string => $artifact->path, $planB->artifacts);
        sort($priorPaths, SORT_STRING);
        sort($nextPaths, SORT_STRING);
        $expectedNextPaths = self::MINIMAL_BLUEPRINT_PATHS;
        array_push($expectedNextPaths, ...self::COMPLETE_BLUEPRINT_ADDED_PATHS);
        sort($expectedNextPaths, SORT_STRING);

        // The compiled plans themselves must carry the reviewed literal path
        // sets, independent of anything the execution authority computes.
        self::assertSame(self::MINIMAL_BLUEPRINT_PATHS, $priorPaths, 'The minimal.yaml compiled plan drifted from its reviewed path set.');
        self::assertSame($expectedNextPaths, $nextPaths, 'The complete.yaml compiled plan drifted from its reviewed path set.');
        self::assertSame([], array_values(array_diff(self::MINIMAL_BLUEPRINT_PATHS, $nextPaths)), 'complete.yaml must retain every path minimal.yaml declared.');

        $before = $this->snapshot();
        $preview = $service->initialize($planB, dryRun: true, decisionReceipt: $receiptB);
        self::assertSame(ArtifactApplyOutcome::Planned, $preview->applyResult->outcome);
        self::assertSame([], $preview->evaluation->drops, 'The engine must not report any dropped path for an additive successor.');
        self::assertSame(self::COMPLETE_BLUEPRINT_ADDED_PATHS, $preview->evaluation->adds, "The engine's own additive delta must equal the reviewed literal added-path set.");
        self::assertSame($before, $this->snapshot(), 'A preview must never write.');
        self::assertFileDoesNotExist($this->root . '/.waaseyaa/site-init.transaction.json');

        $applied = $service->initialize($planB, decisionReceipt: $receiptB);
        self::assertSame(ArtifactApplyOutcome::Applied, $applied->applyResult->outcome);
        self::assertSame($receiptB->digest(), $applied->receipts[array_key_last($applied->receipts)]->decisionReceiptId);
        foreach (self::COMPLETE_BLUEPRINT_ADDED_PATHS as $path) {
            self::assertFileExists($this->root . '/' . $path);
        }
        foreach (self::MINIMAL_BLUEPRINT_PATHS as $path) {
            self::assertFileExists($this->root . '/' . $path);
        }
        $metadata = json_decode((string) file_get_contents($this->root . '/.waaseyaa/generated.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertEquals($receiptB->toArray(), $metadata['application_blueprint']['decision_receipt']);

        $replayBefore = $this->snapshot();
        $replayPreview = $service->initialize($planB, dryRun: true, decisionReceipt: $receiptB);
        self::assertSame([], $replayPreview->changedPaths);
        $replay = $service->initialize($planB, decisionReceipt: $receiptB);
        self::assertSame(ArtifactApplyOutcome::NoChanges, $replay->applyResult->outcome);
        self::assertSame($receiptB->digest(), $replay->receipts[array_key_last($replay->receipts)]->decisionReceiptId);
        self::assertSame($replayBefore, $this->snapshot());
    }

    #[Test]
    public function aDriftedManagedArtifactRefusesAnOtherwiseValidSuccessorBlueprint(): void
    {
        $minimal = $this->manifest();
        $receiptA = $this->receipt($minimal);
        $planA = ApplicationBlueprintCompilerFactory::create()->compile($minimal);
        $service = new SiteInitializationService($this->root);
        $service->initialize($planA, decisionReceipt: $receiptA);
        self::assertFileExists($this->root . '/src/Entity/Article.php');
        file_put_contents($this->root . '/src/Entity/Article.php', "<?php\n// drifted by hand outside any extension region\n");

        $complete = $this->completeManifest();
        $receiptB = $this->receipt($complete);
        $planB = ApplicationBlueprintCompilerFactory::create()->compile($complete);
        $before = $this->snapshot();

        try {
            $service->evaluate($planB, decisionReceipt: $receiptB);
            self::fail('Expected the drifted artifact to refuse the successor blueprint.');
        } catch (SiteInitializationCollisionException $exception) {
            self::assertStringContainsString('edited outside an extension region', $exception->getMessage());
        }

        self::assertSame($before, $this->snapshot());
        self::assertFileDoesNotExist($this->root . '/src/Entity/Person.php');
    }

    #[Test]
    public function aSuccessorPublicationFailureRestoresThePriorApprovedBlueprintSnapshot(): void
    {
        $minimal = $this->manifest();
        $receiptA = $this->receipt($minimal);
        $planA = ApplicationBlueprintCompilerFactory::create()->compile($minimal);
        new SiteInitializationService($this->root)->initialize($planA, decisionReceipt: $receiptA);
        $priorSnapshot = $this->snapshot();

        $complete = $this->completeManifest();
        $receiptB = $this->receipt($complete);
        $planB = ApplicationBlueprintCompilerFactory::create()->compile($complete);
        $fault = static function (string $stage): void {
            if ($stage === 'after-replace') {
                throw new \RuntimeException('simulated successor publication failure');
            }
        };

        try {
            new SiteInitializationService($this->root, $fault)->initialize($planB, decisionReceipt: $receiptB);
            self::fail('Expected the simulated successor publication failure.');
        } catch (SiteInitializationExecutionException $exception) {
            self::assertCount(2, $exception->receipts);
            self::assertSame('site.recover', $exception->receipts[0]->operation);
            self::assertSame('recovered', $exception->receipts[0]->outcome->value);
            self::assertNull($exception->receipts[0]->decisionReceiptId);
            self::assertSame('site.init', $exception->receipts[1]->operation);
            self::assertSame('failed', $exception->receipts[1]->outcome->value);
            self::assertSame($receiptB->digest(), $exception->receipts[1]->decisionReceiptId);
            self::assertSame($exception->receipts[0]->receiptId, $exception->receipts[1]->causationReceiptId);
        }

        self::assertSame($priorSnapshot, $this->snapshot(), 'Rollback must restore the prior approved blueprint snapshot exactly.');
        $metadata = json_decode((string) file_get_contents($this->root . '/.waaseyaa/generated.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertEquals($receiptA->toArray(), $metadata['application_blueprint']['decision_receipt'], 'Receipt A remains the durable approved state.');
        self::assertFileDoesNotExist($this->root . '/.waaseyaa/site-init.transaction.json');
        self::assertSame([], glob($this->root . '/.waaseyaa/site-init-stage-*'));
    }

    private function completeManifest(): SiteManifest
    {
        $yaml = (string) file_get_contents(
            dirname(__DIR__, 4) . '/site-contract/tests/Fixtures/Blueprint/valid/complete.yaml',
        );

        return new SiteManifestParser()->parse($yaml, 'complete.yaml');
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
