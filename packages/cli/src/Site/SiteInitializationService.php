<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Site;

use Waaseyaa\CLI\Site\Blueprint\ApplicationBlueprintCompiler;
use Waaseyaa\CLI\Site\Exception\SiteInitializationCollisionException;
use Waaseyaa\CLI\Site\Exception\SiteInitializationExecutionException;
use Waaseyaa\CLI\Site\Exception\SiteInitializationLockedException;
use Waaseyaa\SiteContract\Blueprint\BlueprintAppliedEvidence;
use Waaseyaa\SiteContract\Blueprint\BlueprintDecisionReceipt;
use Waaseyaa\SiteContract\CanonicalJson;
use Waaseyaa\SiteContract\Exception\SiteManifestValidationException;
use Waaseyaa\SiteContract\Generation\ArtifactApplyOutcome;
use Waaseyaa\SiteContract\Generation\ArtifactApplyRequest;
use Waaseyaa\SiteContract\Generation\ArtifactApplyResult;
use Waaseyaa\SiteContract\Generation\ArtifactPlan;
use Waaseyaa\SiteContract\Generation\ArtifactSetEvolution;
use Waaseyaa\SiteContract\Generation\ArtifactStatus;
use Waaseyaa\SiteContract\Generation\ChangeOutcome;
use Waaseyaa\SiteContract\Generation\ChangeReceipt;
use Waaseyaa\SiteContract\Generation\EvaluatedArtifactPlan;
use Waaseyaa\SiteContract\Generation\Exception\GenerationErrorCode;
use Waaseyaa\SiteContract\Generation\Exception\GenerationRefusalException;
use Waaseyaa\SiteContract\Generation\Exception\GenerationViolation;
use Waaseyaa\SiteContract\Generation\GeneratedArtifact;
use Waaseyaa\SiteContract\Generation\GeneratedSite;
use Waaseyaa\SiteContract\Generation\GenerationUnitDisposition;
use Waaseyaa\SiteContract\Generation\ObservedTargetMode;
use Waaseyaa\SiteContract\Generation\ObservedTargetState;
use Waaseyaa\SiteContract\Generation\ProjectStateIdentity;
use Waaseyaa\SiteContract\Generation\ProjectStateTarget;
use Waaseyaa\SiteContract\Generation\SiteArtifactRenderer;
use Waaseyaa\SiteContract\SiteManifestParser;

/** @api */
final class SiteInitializationService
{
    /**
     * The sole machine-readable declaration of this authority's contract
     * version (ADR-025 D-14.9). Receipts, tests, fixtures and documentation
     * read it; none of them restate its value.
     */
    public const int CONTRACT_VERSION = 1;

    private const string METADATA = '.waaseyaa/generated.json';
    private const string JOURNAL = '.waaseyaa/site-init.transaction.json';
    private const string LOCK = '.waaseyaa/site-init.lock';

    private readonly string $projectRoot;

    private readonly SiteHostPlatform $platform;

    public function __construct(
        string $projectRoot,
        private readonly ?\Closure $faultInjector = null,
        ?SiteHostPlatform $platform = null,
    ) {
        $root = realpath($projectRoot);
        if ($root === false || !is_dir($root) || is_link($projectRoot)) {
            throw new \InvalidArgumentException('The site project root must be an existing, non-symlink directory.');
        }
        $this->projectRoot = rtrim($root, DIRECTORY_SEPARATOR);
        $this->platform = $platform ?? SiteHostPlatform::host();
    }

