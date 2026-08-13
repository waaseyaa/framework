<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Waaseyaa\CLI\Handler\SiteInitHandler;
use Waaseyaa\CLI\Provider\SiteServiceProvider;
use Waaseyaa\CLI\Site\SiteInitializationService;
use Waaseyaa\CLI\Site\SiteManifestWizard;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\SiteContract\Generation\SiteArtifactRenderer;
use Waaseyaa\SiteContract\SiteManifestParser;

#[CoversClass(SiteInitHandler::class)]
#[CoversClass(SiteServiceProvider::class)]
#[CoversClass(SiteManifestWizard::class)]
final class SiteInitHandlerTest extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($root);
        }
    }

    #[Test]
    public function nonInteractiveExecutionRefusesMissingAnswers(): void
    {
        $tester = $this->tester($this->root());

        $tester->execute(['--yes']);

        self::assertSame(2, $tester->getExitCode());
        self::assertStringContainsString('--answers', $tester->getStderr());
    }

    #[Test]
    public function completeAnswerDocumentInitializesTheRequestedProject(): void
    {
        $root = $this->root();
        $answers = $root . '/answers.yaml';
        file_put_contents($answers, $this->manifest());
        $tester = $this->tester($root);

        $tester->execute(["--answers={$answers}", "--project-root={$root}", '--yes']);

        self::assertSame(0, $tester->getExitCode(), $tester->getStderr());
        self::assertFileExists($root . '/.waaseyaa/site.yaml');
        self::assertFileExists($root . '/bin/maintenance/site-verify');
        self::assertStringContainsString('Initialized 7 generated artifacts', $tester->getStdout());
    }

    #[Test]
    public function dryRunReportsThePlanWithoutWriting(): void
    {
        $root = $this->root();
        $answers = $root . '/answers.yaml';
        file_put_contents($answers, $this->manifest());
        $before = hash_file('sha256', $answers);
        $tester = $this->tester($root);

        $tester->execute(["--answers={$answers}", "--project-root={$root}", '--dry-run']);

        self::assertSame(0, $tester->getExitCode(), $tester->getStderr());
        self::assertStringContainsString('Would initialize 8 generated artifacts', $tester->getStdout());
        self::assertDirectoryDoesNotExist($root . '/.waaseyaa');
        self::assertSame($before, hash_file('sha256', $answers));
    }

    #[Test]
    public function theNextCliRunRecoversAnInterruptedPublicationBeforePlanning(): void
    {
        $root = $this->root();
        $answers = $root . '/answers.yaml';
        file_put_contents($answers, $this->manifest());
        $site = new SiteArtifactRenderer()->render(new SiteManifestParser()->parse($this->manifest()));
        $fault = static function (string $stage, int $index): void {
            if ($stage === 'after-replace' && $index === 1) {
                throw new \Error('simulated process termination');
            }
        };
        try {
            new SiteInitializationService($root, $fault)->initialize($site);
            self::fail('Expected simulated process termination.');
        } catch (\Error) {
        }

        $tester = $this->tester($root);
        $tester->execute(["--answers={$answers}", "--project-root={$root}", '--yes']);

        self::assertSame(0, $tester->getExitCode(), $tester->getStderr());
        self::assertStringContainsString('Recovered an interrupted site initialization transaction', $tester->getStdout());
        self::assertFileExists($root . '/.waaseyaa/generated.json');
    }

    #[Test]
    public function interactiveModeAsksPlainLanguageProductQuestions(): void
    {
        $root = $this->root();
        file_put_contents($root . '/composer.lock', "{}\n");
        $lines = ['Example Nation', 'example-nation', 'APP_ORIGIN', 'page', '/{slug}', 'yes', 'no'];
        $stdin = new class ($lines) {
            /** @param list<string> $lines */
            public function __construct(private array $lines) {}
            public function readLine(): ?string
            {
                return array_shift($this->lines);
            }
            public function isInteractive(): bool
            {
                return true;
            }
        };
        $tester = $this->tester($root, $stdin);

        $tester->execute(["--project-root={$root}", '--yes']);

        self::assertSame(0, $tester->getExitCode(), $tester->getStderr());
        $manifest = new SiteManifestParser()->parse((string) file_get_contents($root . '/.waaseyaa/site.yaml'));
        self::assertSame('example-nation', $manifest->application->id);
        self::assertArrayHasKey('governed_authoring', $manifest->capabilities);
        self::assertArrayHasKey('published_content', $manifest->recipes);
        self::assertArrayHasKey('governed_authoring', $manifest->recipes);
        self::assertFileExists($root . '/src/Provider/PublishedContentServiceProvider.php');
        self::assertFileExists($root . '/src/Provider/GovernedAuthoringServiceProvider.php');
        self::assertFileExists($root . '/tests/Acceptance/PublishedContentRecipeTest.php');
        self::assertSame(hash_file('sha256', $root . '/composer.lock'), $manifest->framework->observedLockSha256);
    }

    #[Test]
    public function interactiveModeRecordsPersonalDataLifecycleDecisions(): void
    {
        $root = $this->root();
        file_put_contents($root . '/composer.lock', "{}\n");
        $lines = ['Example Nation', 'example-nation', 'APP_ORIGIN', 'page', '/{slug}', 'yes', 'yes', 'P1Y'];
        $stdin = new class ($lines) {
            /** @param list<string> $lines */
            public function __construct(private array $lines) {}
            public function readLine(): ?string
            {
                return array_shift($this->lines);
            }
            public function isInteractive(): bool
            {
                return true;
            }
        };
        $tester = $this->tester($root, $stdin);

        $tester->execute(["--project-root={$root}", '--yes']);

        self::assertSame(0, $tester->getExitCode(), $tester->getStderr());
        $manifest = new SiteManifestParser()->parse((string) file_get_contents($root . '/.waaseyaa/site.yaml'));
        self::assertArrayHasKey('subscription', $manifest->capabilities);
        self::assertSame('P1Y', $manifest->personalDataStores['subscriber']->retention);
        self::assertArrayHasKey('subscription', $manifest->recipes);
        self::assertFileExists($root . '/migrations/2026_08_13_000001_create_subscriber_table.php');
        self::assertFileExists($root . '/src/Provider/SubscriptionServiceProvider.php');
        self::assertFileExists($root . '/tests/Acceptance/SubscriptionRecipeTest.php');
    }

    private function tester(string $root, ?object $stdin = null): CliTester
    {
        $provider = new SiteServiceProvider(projectRoot: $root);
        $command = iterator_to_array($provider->consoleCommands())[0];
        $handler = new SiteInitHandler($root);
        $container = new class ($handler) implements ContainerInterface {
            public function __construct(private readonly SiteInitHandler $handler) {}
            public function get(string $id): mixed
            {
                return $id === SiteInitHandler::class ? $this->handler : throw new \RuntimeException('Unexpected service.');
            }
            public function has(string $id): bool
            {
                return $id === SiteInitHandler::class;
            }
        };

        return CliTester::for($command, $container, $stdin);
    }

    private function root(): string
    {
        $root = sys_get_temp_dir() . '/waaseyaa_site_init_handler_' . bin2hex(random_bytes(8));
        mkdir($root, 0o777, true);
        $this->roots[] = $root;

        return $root;
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
