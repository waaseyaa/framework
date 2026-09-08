<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\CLI\Handler\SiteApplyHandler;
use Waaseyaa\CLI\Provider\SiteServiceProvider;
use Waaseyaa\CLI\Site\Blueprint\ApplicationBlueprintCompilerFactory;
use Waaseyaa\CLI\Site\SiteInitializationService;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\SiteContract\Blueprint\BlueprintDecisionReceipt;
use Waaseyaa\SiteContract\CanonicalJson;
use Waaseyaa\SiteContract\Generation\ArtifactApplyRequest;
use Waaseyaa\SiteContract\Generation\ArtifactPlan;
use Waaseyaa\SiteContract\Generation\GeneratedArtifact;
use Waaseyaa\SiteContract\Generation\GeneratedSite;
use Waaseyaa\SiteContract\Generation\GenerationUnitDisposition;
use Waaseyaa\SiteContract\Generation\SiteArtifactRenderer;
use Waaseyaa\SiteContract\SiteManifestParser;

/**
 * ADR-025 D-6.5's second process, installed (#2789): a reviewed request
 * document is handed to a command in a *later* process, which executes exactly
 * those bytes through the existing execution authority and recompiles nothing.
 */
#[CoversClass(SiteApplyHandler::class)]
final class SiteApplyHandlerTest extends TestCase
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
    public function theExactEmittedRequestBytesArePublishedInALaterProcess(): void
    {
        $root = $this->fixture();
        $plan = $this->reviewedPlan($root);
        $requestPath = $this->emitRequest($root, $plan);

        $tester = $this->tester($root)->execute(["--project-root={$root}", "--request={$requestPath}", '--json']);

        self::assertSame(0, $tester->getExitCode(), $tester->getStdout());
        $envelope = json_decode(trim($tester->getStdout()), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('applied', $envelope['result']['outcome']);
        self::assertSame($plan->digest, $envelope['result']['plan_digest']);
        self::assertSame([], $envelope['result']['errors']);
        self::assertSame('applied', $envelope['receipts'][0]['outcome']);
        self::assertSame('site.init', $envelope['receipts'][0]['operation']);
        foreach ($plan->artifacts as $artifact) {
            self::assertSame($artifact->content, file_get_contents($root . '/' . $artifact->path), $artifact->path);
        }
        self::assertSame($plan->artifacts[0]->path, $envelope['result']['changed'][0]);
    }

    #[Test]
    public function anAlreadyPublishedRequestReEvaluatedAgainstTheCurrentStateReportsNoChanges(): void
    {
        $root = $this->fixture();
        $plan = $this->reviewedPlan($root);
        $this->tester($root)->execute(["--project-root={$root}", '--request=' . $this->emitRequest($root, $plan), '--json']);

        // Publication moved the project, so a second apply is a second review:
        // the same plan, evaluated against the state it will actually meet.
        $tester = $this->tester($root)->execute(["--project-root={$root}", '--request=' . $this->emitRequest($root, $plan), '--json']);

        self::assertSame(0, $tester->getExitCode(), $tester->getStdout());
        $envelope = json_decode(trim($tester->getStdout()), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('no_changes', $envelope['result']['outcome']);
        self::assertSame([], $envelope['result']['changed']);
        self::assertSame('no_op', $envelope['receipts'][0]['outcome']);
    }

    #[Test]
    public function areviewedRequestCannotBeReplayedOnceItsOwnPublicationMovedTheProject(): void
    {
        $root = $this->fixture();
        $requestPath = $this->emitRequest($root, $this->reviewedPlan($root));
        $this->tester($root)->execute(["--project-root={$root}", "--request={$requestPath}", '--json']);

        $tester = $this->tester($root)->execute(["--project-root={$root}", "--request={$requestPath}", '--json']);

        self::assertSame(2, $tester->getExitCode());
        $envelope = json_decode(trim($tester->getStdout()), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('refused', $envelope['result']['outcome']);
        self::assertSame('GEN005_STALE_PLAN', $envelope['result']['errors'][0]['code']);
        self::assertSame([], $envelope['errors'], 'A governed refusal publishes its coded errors in the result, not beside it.');
        self::assertSame('refused', $envelope['receipts'][0]['outcome']);
    }

    #[Test]
    public function theCommandCarriesNoCompilerAndCannotRecompileWhatItApplies(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Handler/SiteApplyHandler.php');

        self::assertStringNotContainsString('SiteArtifactRenderer', $source);
        self::assertStringNotContainsString('SiteManifestParser', $source);
        self::assertStringNotContainsString('ApplicationBlueprintCompiler', $source);
        self::assertStringNotContainsString('SiteManifestWizard', $source);
    }

    #[Test]
    public function aProjectThatMovedAfterReviewIsRefusedAsStaleWithoutPublishing(): void
    {
        $root = $this->fixture();
        $plan = $this->reviewedPlan($root);
        $requestPath = $this->emitRequest($root, $plan);
        $foreign = "foreign target written after review\n";
        file_put_contents($root . '/AGENTS.md', $foreign);

        $tester = $this->tester($root)->execute(["--project-root={$root}", "--request={$requestPath}"]);

        self::assertSame(2, $tester->getExitCode());
        self::assertStringContainsString('GEN005_STALE_PLAN', $tester->getStdout() . $tester->getStderr());
        self::assertSame($foreign, file_get_contents($root . '/AGENTS.md'));
        self::assertFileDoesNotExist($root . '/.waaseyaa/site.yaml');
    }

    #[Test]
    public function nonCanonicalRequestBytesAreRefusedBeforeTheProjectIsTouched(): void
    {
        $root = $this->fixture();
        $plan = $this->reviewedPlan($root);
        $request = new ArtifactApplyRequest($plan, $plan->digest, $this->stateDigest($root, $plan));
        $requestPath = $root . '/reviewed-request.json';
        file_put_contents($requestPath, (string) json_encode($request->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        $tester = $this->tester($root)->execute(["--project-root={$root}", "--request={$requestPath}", '--json']);

        self::assertSame(2, $tester->getExitCode());
        $envelope = json_decode(trim($tester->getStdout()), true, flags: JSON_THROW_ON_ERROR);
        self::assertNull($envelope['result']);
        self::assertSame([], $envelope['receipts']);
        self::assertSame('SITE014_INVALID_VALUE', $envelope['errors'][0]['code']);
        self::assertSame('/', $envelope['errors'][0]['pointer']);
        self::assertDirectoryDoesNotExist($root . '/.waaseyaa', 'A refused document must not create the project lock.');
    }

    #[Test]
    public function anAbsentOrUnreadableRequestDocumentIsRefused(): void
    {
        $root = $this->fixture();

        $missingOption = $this->tester($root)->execute(["--project-root={$root}"]);
        self::assertSame(2, $missingOption->getExitCode());
        self::assertStringContainsString('--request', $missingOption->getStdout() . $missingOption->getStderr());

        $missingFile = $this->tester($root)->execute(["--project-root={$root}", '--request=absent-request.json']);
        self::assertSame(2, $missingFile->getExitCode());
        self::assertDirectoryDoesNotExist($root . '/.waaseyaa');
    }

    #[Test]
    public function theProviderRegistersTheBootFreeApplyCommand(): void
    {
        $commands = iterator_to_array(new SiteServiceProvider($this->fixture())->consoleCommands());

        self::assertSame(
            ['site:init', 'site:doctor', 'site:apply', 'project:init'],
            array_map(static fn($command): string => (string) $command->getName(), $commands),
        );
        $definition = $commands[2]->getDefinition();
        self::assertTrue($definition->hasOption('request'));
        self::assertTrue($definition->hasOption('decision-receipt'));
        self::assertTrue($definition->hasOption('project-root'));
        self::assertTrue($definition->hasOption('json'));
    }

    #[Test]
    #[DataProvider('invalidBlueprintApprovalCases')]
    public function aReviewedBlueprintRequestRefusesInvalidApprovalWithoutPublishing(string $case): void
    {
        $root = $this->blueprintFixture();
        $requestPath = $this->emitBlueprintRequest($root);
        $arguments = ["--project-root={$root}", "--request={$requestPath}", '--json'];
        if ($case !== 'missing') {
            if ($case === 'unreadable') {
                $arguments[] = '--decision-receipt=absent-receipt.json';
            } elseif ($case === 'malformed') {
                file_put_contents($root . '/decision.json', '{');
                $arguments[] = '--decision-receipt=decision.json';
            } elseif ($case === 'replaced') {
                unlink($root . '/decision.json');
                mkdir($root . '/decision.json');
                $arguments[] = '--decision-receipt=decision.json';
            } else {
                $this->writeBlueprintReceipt($root, $case === 'rejected' ? ['decision' => 'rejected'] : ['manifest_digest' => str_repeat('b', 64)]);
                $arguments[] = '--decision-receipt=decision.json';
            }
        }
        $before = file_get_contents($root . '/composer.json');

        $tester = $this->tester($root)->execute($arguments);

        self::assertSame(2, $tester->getExitCode());
        $envelope = json_decode(trim($tester->getStdout()), true, flags: JSON_THROW_ON_ERROR);
        $expectedCode = in_array($case, ['malformed', 'unreadable', 'replaced'], true)
            ? 'SITE050_DECISION_RECEIPT_INVALID'
            : 'GEN011_UNAUTHORIZED_SET_DELTA';
        if ($expectedCode === 'SITE050_DECISION_RECEIPT_INVALID') {
            self::assertNull($envelope['result']);
            self::assertSame([], $envelope['receipts']);
            self::assertSame($expectedCode, $envelope['errors'][0]['code']);
        } else {
            self::assertSame('refused', $envelope['result']['outcome']);
            self::assertSame($expectedCode, $envelope['result']['errors'][0]['code']);
            self::assertSame([], $envelope['errors']);
        }
        self::assertDirectoryDoesNotExist($root . '/.waaseyaa');
        self::assertSame($before, file_get_contents($root . '/composer.json'));
    }

    public static function invalidBlueprintApprovalCases(): iterable
    {
        foreach (['missing', 'unreadable', 'malformed', 'rejected', 'mismatched', 'replaced'] as $case) {
            yield $case => [$case];
        }
    }

    #[Test]
    public function aBlueprintRequestWithValidApprovalStillRefusesTamperedPlanDigestWithoutPublishing(): void
    {
        $root = $this->blueprintFixture();
        $plan = $this->blueprintPlan($root);
        $receipt = $this->writeBlueprintReceipt($root);
        $request = new ArtifactApplyRequest($plan, str_repeat('f', 64), $this->stateDigest($root, $plan, $receipt));
        $requestPath = $root . '/reviewed-request.json';
        file_put_contents($requestPath, $request->canonicalJson() . "\n");

        $tester = $this->tester($root)->execute([
            "--project-root={$root}",
            "--request={$requestPath}",
            '--decision-receipt=decision.json',
            '--json',
        ]);

        self::assertSame(2, $tester->getExitCode());
        $envelope = json_decode(trim($tester->getStdout()), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('refused', $envelope['result']['outcome']);
        self::assertSame('GEN005_STALE_PLAN', $envelope['result']['errors'][0]['code']);
        self::assertFileDoesNotExist($root . '/.waaseyaa/site.yaml');
    }

    #[Test]
    public function aBlueprintRequestWithValidApprovalStillRefusesProjectStateDriftWithoutPublishing(): void
    {
        $root = $this->blueprintFixture();
        $plan = $this->blueprintPlan($root);
        $requestPath = $this->emitBlueprintRequest($root, $plan);
        $this->writeBlueprintReceipt($root);
        file_put_contents($root . '/AGENTS.md', "foreign target written after review\n");

        $tester = $this->tester($root)->execute([
            "--project-root={$root}",
            "--request={$requestPath}",
            '--decision-receipt=decision.json',
            '--json',
        ]);

        self::assertSame(2, $tester->getExitCode());
        $envelope = json_decode(trim($tester->getStdout()), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('refused', $envelope['result']['outcome']);
        self::assertSame('GEN005_STALE_PLAN', $envelope['result']['errors'][0]['code']);
        self::assertFileDoesNotExist($root . '/.waaseyaa/site.yaml');
    }

    private function emitRequest(string $root, ArtifactPlan $plan): string
    {
        $request = new ArtifactApplyRequest($plan, $plan->digest, $this->stateDigest($root, $plan));
        $requestPath = $root . '/reviewed-request.json';
        file_put_contents($requestPath, $request->canonicalJson() . "\n");

        return $requestPath;
    }

    private function emitBlueprintRequest(string $root, ?ArtifactPlan $plan = null): string
    {
        $plan = $plan ?? $this->blueprintPlan($root);
        $receipt = $this->writeBlueprintReceipt($root);
        $request = new ArtifactApplyRequest($plan, $plan->digest, $this->stateDigest($root, $plan, $receipt));
        $requestPath = $root . '/reviewed-request.json';
        file_put_contents($requestPath, $request->canonicalJson() . "\n");

        return $requestPath;
    }

    private function blueprintPlan(string $root): ArtifactPlan
    {
        return ApplicationBlueprintCompilerFactory::create()->compile($this->blueprintManifest($root));
    }

    private function blueprintManifest(string $root): \Waaseyaa\SiteContract\SiteManifest
    {
        return new SiteManifestParser()->parse((string) file_get_contents($root . '/answers.yaml'), 'answers.yaml');
    }

    /** @param array<string, string> $changes */
    private function writeBlueprintReceipt(string $root, array $changes = []): BlueprintDecisionReceipt
    {
        $manifest = $this->blueprintManifest($root);
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
        file_put_contents($root . '/decision.json', $receipt->canonicalJson() . "\n");

        return $receipt;
    }

    private function blueprintFixture(): string
    {
        $root = $this->fixture();
        file_put_contents($root . '/composer.json', "{}\n");
        $yaml = (string) file_get_contents(dirname(__DIR__, 4) . '/site-contract/tests/Fixtures/Blueprint/valid/minimal.yaml');
        $yaml = str_replace(str_repeat('a', 64), hash('sha256', "{}\n"), $yaml);
        file_put_contents($root . '/answers.yaml', $yaml);

        return $root;
    }

    private function stateDigest(string $root, ArtifactPlan $plan, ?BlueprintDecisionReceipt $decisionReceipt = null): string
    {
        return new SiteInitializationService($root)->evaluate($plan, decisionReceipt: $decisionReceipt)->projectStateDigest;
    }

    private function reviewedPlan(string $root): ArtifactPlan
    {
        $site = $this->site();

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
        );
    }

    private function tester(string $root): CliTester
    {
        $handler = new SiteApplyHandler($root);
        $container = new class ($handler) implements ContainerInterface {
            public function __construct(private readonly SiteApplyHandler $handler) {}

            public function get(string $id): mixed
            {
                return $id === SiteApplyHandler::class ? $this->handler : throw new \RuntimeException('Unexpected service.');
            }

            public function has(string $id): bool
            {
                return $id === SiteApplyHandler::class;
            }
        };

        return CliTester::for(SiteServiceProvider::siteApplyCommand($root), $container);
    }

    private function fixture(): string
    {
        $root = sys_get_temp_dir() . '/waaseyaa_site_apply_' . bin2hex(random_bytes(8));
        mkdir($root, 0o700, true);
        $this->roots[] = $root;
        file_put_contents($root . '/composer.lock', "{}\n");

        return $root;
    }

    private function site(): GeneratedSite
    {
        $manifest = <<<'YAML'
            schema: waaseyaa.site
            version: 1
            generator_version: 1
            application: {id: example, name: Example, canonical_origin: {config_key: APP_ORIGIN}}
            framework: {revision_policy: exact-lock, observed_lock_sha256: aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa}
            content_types: [{id: page, canonical_route: '/{slug}'}]
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

        return new SiteArtifactRenderer()->render(new SiteManifestParser()->parse($manifest));
    }
}