    /** @param null|\Closure(list<string>): bool $authorize */
    public function initialize(
        GeneratedSite|ArtifactPlan $site,
        bool $dryRun = false,
        ?\Closure $authorize = null,
        ?BlueprintDecisionReceipt $decisionReceipt = null,
    ): SiteInitializationResult {
        $plan = $site instanceof ArtifactPlan
            ? $site
            : new ArtifactPlan(
                SiteArtifactRenderer::class,
                $site->generatorVersion,
                'site',
                GenerationUnitDisposition::Managed,
                $site->manifestDigest,
                array_values(array_filter($site->artifacts, static fn(GeneratedArtifact $artifact): bool => $artifact->path !== self::METADATA)),
                setEvolution: ArtifactSetEvolution::Additive,
            );
        $blueprintEvidence = $this->blueprintEvidenceForPlan($plan, $decisionReceipt);
        if ($dryRun) {
            if (is_file($this->absolute(self::JOURNAL))) {
                throw new \RuntimeException('Site initialization recovery or committed cleanup requires a non-dry run before a new plan can be computed.');
            }
            $prepared = $this->prepareUnitPlan($plan, $decisionReceipt);
            $evaluation = $prepared['evaluation'];
            $result = new ArtifactApplyResult(ArtifactApplyOutcome::Planned, $evaluation->planDigest, $evaluation->projectStateDigest, $evaluation->status, []);

            return new SiteInitializationResult($this->transactionPaths($prepared), true, applyResult: $result, evaluation: $evaluation);
        }

        // Keep deterministic refusal side-effect-free, including on a fresh
        // project. Publication still evaluates and gates again under its lock.
        if (!file_exists($this->absolute(self::JOURNAL)) && !is_link($this->absolute(self::JOURNAL))) {
            $this->prepareUnitPlan($plan, $decisionReceipt);
        }
        $context = $this->invocationContext(
            'site.init',
            decision: $blueprintEvidence?->decisionReceipt->digest(),
            blueprintDecisionVerified: $blueprintEvidence !== null,
        );
        $lock = $this->acquireLock();
        $receipts = [];
        try {
            $recovered = $this->recoverForInvocation($context, $receipts);
            $prepared = $this->prepareUnitPlan($plan, $decisionReceipt);
            $evaluation = $prepared['evaluation'];
            $preview = $this->transactionPaths($prepared);
            // Legacy apply's count omitted the control-ignore bootstrap file.
            // Preserve that display only; the actual result reports every write.
            if (($evaluation->status['.waaseyaa/.gitignore'] ?? null) === ArtifactStatus::Created) {
                $preview = array_values(array_diff($preview, ['.waaseyaa/.gitignore']));
            }
            if ($preview !== [] && $authorize !== null && !$authorize($preview)) {
                $result = new ArtifactApplyResult(ArtifactApplyOutcome::Cancelled, $evaluation->planDigest, $evaluation->projectStateDigest, $evaluation->status, [], $recovered);

                return new SiteInitializationResult($preview, recoveredInterruptedTransaction: $recovered, cancelled: true, receipts: $receipts, applyResult: $result, evaluation: $evaluation);
            }
            $request = new ArtifactApplyRequest($plan, $evaluation->planDigest, $evaluation->projectStateDigest);
            $invocation = $this->applyUnderLock($request, $context, $receipts, $recovered, $decisionReceipt);
            if ($invocation->result->outcome === ArtifactApplyOutcome::Refused) {
                $error = new \RuntimeException($invocation->result->errors[0]->message);
                throw new SiteInitializationExecutionException($error, $invocation->receipts, $invocation->result);
            }

            return new SiteInitializationResult(
                $preview,
                recoveredInterruptedTransaction: $recovered,
                cleanupPending: $invocation->result->cleanupPending,
                receipts: $invocation->receipts,
                applyResult: $invocation->result,
                evaluation: $evaluation,
            );
        } catch (\Exception $exception) {
            if ($receipts !== [] && !$exception instanceof SiteInitializationExecutionException) {
                throw new SiteInitializationExecutionException($exception, $receipts);
            }
            throw $exception;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Execute exactly the transported plan; compilation never occurs here. */
    public function apply(
        ArtifactApplyRequest $request,
        string $operation = 'site.init',
        ?string $correlationId = null,
        ?string $causationReceiptId = null,
        ?string $decisionReceiptId = null,
        ?BlueprintDecisionReceipt $decisionReceipt = null,
    ): SiteInitializationInvocation {
        $context = $this->invocationContext($operation, $correlationId, $causationReceiptId, $decisionReceiptId);
        try {
            $blueprintEvidence = $this->blueprintEvidenceForPlan($request->plan, $decisionReceipt);
            if ($blueprintEvidence !== null) {
                $verifiedDecisionId = $blueprintEvidence->decisionReceipt->digest();
                if ($decisionReceiptId !== null && !hash_equals($verifiedDecisionId, $decisionReceiptId)) {
                    $this->unitRefusal(GenerationErrorCode::UnauthorizedSetDelta, 'The declared decision receipt identity does not match the verified blueprint approval.');
                }
                $context = $this->invocationContext(
                    $operation,
                    $correlationId,
                    $causationReceiptId,
                    $verifiedDecisionId,
                    blueprintDecisionVerified: true,
                );
            }
        } catch (GenerationRefusalException $exception) {
            $context['decision'] = null;

            return $this->refusedInvocation($request, $exception->violations, $context);
        }
        try {
            $lock = $this->acquireLock();
        } catch (SiteInitializationLockedException $exception) {
            return $this->refusedInvocation($request, [new GenerationViolation(GenerationErrorCode::Locked, $exception->getMessage())], $context);
        } catch (SiteInitializationCollisionException $exception) {
            return $this->refusedInvocation($request, [new GenerationViolation(GenerationErrorCode::StalePlan, 'The reviewed project state cannot be safely observed.')], $context);
        }
        $receipts = [];
        try {
            $recovered = $this->recoverForInvocation($context, $receipts);

            return $this->applyUnderLock($request, $context, $receipts, $recovered, $decisionReceipt);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return resource */
    private function acquireLock()
    {
        $this->assertSafeTarget(self::LOCK, true);
        $controlDirectory = $this->absolute('.waaseyaa');
        if (!is_dir($controlDirectory) && !mkdir($controlDirectory, 0o700, true) && !is_dir($controlDirectory)) {
            throw new \RuntimeException('Unable to create the site initialization control directory.');
        }
        $lockPath = $this->absolute(self::LOCK);
        if (file_exists($lockPath) || is_link($lockPath)) {
            $this->assertRegularOwnedFile($lockPath, self::LOCK);
        }
        $lock = fopen($lockPath, 'c+b');
        if ($lock === false) {
            throw new \RuntimeException('Unable to open the site initialization lock.');
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new SiteInitializationLockedException('Another site initialization transaction owns this project.');
        }

        return $lock;
    }

    /** @return array{operation: string, correlation: string, causation: ?string, decision: ?string, blueprint_decision_verified: bool} */
    private function invocationContext(
        string $operation,
        ?string $correlation = null,
        ?string $causation = null,
        ?string $decision = null,
        bool $blueprintDecisionVerified = false,
    ): array {
        if ($operation === '' || $correlation === '' || $causation === '' || $decision === '' || ($causation !== null && $causation === $decision)) {
            throw new \InvalidArgumentException('The invocation receipt context is invalid.');
        }

        return [
            'operation' => $operation,
            'correlation' => $correlation ?? $this->mintIdentifier('corr'),
            'causation' => $causation,
            'decision' => $decision,
            'blueprint_decision_verified' => $blueprintDecisionVerified,
        ];
    }

    /** @param array<string, mixed> $context @param list<ChangeReceipt> $receipts */
    private function applyUnderLock(ArtifactApplyRequest $request, array $context, array $receipts, bool $recovered, ?BlueprintDecisionReceipt $decisionReceipt = null): SiteInitializationInvocation
    {
        try {
            $this->assertReviewedState($request);
        } catch (GenerationRefusalException $exception) {
            return $this->refusedInvocation($request, $exception->violations, $context, $receipts, $recovered);
        }
        $this->injectFault('after-apply-state-check', -1, '');
        try {
            $prepared = $this->prepareUnitPlan($request->plan, $decisionReceipt);
        } catch (\Exception $exception) {
            // A change during preparation must not become an incidental
            // collision instead of the promised stale-plan refusal.
            try {
                $this->assertReviewedState($request);
            } catch (GenerationRefusalException $stale) {
                return $this->refusedInvocation($request, $stale->violations, $context, $receipts, $recovered);
            }
            $errors = $exception instanceof GenerationRefusalException
                ? $exception->violations
                : [new GenerationViolation(str_contains($exception->getMessage(), 'Generated artifact bytes changed without') || str_contains($exception->getMessage(), 'extension region') ? GenerationErrorCode::AmbiguousExtensionRegion : GenerationErrorCode::CollisionRefused, $exception->getMessage())];

            return $this->refusedInvocation($request, $errors, $context, $receipts, $recovered);
        }
        if (!hash_equals($request->projectStateDigest, $prepared['evaluation']->projectStateDigest)) {
            return $this->refusedInvocation($request, [new GenerationViolation(GenerationErrorCode::StalePlan, 'Preparation did not observe the reviewed project state.')], $context, $receipts, $recovered);
        }
        try {
            $state = $this->assertReviewedState($request);
        } catch (GenerationRefusalException $exception) {
            return $this->refusedInvocation($request, $exception->violations, $context, $receipts, $recovered);
        }
        $blueprintDecisionVerified = $context['blueprint_decision_verified'] === true;
        $changed = $this->transactionPaths($prepared);
        $evaluation = $prepared['evaluation'];
        if ($changed === []) {
            $result = new ArtifactApplyResult(ArtifactApplyOutcome::NoChanges, $request->planDigest, $request->projectStateDigest, $evaluation->status, [], $recovered);
            if (!$recovered || $blueprintDecisionVerified) {
                $receipts[] = $this->receiptFor($result, $context['operation'], $context['correlation'], $this->receiptCause($receipts, $context), $context['decision']);
            }

            return new SiteInitializationInvocation($result, $receipts);
        }
        $priorReceiptCount = count($receipts);
        $reportRecovery = function (array $journal, bool $success) use (&$receipts, $request, $context): void {
            $receipts[] = $this->recoveryReceipt($journal, $success ? ChangeOutcome::Recovered : ChangeOutcome::Failed, $request->projectStateDigest, $context, $this->receiptCause($receipts, $context));
        };
        $reportResidue = function (array $instructions, bool $success) use (&$receipts, $request, $context): void {
            $receipts[] = $this->residueReceipt($instructions, $success, $request->projectStateDigest, $context, $this->receiptCause($receipts, $context));
        };
        try {
            $cleanupPending = $this->publish($prepared['prepared'], $prepared['retirements'], $prepared['composerMerge'], $reportRecovery, $state, fn(): ProjectStateIdentity => $this->assertReviewedState($request), $reportResidue);
        } catch (\Exception $exception) {
            if (count($receipts) === $priorReceiptCount || $blueprintDecisionVerified) {
                $receipts[] = $this->terminalReceipt(ChangeOutcome::Failed, $request->planDigest, $request->projectStateDigest, $context, $this->receiptCause($receipts, $context));
            }
            throw new SiteInitializationExecutionException($exception, $receipts);
        }
        $result = new ArtifactApplyResult(ArtifactApplyOutcome::Applied, $request->planDigest, $request->projectStateDigest, $evaluation->status, $changed, $recovered, $cleanupPending);
        $receipts[] = $this->receiptFor($result, $context['operation'], $context['correlation'], $this->receiptCause($receipts, $context), $context['decision']);

        return new SiteInitializationInvocation($result, $receipts);
    }

    private function assertReviewedState(ArtifactApplyRequest $request): ProjectStateIdentity
    {
        if (!hash_equals($request->planDigest, hash('sha256', CanonicalJson::encode($request->plan->toArray()) . "\n"))) {
            $this->unitRefusal(GenerationErrorCode::StalePlan, 'The transported plan does not match the reviewed plan digest.');
        }
        try {
            $state = $this->captureCurrentPlanState($request->plan);
        } catch (\Throwable $exception) {
            $this->unitRefusal(GenerationErrorCode::StalePlan, 'The reviewed project state cannot be safely observed.');
        }
        if (!hash_equals($request->projectStateDigest, $state->digest)) {
            $this->unitRefusal(GenerationErrorCode::StalePlan, 'The project state no longer matches the reviewed evaluation.');
        }

        return $state;
    }

    private function captureCurrentPlanState(ArtifactPlan $plan): ProjectStateIdentity
    {
        $this->assertSafeTarget(self::METADATA, true);
        $metadataPath = $this->absolute(self::METADATA);
        $metadataObservation = file_exists($metadataPath) || is_link($metadataPath) ? $this->readUnitMetadataObservation() : null;
        $metadata = $metadataObservation['metadata'] ?? null;
        $rows = [];
        foreach ($metadata['artifacts'] ?? [] as $row) {
            if (($row['unit'] ?? 'site') === $plan->unitId || in_array($row['unit'] ?? 'site', $plan->retires, true)) {
                $rows[$row['path']] = $row;
            }
        }
        $artifacts = [];
        foreach ($plan->artifacts as $artifact) {
            $this->assertSafeTarget($artifact->path, true);
            $artifacts[$artifact->path] = $artifact;
        }
        foreach (['.waaseyaa/site.yaml', 'composer.json'] as $path) {
            $this->assertSafeTarget($path, true);
            $absolute = $this->absolute($path);
            if (file_exists($absolute) || is_link($absolute)) {
                $this->assertRegularOwnedFile($absolute, $path);
            }
        }
        foreach (array_keys($artifacts + $rows) as $path) {
            $path = (string) $path;
            $absolute = $this->absolute($path);
            $this->assertSafeTarget($path, true);
            if (is_file($absolute)) {
                $this->assertRegularOwnedFile($absolute, $path);
            }
        }

        return $this->captureProjectState($artifacts, $rows, metadataDigest: $metadataObservation['sha256'] ?? ProjectStateIdentity::ABSENT_DIGEST);
    }

    /** @param array{prepared: array<string, GeneratedArtifact>, retirements: array<string, array<string, mixed>>, composerMerge: array<string, mixed>|null, evaluation: EvaluatedArtifactPlan} $prepared @return list<string> */
    private function transactionPaths(array $prepared): array
    {
        $paths = array_keys($prepared['prepared'] + $prepared['retirements']);
        if ($prepared['composerMerge'] !== null) {
            $paths[] = 'composer.json';
        }
        sort($paths, SORT_STRING);

        return $paths;
    }

    /** @param list<ChangeReceipt> $receipts @param array<string, mixed> $context */
    private function receiptCause(array $receipts, array $context): ?string
    {
        return $receipts === [] ? $context['causation'] : $receipts[array_key_last($receipts)]->receiptId;
    }

    /** @param list<GenerationViolation> $errors @param array<string, mixed> $context @param list<ChangeReceipt> $receipts */
    private function refusedInvocation(ArtifactApplyRequest $request, array $errors, array $context, array $receipts = [], bool $recovered = false): SiteInitializationInvocation
    {
        $result = new ArtifactApplyResult(ArtifactApplyOutcome::Refused, $request->planDigest, $request->projectStateDigest, [], [], $recovered, errors: $errors);
        $receipts[] = $this->receiptFor($result, $context['operation'], $context['correlation'], $this->receiptCause($receipts, $context), $context['decision']);

        return new SiteInitializationInvocation($result, $receipts);
    }

    /** @param array<string, mixed> $context @param list<ChangeReceipt> $receipts */
    private function recoverForInvocation(array $context, array &$receipts): bool
    {
        try {
            return $this->recoverIfRequired(true, function (array $journal, bool $success) use (&$receipts, $context): void {
                $receipts[] = $this->recoveryReceipt($journal, $success ? ChangeOutcome::Recovered : ChangeOutcome::Failed, ProjectStateIdentity::ABSENT_DIGEST, $context, $this->receiptCause($receipts, $context));
            }, function (array $instructions, bool $success) use (&$receipts, $context): void {
                $receipts[] = $this->residueReceipt($instructions, $success, ProjectStateIdentity::ABSENT_DIGEST, $context, $this->receiptCause($receipts, $context));
            });
        } catch (\Exception $exception) {
            throw new SiteInitializationExecutionException($exception, $receipts);
        }
    }

    /** @param array<string, mixed> $instructions @param array<string, mixed> $context */
    private function residueReceipt(array $instructions, bool $success, string $projectStateDigest, array $context, ?string $cause): ChangeReceipt
    {
        $context['operation'] = 'site.recover';
        if ($context['blueprint_decision_verified']) {
            $context['decision'] = null;
        }

        return $this->terminalReceipt(
            $success ? ChangeOutcome::Recovered : ChangeOutcome::Failed,
            hash('sha256', CanonicalJson::encode($instructions) . "\n"),
            $projectStateDigest,
            $context,
            $cause,
        );
    }

    /** @param array<string, mixed> $journal @param array<string, mixed> $context */
    private function recoveryReceipt(array $journal, ChangeOutcome $outcome, string $projectStateDigest, array $context, ?string $cause): ChangeReceipt
    {
        // This identifies these validated recovery instructions, never the
        // unavailable original publication plan. No extra durable record exists.
        $this->validateJournal($journal, true);
        $digest = hash('sha256', CanonicalJson::encode($journal) . "\n");
        $context['operation'] = 'site.recover';
        if ($context['blueprint_decision_verified']) {
            $context['decision'] = null;
        }

        return $this->terminalReceipt($outcome, $digest, $projectStateDigest, $context, $cause);
    }

    /** @param array<string, mixed> $context */
    private function terminalReceipt(ChangeOutcome $outcome, string $planDigest, string $projectStateDigest, array $context, ?string $cause): ChangeReceipt
    {
        return new ChangeReceipt(
            $this->mintIdentifier('rcpt'),
            ChangeReceipt::GENERATION_AUTHORITY,
            self::CONTRACT_VERSION,
            $context['operation'],
            $planDigest,
            $outcome,
            $context['correlation'],
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            ['version' => 1, 'project_state_digest' => $projectStateDigest, 'status' => new \stdClass(), 'changed' => [], 'errors' => [], 'recovered_interrupted_transaction' => $outcome === ChangeOutcome::Recovered, 'cleanup_pending' => false],
            $cause,
            $context['decision'],
        );
    }

    /**
     * Evaluate one immutable plan against this project (ADR-025 D-6.2).
     *
     * Observes the project and writes nothing -- not even the control directory -- because
     * a preview that mutates is not a preview. It enters the same target
     * evaluator dry-run and apply enter, so no check can differ between them.
     *
     * Collisions throw rather than fabricating a partially evaluated preview.
     * Controlled apply converts refusals to its typed result after its stale gate.
     */
    public function evaluate(ArtifactPlan $plan, ?BlueprintDecisionReceipt $decisionReceipt = null): EvaluatedArtifactPlan
    {
        if (is_file($this->absolute(self::JOURNAL))) {
            throw new SiteInitializationCollisionException('An interrupted site initialization must be recovered before a plan is evaluated.');
        }
        return $this->prepareUnitPlan($plan, $decisionReceipt)['evaluation'];
    }

    /**
     * The closed compiler admission list. No seeded compiler has migrated yet.
     * Persisted provenance is readable independently of new-plan eligibility.
     *
     * @var list<string>
     */
    private const array SEEDED_COMPILERS = [];

    /**
     * The closed compiler admission list for additive successor evolution.
     * Unit identity and disposition are checked separately at admission.
     *
     * @var list<class-string>
     */
    private const array ADDITIVE_COMPILERS = [SiteArtifactRenderer::class, ApplicationBlueprintCompiler::class];

    /**
     * Return normalized applied evidence for a blueprint root plan, or null
     * for every blueprint-free plan. This helper observes only immutable plan
     * bytes, so controlled apply can refuse before creating its lock and then
     * repeat the same decision under that lock.
     */
    private function blueprintEvidenceForPlan(
        ArtifactPlan $plan,
        ?BlueprintDecisionReceipt $decisionReceipt,
    ): ?BlueprintAppliedEvidence {
        $isBlueprintCompiler = $plan->generatorFqcn === ApplicationBlueprintCompiler::class;
        if ($isBlueprintCompiler
            && ($plan->unitId !== 'site' || $plan->disposition !== GenerationUnitDisposition::Managed)) {
            $this->unitRefusal(GenerationErrorCode::UnauthorizedSetDelta, 'The blueprint compiler may publish only the managed root site unit.');
        }
        if ($plan->unitId !== 'site' && !$isBlueprintCompiler) {
            return null;
        }

        $manifestArtifact = null;
        foreach ($plan->artifacts as $artifact) {
            if ($artifact->path === '.waaseyaa/site.yaml') {
                $manifestArtifact = $artifact;
                break;
            }
        }
        if (!$manifestArtifact instanceof GeneratedArtifact) {
            if ($isBlueprintCompiler) {
                $this->unitRefusal(GenerationErrorCode::UnauthorizedSetDelta, 'The blueprint compiler plan does not carry its root manifest authority.');
            }

            return null;
        }

        try {
            $manifest = new SiteManifestParser()->parse($manifestArtifact->content, '.waaseyaa/site.yaml');
        } catch (SiteManifestValidationException) {
            if ($isBlueprintCompiler) {
                $this->unitRefusal(GenerationErrorCode::UnauthorizedSetDelta, 'The blueprint compiler plan does not carry a valid root manifest authority.');
            }

            return null;
        }
        $hasBlueprint = $manifest->applicationBlueprint !== null;
        if (!$hasBlueprint && !$isBlueprintCompiler) {
            return null;
        }
        if (!$hasBlueprint || !$isBlueprintCompiler
            || !hash_equals($manifest->digest, $plan->inputDigest)
            || $manifest->generatorVersion !== $plan->generatorVersion
            || $decisionReceipt === null) {
            $this->unitRefusal(GenerationErrorCode::UnauthorizedSetDelta, 'Blueprint execution requires its declared compiler and a matching approved decision receipt.');
        }

        try {
            $evidence = BlueprintAppliedEvidence::fromDecisionReceipt($decisionReceipt, '<engine-decision-receipt>');
        } catch (SiteManifestValidationException) {
            $this->unitRefusal(GenerationErrorCode::UnauthorizedSetDelta, 'Blueprint execution requires a structurally valid approved decision receipt.');
        }
        if (!$evidence->matches($manifest)) {
            $this->unitRefusal(GenerationErrorCode::UnauthorizedSetDelta, 'Blueprint execution requires a decision receipt matching its exact blueprint and manifest.');
        }

        return $evidence;
    }

    /** @internal The shared validated reader for the dormant unit-aware doctor.
     * @return array<string, mixed>
     */
    public function readUnitMetadata(): array
    {
        return $this->readUnitMetadataObservation()['metadata'];
    }

    /** @return array{metadata: array<string, mixed>, sha256: string} */
    private function readUnitMetadataObservation(): array
    {
        $path = $this->absolute(self::METADATA);
        $this->assertSafeTarget(self::METADATA);
        $this->assertRegularOwnedFile($path, self::METADATA);
        $sha256 = null;
        $metadata = $this->readMetadata($path, true, $sha256);

        return ['metadata' => $metadata, 'sha256' => $sha256];
    }

    /**
     * Prepare the complete ownership transition without staging a byte.
     * Both preview and controlled apply use this ownership transition.
     *
     * @return array{prepared: array<string, GeneratedArtifact>, retirements: array<string, array<string, mixed>>, composerMerge: array{content: string, mode: int, before_sha256: string}|null, evaluation: EvaluatedArtifactPlan}
     */
    private function prepareUnitPlan(ArtifactPlan $plan, ?BlueprintDecisionReceipt $decisionReceipt = null): array
    {
        $blueprintEvidence = $this->blueprintEvidenceForPlan($plan, $decisionReceipt);
        if ($plan->schemaEffects !== [] || $plan->configEffects !== []) {
            $this->unitRefusal(GenerationErrorCode::UnsupportedDeclaration, 'Reserved effects are not active.');
        }
        if ($plan->setEvolution === ArtifactSetEvolution::Additive
            && ($plan->unitId !== 'site'
                || $plan->disposition !== GenerationUnitDisposition::Managed
                || !in_array($plan->generatorFqcn, self::ADDITIVE_COMPILERS, true))) {
            $this->unitRefusal(GenerationErrorCode::UnauthorizedSetDelta, 'The compiler is not permitted to evolve its artifact set.');
        }
        if (in_array('site', $plan->retires, true)) {
            $this->unitRefusal(GenerationErrorCode::UnitPathConflict, 'The root generation unit is not retirable.');
        }
        if ($plan->unitId === 'site' && (!in_array($plan->generatorFqcn, self::ADDITIVE_COMPILERS, true) || $plan->disposition !== GenerationUnitDisposition::Managed)) {
            $this->unitRefusal(GenerationErrorCode::MaliciousIdentifier, 'The site unit is reserved for the managed root compiler.');
        }
        $metadataPath = $this->absolute(self::METADATA);
        $hasMetadata = file_exists($metadataPath) || is_link($metadataPath);
        $priorObservation = $hasMetadata ? $this->readUnitMetadataObservation() : null;
        $prior = $priorObservation['metadata'] ?? null;
        if ($prior === null && $plan->unitId !== 'site') {
            $this->unitRefusal(GenerationErrorCode::CollisionRefused, 'A non-root unit requires an initialized site.');
        }
        if ($prior !== null) {
            $this->assertSafeTarget('.waaseyaa/site.yaml');
            $manifestPath = $this->absolute('.waaseyaa/site.yaml');
            if (!file_exists($manifestPath) && !is_link($manifestPath)) {
                $this->unitRefusal(GenerationErrorCode::UnitPathConflict, 'Generated ownership metadata exists without its manifest authority.');
            }
            $this->assertRegularOwnedFile($manifestPath, '.waaseyaa/site.yaml');
            try {
                $manifest = new SiteManifestParser()->parse((string) file_get_contents($manifestPath), '.waaseyaa/site.yaml');
            } catch (\Throwable $exception) {
                throw new SiteInitializationCollisionException('The previously generated site authority is not reproducible.', previous: $exception);
            }
            if (!hash_equals($manifest->digest, $prior['manifest_digest']) || $manifest->generatorVersion !== $prior['generator_version']) {
                throw new SiteInitializationCollisionException('Generated ownership metadata does not bind the current manifest authority.');
            }
            if ($manifest->applicationBlueprint !== null && !array_key_exists('application_blueprint', $prior)) {
                $this->unitRefusal(GenerationErrorCode::UnauthorizedSetDelta, 'A generated blueprint root requires its matching applied approval evidence.');
            }
            if (array_key_exists('application_blueprint', $prior)) {
                try {
                    $priorEvidence = BlueprintAppliedEvidence::fromArray($prior['application_blueprint'], '.waaseyaa/generated.json');
                } catch (SiteManifestValidationException $exception) {
                    throw new SiteInitializationCollisionException('Generated ownership metadata contains invalid blueprint evidence.', previous: $exception);
                }
                if (!$priorEvidence->matches($manifest)) {
                    throw new SiteInitializationCollisionException('Generated ownership metadata does not bind the current blueprint authority.');
                }
            }
        }
        $units = [];
        foreach ($prior['units'] ?? [] as $unit) {
            $units[$unit['id']] = $unit;
        }
        $existing = $plan->unitId === 'site'
            ? ($prior === null ? null : ['generator' => ['fqcn' => array_key_exists('application_blueprint', $prior) ? ApplicationBlueprintCompiler::class : SiteArtifactRenderer::class, 'version' => $prior['generator_version']], 'disposition' => 'managed', 'input_digest' => $prior['manifest_digest']])
            : ($units[$plan->unitId] ?? null);
        if ($existing === null && $plan->unitId !== 'site' && $plan->artifacts === [] && $plan->registrations === []) {
            $this->unitRefusal(GenerationErrorCode::UnsupportedDeclaration, 'A new non-root unit must own state.');
        }
        if ($existing !== null && $plan->unitId === 'site'
            && $existing['generator']['fqcn'] === ApplicationBlueprintCompiler::class
            && $plan->generatorFqcn !== ApplicationBlueprintCompiler::class) {
            $this->unitRefusal(GenerationErrorCode::UnauthorizedSetDelta, 'An applied blueprint root cannot transition back to the manifest-only compiler.');
        }
        $approvedRootTransition = $existing !== null
            && $plan->unitId === 'site'
            && $existing['generator']['fqcn'] === SiteArtifactRenderer::class
            && $plan->generatorFqcn === ApplicationBlueprintCompiler::class
            && $blueprintEvidence !== null;
        if ($existing !== null && !$approvedRootTransition
            && ($existing['generator']['fqcn'] !== $plan->generatorFqcn || $existing['generator']['version'] !== $plan->generatorVersion || $existing['disposition'] !== $plan->disposition->value)) {
            $this->unitRefusal(GenerationErrorCode::UnitPathConflict, 'A recorded unit cannot change compiler identity or disposition.');
        }
        // @phpstan-ignore function.impossibleType (the reviewed compiler allowlist is intentionally empty before migrations)
        if ($existing === null && $plan->disposition === GenerationUnitDisposition::Seeded && !in_array($plan->generatorFqcn, self::SEEDED_COMPILERS, true)) {
            $this->unitRefusal(GenerationErrorCode::UnsupportedDeclaration, 'The compiler is not permitted to create seeded units.');
        }
        $composerState = $this->readComposerProviderState();
        $registrationEffects = $this->reconcileRegistrations($plan, $prior['registrations'] ?? [], $existing, $composerState);
        /** @var array<string, array<string, mixed>> $priorRows */
        $priorRows = [];
        /** @var array<string, array<string, mixed>> $suppliedRows */
        $suppliedRows = [];
        /** @var array<string, array<string, mixed>> $observedRows */
        $observedRows = [];
        foreach ($prior['artifacts'] ?? [] as $row) {
            $priorRows[$row['path']] = $row;
            $owner = $row['unit'] ?? 'site';
            if ($owner === $plan->unitId) {
                $suppliedRows[$row['path']] = $row;
            }
            if ($owner === $plan->unitId || in_array($owner, $plan->retires, true)) {
                $observedRows[$row['path']] = $row;
            }
        }
        $artifacts = [];
        foreach ($plan->artifacts as $artifact) {
            $this->assertUnitOwnershipPath($artifact->path);
            if ($artifact->path === self::METADATA) {
                $this->unitRefusal(GenerationErrorCode::CollisionRefused, 'A compiler cannot own the composed metadata.', $artifact->path);
            }
            if (isset($priorRows[$artifact->path]) && ($priorRows[$artifact->path]['unit'] ?? 'site') !== $plan->unitId) {
                $this->unitRefusal(GenerationErrorCode::CollisionRefused, 'The path belongs to another generation unit.', $artifact->path);
            }
            $artifacts[$artifact->path] = $artifact;
        }
        /** @var list<string> $adds */
        $adds = $existing === null ? [] : array_values(array_diff(array_keys($artifacts), array_keys($suppliedRows)));
        /** @var list<string> $drops */
        $drops = $existing === null ? [] : array_values(array_diff(array_keys($suppliedRows), array_keys($artifacts)));
        sort($adds, SORT_STRING);
        sort($drops, SORT_STRING);
        if ($drops !== []) {
            $code = $plan->setEvolution === ArtifactSetEvolution::Additive
                ? GenerationErrorCode::UnauthorizedSetDelta
                : GenerationErrorCode::UndeclaredUnitRetirement;
            $this->unitRefusal($code, 'A supplied unit cannot drop recorded paths.', $drops[0]);
        }
        if ($adds !== [] && $plan->setEvolution === ArtifactSetEvolution::Frozen) {
            $this->unitRefusal(GenerationErrorCode::UnauthorizedSetDelta, 'A frozen unit cannot add generated paths.', $adds[0]);
        }
        if ($existing !== null && $plan->disposition === GenerationUnitDisposition::Managed && hash_equals($existing['input_digest'], $plan->inputDigest)) {
            foreach ($artifacts as $path => $artifact) {
                if (!isset($suppliedRows[$path])) {
                    continue;
                }
                if (!hash_equals($suppliedRows[$path]['managed_sha256'], $artifact->managedDigest())) {
                    if ($plan->unitId === 'site') {
                        throw new SiteInitializationCollisionException(sprintf(
                            'Generated artifact bytes changed without a generator-version migration: %s. '
                            . 'If this followed a framework upgrade, rebind framework.observed_lock_sha256 in '
                            . '.waaseyaa/site.yaml to the sha256 of the current composer.lock and re-run site:init.',
                            $path,
                        ));
                    }
                    $this->unitRefusal(GenerationErrorCode::AmbiguousExtensionRegion, 'Generated bytes changed without a changed input identity.', $path);
                }
            }
        }
        if ($plan->unitId === 'site') {
            $rootManifest = $artifacts['.waaseyaa/site.yaml'] ?? null;
            if (!$rootManifest instanceof GeneratedArtifact) {
                $this->unitRefusal(GenerationErrorCode::UnitPathConflict, 'A root plan requires its manifest authority.');
            }
            $renderedManifest = new SiteManifestParser()->parse($rootManifest->content, '.waaseyaa/site.yaml');
            if (!hash_equals($renderedManifest->digest, $plan->inputDigest) || $renderedManifest->generatorVersion !== $plan->generatorVersion) {
                $this->unitRefusal(GenerationErrorCode::UnitPathConflict, 'The root plan does not bind its manifest authority.');
            }
        }
        $retirements = [];
        foreach ($observedRows as $path => $row) {
            $path = (string) $path;
            if (!in_array($row['unit'] ?? 'site', $plan->retires, true)) {
                continue;
            }
            $this->assertSafeTarget($path);
            $absolute = $this->absolute($path);
            if (!file_exists($absolute) && !is_link($absolute)) {
                $this->unitRefusal(GenerationErrorCode::CollisionRefused, 'A retired artifact is missing.', $path);
            }
            $this->assertRegularOwnedFile($absolute, $path);
            try {
                $artifact = new GeneratedArtifact($path, (string) file_get_contents($absolute), intval($row['mode'], 8), $row['extension_region'] ?? null);
                $matches = hash_equals($row['managed_sha256'], $artifact->managedDigest());
            } catch (\InvalidArgumentException) {
                $matches = false;
            }
            if (!$matches || !$this->modeMatches($absolute, intval($row['mode'], 8))) {
                $this->unitRefusal(GenerationErrorCode::AmbiguousExtensionRegion, 'Retirement refuses modified generated content or mode.', $path);
            }
            $retirements[$path] = $row;
        }
        if ($existing !== null && $plan->disposition === GenerationUnitDisposition::Seeded) {
            $targets = ['prepared' => [], 'status' => array_fill_keys(array_keys($artifacts), ArtifactStatus::Unchanged)];
        } else {
            // New, unowned files must retain the same collision polarity as a
            // pristine publish; an initialized project's existence is no grant.
            foreach ($artifacts as $path => $artifact) {
                if (!isset($priorRows[$path]) && (file_exists($this->absolute($path)) || is_link($this->absolute($path)))) {
                    if (in_array($path, $adds, true)) {
                        $this->unitRefusal(GenerationErrorCode::CollisionRefused, 'An added path collides with an unowned artifact.', $path);
                    }
                    $this->evaluateTargets([$path => $artifact], false, []);
                }
            }
            $targets = $this->evaluateTargets($artifacts, $hasMetadata, $suppliedRows);
        }
        $document = $prior ?? [
            'schema' => 'waaseyaa.generated', 'version' => 1,
            'generator_version' => $plan->generatorVersion, 'manifest_digest' => $plan->inputDigest,
            'artifacts' => [],
        ];
        $rows = [];
        foreach ($priorRows as $path => $row) {
            $owner = $row['unit'] ?? 'site';
            if (!in_array($owner, $plan->retires, true) && ($owner !== $plan->unitId || $plan->disposition === GenerationUnitDisposition::Seeded)) {
                $rows[$path] = $row;
            }
        }
        if ($existing === null || $plan->disposition === GenerationUnitDisposition::Managed) {
            foreach ($artifacts as $path => $artifact) {
                $row = ['path' => $path, 'mode' => sprintf('%04o', $artifact->mode), 'managed_sha256' => $artifact->managedDigest()];
                if ($artifact->extensionRegion !== null) {
                    $row['extension_region'] = $artifact->extensionRegion;
                }
                if ($plan->unitId !== 'site') {
                    $row['unit'] = $plan->unitId;
                }
                $rows[$path] = $row;
            }
            if ($plan->unitId === 'site') {
                $document['generator_version'] = $plan->generatorVersion;
                $document['manifest_digest'] = $plan->inputDigest;
                unset($document['application_blueprint']);
                if ($blueprintEvidence !== null) {
                    $document['application_blueprint'] = $blueprintEvidence->toArray();
                }
            } else {
                $units[$plan->unitId] = ['id' => $plan->unitId, 'disposition' => $plan->disposition->value, 'generator' => ['fqcn' => $plan->generatorFqcn, 'version' => $plan->generatorVersion], 'input_digest' => $plan->inputDigest];
            }
        }
        foreach ($plan->retires as $retired) {
            unset($units[$retired]);
        }
        $registrations = $registrationEffects['registrations'];
        if ($plan->unitId !== 'site' && $plan->disposition === GenerationUnitDisposition::Managed) {
            $ownsState = false;
            foreach ([...array_values($rows), ...$registrations] as $row) {
                if (($row['unit'] ?? 'site') === $plan->unitId) {
                    $ownsState = true;
                }
            }
            if (!$ownsState) {
                unset($units[$plan->unitId]);
            }
        }
        unset($document['registrations']);
        if ($registrations !== []) {
            $document['registrations'] = $registrations;
        }
        ksort($rows, SORT_STRING);
        ksort($units, SORT_STRING);
        $document['artifacts'] = array_values($rows);
        unset($document['units']);
        if ($units !== []) {
            $document['units'] = array_values($units);
        }
        // Re-derive every supplied managed row from the actual admitted artifact
        // (including preserved extension bytes), before metadata can be staged.
        foreach ($artifacts as $path => $artifact) {
            if ($plan->disposition === GenerationUnitDisposition::Managed) {
                $admitted = $targets['prepared'][$path] ?? $artifact;
                if (!hash_equals($rows[$path]['managed_sha256'], $admitted->managedDigest())) {
                    $this->unitRefusal(GenerationErrorCode::UnitPathConflict, 'Composed ownership does not certify the admitted artifact.', $path);
                }
            }
        }
        $metadata = new GeneratedArtifact(self::METADATA, CanonicalJson::encode($document) . "\n");
        $metadataTarget = $this->evaluateTargets([self::METADATA => $metadata], $hasMetadata, []);
        $prepared = $targets['prepared'] + $metadataTarget['prepared'];
        ksort($prepared, SORT_STRING);

        return [
            'prepared' => $prepared,
            'retirements' => $retirements,
            'composerMerge' => $registrationEffects['composerMerge'],
            'evaluation' => new EvaluatedArtifactPlan($plan, $this->captureProjectState($artifacts, $observedRows, $composerState['sha256'], $priorObservation['sha256'] ?? ProjectStateIdentity::ABSENT_DIGEST), $targets['status'], $adds, $drops),
        ];
    }

    /**
     * @internal Shared Composer observation for the dormant engine and doctor.
     * @return array{exists: bool, raw: ?string, sha256: string, mode: ?int, providers: list<string>, spans: array<string, mixed>}
     */
    public function readComposerProviderState(): array
    {
        $this->assertSafeTarget('composer.json');
        $path = $this->absolute('composer.json');
        if (!file_exists($path) && !is_link($path)) {
            return ['exists' => false, 'raw' => null, 'sha256' => ProjectStateIdentity::ABSENT_DIGEST, 'mode' => null, 'providers' => [], 'spans' => []];
        }
        $this->assertRegularOwnedFile($path, 'composer.json');
        $raw = file_get_contents($path);
        if (!is_string($raw)) {
            $this->unitRefusal(GenerationErrorCode::InvalidComposerProviderState, 'Composer manifest cannot be read.', 'composer.json');
        }
        $this->injectFault('after-composer-read', -1, 'composer.json');
        try {
            $decoded = json_decode($raw, false, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->unitRefusal(GenerationErrorCode::InvalidComposerProviderState, 'Composer manifest must be valid JSON.', 'composer.json');
        }
        if (!$decoded instanceof \stdClass) {
            $this->unitRefusal(GenerationErrorCode::InvalidComposerProviderState, 'Composer manifest must be a JSON object.', 'composer.json');
        }
        // Decode validates syntax; spans preserve foreign number/escape lexemes
        // and object/list identity. Only the targeted ancestor keys are unique.
        $offset = 0;
        $root = $this->composerJsonSpan($raw, $offset);
        $extra = $this->composerObjectMember($root, 'extra');
        $waaseyaa = $extra === null ? null : $this->composerObjectMember($extra, 'waaseyaa');
        $providerSpan = $waaseyaa === null ? null : $this->composerObjectMember($waaseyaa, 'providers');
        $providers = [];
        if ($providerSpan !== null) {
            if ($providerSpan['kind'] !== 'array') {
                $this->unitRefusal(GenerationErrorCode::InvalidComposerProviderState, 'Composer providers must be a list.', 'composer.json');
            }
            foreach ($providerSpan['items'] as $item) {
                $provider = json_decode(substr($raw, $item['start'], (int) ($item['end'] - $item['start'])), true, flags: JSON_THROW_ON_ERROR);
                if (!is_string($provider) || $provider === '' || in_array($provider, $providers, true)) {
                    $this->unitRefusal(GenerationErrorCode::InvalidComposerProviderState, 'Composer providers must be unique nonempty strings.', 'composer.json');
                }
                $providers[] = $provider;
            }
        }

        return [
            'exists' => true, 'raw' => $raw, 'sha256' => hash('sha256', $raw),
            'mode' => $this->platform->enforcesPermissionBits() ? fileperms($path) & 0o777 : 0o644,
            'providers' => $providers,
            'spans' => ['root' => $root, 'extra' => $extra, 'waaseyaa' => $waaseyaa, 'providers' => $providerSpan],
        ];
    }

    /** @return array<string, mixed> */
    private function composerJsonSpan(string $raw, int &$offset): array
    {
        $offset += strspn($raw, " \t\r\n", $offset);
        $start = $offset;
        $token = $raw[$offset++];
        if ($token === '"') {
            while ($raw[$offset] !== '"') {
                $offset += $raw[$offset] === '\\' ? 2 : 1;
            }
            ++$offset;

            return ['start' => $start, 'end' => $offset, 'kind' => 'string'];
        }
        if ($token !== '{' && $token !== '[') {
            while ($offset < strlen($raw) && !str_contains(" \t\r\n,]}", $raw[$offset])) {
                ++$offset;
            }

            return ['start' => $start, 'end' => $offset, 'kind' => 'scalar'];
        }
        $object = $token === '{';
        $closing = $object ? '}' : ']';
        $members = [];
        $items = [];
        $offset += strspn($raw, " \t\r\n", $offset);
        while ($raw[$offset] !== $closing) {
            if ($object) {
                $key = $this->composerJsonSpan($raw, $offset);
                $offset += strspn($raw, " \t\r\n", $offset);
                ++$offset; // The colon is already JSON-validated.
                $value = $this->composerJsonSpan($raw, $offset);
                $members[] = ['key' => json_decode(substr($raw, $key['start'], (int) ($key['end'] - $key['start'])), true, flags: JSON_THROW_ON_ERROR), 'key_start' => $key['start'], 'value' => $value];
            } else {
                $items[] = $this->composerJsonSpan($raw, $offset);
            }
            $offset += strspn($raw, " \t\r\n", $offset);
            if ($raw[$offset] === ',') {
                ++$offset;
                $offset += strspn($raw, " \t\r\n", $offset);
            }
        }
        ++$offset;

        return ['start' => $start, 'end' => $offset, 'kind' => $object ? 'object' : 'array', 'members' => $members, 'items' => $items];
    }

    /** @param array<string, mixed> $object @return array<string, mixed>|null */
    private function composerObjectMember(array $object, string $key): ?array
    {
        if ($object['kind'] !== 'object') {
            $this->unitRefusal(GenerationErrorCode::InvalidComposerProviderState, 'Composer provider ancestors must be JSON objects.', 'composer.json');
        }
        $found = null;
        foreach ($object['members'] as $member) {
            if ($member['key'] === $key) {
                if ($found !== null) {
                    $this->unitRefusal(GenerationErrorCode::InvalidComposerProviderState, 'Composer provider ancestors must not contain duplicate targeted keys.', 'composer.json');
                }
                $found = $member['value'];
            }
        }

        return $found;
    }

    private function validateRegistrationRosterShape(mixed $roster): void
    {
        if (!is_array($roster) || !array_is_list($roster) || $roster === []) {
            $this->unitRefusal(GenerationErrorCode::InvalidRegistrationRoster, 'A present registration roster must be a nonempty list.');
        }
        foreach ($roster as $row) {
            if (!is_array($row)) {
                $this->unitRefusal(GenerationErrorCode::InvalidRegistrationRoster, 'A registration row must be an object.');
            }
            foreach ($row as $key => $value) {
                if (!in_array($key, ['fqcn', 'group', 'unit'], true) || !is_string($value) || $value === '') {
                    $this->unitRefusal(GenerationErrorCode::InvalidRegistrationRoster, 'A registration row has invalid members.');
                }
            }
            if (!isset($row['fqcn'])) {
                $this->unitRefusal(GenerationErrorCode::InvalidRegistrationRoster, 'A registration row requires an FQCN.');
            }
        }
    }

    /** @param list<array<string, string>> $roster @param array<string, bool> $units */
    private function validateRegistrationRosterOwnership(array $roster, array $units): void
    {
        $seen = [];
        foreach ($roster as $row) {
            if (isset($seen[$row['fqcn']]) || (isset($row['unit']) && !isset($units[$row['unit']]))) {
                $this->unitRefusal(GenerationErrorCode::RegistrationOwnershipConflict, 'Registration ownership is duplicated or names an unknown unit.');
            }
            $seen[$row['fqcn']] = true;
        }
        $previous = null;
        foreach ($roster as $row) {
            if ($previous !== null && strcmp($previous, $row['fqcn']) >= 0) {
                $this->unitRefusal(GenerationErrorCode::InvalidRegistrationRoster, 'Registration rows must be in canonical FQCN order.');
            }
            $previous = $row['fqcn'];
        }
    }

    /**
     * @param list<array<string, string>> $priorRoster
     * @param array<string, mixed>|null $existing
     * @param array<string, mixed> $composer
     * @return array{registrations: list<array<string, string>>, composerMerge: array{content: string, mode: int, before_sha256: string}|null}
     */
    private function reconcileRegistrations(ArtifactPlan $plan, array $priorRoster, ?array $existing, array $composer): array
    {
        $prior = [];
        $supplied = [];
        foreach ($priorRoster as $row) {
            $prior[$row['fqcn']] = $row;
            if (($row['unit'] ?? 'site') === $plan->unitId) {
                $entry = $row;
                unset($entry['unit']);
                $supplied[] = $entry;
            }
        }
        $declared = array_map(static fn($registration): array => $registration->toArray(), $plan->registrations);
        foreach ($declared as $row) {
            if (isset($prior[$row['fqcn']]) && ($prior[$row['fqcn']]['unit'] ?? 'site') !== $plan->unitId) {
                $this->unitRefusal(GenerationErrorCode::RegistrationOwnershipConflict, 'The provider belongs to another generation unit.');
            }
            if (!isset($prior[$row['fqcn']]) && in_array($row['fqcn'], $composer['providers'], true)) {
                $this->unitRefusal(GenerationErrorCode::RegistrationOwnershipConflict, 'The provider is application-owned. Keep its manual registration or remove it deliberately before requesting generation; implicit adoption is not supported.');
            }
        }
        if ($existing !== null && $plan->disposition === GenerationUnitDisposition::Seeded && $supplied !== $declared) {
            $this->unitRefusal(GenerationErrorCode::SeededRegistrationRedeclared, 'A seeded registration declaration is frozen after creation.');
        }
        $roster = [];
        $withdraw = [];
        foreach ($priorRoster as $row) {
            $owner = $row['unit'] ?? 'site';
            if (in_array($owner, $plan->retires, true)) {
                $withdraw[] = $row['fqcn'];
            } elseif ($owner !== $plan->unitId) {
                $roster[$row['fqcn']] = $row;
            } elseif (!in_array($row['fqcn'], array_column($declared, 'fqcn'), true)) {
                $withdraw[] = $row['fqcn'];
            }
        }
        $providers = array_values(array_filter($composer['providers'], static fn(string $fqcn): bool => !in_array($fqcn, $withdraw, true)));
        foreach ($declared as $row) {
            if ($plan->unitId !== 'site') {
                $row['unit'] = $plan->unitId;
            }
            $roster[$row['fqcn']] = $row;
            if (($existing === null || $plan->disposition === GenerationUnitDisposition::Managed) && !in_array($row['fqcn'], $providers, true)) {
                $providers[] = $row['fqcn'];
            }
        }
        ksort($roster, SORT_STRING);
        $merge = null;
        if ($providers !== $composer['providers']) {
            if ($composer['exists'] !== true) {
                $this->unitRefusal(GenerationErrorCode::InvalidComposerProviderState, 'Registration changes require an existing application composer.json.', 'composer.json');
            }
            $merge = ['content' => $this->renderComposerProviders($composer, $providers), 'mode' => $composer['mode'], 'before_sha256' => $composer['sha256']];
        }

        return ['registrations' => array_values($roster), 'composerMerge' => $merge];
    }

    /** @param array<string, mixed> $composer @param list<string> $providers */
    private function renderComposerProviders(array $composer, array $providers): string
    {
        $raw = $composer['raw'];
        $spans = $composer['spans'];
        $tokens = [];
        if ($spans['providers'] !== null) {
            foreach ($spans['providers']['items'] as $index => $item) {
                $tokens[$composer['providers'][$index]] = substr($raw, $item['start'], (int) ($item['end'] - $item['start']));
            }
        }
        $encoded = array_map(static fn(string $provider): string => $tokens[$provider] ?? json_encode($provider, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $providers);
        $rootBytes = substr($raw, $spans['root']['start'], (int) ($spans['root']['end'] - $spans['root']['start']));
        $newline = str_contains($rootBytes, "\r\n") ? "\r\n" : "\n";
        $pretty = str_contains($rootBytes, "\n");
        preg_match('/\r?\n([ \t]+)"/', $rootBytes, $indentMatch);
        $indent = $indentMatch[1] ?? '    ';
        $colon = str_contains($rootBytes, '": ') ? ': ' : ':';
        if ($spans['providers'] !== null) {
            $span = $spans['providers'];
            $old = substr($raw, $span['start'], (int) ($span['end'] - $span['start']));
            if (str_contains($old, "\n")) {
                $base = $this->composerLineIndent($raw, (int) $span['end'] - 1);
                $itemIndent = isset($span['items'][0]) ? $this->composerLineIndent($raw, $span['items'][0]['start']) : $base . $indent;
                $replacement = $encoded === [] ? '[]' : '[' . $newline . $itemIndent . implode(',' . $newline . $itemIndent, $encoded) . $newline . $base . ']';
            } else {
                preg_match('/^\[([ \t]*)/', $old, $left);
                preg_match('/([ \t]*)\]$/', $old, $right);
                $separator = ',';
                if (count($span['items']) > 1) {
                    $gap = substr($raw, $span['items'][0]['end'], (int) ($span['items'][1]['start'] - $span['items'][0]['end']));
                    $separator = $gap;
                } elseif (($left[1] ?? '') !== '') {
                    $separator = ', ';
                }
                $replacement = '[' . ($left[1] ?? '') . implode($separator, $encoded) . ($right[1] ?? '') . ']';
            }

            return substr($raw, 0, $span['start']) . $replacement . substr($raw, $span['end']);
        }
        if ($spans['waaseyaa'] !== null) {
            $parent = $spans['waaseyaa'];
            $keys = ['providers'];
        } elseif ($spans['extra'] !== null) {
            $parent = $spans['extra'];
            $keys = ['waaseyaa', 'providers'];
        } else {
            $parent = $spans['root'];
            $keys = ['extra', 'waaseyaa', 'providers'];
        }
        if ($parent['members'] !== []) {
            $pretty = str_contains(substr($raw, $parent['start'], (int) ($parent['end'] - $parent['start'])), "\n");
        }
        $base = $this->composerLineIndent($raw, $parent['start']);
        $memberIndent = $base . $indent;
        if ($pretty && isset($parent['members'][0])) {
            $memberIndent = $this->composerLineIndent($raw, $parent['members'][0]['key_start']);
        }
        $member = $this->composerNewMember($keys, $encoded, $pretty, $memberIndent, $indent, $newline, $colon);
        if ($parent['members'] === []) {
            $replacement = $pretty ? '{' . $newline . $memberIndent . $member . $newline . $base . '}' : '{' . $member . '}';

            return substr($raw, 0, $parent['start']) . $replacement . substr($raw, $parent['end']);
        }
        $last = $parent['members'][array_key_last($parent['members'])]['value']['end'];
        $separator = $pretty ? $newline . $memberIndent : ($colon === ': ' ? ' ' : '');

        return substr($raw, 0, $last) . ',' . $separator . $member . substr($raw, $last);
    }

    private function composerLineIndent(string $raw, int $offset): string
    {
        $lineStart = strrpos(substr($raw, 0, $offset), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        preg_match('/^[ \t]*/', substr($raw, $lineStart), $match);

        return $match[0];
    }

    /** @param list<string> $keys @param list<string> $encoded */
    private function composerNewMember(array $keys, array $encoded, bool $pretty, string $base, string $indent, string $newline, string $colon): string
    {
        $key = array_shift($keys);
        if ($keys === []) {
            $value = $pretty
                ? '[' . $newline . $base . $indent . implode(',' . $newline . $base . $indent, $encoded) . $newline . $base . ']'
                : '[' . implode(',', $encoded) . ']';
        } else {
            $nested = $this->composerNewMember($keys, $encoded, $pretty, $base . $indent, $indent, $newline, $colon);
            $value = $pretty ? '{' . $newline . $base . $indent . $nested . $newline . $base . '}' : '{' . $nested . '}';
        }

        return json_encode($key, JSON_THROW_ON_ERROR) . $colon . $value;
    }

    private function unitRefusal(GenerationErrorCode $code, string $message, ?string $path = null): never
    {
        throw new GenerationRefusalException('generation', [new GenerationViolation($code, $message, $path)]);
    }

    /**
     * The change receipt for one terminated apply (ADR-025 D-14.7).
     *
     * Returns null for the two outcomes that terminate before controlled apply:
     * a preview yields its evaluation and nothing more, and an operator who
     * declines at confirmation does so before a byte is staged. Neither is a
     * `no_op`, which means apply ran and found the end state already satisfied.
     *
     * v1 emits the receipt and retains none. This method returns a value; it
     * opens no file, appends to no log, and writes no record anywhere.
     */
    public function receiptFor(
        ArtifactApplyResult $result,
        string $operation,
        ?string $correlationId = null,
        ?string $causationReceiptId = null,
        ?string $decisionReceiptId = null,
        ?\DateTimeImmutable $issuedAt = null,
    ): ?ChangeReceipt {
        $outcome = ChangeOutcome::forApplyOutcome($result->outcome);
        if ($outcome === null) {
            return null;
        }
        $payload = $result->toArray();
        unset($payload['schema'], $payload['version'], $payload['outcome'], $payload['plan_digest']);

        return new ChangeReceipt(
            $this->mintIdentifier('rcpt'),
            ChangeReceipt::GENERATION_AUTHORITY,
            self::CONTRACT_VERSION,
            $operation,
            $result->planDigest,
            $outcome,
            $correlationId ?? $this->mintIdentifier('corr'),
            $issuedAt ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            ['version' => 1] + $payload,
            $causationReceiptId,
            $decisionReceiptId,
        );
    }

    private function mintIdentifier(string $prefix): string
    {
        return $prefix . '-' . bin2hex(random_bytes(16));
    }

    /**
     * The captured precondition identity (ADR-025 D-6.2): the union of the
     * plan's artifact paths and every path recorded to a unit it supplies,
     * which is precisely the set evaluation reads.
     *
     * @param array<string, GeneratedArtifact> $artifacts
     * @param array<string, array<string, mixed>> $priorRows
     */
    private function captureProjectState(array $artifacts, array $priorRows, ?string $composerDigest = null, ?string $metadataDigest = null): ProjectStateIdentity
    {
        $paths = array_values(array_unique([...array_keys($artifacts), ...array_keys($priorRows)]));
        sort($paths, SORT_STRING);

        $targets = [];
        foreach ($paths as $path) {
            $targets[] = $this->observeTarget($path);
        }

        return new ProjectStateIdentity(
            $metadataDigest ?? $this->observeDocument(self::METADATA),
            $this->observeDocument('.waaseyaa/site.yaml'),
            $composerDigest ?? $this->observeDocument('composer.json'),
            $targets,
        );
    }

    private function observeTarget(string $path): ProjectStateTarget
    {
        $absolute = $this->absolute($path);
        if (is_link($absolute)) {
            return new ProjectStateTarget($path, ObservedTargetState::Other, ProjectStateIdentity::ABSENT_DIGEST, ObservedTargetMode::Other);
        }
        if (!file_exists($absolute)) {
            return new ProjectStateTarget($path, ObservedTargetState::Absent);
        }
        if (!is_file($absolute)) {
            return new ProjectStateTarget($path, ObservedTargetState::Other, ProjectStateIdentity::ABSENT_DIGEST, ObservedTargetMode::Other);
        }

        return new ProjectStateTarget($path, ObservedTargetState::File, $this->digestFile($absolute), $this->observeMode($absolute));
    }

    private function observeMode(string $absolute): ObservedTargetMode
    {
        if (!$this->platform->enforcesPermissionBits()) {
            return ObservedTargetMode::Unknown;
        }
        $bits = fileperms($absolute);

        return $bits === false
            ? ObservedTargetMode::Other
            : ObservedTargetMode::tryFrom(sprintf('%04o', $bits & 0o777)) ?? ObservedTargetMode::Other;
    }

    private function observeDocument(string $path): string
    {
        $absolute = $this->absolute($path);

        return is_file($absolute) ? $this->digestFile($absolute) : ProjectStateIdentity::ABSENT_DIGEST;
    }

    /**
     * Target evaluation, extracted from `prepare()` so that dry-run, apply and
     * plan evaluation enter one implementation rather than three (ADR-025 D-6.2,
     * and D-13's prohibition on a second collision, containment or
     * symlink-safety check).
     *
     * It stays physically below the prior-state admission block on purpose. The
     * managed-byte freeze must run BEFORE this loop: a row whose recorded digest
     * was corrupted satisfies both refusals at once, so only statement order
     * decides which message an operator sees, and that message is frozen.
     *
     * @param array<string, GeneratedArtifact> $artifacts
     * @param array<string, array<string, mixed>> $priorRows
     * @return array{prepared: array<string, GeneratedArtifact>, status: array<string, ArtifactStatus>}
     */
    private function evaluateTargets(array $artifacts, bool $hasMetadata, array $priorRows): array
    {
        $prepared = [];
        $status = [];
        foreach ($artifacts as $path => $artifact) {
            $this->assertSafeTarget($path);
            $absolute = $this->absolute($path);
            $existed = file_exists($absolute) || is_link($absolute);
            if ($existed) {
                $bootstrapControlIgnore = !$hasMetadata
                    && $path === '.waaseyaa/.gitignore'
                    && is_file($absolute)
                    && hash_equals(hash('sha256', $artifact->content), $this->digestFile($absolute));
                if (!$hasMetadata && !$bootstrapControlIgnore || $path === self::METADATA && !is_file($absolute)) {
                    throw new SiteInitializationCollisionException("Refusing to overwrite unowned artifact: {$path}");
                }
                $this->assertRegularOwnedFile($absolute, $path);
                $existing = (string) file_get_contents($absolute);
                if ($path !== self::METADATA && !$bootstrapControlIgnore) {
                    $row = $priorRows[$path] ?? null;
                    try {
                        $managedDigest = $artifact->managedDigest($existing);
                    } catch (\InvalidArgumentException $exception) {
                        throw new SiteInitializationCollisionException("Generated artifact has a damaged extension region: {$path}", previous: $exception);
                    }
                    if (!is_array($row) || !hash_equals($row['managed_sha256'], $managedDigest)) {
                        throw new SiteInitializationCollisionException("Generated artifact was edited outside an extension region: {$path}");
                    }
                    if (($row['extension_region'] ?? null) !== $artifact->extensionRegion) {
                        throw new SiteInitializationCollisionException("Generated extension ownership changed unexpectedly: {$path}");
                    }
                    try {
                        $artifact = $artifact->withExtensionFrom($existing);
                    } catch (\InvalidArgumentException $exception) {
                        throw new SiteInitializationCollisionException("Generated artifact has a damaged extension region: {$path}", previous: $exception);
                    }
                }
                if (hash_equals(hash('sha256', $existing), hash('sha256', $artifact->content)) && $this->modeMatches($absolute, $artifact->mode)) {
                    $status[$path] = ArtifactStatus::Unchanged;
                    continue;
                }
            }
            $status[$path] = $existed ? ArtifactStatus::Changed : ArtifactStatus::Created;
            $prepared[$path] = $artifact;
        }
        ksort($prepared, SORT_STRING);
        ksort($status, SORT_STRING);

        return ['prepared' => $prepared, 'status' => $status];
    }

    /**
     * @param array<string, GeneratedArtifact> $artifacts
     * @param array<string, array<string, mixed>> $retirements
     * @param array{content: string, mode: int, before_sha256: string}|null $composerMerge
     */
    private function publish(array $artifacts, array $retirements = [], ?array $composerMerge = null, ?\Closure $reportRecovery = null, ?ProjectStateIdentity $expectedState = null, ?\Closure $assertBeforeInstall = null, ?\Closure $reportResidue = null): bool
    {
        $transactionId = bin2hex(random_bytes(12));
        $stageRelative = '.waaseyaa/site-init-stage-' . $transactionId;
        $backupRelative = '.waaseyaa/site-init-backup-' . $transactionId;
        $stage = $this->absolute($stageRelative);
        $backup = $this->absolute($backupRelative);
        $stageCreated = false;
        $backupCreated = false;
        try {
            $this->makePrivateDirectory($stage, static function () use (&$stageCreated): void {
                $stageCreated = true;
            });
            $this->makePrivateDirectory($backup, static function () use (&$backupCreated): void {
                $backupCreated = true;
            });
        } catch (\Exception $exception) {
            if ($reportRecovery !== null) {
                // Never remove a path that mkdir did not create for this run,
                // including an identifier collision or a failed mkdir.
                $paths = [];
                if ($stageCreated) {
                    $paths[] = ['path' => $stageRelative, 'kind' => 'tree'];
                }
                if ($backupCreated) {
                    $paths[] = ['path' => $backupRelative, 'kind' => 'tree'];
                }
                usort($paths, static fn(array $left, array $right): int => strcmp($left['path'], $right['path']));
                if ($paths !== []) {
                    $instructions = ['kind' => 'control-residue', 'paths' => $paths];
                    try {
                        foreach ($paths as $path) {
                            $this->removeControlTree($this->absolute($path['path']));
                        }
                    } catch (\Exception $cleanupException) {
                        if ($reportResidue !== null) {
                            $reportResidue($instructions, false);
                        }
                        throw new \RuntimeException($exception->getMessage() . ' Staging cleanup failed: ' . $cleanupException->getMessage(), previous: $exception);
                    }
                    if ($reportResidue !== null) {
                        $reportResidue($instructions, true);
                    }
                }
            }
            throw $exception;
        }
        $draftJournal = [
            'schema' => 'waaseyaa.site-init-transaction', 'version' => 1,
            'id' => $transactionId, 'state' => 'prepared',
            'stage' => $stageRelative, 'backup' => $backupRelative,
            'created_directories' => [], 'items' => [],
        ];
        try {
            $publishOrder = array_keys($artifacts + $retirements);
            if ($composerMerge !== null) {
                $publishOrder[] = 'composer.json';
            }
            if ($retirements !== [] || $composerMerge !== null) {
                sort($publishOrder, SORT_STRING);
            }
            $publishOrder = array_values(array_filter($publishOrder, static fn(string $path): bool => $path !== self::METADATA));
            if (isset($artifacts[self::METADATA])) {
                $publishOrder[] = self::METADATA;
            }
            $items = [];
            foreach ($publishOrder as $index => $path) {
                $removing = isset($retirements[$path]);
                $merging = $path === 'composer.json' && $composerMerge !== null;
                $artifact = $artifacts[$path] ?? null;
                $mode = $merging ? $composerMerge['mode'] : ($removing ? intval($retirements[$path]['mode'], 8) : $artifact->mode);
                $stageFile = $stage . '/' . sprintf('%04d.artifact', $index);
                if (!$removing) {
                    $this->writeDurably($stageFile, $merging ? $composerMerge['content'] : $artifact->content, $mode);
                    $this->injectFault('after-stage', $index, $path);
                }
                if ($expectedState !== null) {
                    $this->assertPublicationObservation($path, $expectedState, $composerMerge);
                }
                $target = $this->absolute($path);
                if ($removing || $merging) {
                    $this->assertSafeTarget($path);
                    $this->assertRegularOwnedFile($target, $path);
                }
                $existed = is_file($target);
                $backupFile = null;
                $backupMode = null;
                if ($existed) {
                    $backupFile = $backup . '/' . sprintf('%04d.backup', $index);
                    // A host without permission bits has no observed mode to preserve, so the
                    // journal records the declared one and rollback stays reproducible.
                    $backupMode = $this->platform->enforcesPermissionBits() ? fileperms($target) & 0o777 : $mode;
                    $this->copyDurably($target, $backupFile, $backupMode);
                    $this->injectFault('after-backup', $index, $path);
                }
                $items[] = [
                    'path' => $path,
                    'stage' => $removing ? null : $this->relative($stageFile),
                    'installed_sha256' => $removing ? null : $this->digestFile($stageFile),
                    'backup' => $backupFile === null ? null : $this->relative($backupFile),
                    'backup_sha256' => $backupFile === null ? null : $this->digestFile($backupFile),
                    'backup_mode' => $backupMode,
                    'existed' => $existed,
                    'mode' => $mode,
                    'state' => 'pending',
                ];
                if ($removing || $merging) {
                    $items[array_key_last($items)]['kind'] = $merging ? 'composer-merge' : 'remove';
                }
            }
            $journal = [
                'schema' => 'waaseyaa.site-init-transaction',
                'version' => 1,
                'id' => $transactionId,
                'state' => 'prepared',
                'stage' => $stageRelative,
                'backup' => $backupRelative,
                'created_directories' => $this->missingTargetDirectories(array_keys($artifacts)),
                'items' => $items,
            ];
            if ($retirements !== []) {
                $journal['removed_directories'] = $this->retirementDirectories(array_keys($retirements));
            }
            if ($assertBeforeInstall !== null) {
                $assertBeforeInstall();
            }
            $this->writeJournal($journal);
        } catch (\Exception $exception) {
            if ($reportRecovery !== null) {
                // No target publication began. These validated empty-item
                // instructions can only clean this invocation's control trees.
                $this->validateJournal($draftJournal, true);
                try {
                    $this->rollback($draftJournal, true);
                } catch (\Exception $cleanupException) {
                    $reportRecovery($draftJournal, false);
                    throw new \RuntimeException($exception->getMessage() . ' Staging cleanup failed: ' . $cleanupException->getMessage(), previous: $exception);
                }
                $reportRecovery($draftJournal, true);
            }
            throw $exception;
        }

        try {
            foreach ($journal['items'] as $index => &$item) {
                $item['state'] = 'installing';
                $this->writeJournal($journal);
                if (($item['kind'] ?? null) === 'remove') {
                    $this->injectFault('before-remove', $index, $item['path']);
                    if ($expectedState !== null) {
                        $this->assertPublicationPriorTuple($item);
                    }
                    $target = $this->absolute($item['path']);
                    $this->assertSafeTarget($item['path']);
                    $this->assertRegularOwnedFile($target, $item['path']);
                    if (!hash_equals($item['backup_sha256'], $this->digestFile($target))) {
                        throw new SiteInitializationCollisionException("Cannot retire a changed generated target: {$item['path']}");
                    }
                    if (!unlink($target)) {
                        throw new \RuntimeException("Unable to retire {$item['path']}.");
                    }
                    $this->syncDirectory(dirname($target));
                    $this->injectFault('after-remove', $index, $item['path']);
                    $item['state'] = 'applied';
                    $this->writeJournal($journal);
                    continue;
                }
                $this->injectFault('before-replace', $index, $item['path']);
                if ($expectedState !== null) {
                    $this->assertPublicationPriorTuple($item);
                    if ($item['path'] === self::METADATA) {
                        $this->assertPublicationProgress($journal, $expectedState);
                    }
                }
                $target = $this->absolute($item['path']);
                $this->ensureTargetDirectory(dirname($target));
                if (!rename($this->absolute($item['stage']), $target)) {
                    throw new \RuntimeException("Unable to atomically install {$item['path']}.");
                }
                if ($this->platform->enforcesPermissionBits() && !chmod($target, $item['mode'])) {
                    throw new \RuntimeException("Unable to set mode on {$item['path']}.");
                }
                $this->syncFile($target);
                $this->syncDirectory(dirname($target));
                $this->injectFault('after-replace', $index, $item['path']);
                $item['state'] = 'applied';
                $this->writeJournal($journal);
            }
            unset($item);
            if (isset($journal['removed_directories'])) {
                foreach ($journal['removed_directories'] as $index => &$directory) {
                    $absolute = $this->absolute($directory['path']);
                    $this->assertSafeTarget($directory['path'] . '/placeholder', true);
                    if (!is_dir($absolute) || is_link($absolute) || !$this->directoryIsEmpty($absolute)) {
                        continue;
                    }
                    $directory['state'] = 'removing';
                    $this->writeJournal($journal);
                    $this->injectFault('before-remove-directory', (int) $index, $directory['path']);
                    if (!rmdir($absolute)) {
                        throw new \RuntimeException("Unable to remove retired directory {$directory['path']}.");
                    }
                    $this->syncDirectory(dirname($absolute));
                    $this->injectFault('after-remove-directory', (int) $index, $directory['path']);
                    $directory['state'] = 'applied';
                    $this->writeJournal($journal);
                }
                unset($directory);
            }
            if ($expectedState !== null) {
                $this->assertPublicationProgress($journal, $expectedState);
            }
            $journal['state'] = 'committed';
            $this->writeJournal($journal);
        } catch (\Exception $exception) {
            unset($item);
            try {
                $this->rollback($journal, $reportRecovery !== null || $retirements !== [] || $composerMerge !== null);
            } catch (\Exception $rollbackException) {
                if ($reportRecovery !== null) {
                    $reportRecovery($journal, false);
                    throw new \RuntimeException($exception->getMessage() . ' Rollback failed: ' . $rollbackException->getMessage(), previous: $exception);
                }
                throw $rollbackException;
            }
            if ($reportRecovery !== null) {
                $reportRecovery($journal, true);
            }
            throw $exception;
        }
        try {
            $this->injectFault('after-commit', -1, '');
            $this->cleanupTransaction($journal);
        } catch (\Exception) {
            return true;
        }

        return false;
    }

    private function recoverIfRequired(bool $unitAware = false, ?\Closure $reportRecovery = null, ?\Closure $reportResidue = null): bool
    {
        $path = $this->absolute(self::JOURNAL);
        if (!is_file($path)) {
            return $this->cleanupOrphanControlResidue($reportResidue);
        }
        $this->assertRegularOwnedFile($path, self::JOURNAL);
        try {
            $journal = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('The interrupted site initialization journal is invalid JSON.', previous: $exception);
        }
        if (!is_array($journal)) {
            throw new \RuntimeException('The interrupted site initialization journal is invalid.');
        }
        $this->validateJournal($journal, $unitAware);
        try {
            if ($journal['state'] === 'committed') {
                $this->cleanupTransaction($journal);
            } elseif ($unitAware && $this->hasMissingRollbackBackup($journal)) {
                // Cleanup can be interrupted after backups disappear but before
                // the prepared journal is unlinked. Only a complete proof of the
                // prior state permits finishing that cleanup without those backups.
                $this->assertFullyRestoredTransaction($journal);
                $this->cleanupTransaction($journal);
            } else {
                $this->rollback($journal, $unitAware);
            }

        } catch (\Exception $exception) {
            if ($reportRecovery !== null) {
                $reportRecovery($journal, false);
            }
            throw $exception;
        }
        if ($reportRecovery !== null) {
            $reportRecovery($journal, true);
        }
        $this->cleanupOrphanControlResidue($reportResidue);

        return true;
    }

    /** @param array{content: string, mode: int, before_sha256: string}|null $composerMerge */
    private function assertPublicationObservation(string $path, ProjectStateIdentity $state, ?array $composerMerge): void
    {
        $this->assertSafeTarget($path, true);
        if ($path === self::METADATA || $path === 'composer.json') {
            $expected = $path === self::METADATA ? $state->generatedMetadataSha256 : $state->composerJsonSha256;
            $absolute = $this->absolute($path);
            if (file_exists($absolute) || is_link($absolute)) {
                $this->assertRegularOwnedFile($absolute, $path);
            }
            if (!hash_equals($expected, $this->observeDocument($path))
                || ($path === 'composer.json' && $composerMerge !== null && !$this->modeMatches($absolute, $composerMerge['mode']))) {
                $this->unitRefusal(GenerationErrorCode::StalePlan, 'A publication target changed after evaluation.', $path);
            }
            return;
        }
        foreach ($state->targets as $target) {
            if ($target->path === $path) {
                if ($target->toArray() !== $this->observeTarget($path)->toArray()) {
                    $this->unitRefusal(GenerationErrorCode::StalePlan, 'A publication target changed after evaluation.', $path);
                }
                return;
            }
        }
        $this->unitRefusal(GenerationErrorCode::StalePlan, 'A publication target was not captured in the reviewed state.', $path);
    }

    /** @param array<string, mixed> $journal */
    private function assertPublicationProgress(array $journal, ProjectStateIdentity $reviewed): void
    {
        $installed = [];
        foreach ($journal['items'] as $item) {
            if ($item['state'] === 'applied') {
                $installed[$item['path']] = $item;
            }
        }
        foreach ($reviewed->targets as $target) {
            $this->assertSafeTarget($target->path, true);
            if (isset($installed[$target->path])) {
                $this->assertInstalledTuple($installed[$target->path]);
                continue;
            }
            $absolute = $this->absolute($target->path);
            if (is_file($absolute)) {
                $this->assertRegularOwnedFile($absolute, $target->path);
            }
            if ($target->toArray() !== $this->observeTarget($target->path)->toArray()) {
                $this->unitRefusal(GenerationErrorCode::StalePlan, 'An unchanged reviewed target moved during publication.', $target->path);
            }
        }
        foreach ([
            self::METADATA => $reviewed->generatedMetadataSha256,
            '.waaseyaa/site.yaml' => $reviewed->manifestSha256,
            'composer.json' => $reviewed->composerJsonSha256,
        ] as $path => $digest) {
            $this->assertSafeTarget($path, true);
            if (isset($installed[$path])) {
                $this->assertInstalledTuple($installed[$path]);
                continue;
            }
            $absolute = $this->absolute($path);
            if (file_exists($absolute) || is_link($absolute)) {
                $this->assertRegularOwnedFile($absolute, $path);
            }
            if (!hash_equals($digest, $this->observeDocument($path))) {
                $this->unitRefusal(GenerationErrorCode::StalePlan, 'A reviewed document moved during publication.', $path);
            }
        }
    }

    /** @param array<string, mixed> $item */
    private function assertInstalledTuple(array $item): void
    {
        $absolute = $this->absolute($item['path']);
        if (($item['kind'] ?? null) === 'remove') {
            if (file_exists($absolute) || is_link($absolute)) {
                $this->unitRefusal(GenerationErrorCode::StalePlan, 'A retired target reappeared during publication.', $item['path']);
            }
            return;
        }
        $this->assertRegularOwnedFile($absolute, $item['path']);
        if (!hash_equals($item['installed_sha256'], $this->digestFile($absolute)) || !$this->modeMatches($absolute, $item['mode'])) {
            $this->unitRefusal(GenerationErrorCode::StalePlan, 'An installed target moved during publication.', $item['path']);
        }
    }

    /** @param array<string, mixed> $item */
    private function assertPublicationPriorTuple(array $item): void
    {
        $this->assertSafeTarget($item['path'], true);
        $absolute = $this->absolute($item['path']);
        $present = file_exists($absolute) || is_link($absolute);
        if ($item['existed'] === false) {
            if ($present) {
                $this->unitRefusal(GenerationErrorCode::CollisionRefused, 'Refusing to overwrite a newly present unowned artifact.', $item['path']);
            }
            return;
        }
        $this->assertRegularOwnedFile($absolute, $item['path']);
        if (!hash_equals($item['backup_sha256'], $this->digestFile($absolute)) || !$this->modeMatches($absolute, $item['backup_mode'])) {
            $this->unitRefusal(GenerationErrorCode::StalePlan, 'A publication target changed before replacement.', $item['path']);
        }
    }

    /** @param array<string, mixed> $journal */
    private function rollback(array $journal, bool $unitAware = false): void
    {
        if ($unitAware) {
            // Prove every removal before restoring any item or deleting its
            // recovery evidence. Already-restored exact tuples are valid after
            // an interruption; merely matching bytes are not enough.
            $this->validateRetirementRecoveryState($journal);
            $this->validateComposerMergeRecoveryState($journal);
            foreach (array_reverse($journal['removed_directories'] ?? []) as $directory) {
                if ($directory['state'] === 'pending') {
                    continue;
                }
                $this->assertSafeTarget($directory['path'] . '/placeholder', true);
                $absolute = $this->absolute($directory['path']);
                if (is_link($absolute) || (file_exists($absolute) && !is_dir($absolute))) {
                    throw new SiteInitializationCollisionException("Cannot recover a changed target directory: {$directory['path']}");
                }
                if (!is_dir($absolute)) {
                    if (!mkdir($absolute, $directory['mode'])) {
                        throw new \RuntimeException("Cannot restore target directory {$directory['path']}.");
                    }
                    if ($this->platform->enforcesPermissionBits() && !chmod($absolute, $directory['mode'])) {
                        throw new \RuntimeException("Cannot restore target directory mode {$directory['path']}.");
                    }
                    $this->syncDirectory(dirname($absolute));
                    $this->injectFault('after-rollback-directory', -1, $directory['path']);
                } elseif ($this->platform->enforcesPermissionBits() && (fileperms($absolute) & 0o777) !== $directory['mode']) {
                    throw new SiteInitializationCollisionException("Cannot recover a changed target directory mode: {$directory['path']}");
                }
            }
        }
        foreach (array_reverse($journal['items'], true) as $index => $item) {
            if (!in_array($item['state'], ['installing', 'applied'], true)) {
                continue;
            }
            $target = $this->absolute($item['path']);
            if ($item['existed'] === true) {
                $backup = $this->absolute($item['backup']);
                if (!is_file($backup) || is_link($backup)) {
                    throw new \RuntimeException("Cannot recover {$item['path']}: its backup is missing.");
                }
                $this->assertRegularOwnedFile($backup, $item['backup']);
                if (!hash_equals($item['backup_sha256'], $this->digestFile($backup))) {
                    throw new \RuntimeException("Cannot recover {$item['path']}: its backup was substituted.");
                }
                if ($unitAware && ($item['kind'] ?? null) === 'remove' && !file_exists($target) && !is_link($target)) {
                    $this->assertSafeTarget($item['path']);
                    $this->assertPathWithinRoot(dirname($target));
                    $temp = dirname($backup) . '/restore-' . sprintf('%04d', $index) . '-' . bin2hex(random_bytes(6));
                    $this->copyDurably($backup, $temp, $item['backup_mode']);
                    $this->injectFault('after-rollback-copy', (int) $index, $item['path']);
                    if (!rename($temp, $target)) {
                        @unlink($temp);
                        throw new \RuntimeException("Cannot restore {$item['path']}.");
                    }
                    $this->syncDirectory(dirname($target));
                    $this->injectFault('after-rollback-restore', (int) $index, $item['path']);
                    continue;
                }
                if (!is_file($target) || is_link($target)) {
                    throw new SiteInitializationCollisionException("Cannot recover a changed generated target: {$item['path']}");
                }
                $this->assertRegularOwnedFile($target, $item['path']);
                $currentDigest = $this->digestFile($target);
                if (hash_equals($item['backup_sha256'], $currentDigest)) {
                    if (!$this->modeMatches($target, $item['backup_mode']) && !chmod($target, $item['backup_mode'])) {
                        throw new \RuntimeException("Cannot restore the mode of {$item['path']}.");
                    }
                    $this->syncFile($target);
                    continue;
                }
                if (($unitAware && ($item['kind'] ?? null) === 'remove') || !hash_equals($item['installed_sha256'], $currentDigest)) {
                    throw new SiteInitializationCollisionException("Cannot recover a substituted generated target: {$item['path']}");
                }
                $temp = dirname($backup) . '/restore-' . sprintf('%04d', $index) . '-' . bin2hex(random_bytes(6));
                $this->copyDurably($backup, $temp, $item['backup_mode']);
                $this->injectFault('after-rollback-copy', (int) $index, $item['path']);
                if (!rename($temp, $target)) {
                    @unlink($temp);
                    throw new \RuntimeException("Cannot restore {$item['path']}.");
                }
                $this->syncDirectory(dirname($target));
            } elseif (file_exists($target) || is_link($target)) {
                $this->assertRegularOwnedFile($target, $item['path']);
                if (!hash_equals($item['installed_sha256'], $this->digestFile($target))) {
                    throw new SiteInitializationCollisionException("Cannot recover a substituted generated target: {$item['path']}");
                }
                if (!unlink($target)) {
                    throw new \RuntimeException("Cannot remove interrupted artifact {$item['path']}.");
                }
                $this->syncDirectory(dirname($target));
            }
        }
        foreach (array_reverse($journal['created_directories']) as $relative) {
            $directory = $this->absolute($relative);
            if (is_dir($directory) && $this->directoryIsEmpty($directory)) {
                if (!rmdir($directory)) {
                    throw new \RuntimeException("Cannot remove interrupted target directory {$relative}.");
                }
                $this->syncDirectory(dirname($directory));
            }
        }
        if ($unitAware) {
            $this->injectFault('before-rollback-cleanup', -1, '');
        }
        $this->cleanupTransaction($journal);
    }

    /** @param array<string, mixed> $journal */
    private function hasMissingRollbackBackup(array $journal): bool
    {
        foreach ($journal['items'] as $item) {
            if ($item['existed'] === true && !is_file($this->absolute($item['backup']))) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $journal */
    private function assertFullyRestoredTransaction(array $journal): void
    {
        foreach ($journal['items'] as $item) {
            $this->assertSafeTarget($item['path'], true);
            $absolute = $this->absolute($item['path']);
            $present = file_exists($absolute) || is_link($absolute);
            if ($item['existed'] === false) {
                if ($present) {
                    throw new SiteInitializationCollisionException("Cannot finish recovery cleanup with a newly present target: {$item['path']}");
                }
                continue;
            }
            if (!$present) {
                throw new SiteInitializationCollisionException("Cannot finish recovery cleanup with a missing prior target: {$item['path']}");
            }
            $this->assertRegularOwnedFile($absolute, $item['path']);
            if (!hash_equals($item['backup_sha256'], $this->digestFile($absolute)) || !$this->modeMatches($absolute, $item['backup_mode'])) {
                throw new SiteInitializationCollisionException("Cannot finish recovery cleanup with a changed prior target: {$item['path']}");
            }
        }
        foreach ($journal['created_directories'] as $relative) {
            $this->assertSafeTarget($relative . '/placeholder', true);
            if (file_exists($this->absolute($relative)) || is_link($this->absolute($relative))) {
                throw new SiteInitializationCollisionException("Cannot finish recovery cleanup with a newly present directory: {$relative}");
            }
        }
        foreach ($journal['removed_directories'] ?? [] as $directory) {
            $this->assertSafeTarget($directory['path'] . '/placeholder', true);
            $absolute = $this->absolute($directory['path']);
            if (!is_dir($absolute) || is_link($absolute) || !$this->modeMatches($absolute, $directory['mode'])) {
                throw new SiteInitializationCollisionException("Cannot finish recovery cleanup with an unrestored prior directory: {$directory['path']}");
            }
        }
        $this->validateRetirementRecoveryState($journal);
    }

    /** @param array<string, mixed> $journal */
    private function validateComposerMergeRecoveryState(array $journal): void
    {
        foreach ($journal['items'] as $item) {
            if (($item['kind'] ?? null) !== 'composer-merge') {
                continue;
            }
            // A pending merge has not touched the manifest. Later states may
            // hold either the installed tuple or an exact prior restoration.
            // Prove all tuples before any rollback item mutates the project.
            $this->assertSafeTarget($item['backup'], true);
            $backup = $this->absolute($item['backup']);
            $this->assertRegularOwnedFile($backup, $item['backup']);
            if (!hash_equals($item['backup_sha256'], $this->digestFile($backup)) || !$this->modeMatches($backup, $item['backup_mode'])) {
                throw new SiteInitializationCollisionException('Cannot recover composer.json: its backup was substituted.');
            }
            $this->assertSafeTarget($item['path'], true);
            $target = $this->absolute($item['path']);
            $this->assertRegularOwnedFile($target, $item['path']);
            $digest = $this->digestFile($target);
            $prior = hash_equals($item['backup_sha256'], $digest) && $this->modeMatches($target, $item['backup_mode']);
            $installed = $item['state'] !== 'pending'
                && hash_equals($item['installed_sha256'], $digest)
                && $this->modeMatches($target, $item['mode']);
            if (!$prior && !$installed) {
                throw new SiteInitializationCollisionException('Cannot recover a changed Composer manifest.');
            }
        }
    }

    /** @param array<string, mixed> $journal */
    private function validateRetirementRecoveryState(array $journal): void
    {
        $removals = [];
        foreach ($journal['items'] as $item) {
            if (($item['kind'] ?? null) !== 'remove') {
                continue;
            }
            $removals[$item['path']] = $item;
            $this->assertUnitOwnershipPath($item['path']);
            $target = $this->absolute($item['path']);
            $present = file_exists($target) || is_link($target);
            if (!$present) {
                if ($item['state'] === 'pending') {
                    throw new SiteInitializationCollisionException("Cannot recover a missing pending retirement target: {$item['path']}");
                }
                continue;
            }
            $this->assertRegularOwnedFile($target, $item['path']);
            if (!hash_equals($item['backup_sha256'], $this->digestFile($target)) || !$this->modeMatches($target, $item['backup_mode'])) {
                throw new SiteInitializationCollisionException("Cannot recover a changed retirement target: {$item['path']}");
            }
        }
        $directories = [];
        foreach ($journal['removed_directories'] ?? [] as $directory) {
            $directories[$directory['path']] = $directory;
        }
        foreach ($directories as $directory) {
            if ($directory['state'] === 'pending') {
                continue;
            }
            $this->assertSafeTarget($directory['path'] . '/placeholder', true);
            $absolute = $this->absolute($directory['path']);
            if (!file_exists($absolute) && !is_link($absolute)) {
                continue;
            }
            if (!is_dir($absolute) || is_link($absolute) || !$this->modeMatches($absolute, $directory['mode'])) {
                throw new SiteInitializationCollisionException("Cannot recover a changed retirement directory: {$directory['path']}");
            }
            $entries = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST,
            );
            foreach ($entries as $entry) {
                $relative = $this->relative($entry->getPathname());
                $this->assertSafeTarget($relative, true);
                if ($entry->isLink()) {
                    throw new SiteInitializationCollisionException("Cannot recover a linked retirement directory entry: {$relative}");
                }
                if ($entry->isDir()) {
                    if (!isset($directories[$relative]) || !$this->modeMatches($entry->getPathname(), $directories[$relative]['mode'])) {
                        throw new SiteInitializationCollisionException("Cannot recover an unknown retirement directory entry: {$relative}");
                    }
                } elseif (!isset($removals[$relative])) {
                    throw new SiteInitializationCollisionException("Cannot recover an unknown retirement directory entry: {$relative}");
                }
                // Every recognized file was proven against its original
                // digest, private-file identity and mode in the first loop.
            }
        }
    }

    /** @param array<string, mixed> $journal */
    private function cleanupTransaction(array $journal): void
    {
        $this->removeControlTree($this->absolute($journal['stage']));
        $this->removeControlTree($this->absolute($journal['backup']));
        $journalPath = $this->absolute(self::JOURNAL);
        if (is_file($journalPath)) {
            if (!unlink($journalPath)) {
                throw new \RuntimeException('Unable to remove the completed site initialization journal.');
            }
            $this->syncDirectory(dirname($journalPath));
        }
    }

    /**
     * @return array<string, mixed>
     * @param-out string $observedDigest
     */
    private function readMetadata(string $path, bool $unitAware = false, ?string &$observedDigest = null): array
    {
        $raw = (string) file_get_contents($path);
        $observedDigest = hash('sha256', $raw);
        try {
            $metadata = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new SiteInitializationCollisionException('Generated ownership metadata is invalid.', previous: $exception);
        }
        if (!is_array($metadata)) {
            throw new SiteInitializationCollisionException('Generated ownership metadata has an unsupported shape.');
        }
        if ($unitAware && (!is_string($metadata['manifest_digest'] ?? null))) {
            $this->unitRefusal(GenerationErrorCode::UnitPathConflict, 'The root digest must be a string.');
        }
        $metadataKeys = array_keys($metadata);
        sort($metadataKeys, SORT_STRING);
        $allowedMetadataKeys = ['artifacts', 'generator_version', 'manifest_digest', 'schema', 'version'];
        if ($unitAware) {
            foreach (['units', 'registrations', 'application_blueprint'] as $member) {
                if (array_key_exists($member, $metadata)) {
                    $allowedMetadataKeys[] = $member;
                }
            }
            sort($allowedMetadataKeys, SORT_STRING);
            if (array_key_exists('registrations', $metadata)) {
                $this->validateRegistrationRosterShape($metadata['registrations']);
            }
            if (array_key_exists('application_blueprint', $metadata)) {
                if (!is_array($metadata['application_blueprint'])) {
                    throw new SiteInitializationCollisionException('Generated ownership metadata contains invalid blueprint evidence.');
                }
                try {
                    BlueprintAppliedEvidence::fromArray($metadata['application_blueprint'], '.waaseyaa/generated.json');
                } catch (SiteManifestValidationException $exception) {
                    throw new SiteInitializationCollisionException('Generated ownership metadata contains invalid blueprint evidence.', previous: $exception);
                }
            }
        }
        if ($metadataKeys !== $allowedMetadataKeys
            || ($metadata['schema'] ?? null) !== 'waaseyaa.generated'
            || ($metadata['version'] ?? null) !== 1
            || !is_int($metadata['generator_version'] ?? null)
            || $metadata['generator_version'] < 1
            || preg_match('/^[a-f0-9]{64}$/D', $metadata['manifest_digest'] ?? '') !== 1
            || !is_array($metadata['artifacts'] ?? null)
            || !hash_equals(CanonicalJson::encode($metadata) . "\n", $raw)) {
            throw new SiteInitializationCollisionException('Generated ownership metadata has an unsupported shape.');
        }
        $unitIds = [];
        if ($unitAware) {
            if (!array_is_list($metadata['artifacts'])) {
                $this->unitRefusal(GenerationErrorCode::UnitPathConflict, 'Artifact records must be a list.');
            }
            $unitRows = $metadata['units'] ?? [];
            if (!is_array($unitRows) || !array_is_list($unitRows) || (array_key_exists('units', $metadata) && $unitRows === [])) {
                $this->unitRefusal(GenerationErrorCode::UnitPathConflict, 'The unit roster must be a nonempty list when present.');
            }
            $previousId = null;
            foreach ($unitRows as $unit) {
                if (!is_array($unit)) {
                    $this->unitRefusal(GenerationErrorCode::UnitPathConflict, 'A unit record must be an object.');
                }
                $keys = array_keys($unit);
                sort($keys, SORT_STRING);
                $generator = $unit['generator'] ?? null;
                $generatorKeys = is_array($generator) ? array_keys($generator) : [];
                sort($generatorKeys, SORT_STRING);
                if ($keys !== ['disposition', 'generator', 'id', 'input_digest']
                    || !is_string($unit['id']) || !is_string($unit['input_digest'])
                    || !in_array($unit['disposition'], ['managed', 'seeded'], true)
                    || $generatorKeys !== ['fqcn', 'version']
                    || !is_string($generator['fqcn']) || $generator['fqcn'] === ''
                    || !is_int($generator['version']) || $generator['version'] < 1
                    || preg_match('/^[a-f0-9]{64}$/D', $unit['input_digest']) !== 1) {
                    $this->unitRefusal(GenerationErrorCode::UnitPathConflict, 'The unit record has an unsupported shape.');
                }
                $id = $unit['id'];
                if (strlen($id) > 128 || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*(?::[a-z0-9]+(?:-[a-z0-9]+)*)*$/D', $id) !== 1) {
                    $this->unitRefusal(GenerationErrorCode::MaliciousIdentifier, 'The unit id is invalid.');
                }
                if ($id === 'site' || ($previousId !== null && strcmp($previousId, $id) >= 0)) {
                    $this->unitRefusal(GenerationErrorCode::UnitPathConflict, 'Unit ids must be unique, non-root and sorted.');
                }
                $previousId = $id;
                $unitIds[$id] = true;
            }
        }
        if ($unitAware && array_key_exists('registrations', $metadata)) {
            $this->validateRegistrationRosterOwnership($metadata['registrations'], $unitIds);
        }
        $paths = [];
        foreach ($metadata['artifacts'] as $row) {
            if ($unitAware && (!is_array($row) || !is_string($row['path'] ?? null) || !is_string($row['managed_sha256'] ?? null) || !is_string($row['mode'] ?? null)
                || (array_key_exists('extension_region', $row) && !is_string($row['extension_region'])))) {
                $this->unitRefusal(GenerationErrorCode::UnitPathConflict, 'An artifact record has invalid member types.');
            }
            if ($unitAware && is_array($row) && is_string($row['path'] ?? null) && isset($paths[$row['path']])) {
                $this->unitRefusal(GenerationErrorCode::UnitPathConflict, 'A path is owned more than once.', $row['path']);
            }
            if (!is_array($row) || !is_string($row['path'] ?? null) || isset($paths[$row['path']]) || preg_match('/^[a-f0-9]{64}$/D', $row['managed_sha256'] ?? '') !== 1) {
                throw new SiteInitializationCollisionException('Generated ownership metadata contains an invalid artifact record.');
            }
            $allowed = isset($row['extension_region'])
                ? ['extension_region', 'managed_sha256', 'mode', 'path']
                : ['managed_sha256', 'mode', 'path'];
            if ($unitAware && array_key_exists('unit', $row)) {
                if (!is_string($row['unit']) || !isset($unitIds[$row['unit']])) {
                    $this->unitRefusal(GenerationErrorCode::UnitPathConflict, 'An artifact names an unknown unit.', $row['path']);
                }
                $allowed[] = 'unit';
                sort($allowed, SORT_STRING);
            }
            $keys = array_keys($row);
            sort($keys, SORT_STRING);
            if ($keys !== $allowed || preg_match('/^0(?:644|755)$/D', $row['mode'] ?? '') !== 1) {
                throw new SiteInitializationCollisionException('Generated ownership metadata contains an unsupported artifact record.');
            }
            if ($unitAware) {
                $this->assertUnitOwnershipPath($row['path']);
                $absolute = $this->absolute($row['path']);
                if (file_exists($absolute) || is_link($absolute)) {
                    $this->assertRegularOwnedFile($absolute, $row['path']);
                }
            }
            $paths[$row['path']] = true;
        }
        $sortedPaths = array_keys($paths);
        $recordedPaths = $sortedPaths;
        sort($sortedPaths, SORT_STRING);
        if ($recordedPaths !== $sortedPaths) {
            throw new SiteInitializationCollisionException('Generated ownership metadata artifact records are not canonical.');
        }

        return $metadata;
    }

    /** @param array<string, mixed> $journal */
    private function writeJournal(array $journal): void
    {
        $this->writeAtomically($this->absolute(self::JOURNAL), json_encode($journal, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", 0o600);
    }

    private function writeAtomically(string $target, string $content, int $mode): void
    {
        $temp = $target . '.tmp-' . bin2hex(random_bytes(6));
        $this->writeDurably($temp, $content, $mode);
        if (!rename($temp, $target)) {
            @unlink($temp);
            throw new \RuntimeException("Unable to publish control file {$target}.");
        }
        $this->syncDirectory(dirname($target));
    }

    private function writeDurably(string $path, string $content, int $mode): void
    {
        $handle = fopen($path, 'x+b');
        if ($handle === false) {
            throw new \RuntimeException("Unable to create {$path} exclusively.");
        }
        try {
            $written = fwrite($handle, $content);
            if ($written !== strlen($content) || !fflush($handle)) {
                throw new \RuntimeException("Unable to durably write {$path}.");
            }
            if ($this->platform->enforcesPermissionBits() && !chmod($path, $mode)) {
                throw new \RuntimeException("Unable to set mode on {$path}.");
            }
            if (!fsync($handle)) {
                throw new \RuntimeException("Unable to durably write {$path}.");
            }
        } finally {
            fclose($handle);
        }
        $this->syncDirectory(dirname($path));
    }

    private function copyDurably(string $source, string $target, int $mode): void
    {
        $content = file_get_contents($source);
        if ($content === false) {
            throw new \RuntimeException("Unable to read {$source} for recovery.");
        }
        $this->writeDurably($target, $content, $mode);
    }

    private function digestFile(string $path): string
    {
        $digest = hash_file('sha256', $path);
        if ($digest === false) {
            throw new \RuntimeException("Unable to digest {$path}.");
        }

        return $digest;
    }

    private function syncFile(string $path): void
    {
        if (!$this->platform->synchronizesDirectories()) {
            return;
        }
        $handle = fopen($path, 'rb');
        if ($handle === false || !fsync($handle)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new \RuntimeException("Unable to sync {$path}.");
        }
        fclose($handle);
    }

    private function syncDirectory(string $directory): void
    {
        if (!$this->platform->synchronizesDirectories()) {
            return;
        }
        $handle = fopen($directory, 'rb');
        if ($handle === false || !fsync($handle)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new \RuntimeException("Unable to sync directory {$directory}.");
        }
        fclose($handle);
    }

    private function makePrivateDirectory(string $directory, ?\Closure $created = null): void
    {
        if (!mkdir($directory, 0o700) || is_link($directory)) {
            throw new \RuntimeException("Unable to create transaction directory {$directory}.");
        }
        if ($created !== null) {
            $created();
        }
        $this->syncDirectory(dirname($directory));
    }

    private function ensureTargetDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0o755, true) && !is_dir($directory)) {
            throw new \RuntimeException("Unable to create target directory {$directory}.");
        }
        $this->assertPathWithinRoot($directory);
    }

    /** @param list<string> $paths @return list<string> */
    private function missingTargetDirectories(array $paths): array
    {
        $directories = [];
        foreach ($paths as $path) {
            $relative = dirname($path);
            while ($relative !== '.' && $relative !== '.waaseyaa') {
                if (!is_dir($this->absolute($relative))) {
                    $directories[$relative] = substr_count($relative, '/');
                }
                $relative = dirname($relative);
            }
        }
        uasort($directories, static fn(int $left, int $right): int => $left <=> $right);

        return array_keys($directories);
    }

    /** @param list<string> $paths @return list<array{path: string, mode: int, state: string}> */
    private function retirementDirectories(array $paths): array
    {
        $directories = [];
        foreach ($paths as $path) {
            $relative = dirname($path);
            while ($relative !== '.' && $relative !== '.waaseyaa') {
                $this->assertSafeTarget($relative . '/placeholder');
                $absolute = $this->absolute($relative);
                if (is_dir($absolute)) {
                    $directories[$relative] = [
                        'path' => $relative,
                        'mode' => $this->platform->enforcesPermissionBits() ? fileperms($absolute) & 0o777 : 0o755,
                        'state' => 'pending',
                    ];
                }
                $relative = dirname($relative);
            }
        }
        uksort($directories, static function (string $left, string $right): int {
            $depth = substr_count($right, '/') <=> substr_count($left, '/');

            return $depth !== 0 ? $depth : strcmp($left, $right);
        });

        return array_values($directories);
    }

    private function assertUnitOwnershipPath(string $path): void
    {
        $this->assertSafeTarget($path, true);
        if ($path === 'composer.json' || $path === self::METADATA || $path === self::LOCK || $path === self::JOURNAL
            || str_starts_with($path, '.waaseyaa/site-init-')
            || str_starts_with($path, self::JOURNAL . '.')) {
            $this->unitRefusal(GenerationErrorCode::CollisionRefused, 'Transaction control state cannot be owned by a generation unit.', $path);
        }
    }

    private function assertSafeTarget(string $relative, bool $canonical = false): void
    {
        if ($canonical && (in_array('', explode('/', $relative), true) || in_array('.', explode('/', $relative), true))) {
            $this->unitRefusal(GenerationErrorCode::UnsafePath, 'Unit-owned paths must have canonical nonempty segments.', $relative);
        }
        if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '\\') || str_contains("/{$relative}/", '/../') || str_contains($relative, "\0")) {
            throw new SiteInitializationCollisionException("Unsafe generated target: {$relative}");
        }
        $cursor = $this->projectRoot;
        foreach (explode('/', dirname($relative)) as $segment) {
            if ($segment === '.') {
                continue;
            }
            $cursor .= '/' . $segment;
            if (is_link($cursor)) {
                throw new SiteInitializationCollisionException("Generated target traverses a symbolic link: {$relative}");
            }
        }
    }

    private function assertRegularOwnedFile(string $path, string $relative): void
    {
        $stat = lstat($path);
        // The hard-link count is a POSIX guarantee; on Windows it is not a
        // portable signal, so enforcing it there would refuse ordinary files.
        // The symlink and regular-file clauses stay unconditional (#2644).
        $aliased = $this->platform->enforcesHardLinkCounts() && ($stat === false || $stat['nlink'] !== 1);
        if ($stat === false || !is_file($path) || is_link($path) || $aliased) {
            throw new SiteInitializationCollisionException("Generated target is not a private regular file: {$relative}");
        }
    }

    /**
     * Whether an existing artifact already carries its declared mode.
     *
     * On a host without POSIX permission bits there is nothing to compare, so
     * the mode half of the unchanged-artifact test is vacuously satisfied.
     * Comparing anyway meant no artifact ever matched and `site:init` rewrote
     * the entire generated set on every run (#2644).
     */
    private function modeMatches(string $absolute, int $mode): bool
    {
        if (!$this->platform->enforcesPermissionBits()) {
            return true;
        }

        return (fileperms($absolute) & 0o777) === $mode;
    }

    private function assertPathWithinRoot(string $path): void
    {
        $resolved = realpath($path);
        // #2644: realpath() returns backslash-separated paths on Windows, so a
        // separator-naive prefix test rejected every legitimate target there.
        if ($resolved === false || !SitePathContainment::contains($this->projectRoot, $resolved)) {
            throw new SiteInitializationCollisionException('Generated target escaped the project root.');
        }
    }

    private function absolute(string $relative): string
    {
        return $this->projectRoot . '/' . $relative;
    }

    private function relative(string $absolute): string
    {
        return substr($absolute, strlen($this->projectRoot) + 1);
    }

    /** @param array<string, mixed> $journal */
    private function validateJournal(array $journal, bool $unitAware = false): void
    {
        $keys = array_keys($journal);
        sort($keys, SORT_STRING);
        $expectedKeys = ['backup', 'created_directories', 'id', 'items', 'schema', 'stage', 'state', 'version'];
        if ($unitAware && array_key_exists('removed_directories', $journal)) {
            $expectedKeys[] = 'removed_directories';
            sort($expectedKeys, SORT_STRING);
        }
        if ($keys !== $expectedKeys
            || ($journal['schema'] ?? null) !== 'waaseyaa.site-init-transaction'
            || ($journal['version'] ?? null) !== 1
            || preg_match('/^[a-f0-9]{24}$/D', $journal['id'] ?? '') !== 1
            || ($journal['stage'] ?? null) !== '.waaseyaa/site-init-stage-' . $journal['id']
            || ($journal['backup'] ?? null) !== '.waaseyaa/site-init-backup-' . $journal['id']
            || !in_array($journal['state'] ?? null, ['prepared', 'committed'], true)
            || !is_array($journal['items'] ?? null)
            || !is_array($journal['created_directories'] ?? null)) {
            throw new \RuntimeException('The interrupted site initialization journal is invalid.');
        }
        $paths = [];
        foreach ($journal['items'] as $index => $item) {
            if (!is_array($item)) {
                throw new \RuntimeException('The interrupted site initialization journal contains an invalid item.');
            }
            $itemKeys = array_keys($item);
            sort($itemKeys, SORT_STRING);
            $removing = $unitAware && ($item['kind'] ?? null) === 'remove';
            $merging = $unitAware && ($item['kind'] ?? null) === 'composer-merge';
            $expectedItemKeys = ['backup', 'backup_mode', 'backup_sha256', 'existed', 'installed_sha256', 'mode', 'path', 'stage', 'state'];
            if ($removing || $merging) {
                $expectedItemKeys[] = 'kind';
                sort($expectedItemKeys, SORT_STRING);
            }
            if ($itemKeys !== $expectedItemKeys
                || !is_string($item['path'] ?? null)
                || isset($paths[$item['path']])
                || !is_bool($item['existed'] ?? null)
                || ($merging
                    ? (!is_int($item['mode'] ?? null) || $item['mode'] < 0 || $item['mode'] > 0o777 || $item['path'] !== 'composer.json' || $item['existed'] !== true)
                    : !in_array($item['mode'] ?? null, [0o644, 0o755], true))
                || !in_array($item['state'] ?? null, ['pending', 'installing', 'applied'], true)
                || ($removing
                    ? ($item['installed_sha256'] !== null || $item['stage'] !== null || $item['existed'] !== true || !array_key_exists('removed_directories', $journal))
                    : (preg_match('/^[a-f0-9]{64}$/D', $item['installed_sha256'] ?? '') !== 1
                        || ($item['stage'] ?? null) !== $journal['stage'] . '/' . sprintf('%04d.artifact', $index)))
                || ($item['existed'] === true && (($item['backup'] ?? null) !== $journal['backup'] . '/' . sprintf('%04d.backup', $index) || preg_match('/^[a-f0-9]{64}$/D', $item['backup_sha256'] ?? '') !== 1 || !is_int($item['backup_mode'] ?? null) || $item['backup_mode'] < 0 || $item['backup_mode'] > 0o777))
                || ($item['existed'] === false && (($item['backup'] ?? null) !== null || ($item['backup_sha256'] ?? null) !== null || ($item['backup_mode'] ?? null) !== null))) {
                throw new \RuntimeException('The interrupted site initialization journal contains an invalid item.');
            }
            if ($removing) {
                $this->assertUnitOwnershipPath($item['path']);
            }
            $this->assertSafeTarget($item['path']);
            $paths[$item['path']] = true;
        }
        if ($unitAware && array_key_exists('removed_directories', $journal)) {
            if (!is_array($journal['removed_directories']) || !array_is_list($journal['removed_directories'])) {
                throw new \RuntimeException('The interrupted site initialization journal contains invalid retired directories.');
            }
            $seenDirectories = [];
            foreach ($journal['removed_directories'] as $directory) {
                if (!is_array($directory)) {
                    throw new \RuntimeException('The interrupted site initialization journal contains an invalid retired directory.');
                }
                $directoryKeys = array_keys($directory);
                sort($directoryKeys, SORT_STRING);
                if ($directoryKeys !== ['mode', 'path', 'state'] || !is_string($directory['path'])
                    || in_array($directory['path'], ['', '.', '.waaseyaa'], true) || isset($seenDirectories[$directory['path']])
                    || !is_int($directory['mode']) || $directory['mode'] < 0 || $directory['mode'] > 0o777
                    || !in_array($directory['state'], ['pending', 'removing', 'applied'], true)) {
                    throw new \RuntimeException('The interrupted site initialization journal contains an invalid retired directory.');
                }
                $this->assertSafeTarget($directory['path'] . '/placeholder', true);
                $ownsRemoval = false;
                foreach ($journal['items'] as $item) {
                    if (($item['kind'] ?? null) === 'remove' && str_starts_with($item['path'], $directory['path'] . '/')) {
                        $ownsRemoval = true;
                    }
                }
                if (!$ownsRemoval) {
                    throw new \RuntimeException('The interrupted site initialization journal contains an unowned retired directory.');
                }
                $seenDirectories[$directory['path']] = true;
            }
        }
        $directories = [];
        foreach ($journal['created_directories'] as $directory) {
            if (!is_string($directory) || $directory === '.' || $directory === '.waaseyaa' || isset($directories[$directory])) {
                throw new \RuntimeException('The interrupted site initialization journal contains an invalid target directory.');
            }
            $this->assertSafeTarget($directory . '/placeholder');
            $ownedAncestor = false;
            foreach (array_keys($paths) as $path) {
                if (str_starts_with($path, $directory . '/')) {
                    $ownedAncestor = true;
                    break;
                }
            }
            if (!$ownedAncestor) {
                throw new \RuntimeException('The interrupted site initialization journal contains an unowned target directory.');
            }
            $directories[$directory] = true;
        }
    }

    private function cleanupOrphanControlResidue(?\Closure $reportRecovery = null): bool
    {
        $directory = $this->absolute('.waaseyaa');
        $entries = scandir($directory);
        if ($entries === false) {
            throw new \RuntimeException('Unable to inspect site initialization control state.');
        }
        $paths = [];
        foreach ($entries as $entry) {
            $path = $directory . '/' . $entry;
            if (preg_match('/^site-init-(?:stage|backup)-[a-f0-9]{24}$/D', $entry) === 1) {
                if (is_link($path) || !is_dir($path)) {
                    throw new SiteInitializationCollisionException("Unsafe site initialization residue: .waaseyaa/{$entry}");
                }
                $paths[] = ['path' => '.waaseyaa/' . $entry, 'kind' => 'tree'];
            } elseif (preg_match('/^site-init\.transaction\.json\.tmp-[a-f0-9]{12}$/D', $entry) === 1) {
                $this->assertRegularOwnedFile($path, '.waaseyaa/' . $entry);
                $paths[] = ['path' => '.waaseyaa/' . $entry, 'kind' => 'file'];
            }
        }
        if ($paths === []) {
            return false;
        }
        usort($paths, static fn(array $left, array $right): int => strcmp($left['path'], $right['path']));
        // The existing reserved-name cleanup policy owns these actions. This
        // transient identity binds the sorted instructions, not a data snapshot
        // or an invented original publication plan; no new journal is written.
        $instructions = ['kind' => 'control-residue', 'paths' => $paths];
        try {
            foreach ($paths as $instruction) {
                $path = $this->absolute($instruction['path']);
                if ($instruction['kind'] === 'tree') {
                    $this->removeControlTree($path);
                } else {
                    $this->assertRegularOwnedFile($path, $instruction['path']);
                    if (!unlink($path)) {
                        throw new \RuntimeException("Unable to remove site initialization residue: {$instruction['path']}");
                    }
                    $this->syncDirectory($directory);
                }
            }
        } catch (\Exception $exception) {
            if ($reportRecovery !== null) {
                $reportRecovery($instructions, false);
            }
            throw $exception;
        }
        if ($reportRecovery !== null) {
            $reportRecovery($instructions, true);
        }

        return true;
    }

    private function removeControlTree(string $path): void
    {
        if (is_link($path)) {
            throw new \RuntimeException('Refusing to clean a linked transaction root.');
        }
        if (!is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isLink()) {
                throw new \RuntimeException('Refusing to clean a linked transaction artifact.');
            }
            $removed = $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            if (!$removed) {
                throw new \RuntimeException('Unable to clean a transaction artifact.');
            }
        }
        if (!rmdir($path)) {
            throw new \RuntimeException('Unable to clean a transaction directory.');
        }
        $this->syncDirectory(dirname($path));
    }

    private function directoryIsEmpty(string $directory): bool
    {
        $items = scandir($directory);

        return $items === ['.', '..'];
    }

    private function injectFault(string $stage, int $index, string $path): void
    {
        if ($this->faultInjector !== null) {
            ($this->faultInjector)($stage, $index, $path);
        }
    }
}
