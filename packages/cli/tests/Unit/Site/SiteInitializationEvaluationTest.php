<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Site;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\CLI\Site\Exception\SiteInitializationCollisionException;
use Waaseyaa\CLI\Site\SiteInitializationService;
use Waaseyaa\SiteContract\Generation\ArtifactApplyOutcome;
use Waaseyaa\SiteContract\Generation\ArtifactApplyResult;
use Waaseyaa\SiteContract\Generation\ArtifactPlan;
use Waaseyaa\SiteContract\Generation\ArtifactStatus;
use Waaseyaa\SiteContract\Generation\ChangeOutcome;
use Waaseyaa\SiteContract\Generation\GeneratedArtifact;
use Waaseyaa\SiteContract\Generation\GenerationUnitDisposition;
use Waaseyaa\SiteContract\Generation\ObservedTargetMode;
use Waaseyaa\SiteContract\Generation\ObservedTargetState;
use Waaseyaa\SiteContract\Generation\ProjectStateIdentity;
use Waaseyaa\SiteContract\Generation\SiteArtifactRenderer;
use Waaseyaa\SiteContract\SiteManifestParser;

#[CoversClass(SiteInitializationService::class)]
final class SiteInitializationEvaluationTest extends TestCase
{
    private const string INPUT_DIGEST = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/waaseyaa_test_' . uniqid('s4', true);
        mkdir($this->root, 0o755, true);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
    }

    #[Test]
    public function itEvaluatesEveryPlannedArtifactAgainstAnUntouchedProject(): void
    {
        $plan = $this->plan();
        $evaluated = new SiteInitializationService($this->root)->evaluate($plan);

        $paths = array_map(static fn(GeneratedArtifact $artifact): string => $artifact->path, $plan->artifacts);
        self::assertSame(array_fill_keys($paths, ArtifactStatus::Created), $evaluated->status);
        self::assertSame($paths, $evaluated->changed());
        self::assertSame([], $evaluated->refusals);
        self::assertSame($plan->digest, $evaluated->planDigest);
        self::assertSame($evaluated->projectState->digest, $evaluated->projectStateDigest);
    }

    #[Test]
    public function evaluationWritesNothingToTheProjectNotEvenTheControlDirectory(): void
    {
        $before = $this->snapshot();
        new SiteInitializationService($this->root)->evaluate($this->plan());

        self::assertSame($before, $this->snapshot(), 'A preview that mutates is not a preview.');
        self::assertDirectoryDoesNotExist($this->root . '/.waaseyaa');
    }

    #[Test]
    public function evaluationRefusesWhileAnInterruptedTransactionIsPending(): void
    {
        mkdir($this->root . '/.waaseyaa', 0o700, true);
        file_put_contents($this->root . '/.waaseyaa/site-init.transaction.json', '{"state":"staged"}');

        $this->expectException(SiteInitializationCollisionException::class);
        $this->expectExceptionMessage('An interrupted site initialization must be recovered before a plan is evaluated.');

        new SiteInitializationService($this->root)->evaluate($this->plan());
    }

    #[Test]
    public function theCapturedIdentityRecordsExactlyWhatWasObserved(): void
    {
        mkdir($this->root . '/.waaseyaa', 0o700, true);
        file_put_contents($this->root . '/composer.json', "{}\n");

        $state = new SiteInitializationService($this->root)->evaluate($this->plan())->projectState;
        $targets = [];
        foreach ($state->targets as $target) {
            $targets[$target->path] = $target;
        }

        self::assertSame(array_map(static fn(GeneratedArtifact $artifact): string => $artifact->path, $this->plan()->artifacts), array_keys($targets));
        foreach ($targets as $target) {
            self::assertSame(ObservedTargetState::Absent, $target->state);
            self::assertSame(ProjectStateIdentity::ABSENT_DIGEST, $target->sha256);
            self::assertSame(ObservedTargetMode::Unknown, $target->mode);
        }
        self::assertSame(ProjectStateIdentity::ABSENT_DIGEST, $state->manifestSha256);
        self::assertSame(hash('sha256', "{}\n"), $state->composerJsonSha256);
        self::assertSame(ProjectStateIdentity::ABSENT_DIGEST, $state->generatedMetadataSha256);
    }

    #[Test]
    public function evaluationInheritsTodaysFrozenRefusalForAnUnownedTarget(): void
    {
        // Constraint 2 in action: evaluate() enters the same evaluator, so it
        // refuses an existing unowned file with the message site:init already
        // emits, rather than inventing a second one.
        mkdir($this->root . '/src/Entity', 0o755, true);
        file_put_contents($this->root . '/AGENTS.md', "<?php\n// mine\n");

        $this->expectException(SiteInitializationCollisionException::class);
        $this->expectExceptionMessage('Refusing to overwrite unowned artifact: AGENTS.md');

        new SiteInitializationService($this->root)->evaluate($this->plan());
    }

    #[Test]
    public function evaluationSharesTheChangedSetPrepareComputes(): void
    {
        $site = new SiteArtifactRenderer()->render(new SiteManifestParser()->parse($this->manifest()));
        $service = new SiteInitializationService($this->root);
        $dryRun = $service->initialize($site, dryRun: true);

        $artifacts = [];
        foreach ($site->artifacts as $path => $artifact) {
            if ($path !== '.waaseyaa/generated.json') {
                $artifacts[] = $artifact;
            }
        }
        $evaluated = $service->evaluate($this->planWith($artifacts));

        $expected = array_values(array_filter($dryRun->changedPaths, static fn(string $p): bool => $p !== '.waaseyaa/generated.json'));
        sort($expected, SORT_STRING);

        self::assertSame($expected, $evaluated->changed(), 'Dry-run and plan evaluation must enter one implementation.');
    }

    #[Test]
    public function dryRunAndPlanEvaluationRefuseIdenticallyForEveryHostileProjectState(): void
    {
        // D-13 clause 3 forbids a second collision, containment or
        // symlink-safety check. The structural mitigation is one evaluator; this
        // is the behavioural proof that no caller can be given its own branch.
        // Both sides carry the identical artifact set, so any difference in the
        // refusal is a difference in the checks, not in the input.
        $target = 'AGENTS.md';
        $states = [
            'unowned file at a target' => function (string $root) use ($target): void {
                file_put_contents($root . '/' . $target, "# mine\n");
            },
            'directory where a target belongs' => function (string $root) use ($target): void {
                mkdir($root . '/' . $target, 0o755, true);
            },
            'symlink where a target belongs' => function (string $root) use ($target): void {
                file_put_contents($root . '/decoy.md', "# decoy\n");
                symlink($root . '/decoy.md', $root . '/' . $target);
            },
        ];

        foreach ($states as $name => $plant) {
            $root = sys_get_temp_dir() . '/waaseyaa_test_' . uniqid('s4h', true);
            mkdir($root, 0o755, true);
            $plant($root);

            $site = new SiteArtifactRenderer()->render(new SiteManifestParser()->parse($this->manifest()));
            $plan = $this->planWith(array_values(array_filter(
                iterator_to_array((function () use ($site) {
                    yield from $site->artifacts;
                })()),
                static fn(GeneratedArtifact $a): bool => $a->path !== '.waaseyaa/generated.json',
            )));

            $viaPlan = $this->refusalOf($root, static fn(SiteInitializationService $s): mixed => $s->evaluate($plan));
            $viaDryRun = $this->refusalOf($root, static fn(SiteInitializationService $s): mixed => $s->initialize($site, dryRun: true));

            new Filesystem()->remove($root);

            self::assertNotNull($viaPlan, "Plan evaluation must refuse the {$name}.");
            self::assertSame($viaDryRun, $viaPlan, "Dry-run and plan evaluation must refuse the {$name} identically.");
        }
    }

    #[Test]
    public function evaluationWritesNothingToAnAlreadyInitializedProjectEither(): void
    {
        $site = new SiteArtifactRenderer()->render(new SiteManifestParser()->parse($this->manifest()));
        $service = new SiteInitializationService($this->root);
        $service->initialize($site);

        $before = $this->snapshot();
        $artifacts = [];
        foreach ($site->artifacts as $path => $artifact) {
            if ($path !== '.waaseyaa/generated.json') {
                $artifacts[] = $artifact;
            }
        }
        $service->evaluate($this->planWith($artifacts));

        self::assertSame($before, $this->snapshot(), 'The already-initialized branch must be as side-effect-free as the pristine one.');
    }

    #[Test]
    public function theReceiptCarriesTheContractVersionConstantAndMintsAUniqueImmutableIdentity(): void
    {
        $service = new SiteInitializationService($this->root);
        $result = $this->applyResult(ArtifactApplyOutcome::Applied);

        $first = $service->receiptFor($result, 'site.init');
        $second = $service->receiptFor($result, 'site.init');

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertSame(SiteInitializationService::CONTRACT_VERSION, $first->authorityVersion);
        self::assertSame('waaseyaa.generation', $first->authority);
        self::assertSame(ChangeOutcome::Applied, $first->outcome);
        self::assertNotSame($first->receiptId, $second->receiptId, 'A receipt id is never reused.');
        self::assertNotSame($first->correlationId, $second->correlationId);
    }

    #[Test]
    public function theReceiptRelocatesGenerationDetailUnderAVersionedDomainPayload(): void
    {
        $receipt = new SiteInitializationService($this->root)->receiptFor($this->applyResult(ArtifactApplyOutcome::NoChanges), 'site.init');

        self::assertNotNull($receipt);
        self::assertSame(ChangeOutcome::NoOp, $receipt->outcome);
        self::assertSame(1, $receipt->domainPayload['version']);
        self::assertArrayHasKey('project_state_digest', $receipt->domainPayload);
        self::assertArrayHasKey('cleanup_pending', $receipt->domainPayload);
        self::assertArrayNotHasKey('plan_digest', $receipt->domainPayload, 'plan_digest is an envelope member, not domain detail.');
        self::assertArrayNotHasKey('schema', $receipt->domainPayload);
    }

    #[Test]
    public function previewAndCancellationEarnNoReceiptAtAll(): void
    {
        $service = new SiteInitializationService($this->root);

        self::assertNull($service->receiptFor($this->applyResult(ArtifactApplyOutcome::Planned), 'site.init'));
        self::assertNull($service->receiptFor($this->applyResult(ArtifactApplyOutcome::Cancelled), 'site.init'));
    }

    #[Test]
    public function noReceiptReachesAnyDurableSink(): void
    {
        // The honest predicate is effect, not a filename allowlist: snapshot
        // the whole root and assert the file set and every byte are unchanged.
        mkdir($this->root . '/.waaseyaa', 0o700, true);
        $before = $this->snapshot();

        $service = new SiteInitializationService($this->root);
        foreach ([ArtifactApplyOutcome::Applied, ArtifactApplyOutcome::NoChanges, ArtifactApplyOutcome::Refused] as $outcome) {
            $service->receiptFor($this->applyResult($outcome), 'site.init');
        }

        self::assertSame($before, $this->snapshot(), 'v1 emits receipts and retains none.');
    }

    /**
     * The refusal one call produced, as a comparable class-and-message pair,
     * or null when it did not refuse.
     *
     * @param \Closure(SiteInitializationService): mixed $call
     * @return array{string, string}|null
     */
    private function refusalOf(string $root, \Closure $call): ?array
    {
        try {
            $call(new SiteInitializationService($root));

            return null;
        } catch (\Throwable $exception) {
            return [$exception::class, $exception->getMessage()];
        }
    }

    private function applyResult(ArtifactApplyOutcome $outcome): ArtifactApplyResult
    {
        return new ArtifactApplyResult(
            $outcome,
            $this->plan()->digest,
            str_repeat('b', 64),
            ['src/Entity/Story.php' => ArtifactStatus::Created],
            $outcome === ArtifactApplyOutcome::Applied ? ['src/Entity/Story.php'] : [],
            errors: $outcome === ArtifactApplyOutcome::Refused
                ? [new \Waaseyaa\SiteContract\Generation\Exception\GenerationViolation(
                    \Waaseyaa\SiteContract\Generation\Exception\GenerationErrorCode::Locked,
                    'A concurrent initialization holds the project lock.',
                )]
                : [],
        );
    }

    private function plan(): ArtifactPlan
    {
        $site = new SiteArtifactRenderer()->render(new SiteManifestParser()->parse($this->manifest()));

        return $this->planWith(array_values(array_filter($site->artifacts, static fn(GeneratedArtifact $artifact): bool => $artifact->path !== '.waaseyaa/generated.json')));
    }

    /** @param list<GeneratedArtifact> $artifacts */
    private function planWith(array $artifacts): ArtifactPlan
    {
        usort($artifacts, static fn(GeneratedArtifact $a, GeneratedArtifact $b): int => strcmp($a->path, $b->path));
        $manifest = new SiteManifestParser()->parse($this->manifest());

        // Slice 4's seeded scaffold placeholder had no lawful compiler
        // admission or root binding. Slice 5 completes that dormant boundary.
        return new ArtifactPlan(
            SiteArtifactRenderer::class,
            $manifest->generatorVersion,
            'site',
            GenerationUnitDisposition::Managed,
            $manifest->digest,
            $artifacts,
        );
    }

    /** @return array<string, string> */
    private function snapshot(): array
    {
        if (!is_dir($this->root)) {
            return [];
        }
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $entry) {
            $relative = substr($entry->getPathname(), strlen($this->root) + 1);
            $files[$relative] = $entry->isFile() ? hash_file('sha256', $entry->getPathname()) : 'dir';
        }
        ksort($files, SORT_STRING);

        return $files;
    }

    private function manifest(): string
    {
        return <<<'YAML'
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
    }
}
