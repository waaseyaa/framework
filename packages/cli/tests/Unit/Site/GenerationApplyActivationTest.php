<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Site;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Waaseyaa\CLI\Site\Exception\SiteInitializationCollisionException;
use Waaseyaa\CLI\Site\Exception\SiteInitializationExecutionException;
use Waaseyaa\CLI\Site\SiteInitializationInvocation;
use Waaseyaa\CLI\Site\SiteInitializationResult;
use Waaseyaa\CLI\Site\SiteInitializationService;
use Waaseyaa\SiteContract\CanonicalJson;
use Waaseyaa\SiteContract\Generation\ArtifactApplyOutcome;
use Waaseyaa\SiteContract\Generation\ArtifactApplyRequest;
use Waaseyaa\SiteContract\Generation\ArtifactPlan;
use Waaseyaa\SiteContract\Generation\ArtifactSetEvolution;
use Waaseyaa\SiteContract\Generation\ChangeOutcome;
use Waaseyaa\SiteContract\Generation\ChangeReceipt;
use Waaseyaa\SiteContract\Generation\ComposerProviderRegistration;
use Waaseyaa\SiteContract\Generation\Exception\GenerationRefusalException;
use Waaseyaa\SiteContract\Generation\GeneratedArtifact;
use Waaseyaa\SiteContract\Generation\GeneratedSite;
use Waaseyaa\SiteContract\Generation\GenerationUnitDisposition;
use Waaseyaa\SiteContract\Generation\SiteArtifactRenderer;
use Waaseyaa\SiteContract\SiteManifestParser;

