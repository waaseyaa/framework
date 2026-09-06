<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Handler\SiteApplyHandler;
use Waaseyaa\CLI\Provider\SiteServiceProvider;
use Waaseyaa\CLI\Site\DevelopmentInterruptionSeam;
use Waaseyaa\CLI\Site\SiteInitializationService;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\SiteContract\Generation\ArtifactApplyRequest;
use Waaseyaa\SiteContract\Generation\ArtifactPlan;
use Waaseyaa\SiteContract\Generation\GeneratedArtifact;
use Waaseyaa\SiteContract\Generation\GeneratedSite;
use Waaseyaa\SiteContract\Generation\GenerationUnitDisposition;
use Waaseyaa\SiteContract\Generation\SiteArtifactRenderer;
use Waaseyaa\SiteContract\SiteManifestParser;

/**
 * #2789 phase 3: a development-only seam that stops `site:apply` after its
 * transaction journal is durable but before publication completes, so a
 * black-box harness can prove that the next ordinary later-process apply
 * recovers the interrupted transaction before completing its own work.
 *
 * The seam is deliberately not a fault-injection API: it takes no stage, path
 * or index from the operator, it fires once, and it exists only when
 * `APP_ENV=development` is explicit. It bypasses nothing — the lock, both
 * digests, path containment and the recovery authority are untouched.
 */
