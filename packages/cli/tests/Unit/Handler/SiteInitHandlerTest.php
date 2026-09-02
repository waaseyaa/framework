<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\CLI\Handler\SiteInitHandler;
use Waaseyaa\CLI\Provider\SiteServiceProvider;
use Waaseyaa\CLI\Site\SiteInitializationService;
use Waaseyaa\CLI\Site\SiteManifestWizard;
use Waaseyaa\CLI\Site\SitePreset;
use Waaseyaa\CLI\Site\SitePresetResolver;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\SiteContract\Generation\SiteArtifactRenderer;
use Waaseyaa\SiteContract\SiteManifestParser;

#[CoversClass(SiteInitHandler::class)]
#[CoversClass(SiteServiceProvider::class)]
#[CoversClass(SiteManifestWizard::class)]
#[CoversClass(SitePreset::class)]
#[CoversClass(SitePresetResolver::class)]
final class SiteInitHandlerTest extends TestCase
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

    #[Test]
    public function unknownPresetValueIsRejected(): void
    {
        $root = $this->root();
        $answers = $root . '/seed.yaml';
        file_put_contents($answers, $this->presetSeed());
        $tester = $this->tester($root);

        $tester->execute(['--preset=nonexistent', "--answers={$answers}", "--project-root={$root}", '--yes']);

        self::assertSame(2, $tester->getExitCode());
        self::assertStringContainsString("Unknown site:init preset 'nonexistent'", $tester->getStderr());
    }

    #[Test]
    public function nonInteractivePresetWithoutAnswersRequiresASeedDocument(): void
    {
        $tester = $this->tester($this->root());

        $tester->execute(['--preset=minimal', '--yes']);

        self::assertSame(2, $tester->getExitCode());
        self::assertStringContainsString('--preset', $tester->getStderr());
        self::assertStringContainsString('--answers', $tester->getStderr());
    }

    /**
     * #2442, ADR-024 D-3/D-4: `minimal` resolves deterministically to the
     * smallest active decision set — `published_content` only. Neither
     * `governed_authoring` nor `subscription` artifacts are generated, and
     * nothing in the published manifest names the preset that produced it.
     */
    #[Test]
    public function minimalPresetSeedDocumentInitializesTheSmallestProject(): void
    {
        $root = $this->root();
        file_put_contents($root . '/composer.lock', "{}\n");
        $seed = $root . '/seed.yaml';
        file_put_contents($seed, $this->presetSeed());
        $tester = $this->tester($root);

        $tester->execute(['--preset=minimal', "--answers={$seed}", "--project-root={$root}", '--yes']);

        self::assertSame(0, $tester->getExitCode(), $tester->getStderr());
        $manifestYaml = (string) file_get_contents($root . '/.waaseyaa/site.yaml');
        self::assertStringNotContainsString('preset', $manifestYaml);
        self::assertStringNotContainsString('profile', $manifestYaml);
        $manifest = new SiteManifestParser()->parse($manifestYaml);
        self::assertSame('not_needed', $manifest->capabilities['governed_authoring']->state->value);
        self::assertSame('not_needed', $manifest->capabilities['subscription']->state->value);
        self::assertArrayHasKey('published_content', $manifest->recipes);
        self::assertArrayNotHasKey('governed_authoring', $manifest->recipes);
        self::assertArrayNotHasKey('subscription', $manifest->recipes);
        self::assertFileExists($root . '/src/Provider/PublishedContentServiceProvider.php');
        self::assertFileDoesNotExist($root . '/src/Provider/GovernedAuthoringServiceProvider.php');
        self::assertFileDoesNotExist($root . '/src/Provider/SubscriptionServiceProvider.php');
    }

    /**
     * `editorial` additionally activates `governed_authoring` — a usable
     * authenticated authoring starting point built entirely from the
     * existing governed-authoring recipe, never backend auth/security
     * implementation code of its own.
     */
    #[Test]
    public function editorialPresetSeedDocumentInitializesAnAuthoringStartingPoint(): void
    {
        $root = $this->root();
        file_put_contents($root . '/composer.lock', "{}\n");
        $seed = $root . '/seed.yaml';
        file_put_contents($seed, $this->presetSeed());
        $tester = $this->tester($root);

        $tester->execute(['--preset=editorial', "--answers={$seed}", "--project-root={$root}", '--yes']);

        self::assertSame(0, $tester->getExitCode(), $tester->getStderr());
        $manifest = new SiteManifestParser()->parse((string) file_get_contents($root . '/.waaseyaa/site.yaml'));
        self::assertSame('active', $manifest->capabilities['governed_authoring']->state->value);
        self::assertSame('not_needed', $manifest->capabilities['subscription']->state->value);
        self::assertArrayHasKey('published_content', $manifest->recipes);
        self::assertArrayHasKey('governed_authoring', $manifest->recipes);
        self::assertArrayNotHasKey('subscription', $manifest->recipes);
        self::assertFileExists($root . '/src/Provider/GovernedAuthoringServiceProvider.php');
        self::assertFileExists($root . '/tests/Acceptance/GovernedAuthoringRecipeTest.php');
        self::assertFileDoesNotExist($root . '/src/Provider/SubscriptionServiceProvider.php');
        self::assertFileDoesNotExist($root . '/migrations');
    }

    #[Test]
    public function presetDryRunReportsThePlanWithoutWriting(): void
    {
        $root = $this->root();
        file_put_contents($root . '/composer.lock', "{}\n");
        $seed = $root . '/seed.yaml';
        file_put_contents($seed, $this->presetSeed());
        $before = hash_file('sha256', $seed);
        $tester = $this->tester($root);

        $tester->execute(['--preset=editorial', "--answers={$seed}", "--project-root={$root}", '--dry-run']);

        self::assertSame(0, $tester->getExitCode(), $tester->getStderr());
        self::assertStringContainsString('Would initialize', $tester->getStdout());
        self::assertStringContainsString('generated artifacts', $tester->getStdout());
        self::assertDirectoryDoesNotExist($root . '/.waaseyaa');
        self::assertSame($before, hash_file('sha256', $seed));
    }

    /**
     * Re-running the same preset seed is deterministic and reports the exact
     * proposed diff before changing an already-initialized site: a second
     * `--dry-run` (nothing changed) reports zero pending artifacts, and a
     * second non-dry-run publish is a byte-identical no-op.
     */
    #[Test]
    public function reRunningTheSamePresetIsIdempotentAndReportsAnEmptyDiff(): void
    {
        $root = $this->root();
        file_put_contents($root . '/composer.lock', "{}\n");
        $seed = $root . '/seed.yaml';
        file_put_contents($seed, $this->presetSeed());

        $first = $this->tester($root);
        $first->execute(['--preset=editorial', "--answers={$seed}", "--project-root={$root}", '--yes']);
        self::assertSame(0, $first->getExitCode(), $first->getStderr());

        $dryRun = $this->tester($root);
        $dryRun->execute(['--preset=editorial', "--answers={$seed}", "--project-root={$root}", '--dry-run']);
        self::assertSame(0, $dryRun->getExitCode(), $dryRun->getStderr());
        self::assertStringContainsString('Would initialize 0 generated artifacts', $dryRun->getStdout());

        $second = $this->tester($root);
        $second->execute(['--preset=editorial', "--answers={$seed}", "--project-root={$root}", '--yes']);
        self::assertSame(0, $second->getExitCode(), $second->getStderr());
        self::assertStringContainsString('Initialized 0 generated artifacts', $second->getStdout());
    }

    /**
     * Conflicting-existing-state: a foreign, un-owned file already occupying
     * a preset-generated target path is never silently overwritten. This
     * reuses `SiteInitializationService`'s existing collision detection —
     * preset resolution introduces no second ownership authority.
     */
    #[Test]
    public function presetRefusesAnUnownedCollisionBeforeWritingAnyTarget(): void
    {
        $root = $this->root();
        file_put_contents($root . '/composer.lock', "{}\n");
        $seed = $root . '/seed.yaml';
        file_put_contents($seed, $this->presetSeed());
        mkdir($root . '/tests/Architecture', 0o777, true);
        file_put_contents($root . '/tests/Architecture/SiteContractTest.php', 'owned by the application, not the generator');
        $tester = $this->tester($root);

        $tester->execute(['--preset=minimal', "--answers={$seed}", "--project-root={$root}", '--yes']);

        self::assertSame(2, $tester->getExitCode());
        self::assertStringContainsString('Refusing to overwrite unowned artifact', $tester->getStderr());
        self::assertDirectoryDoesNotExist($root . '/.waaseyaa');
    }

    /**
     * A conflict that surfaces only after a project already carries a
     * preset-generated site: editing a governed artifact outside its
     * declared extension region is refused on the next `--preset` run,
     * exactly as it is for a full `--answers` manifest.
     */
    #[Test]
    public function presetRefusesRegenerationAfterAnExternalEditToAGeneratedArtifact(): void
    {
        $root = $this->root();
        file_put_contents($root . '/composer.lock', "{}\n");
        $seed = $root . '/seed.yaml';
        file_put_contents($seed, $this->presetSeed());
        $first = $this->tester($root);
        $first->execute(['--preset=minimal', "--answers={$seed}", "--project-root={$root}", '--yes']);
        self::assertSame(0, $first->getExitCode(), $first->getStderr());

        file_put_contents($root . '/src/Provider/PublishedContentServiceProvider.php', '<?php // tampered outside any extension region');

        $second = $this->tester($root);
        $second->execute(['--preset=minimal', "--answers={$seed}", "--project-root={$root}", '--yes']);

        self::assertSame(2, $second->getExitCode());
        self::assertStringContainsString('edited outside an extension region', $second->getStderr());
    }

    #[Test]
    public function interactivePresetAsksOnlyIdentityAndContentTypeQuestions(): void
    {
        $root = $this->root();
        file_put_contents($root . '/composer.lock', "{}\n");
        $lines = ['Example Nation', 'example-nation', 'APP_ORIGIN', 'page', '/{slug}'];
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

        $tester->execute(['--preset=editorial', "--project-root={$root}", '--yes']);

        self::assertSame(0, $tester->getExitCode(), $tester->getStderr());
        $manifest = new SiteManifestParser()->parse((string) file_get_contents($root . '/.waaseyaa/site.yaml'));
        self::assertSame('example-nation', $manifest->application->id);
        self::assertSame('active', $manifest->capabilities['governed_authoring']->state->value);
        self::assertSame('not_needed', $manifest->capabilities['subscription']->state->value);
        self::assertSame([], $manifest->personalDataStores);
        self::assertSame(hash_file('sha256', $root . '/composer.lock'), $manifest->framework->observedLockSha256);
    }

    private function presetSeed(): string
    {
        return <<<'YAML'
            application:
              id: example
              name: Example
              canonical_origin: {config_key: APP_ORIGIN}
            content_types:
              - {id: page, canonical_route: '/{slug}'}
            YAML;
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