#[CoversClass(SiteInitializationService::class)]
#[CoversClass(SiteInitializationInvocation::class)]
#[CoversClass(SiteInitializationResult::class)]
#[CoversClass(SiteInitializationExecutionException::class)]
final class GenerationApplyActivationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/waaseyaa_apply_activation_' . bin2hex(random_bytes(8));
        mkdir($this->root, 0o700);
        file_put_contents($this->root . '/composer.lock', "{}\n");
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
    }

    #[Test]
    public function exactReviewedRequestPublishesLegacyBytesAndEmitsAppliedReceipt(): void
    {
        $site = $this->site();
        $plan = $this->rootPlan($site);
        $invocation = $this->applyExact(new SiteInitializationService($this->root), $plan);

        self::assertSame(ArtifactApplyOutcome::Applied, $invocation->result->outcome);
        self::assertSame(array_keys($site->artifacts), $invocation->result->changed);
        self::assertSame(
            array_values(array_filter(array_keys($site->artifacts), static fn(string $path): bool => $path !== '.waaseyaa/generated.json')),
            array_keys($invocation->result->status),
        );
        self::assertFalse($invocation->result->recoveredInterruptedTransaction);
        self::assertFalse($invocation->result->cleanupPending);
        foreach ($site->artifacts as $path => $artifact) {
            self::assertSame($artifact->content, file_get_contents($this->root . '/' . $path), $path);
            if (DIRECTORY_SEPARATOR === '/') {
                self::assertSame($artifact->mode, fileperms($this->root . '/' . $path) & 0o777, $path);
            }
        }
        $this->assertSingleReceipt($invocation, ChangeOutcome::Applied, 'site.init', $plan->digest);
        self::assertSame([], glob($this->root . '/.waaseyaa/*receipt*'));
    }

    #[Test]
    public function canonicalJsonRequestCanBeReconstructedAndAppliedInASeparateProcess(): void
    {
        $plan = $this->rootPlan($this->site());
        $service = new SiteInitializationService($this->root);
        $evaluation = $service->evaluate($plan);
        $request = new ArtifactApplyRequest($plan, $plan->digest, $evaluation->projectStateDigest);
        $requestPath = $this->root . '/reviewed-request.json';
        $runnerPath = $this->root . '/apply-request.php';
        file_put_contents($requestPath, $request->canonicalJson() . "\n");
        $runner = $this->separateProcessApplyRunner();
        self::assertStringNotContainsString('SiteArtifactRenderer', $runner);
        self::assertStringNotContainsString('SiteManifestParser', $runner);
        file_put_contents($runnerPath, $runner);

        $process = new Process([PHP_BINARY, $runnerPath, $this->root, $requestPath]);
        $process->mustRun();
        $response = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('applied', $response['result']['outcome']);
        self::assertSame($plan->digest, $response['result']['plan_digest']);
        self::assertSame('applied', $response['receipts'][0]['outcome']);
        foreach ($plan->artifacts as $artifact) {
            self::assertSame($artifact->content, file_get_contents($this->root . '/' . $artifact->path));
        }
        $this->assertNoTransactionResidue();
    }

    #[Test]
    public function exactNoOpRequestReturnsNoChangesAndOneNoOpReceipt(): void
    {
        $plan = $this->rootPlan($this->site());
        $service = new SiteInitializationService($this->root);
        $this->applyExact($service, $plan);
        $before = $this->targetSnapshot($plan);

        $invocation = $this->applyExact($service, $plan);

        self::assertSame(ArtifactApplyOutcome::NoChanges, $invocation->result->outcome);
        self::assertSame([], $invocation->result->changed);
        self::assertSame($before, $this->targetSnapshot($plan));
        $this->assertSingleReceipt($invocation, ChangeOutcome::NoOp, 'site.init', $plan->digest);
        $this->assertNoTransactionResidue();
    }

    #[Test]
    public function heldProjectLockReturnsGen008AndARefusedReceiptWithoutPublishing(): void
    {
        $plan = $this->rootPlan($this->site());
        $service = new SiteInitializationService($this->root);
        $evaluation = $service->evaluate($plan);
        mkdir($this->root . '/.waaseyaa', 0o700);
        $lock = fopen($this->root . '/.waaseyaa/site-init.lock', 'c+b');
        self::assertIsResource($lock);
        self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));

        try {
            $invocation = $service->apply(new ArtifactApplyRequest($plan, $plan->digest, $evaluation->projectStateDigest));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        self::assertSame(ArtifactApplyOutcome::Refused, $invocation->result->outcome);
        self::assertSame('GEN008_LOCKED', $invocation->result->errors[0]->code->value);
        self::assertSame([], $this->targetSnapshot($plan));
        $this->assertSingleReceipt($invocation, ChangeOutcome::Refused, 'site.init', $plan->digest);
        self::assertFileDoesNotExist($this->root . '/.waaseyaa/site-init.transaction.json');
    }

    #[Test]
    public function legacyAuthorizationMutationReentersTheSameGen005GateAndPreservesForeignBytes(): void
    {
        $site = $this->site();
        $foreign = "foreign target written by authorization callback\n";
        $target = $this->root . '/AGENTS.md';

        try {
            new SiteInitializationService($this->root)->initialize($site, authorize: static function (array $paths) use ($target, $foreign): bool {
                self::assertContains('AGENTS.md', $paths);
                file_put_contents($target, $foreign);
                chmod($target, 0o640);

                return true;
            });
            self::fail('Expected the authorization-time mutation to stale the evaluation.');
        } catch (SiteInitializationExecutionException $exception) {
            self::assertNotNull($exception->applyResult);
            self::assertSame(ArtifactApplyOutcome::Refused, $exception->applyResult->outcome);
            self::assertSame('GEN005_STALE_PLAN', $exception->applyResult->errors[0]->code->value);
            self::assertNull($exception->applyResult->errors[0]->path);
            self::assertNull($exception->applyResult->errors[0]->pointer);
            self::assertCount(1, $exception->receipts);
            $this->assertReceipt($exception->receipts[0], ChangeOutcome::Refused, 'site.init');
        }

        clearstatcache(true, $target);
        self::assertSame($foreign, file_get_contents($target));
        self::assertSame(0o640, fileperms($target) & 0o777);
        self::assertFileDoesNotExist($this->root . '/.waaseyaa/site-init.transaction.json');
    }

    #[Test]
    public function transportPlanDigestMismatchIsLocationFreeGen005(): void
    {
        $plan = $this->rootPlan($this->site());
        $service = new SiteInitializationService($this->root);
        $evaluation = $service->evaluate($plan);
        $request = new ArtifactApplyRequest($plan, str_repeat('f', 64), $evaluation->projectStateDigest);

        $invocation = $service->apply($request);

        $this->assertStaleRefusal($invocation);
        self::assertNull($invocation->result->errors[0]->path);
        self::assertNull($invocation->result->errors[0]->pointer);
        $this->assertSingleReceipt($invocation, ChangeOutcome::Refused, 'site.init', $request->planDigest);
        self::assertSame([], $this->targetSnapshot($plan));
        self::assertFileDoesNotExist($this->root . '/.waaseyaa/site-init.transaction.json');
    }

    #[Test]
    #[DataProvider('staleProjectMutations')]
    public function projectStateMismatchIsLocationFreeGen005AndPreservesTheObservedMutation(string $mutation): void
    {
        $plan = $this->rootPlan($this->site());
        $service = new SiteInitializationService($this->root);
        if (str_starts_with($mutation, 'existing-')) {
            $this->applyExact($service, $plan);
        }
        $evaluation = $service->evaluate($plan);
        $request = new ArtifactApplyRequest($plan, $plan->digest, $evaluation->projectStateDigest);
        [$path, $bytes, $mode] = $this->mutateProjectState($mutation);

        $invocation = $service->apply($request);

        $this->assertStaleRefusal($invocation);
        self::assertNull($invocation->result->errors[0]->path);
        self::assertNull($invocation->result->errors[0]->pointer);
        self::assertSame($bytes, file_get_contents($path));
        if ($mode !== null && DIRECTORY_SEPARATOR === '/') {
            clearstatcache(true, $path);
            self::assertSame($mode, fileperms($path) & 0o777);
        }
        $this->assertSingleReceipt($invocation, ChangeOutcome::Refused, 'site.init', $plan->digest);
        self::assertFileDoesNotExist($this->root . '/.waaseyaa/site-init.transaction.json');
    }

    #[Test]
    public function unsafeCurrentMetadataSymlinkIsALocationFreeGen005AndItsReferentIsUntouched(): void
    {
        $plan = $this->rootPlan($this->site());
        $service = new SiteInitializationService($this->root);
        $this->applyExact($service, $plan);
        $evaluation = $service->evaluate($plan);
        $metadata = $this->root . '/.waaseyaa/generated.json';
        $sentinel = $this->root . '/foreign-sentinel.txt';
        $foreign = "foreign metadata referent\n";
        file_put_contents($sentinel, $foreign);
        unlink($metadata);
        self::assertTrue(symlink('../foreign-sentinel.txt', $metadata));

        $invocation = $service->apply(new ArtifactApplyRequest($plan, $plan->digest, $evaluation->projectStateDigest));

        $this->assertStaleRefusal($invocation);
        self::assertNull($invocation->result->errors[0]->path);
        self::assertNull($invocation->result->errors[0]->pointer);
        self::assertTrue(is_link($metadata));
        self::assertSame('../foreign-sentinel.txt', readlink($metadata));
        self::assertSame($foreign, file_get_contents($sentinel));
        $this->assertSingleReceipt($invocation, ChangeOutcome::Refused, 'site.init', $plan->digest);
        self::assertFileDoesNotExist($this->root . '/.waaseyaa/site-init.transaction.json');
    }

    #[Test]
    public function metadataPreparationAbaCannotPublishAStateThatWasNeverReviewed(): void
    {
        file_put_contents($this->root . '/composer.json', "{}\n");
        $plan = $this->rootPlan($this->site());
        $service = new SiteInitializationService($this->root);
        $this->applyExact($service, $plan);
        $metadataPath = $this->root . '/.waaseyaa/generated.json';
        $metadata = json_decode((string) file_get_contents($metadataPath), true, flags: JSON_THROW_ON_ERROR);
        $metadata['units'] = [[
            'id' => 'unrelated',
            'disposition' => 'managed',
            'generator' => ['fqcn' => 'UnrelatedCompiler', 'version' => 1],
            'input_digest' => str_repeat('b', 64),
        ]];
        $reviewed = CanonicalJson::encode($metadata) . "\n";
        file_put_contents($metadataPath, $reviewed);
        $transientMetadata = $metadata;
        $transientMetadata['units'][0]['generator']['version'] = 99;
        $transient = CanonicalJson::encode($transientMetadata) . "\n";
        $mutated = false;
        $faulty = new SiteInitializationService($this->root, static function (string $stage, int $index, string $path) use ($metadataPath, $reviewed, $transient, &$mutated): void {
            if ($stage === 'after-apply-state-check') {
                file_put_contents($metadataPath, $transient);
                $mutated = true;
            } elseif ($stage === 'after-composer-read' && $mutated) {
                file_put_contents($metadataPath, $reviewed);
            }
        });
        $evaluation = $faulty->evaluate($plan);

        $invocation = $faulty->apply(new ArtifactApplyRequest($plan, $plan->digest, $evaluation->projectStateDigest));

        $this->assertStaleRefusal($invocation);
        self::assertNull($invocation->result->errors[0]->path);
        self::assertNull($invocation->result->errors[0]->pointer);
        self::assertSame($reviewed, file_get_contents($metadataPath));
        $this->assertNoTransactionResidue();
    }

    public static function staleProjectMutations(): iterable
    {
        yield 'new target appeared' => ['new-target'];
        yield 'carried target content moved' => ['existing-content'];
        yield 'carried target mode moved' => ['existing-mode'];
        yield 'manifest became unverifiable' => ['existing-manifest'];
        yield 'metadata became unverifiable' => ['existing-metadata'];
    }

    #[Test]
    public function composerMergeCannotOverwriteBytesChangedAfterEvaluation(): void
    {
        file_put_contents($this->root . '/composer.json', "{}\n");
        chmod($this->root . '/composer.json', 0o600);
        $plan = $this->rootPlan(
            $this->site(),
            [new ComposerProviderRegistration('App\\Provider\\Published')],
        );
        $service = new SiteInitializationService($this->root);
        $evaluation = $service->evaluate($plan);
        $request = new ArtifactApplyRequest($plan, $plan->digest, $evaluation->projectStateDigest);
        $foreign = "{\"foreign\":\"newer\"}\n";
        file_put_contents($this->root . '/composer.json', $foreign);
        chmod($this->root . '/composer.json', 0o640);

        $invocation = $service->apply($request);

        $this->assertStaleRefusal($invocation);
        self::assertNull($invocation->result->errors[0]->path);
        self::assertNull($invocation->result->errors[0]->pointer);
        clearstatcache(true, $this->root . '/composer.json');
        self::assertSame($foreign, file_get_contents($this->root . '/composer.json'));
        self::assertSame(0o640, fileperms($this->root . '/composer.json') & 0o777);
        self::assertFileDoesNotExist($this->root . '/.waaseyaa/site-init.transaction.json');
    }

    #[Test]
    public function composerPreparationAbaIsRefusedEvenWhenTheFinalObservedStateMatchesTheRequest(): void
    {
        $reviewed = "{\n    \"name\": \"reviewed/project\"\n}\n";
        $transient = "{\n    \"name\": \"foreign/project\"\n}\n";
        $composerPath = $this->root . '/composer.json';
        file_put_contents($composerPath, $reviewed);
        chmod($composerPath, 0o600);
        $plan = $this->rootPlan(
            $this->site(),
            [new ComposerProviderRegistration('App\\Provider\\Published')],
        );
        $mutated = false;
        $service = new SiteInitializationService($this->root, static function (string $stage, int $index, string $path) use ($composerPath, $reviewed, $transient, &$mutated): void {
            if ($stage === 'after-apply-state-check') {
                file_put_contents($composerPath, $transient);
                $mutated = true;
            } elseif ($stage === 'after-composer-read' && $mutated) {
                file_put_contents($composerPath, $reviewed);
                chmod($composerPath, 0o600);
            }
        });
        $evaluation = $service->evaluate($plan);

        $invocation = $service->apply(new ArtifactApplyRequest($plan, $plan->digest, $evaluation->projectStateDigest));

        $this->assertStaleRefusal($invocation);
        self::assertNull($invocation->result->errors[0]->path);
        self::assertNull($invocation->result->errors[0]->pointer);
        clearstatcache(true, $composerPath);
        self::assertSame($reviewed, file_get_contents($composerPath));
        self::assertSame(0o600, fileperms($composerPath) & 0o777);
        $this->assertNoTransactionResidue();
    }

    #[Test]
    public function codedAdmissionRefusalReturnsItsOriginalCodeAndReceipt(): void
    {
        $site = $this->site();
        $eligible = $this->rootPlan($site);
        $service = new SiteInitializationService($this->root);
        $state = $service->evaluate($eligible)->projectStateDigest;
        $ineligible = new ArtifactPlan(
            'ExampleCompiler',
            $eligible->generatorVersion,
            'site',
            GenerationUnitDisposition::Managed,
            $eligible->inputDigest,
            $eligible->artifacts,
            setEvolution: ArtifactSetEvolution::Additive,
        );

        $invocation = $service->apply(new ArtifactApplyRequest($ineligible, $ineligible->digest, $state));

        self::assertSame(ArtifactApplyOutcome::Refused, $invocation->result->outcome);
        self::assertSame('GEN011_UNAUTHORIZED_SET_DELTA', $invocation->result->errors[0]->code->value);
        self::assertNull($invocation->result->errors[0]->path);
        self::assertNull($invocation->result->errors[0]->pointer);
        $this->assertSingleReceipt($invocation, ChangeOutcome::Refused, 'site.init', $ineligible->digest);
        self::assertSame([], $this->targetSnapshot($eligible));
    }

    #[Test]
    public function recoveryThenPublicationEmitsOrderedCorrelatedReceipts(): void
    {
        $plan = $this->rootPlan($this->site());
        $service = new SiteInitializationService($this->root);
        $evaluation = $service->evaluate($plan);
        $request = new ArtifactApplyRequest($plan, $plan->digest, $evaluation->projectStateDigest);
        $this->interrupt($plan);

        $invocation = $service->apply($request, correlationId: 'corr-recovery-publish');

        self::assertSame(ArtifactApplyOutcome::Applied, $invocation->result->outcome);
        self::assertTrue($invocation->result->recoveredInterruptedTransaction);
        self::assertCount(2, $invocation->receipts);
        [$recovery, $applied] = $invocation->receipts;
        $this->assertReceipt($recovery, ChangeOutcome::Recovered, 'site.recover');
        $this->assertReceipt($applied, ChangeOutcome::Applied, 'site.init', $plan->digest);
        self::assertSame('corr-recovery-publish', $recovery->correlationId);
        self::assertSame($recovery->correlationId, $applied->correlationId);
        self::assertSame($recovery->receiptId, $applied->causationReceiptId);
        self::assertNotSame($plan->digest, $recovery->planDigest);
        $this->assertNoTransactionResidue();
    }

    #[Test]
    public function recoveryOnlyEmitsOnlyTheRecoveryReceipt(): void
    {
        $original = $this->rootPlan($this->site());
        $service = new SiteInitializationService($this->root);
        $this->applyExact($service, $original);
        $evaluation = $service->evaluate($original);
        $request = new ArtifactApplyRequest($original, $original->digest, $evaluation->projectStateDigest);
        $interrupted = $this->rootPlan($this->site('Changed'));
        $this->interrupt($interrupted, '.waaseyaa/site.yaml');

        $invocation = $service->apply($request, correlationId: 'corr-recovery-only');

        self::assertSame(ArtifactApplyOutcome::NoChanges, $invocation->result->outcome);
        self::assertTrue($invocation->result->recoveredInterruptedTransaction);
        self::assertSame([], $invocation->result->changed);
        self::assertCount(1, $invocation->receipts);
        $this->assertReceipt($invocation->receipts[0], ChangeOutcome::Recovered, 'site.recover');
        self::assertSame('corr-recovery-only', $invocation->receipts[0]->correlationId);
        self::assertNotSame($interrupted->digest, $invocation->receipts[0]->planDigest);
        $this->assertNoTransactionResidue();
    }

    #[Test]
    public function recoveredReceiptSurvivesAFollowingAdmissionRefusal(): void
    {
        $site = $this->site();
        $eligible = $this->rootPlan($site);
        $service = new SiteInitializationService($this->root);
        $state = $service->evaluate($eligible)->projectStateDigest;
        $ineligible = new ArtifactPlan(
            'ExampleCompiler',
            $eligible->generatorVersion,
            'site',
            GenerationUnitDisposition::Managed,
            $eligible->inputDigest,
            $eligible->artifacts,
            setEvolution: ArtifactSetEvolution::Additive,
        );
        $this->interrupt($eligible);

        $invocation = $service->apply(
            new ArtifactApplyRequest($ineligible, $ineligible->digest, $state),
            correlationId: 'corr-recovered-refusal',
        );

        self::assertSame(ArtifactApplyOutcome::Refused, $invocation->result->outcome);
        self::assertSame('GEN011_UNAUTHORIZED_SET_DELTA', $invocation->result->errors[0]->code->value);
        self::assertCount(2, $invocation->receipts);
        [$recovery, $refusal] = $invocation->receipts;
        $this->assertReceipt($recovery, ChangeOutcome::Recovered, 'site.recover');
        $this->assertReceipt($refusal, ChangeOutcome::Refused, 'site.init', $ineligible->digest);
        self::assertSame('corr-recovered-refusal', $recovery->correlationId);
        self::assertSame($recovery->correlationId, $refusal->correlationId);
        self::assertSame($recovery->receiptId, $refusal->causationReceiptId);
        $this->assertNoTransactionResidue();
    }

    #[Test]
    public function recognizedOrphanResidueCleanupEmitsTheOnlyReceiptOnANoOpRetry(): void
    {
        $plan = $this->rootPlan($this->site());
        $service = new SiteInitializationService($this->root);
        $this->applyExact($service, $plan);
        $evaluation = $service->evaluate($plan);
        $residue = $this->root . '/.waaseyaa/site-init-stage-' . str_repeat('a', 24);
        mkdir($residue, 0o700);
        file_put_contents($residue . '/0000.artifact', "orphaned stage bytes\n");

        $invocation = $service->apply(new ArtifactApplyRequest($plan, $plan->digest, $evaluation->projectStateDigest));

        self::assertSame(ArtifactApplyOutcome::NoChanges, $invocation->result->outcome);
        self::assertTrue($invocation->result->recoveredInterruptedTransaction);
        self::assertSame([], $invocation->result->changed);
        self::assertCount(1, $invocation->receipts);
        $this->assertReceipt($invocation->receipts[0], ChangeOutcome::Recovered, 'site.recover');
        self::assertDirectoryDoesNotExist($residue);
        $this->assertNoTransactionResidue();
    }

    #[Test]
    public function hostileRecoveryThrowsWithFailedReceiptAndPreservesEvidence(): void
    {
        $original = $this->rootPlan($this->site());
        $service = new SiteInitializationService($this->root);
        $this->applyExact($service, $original);
        $evaluation = $service->evaluate($original);
        $request = new ArtifactApplyRequest($original, $original->digest, $evaluation->projectStateDigest);
        $this->interrupt($this->rootPlan($this->site('Changed')), '.waaseyaa/site.yaml');
        $journalPath = $this->root . '/.waaseyaa/site-init.transaction.json';
        $journal = json_decode((string) file_get_contents($journalPath), true, flags: JSON_THROW_ON_ERROR);
        $item = current(array_filter($journal['items'], static fn(array $row): bool => in_array($row['state'], ['installing', 'applied'], true)));
        self::assertIsArray($item);
        $target = $this->root . '/' . $item['path'];
        $foreign = "foreign recovery tuple\n";
        file_put_contents($target, $foreign);
        chmod($target, 0o640);

        try {
            $service->apply($request, correlationId: 'corr-recovery-failed');
            self::fail('Expected failed recovery.');
        } catch (SiteInitializationExecutionException $exception) {
            self::assertInstanceOf(SiteInitializationCollisionException::class, $exception->getPrevious());
            self::assertCount(1, $exception->receipts);
            $this->assertReceipt($exception->receipts[0], ChangeOutcome::Failed, 'site.recover');
            self::assertSame('corr-recovery-failed', $exception->receipts[0]->correlationId);
        }

        clearstatcache(true, $target);
        self::assertSame($foreign, file_get_contents($target));
        self::assertSame(0o640, fileperms($target) & 0o777);
        self::assertFileExists($journalPath);
    }

    #[Test]
    public function activatedRuntimeFailureRollsBackAndReportsRecoveredReceipt(): void
    {
        $plan = $this->rootPlan($this->site());
        $service = new SiteInitializationService($this->root, static function (string $stage, int $index, string $path): void {
            if ($stage === 'after-replace' && $path === '.waaseyaa/site.schema.json') {
                throw new \RuntimeException('publication failed after replacement');
            }
        });
        $evaluation = $service->evaluate($plan);

        try {
            $service->apply(new ArtifactApplyRequest($plan, $plan->digest, $evaluation->projectStateDigest), correlationId: 'corr-runtime-rollback');
            self::fail('Expected activated publication failure.');
        } catch (SiteInitializationExecutionException $exception) {
            self::assertInstanceOf(\RuntimeException::class, $exception->getPrevious());
            self::assertSame('publication failed after replacement', $exception->getPrevious()->getMessage());
            self::assertCount(1, $exception->receipts);
            $this->assertReceipt($exception->receipts[0], ChangeOutcome::Recovered, 'site.recover');
            self::assertSame('corr-runtime-rollback', $exception->receipts[0]->correlationId);
        }

        self::assertSame([], $this->targetSnapshot($plan));
        $this->assertNoTransactionResidue();
    }

    #[Test]
    public function activatedLateForeignTargetMakesRollbackFailClosedAndPreservesEvidence(): void
    {
        $plan = $this->rootPlan($this->site());
        $foreign = "foreign target created during publication\n";
        $foreignPath = $this->root . '/AGENTS.md';
        $service = new SiteInitializationService($this->root, static function (string $stage, int $index, string $path) use ($foreignPath, $foreign): void {
            if ($stage === 'before-replace' && $path === 'AGENTS.md') {
                file_put_contents($foreignPath, $foreign);
                chmod($foreignPath, 0o640);
                throw new \RuntimeException('late failure after foreign target appeared');
            }
        });
        $evaluation = $service->evaluate($plan);

        try {
            $service->apply(new ArtifactApplyRequest($plan, $plan->digest, $evaluation->projectStateDigest), correlationId: 'corr-hostile-runtime');
            self::fail('Expected failed activated rollback.');
        } catch (SiteInitializationExecutionException $exception) {
            self::assertCount(1, $exception->receipts);
            $this->assertReceipt($exception->receipts[0], ChangeOutcome::Failed, 'site.recover');
            self::assertSame('corr-hostile-runtime', $exception->receipts[0]->correlationId);
        }

        clearstatcache(true, $foreignPath);
        self::assertSame($foreign, file_get_contents($foreignPath));
        self::assertSame(0o640, fileperms($foreignPath) & 0o777);
        self::assertFileExists($this->root . '/.waaseyaa/site-init.transaction.json');
    }

    #[Test]
    public function targetAppearingAfterStagingIsPreservedWhileTheDraftTransactionIsCleaned(): void
    {
        $plan = $this->rootPlan($this->site());
        $foreign = "foreign target created after staging\n";
        $foreignPath = $this->root . '/AGENTS.md';
        $service = new SiteInitializationService($this->root, static function (string $stage, int $index, string $path) use ($foreignPath, $foreign): void {
            if ($stage === 'after-stage' && $path === 'AGENTS.md') {
                file_put_contents($foreignPath, $foreign);
                chmod($foreignPath, 0o640);
            }
        });
        $evaluation = $service->evaluate($plan);

        try {
            $service->apply(new ArtifactApplyRequest($plan, $plan->digest, $evaluation->projectStateDigest), correlationId: 'corr-after-stage');
            self::fail('Expected a stale target found after staging.');
        } catch (SiteInitializationExecutionException $exception) {
            self::assertInstanceOf(GenerationRefusalException::class, $exception->getPrevious());
            self::assertSame('GEN005_STALE_PLAN', $exception->getPrevious()->violations[0]->code->value);
            self::assertCount(1, $exception->receipts);
            $this->assertReceipt($exception->receipts[0], ChangeOutcome::Recovered, 'site.recover');
            self::assertSame('corr-after-stage', $exception->receipts[0]->correlationId);
        }

        clearstatcache(true, $foreignPath);
        self::assertSame($foreign, file_get_contents($foreignPath));
        self::assertSame(0o640, fileperms($foreignPath) & 0o777);
        $this->assertNoTransactionResidue();
    }

    #[Test]
    public function untouchedTargetMovingDuringStagingIsRefusedBeforeTheJournalIsOpened(): void
    {
        $original = $this->rootPlan($this->site());
        $service = new SiteInitializationService($this->root);
        $this->applyExact($service, $original);
        $changed = $this->rootPlan($this->site('Changed'));
        $foreign = "foreign unchanged target during staging\n";
        $target = $this->root . '/AGENTS.md';
        $publicationHooks = [];
        $faulty = new SiteInitializationService($this->root, static function (string $stage, int $index, string $path) use ($target, $foreign, &$publicationHooks): void {
            if ($stage === 'after-stage' && $path === '.waaseyaa/site.yaml') {
                file_put_contents($target, $foreign);
                chmod($target, 0o640);
            }
            if (in_array($stage, ['before-replace', 'after-replace'], true)) {
                $publicationHooks[] = $stage . ':' . $path;
            }
        });
        $evaluation = $faulty->evaluate($changed);

        try {
            $faulty->apply(new ArtifactApplyRequest($changed, $changed->digest, $evaluation->projectStateDigest));
            self::fail('Expected a staging-time mutation of an unchanged reviewed target.');
        } catch (SiteInitializationExecutionException $exception) {
            self::assertSame([], $publicationHooks, 'The whole-state gate must refuse before the first replacement hook.');
            self::assertInstanceOf(GenerationRefusalException::class, $exception->getPrevious());
            self::assertSame('GEN005_STALE_PLAN', $exception->getPrevious()->violations[0]->code->value);
            self::assertNull($exception->getPrevious()->violations[0]->path);
            self::assertCount(1, $exception->receipts);
            $this->assertReceipt($exception->receipts[0], ChangeOutcome::Recovered, 'site.recover');
        }

        clearstatcache(true, $target);
        self::assertSame($foreign, file_get_contents($target));
        self::assertSame(0o640, fileperms($target) & 0o777);
        $this->assertNoTransactionResidue();
    }

    #[Test]
    public function untouchedTargetMovingAfterAnEarlierReplacementPreventsAFalseAppliedResult(): void
    {
        $original = $this->rootPlan($this->site());
        $service = new SiteInitializationService($this->root);
        $this->applyExact($service, $original);
        $changed = $this->rootPlan($this->site('Changed'));
        $foreign = "foreign unchanged target during publication\n";
        $target = $this->root . '/AGENTS.md';
        $faulty = new SiteInitializationService($this->root, static function (string $stage, int $index, string $path) use ($target, $foreign): void {
            if ($stage === 'after-replace' && $path === '.waaseyaa/site.yaml') {
                file_put_contents($target, $foreign);
                chmod($target, 0o640);
            }
        });
        $evaluation = $faulty->evaluate($changed);

        try {
            $faulty->apply(new ArtifactApplyRequest($changed, $changed->digest, $evaluation->projectStateDigest));
            self::fail('Expected a late mutation of an unchanged reviewed target.');
        } catch (SiteInitializationExecutionException $exception) {
            self::assertInstanceOf(GenerationRefusalException::class, $exception->getPrevious());
            self::assertSame('GEN005_STALE_PLAN', $exception->getPrevious()->violations[0]->code->value);
            self::assertSame('AGENTS.md', $exception->getPrevious()->violations[0]->path);
            self::assertCount(1, $exception->receipts);
            $this->assertReceipt($exception->receipts[0], ChangeOutcome::Recovered, 'site.recover');
        }

        clearstatcache(true, $target);
        self::assertSame($foreign, file_get_contents($target));
        self::assertSame(0o640, fileperms($target) & 0o777);
        $this->assertNoTransactionResidue();
    }

    private function applyExact(SiteInitializationService $service, ArtifactPlan $plan): object
    {
        $evaluation = $service->evaluate($plan);

        return $service->apply(new ArtifactApplyRequest($plan, $plan->digest, $evaluation->projectStateDigest));
    }

    private function separateProcessApplyRunner(): string
    {
        $runner = <<<'PHP'
            <?php

            declare(strict_types=1);

            require __AUTOLOAD__;

            use Waaseyaa\CLI\Site\SiteInitializationService;
            use Waaseyaa\SiteContract\Generation\ArtifactApplyRequest;
            use Waaseyaa\SiteContract\Generation\ArtifactPlan;
            use Waaseyaa\SiteContract\Generation\ArtifactSetEvolution;
            use Waaseyaa\SiteContract\Generation\ComposerProviderRegistration;
            use Waaseyaa\SiteContract\Generation\GeneratedArtifact;
            use Waaseyaa\SiteContract\Generation\GenerationUnitDisposition;

            $requestDocument = json_decode((string) file_get_contents($argv[2]), true, flags: JSON_THROW_ON_ERROR);
            $planDocument = $requestDocument['plan'];
            $artifacts = array_map(
                static fn(array $row): GeneratedArtifact => new GeneratedArtifact(
                    $row['path'],
                    $row['content'],
                    intval($row['mode'], 8),
                    $row['extension_region'] ?? null,
                ),
                $planDocument['artifacts'],
            );
            $registrations = array_map(
                static fn(array $row): ComposerProviderRegistration => new ComposerProviderRegistration($row['fqcn'], $row['group'] ?? null),
                $planDocument['registrations'],
            );
            $plan = new ArtifactPlan(
                $planDocument['generator']['fqcn'],
                $planDocument['generator']['version'],
                $planDocument['unit']['id'],
                GenerationUnitDisposition::from($planDocument['unit']['disposition']),
                $planDocument['input_digest'],
                $artifacts,
                $planDocument['retires'],
                $registrations,
                $planDocument['companion_tests'],
                ArtifactSetEvolution::from($planDocument['set_evolution']),
                $planDocument['schema_effects'],
                $planDocument['config_effects'],
            );
            $request = new ArtifactApplyRequest(
                $plan,
                $requestDocument['plan_digest'],
                $requestDocument['project_state_digest'],
            );
            $invocation = new SiteInitializationService($argv[1])->apply($request);
            echo json_encode([
                'result' => $invocation->result->toArray(),
                'receipts' => array_map(static fn($receipt): array => $receipt->toArray(), $invocation->receipts),
            ], JSON_THROW_ON_ERROR);
            PHP;

        return str_replace('__AUTOLOAD__', var_export(dirname(__DIR__, 5) . '/vendor/autoload.php', true), $runner);
    }

    private function assertStaleRefusal(object $invocation): void
    {
        self::assertSame(ArtifactApplyOutcome::Refused, $invocation->result->outcome);
        self::assertSame([], $invocation->result->changed);
        self::assertCount(1, $invocation->result->errors);
        self::assertSame('GEN005_STALE_PLAN', $invocation->result->errors[0]->code->value);
    }

    private function assertSingleReceipt(object $invocation, ChangeOutcome $outcome, string $operation, string $planDigest): void
    {
        self::assertCount(1, $invocation->receipts);
        $this->assertReceipt($invocation->receipts[0], $outcome, $operation, $planDigest);
        $payload = $invocation->result->toArray();
        unset($payload['schema'], $payload['version'], $payload['outcome'], $payload['plan_digest']);
        self::assertSame(
            CanonicalJson::encode(['version' => 1] + $payload),
            CanonicalJson::encode($invocation->receipts[0]->domainPayload),
        );
    }

    private function assertReceipt(ChangeReceipt $receipt, ChangeOutcome $outcome, string $operation, ?string $planDigest = null): void
    {
        self::assertSame(ChangeReceipt::GENERATION_AUTHORITY, $receipt->authority);
        self::assertSame(SiteInitializationService::CONTRACT_VERSION, $receipt->authorityVersion);
        self::assertSame($outcome, $receipt->outcome);
        self::assertSame($operation, $receipt->operation);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $receipt->planDigest);
        if ($planDigest !== null) {
            self::assertSame($planDigest, $receipt->planDigest);
        }
        $encoded = CanonicalJson::encode($receipt->toArray());
        self::assertSame($encoded, CanonicalJson::encode((array) json_decode($encoded, flags: JSON_THROW_ON_ERROR)));
    }

    /** @return array{string, string, int|null} */
    private function mutateProjectState(string $mutation): array
    {
        $path = match ($mutation) {
            'new-target', 'existing-content', 'existing-mode' => $this->root . '/AGENTS.md',
            'existing-manifest' => $this->root . '/.waaseyaa/site.yaml',
            'existing-metadata' => $this->root . '/.waaseyaa/generated.json',
        };
        if ($mutation === 'new-target') {
            $bytes = "new foreign target\n";
            file_put_contents($path, $bytes);
            chmod($path, 0o640);
        } elseif ($mutation === 'existing-mode') {
            $bytes = (string) file_get_contents($path);
            chmod($path, 0o600);
        } elseif ($mutation === 'existing-content') {
            $bytes = "changed carried target\n";
            file_put_contents($path, $bytes);
        } elseif ($mutation === 'existing-manifest') {
            $bytes = "not: [valid\n";
            file_put_contents($path, $bytes);
        } else {
            $bytes = "{}\n";
            file_put_contents($path, $bytes);
        }
        clearstatcache(true, $path);

        return [$path, $bytes, DIRECTORY_SEPARATOR === '/' ? fileperms($path) & 0o777 : null];
    }

    private function interrupt(ArtifactPlan $plan, string $faultPath = '.waaseyaa/site.schema.json'): void
    {
        $control = $this->root . '/.waaseyaa';
        if (!is_dir($control)) {
            mkdir($control, 0o700);
        }
        $faulty = new SiteInitializationService($this->root, static function (string $stage, int $index, string $path) use ($faultPath): void {
            if ($stage === 'after-replace' && $path === $faultPath) {
                throw new \Error('simulated process death');
            }
        });
        $lock = fopen($control . '/site-init.lock', 'c+b');
        self::assertIsResource($lock);
        self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        try {
            $prepared = $this->invoke($faulty, 'prepareUnitPlan', $plan);
            $this->invoke($faulty, 'publish', $prepared['prepared'], $prepared['retirements'], $prepared['composerMerge']);
            self::fail('Expected simulated process death.');
        } catch (\Error $error) {
            self::assertSame('simulated process death', $error->getMessage());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        self::assertFileExists($control . '/site-init.transaction.json');
    }

    /** @param list<ComposerProviderRegistration> $registrations */
    private function rootPlan(GeneratedSite $site, array $registrations = []): ArtifactPlan
    {
        return new ArtifactPlan(
            SiteArtifactRenderer::class,
            $site->generatorVersion,
            'site',
            GenerationUnitDisposition::Managed,
            $site->manifestDigest,
            array_values(array_filter(
                $site->artifacts,
                static fn(GeneratedArtifact $artifact): bool => $artifact->path !== '.waaseyaa/generated.json',
            )),
            registrations: $registrations,
        );
    }

    private function invoke(SiteInitializationService $service, string $method, mixed ...$arguments): mixed
    {
        return new \ReflectionMethod($service, $method)->invoke($service, ...$arguments);
    }

    /** @return array<string, array{bytes: string, mode: int|null}> */
    private function targetSnapshot(ArtifactPlan $plan): array
    {
        $snapshot = [];
        foreach ($plan->artifacts as $artifact) {
            $path = $this->root . '/' . $artifact->path;
            if (is_file($path)) {
                $snapshot[$artifact->path] = [
                    'bytes' => (string) file_get_contents($path),
                    'mode' => DIRECTORY_SEPARATOR === '/' ? fileperms($path) & 0o777 : null,
                ];
            }
        }

        return $snapshot;
    }

    private function assertNoTransactionResidue(): void
    {
        self::assertFileDoesNotExist($this->root . '/.waaseyaa/site-init.transaction.json');
        self::assertSame([], glob($this->root . '/.waaseyaa/site-init-stage-*'));
        self::assertSame([], glob($this->root . '/.waaseyaa/site-init-backup-*'));
    }

    private function site(string $name = 'Example'): GeneratedSite
    {
        $manifest = <<<'YAML'
            schema: waaseyaa.site
            version: 1
            generator_version: 1
            application:
              id: example
              name: Example
              canonical_origin: {config_key: APP_ORIGIN}
            framework:
              revision_policy: exact-lock
              observed_lock_sha256: aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
            content_types:
              - {id: page, canonical_route: '/{slug}'}
            capabilities:
              - id: publishing
                state: active
                package: waaseyaa/publishing
                provider: site.publishing
                configuration_authority: .waaseyaa/site.yaml#/capabilities/publishing
                public_routes: []
                data_classification: public
                lifecycle: [create, publish]
                verification: [tests/Acceptance/SiteGoldenPathTest.php]
            personal_data_stores: []
            recipes: []
            verification: {command: bin/maintenance/site-verify}
            YAML;

        return new SiteArtifactRenderer()->render(
            new SiteManifestParser()->parse(str_replace('name: Example', 'name: ' . $name, $manifest)),
        );
    }
}