#[CoversClass(SiteApplyHandler::class)]
#[CoversClass(DevelopmentInterruptionSeam::class)]
final class SiteApplyInterruptionSeamTest extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    private string|false $environment = false;

    protected function setUp(): void
    {
        $this->environment = getenv('APP_ENV');
    }

    protected function tearDown(): void
    {
        $this->environment === false ? putenv('APP_ENV') : putenv('APP_ENV=' . $this->environment);
        foreach ($this->roots as $root) {
            new Filesystem()->remove($root);
        }
    }

    #[Test]
    public function theSeamOptionExistsOnlyWhenTheEnvironmentIsExplicitlyDevelopment(): void
    {
        $root = $this->fixture();

        putenv('APP_ENV=production');
        self::assertFalse(
            SiteServiceProvider::siteApplyCommand($root)->getDefinition()->hasOption(DevelopmentInterruptionSeam::OPTION),
            'The seam must not exist as an option outside development.',
        );

        putenv('APP_ENV=development');
        self::assertTrue(SiteServiceProvider::siteApplyCommand($root)->getDefinition()->hasOption(DevelopmentInterruptionSeam::OPTION));
    }

    #[Test]
    public function theSeamStopsAfterTheJournalIsDurableAndBeforePublicationCompletes(): void
    {
        putenv('APP_ENV=development');
        $root = $this->fixture();
        $plan = $this->reviewedPlan();
        $requestPath = $this->emitRequest($root, $plan);

        $tester = $this->tester($root)->execute([
            "--project-root={$root}",
            "--request={$requestPath}",
            '--' . DevelopmentInterruptionSeam::OPTION,
        ]);

        self::assertSame(DevelopmentInterruptionSeam::EXIT_CODE, $tester->getExitCode());
        self::assertFileExists($root . '/.waaseyaa/site-init.transaction.json', 'The interrupted transaction must survive for the next process to recover.');
        self::assertFileDoesNotExist($root . '/.waaseyaa/generated.json', 'Ownership is installed last, so an interrupted publication records none.');
    }

    #[Test]
    public function theNextOrdinaryLaterProcessApplyRecoversBeforeCompleting(): void
    {
        $root = $this->fixture();
        $plan = $this->reviewedPlan();
        $requestPath = $this->emitRequest($root, $plan);

        $interrupted = $this->waaseyaa($root, $requestPath, ['APP_ENV' => 'development'], ['--' . DevelopmentInterruptionSeam::OPTION]);
        self::assertSame(DevelopmentInterruptionSeam::EXIT_CODE, $interrupted->getExitCode(), self::transcript($interrupted));
        self::assertFileExists($root . '/.waaseyaa/site-init.transaction.json');

        $recovered = $this->waaseyaa($root, $requestPath, ['APP_ENV' => 'production'], []);

        self::assertSame(0, $recovered->getExitCode(), self::transcript($recovered));
        $envelope = json_decode(trim($recovered->getOutput()), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($envelope['result']['recovered_interrupted_transaction']);
        self::assertSame('applied', $envelope['result']['outcome']);
        self::assertSame('recovered', $envelope['receipts'][0]['outcome']);
        self::assertSame('site.recover', $envelope['receipts'][0]['operation']);
        self::assertSame('applied', $envelope['receipts'][1]['outcome']);
        foreach ($plan->artifacts as $artifact) {
            self::assertSame($artifact->content, file_get_contents($root . '/' . $artifact->path), $artifact->path);
        }
        self::assertFileDoesNotExist($root . '/.waaseyaa/site-init.transaction.json');
        self::assertSame([], glob($root . '/.waaseyaa/site-init-stage-*'));
        self::assertSame([], glob($root . '/.waaseyaa/site-init-backup-*'));
    }

    #[Test]
    public function aForgedSeamOptionIsInertOutsideDevelopment(): void
    {
        putenv('APP_ENV=production');
        $root = $this->fixture();
        $requestPath = $this->emitRequest($root, $this->reviewedPlan());

        // A stale or hand-built command definition cannot enable the seam: the
        // handler re-reads the environment itself before it constructs one.
        $tester = CliTester::for($this->forgedCommand($root), $this->container($root))->execute([
            "--project-root={$root}",
            "--request={$requestPath}",
            '--' . DevelopmentInterruptionSeam::OPTION,
        ]);

        self::assertSame(0, $tester->getExitCode(), $tester->getStdout());
        self::assertFileDoesNotExist($root . '/.waaseyaa/site-init.transaction.json');
        self::assertFileExists($root . '/.waaseyaa/generated.json');
    }

    #[Test]
    public function theSeamDoesNotBypassTheReviewedStateGate(): void
    {
        putenv('APP_ENV=development');
        $root = $this->fixture();
        $requestPath = $this->emitRequest($root, $this->reviewedPlan());
        $foreign = "foreign target written after review\n";
        file_put_contents($root . '/AGENTS.md', $foreign);

        $tester = $this->tester($root)->execute([
            "--project-root={$root}",
            "--request={$requestPath}",
            '--' . DevelopmentInterruptionSeam::OPTION,
        ]);

        self::assertSame(2, $tester->getExitCode());
        self::assertStringContainsString('GEN005_STALE_PLAN', $tester->getStdout() . $tester->getStderr());
        self::assertSame($foreign, file_get_contents($root . '/AGENTS.md'));
        self::assertFileDoesNotExist($root . '/.waaseyaa/site-init.transaction.json');
        self::assertFileDoesNotExist($root . '/.waaseyaa/generated.json');
    }

    /**
     * A failing subprocess assertion is unreadable without both streams: the
     * envelope goes to stdout, and a throwable the console renders goes to
     * stderr, so an exit-code mismatch is only diagnosable with each in hand.
     */
    private static function transcript(Process $process): string
    {
        return sprintf(
            "exit=%s\n--- stdout ---\n%s\n--- stderr ---\n%s",
            var_export($process->getExitCode(), true),
            $process->getOutput(),
            $process->getErrorOutput(),
        );
    }

    /**
     * @param array<string, string> $environment
     * @param list<string> $arguments
     */
    private function waaseyaa(string $root, string $requestPath, array $environment, array $arguments): Process
    {
        $repositoryRoot = dirname(__DIR__, 5);
        $process = new Process(
            [PHP_BINARY, 'packages/cli/bin/waaseyaa', 'site:apply', "--request={$requestPath}", "--project-root={$root}", '--json', ...$arguments],
            $repositoryRoot,
            $environment,
        );
        $process->run();

        return $process;
    }

    private function forgedCommand(string $root): HandlerCommand
    {
        $handler = new SiteApplyHandler($root);

        return new HandlerCommand(
            name: 'site:apply',
            description: 'Publish a reviewed artifact apply request exactly as emitted, without recompiling it',
            options: [
                new HandlerOption('request', mode: HandlerOptionMode::Required, description: 'request'),
                new HandlerOption('project-root', mode: HandlerOptionMode::Required, description: 'root'),
                new HandlerOption('json', mode: HandlerOptionMode::None, description: 'json'),
                new HandlerOption(DevelopmentInterruptionSeam::OPTION, mode: HandlerOptionMode::None, description: 'forged'),
            ],
            handler: \Closure::fromCallable([$handler, 'execute']),
        );
    }

    private function emitRequest(string $root, ArtifactPlan $plan): string
    {
        $evaluation = new SiteInitializationService($root)->evaluate($plan);
        $requestPath = $root . '/reviewed-request.json';
        file_put_contents($requestPath, new ArtifactApplyRequest($plan, $plan->digest, $evaluation->projectStateDigest)->canonicalJson() . "\n");

        return $requestPath;
    }

    private function reviewedPlan(): ArtifactPlan
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
        return CliTester::for(SiteServiceProvider::siteApplyCommand($root), $this->container($root));
    }

    private function container(string $root): ContainerInterface
    {
        return new class (new SiteApplyHandler($root)) implements ContainerInterface {
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
    }

    private function fixture(): string
    {
        $root = sys_get_temp_dir() . '/waaseyaa_site_interrupt_' . bin2hex(random_bytes(8));
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
