<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\CLI\Command\HandlerArgument;
use Waaseyaa\CLI\Command\HandlerArgumentMode;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Handler\MakeSearchProjectionHandler;
use Waaseyaa\CLI\Provider\MakeServiceProviderB;
use Waaseyaa\CLI\Site\Scaffold\SearchProjectionScaffoldCompiler;
use Waaseyaa\CLI\Site\SiteInitializationService;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Search\ProvidesEntitySearchProjectorsInterface;
use Waaseyaa\SiteContract\CanonicalJson;
use Waaseyaa\SiteContract\Generation\GenerationUnitDisposition;
use Waaseyaa\SiteContract\Generation\SiteArtifactRenderer;
use Waaseyaa\SiteContract\SiteManifestParser;

/**
 * #2849: `make:search-projection` publishes through the shared generation
 * custody, exactly mirroring the already-migrated `make:content-type`
 * (`MakeContentTypeCustodyTest`).
 *
 * ADR-025 D-2.2 makes the scaffold a **seeded** unit — published exactly
 * once, then owned by the developer and never re-rendered — and D-6.6
 * carries its provider registration as a plan-borne merge instruction
 * enacted inside the same transaction as the file writes, rather than a
 * `json_decode`/mutate/`json_encode` of the application's own manifest.
 */
#[CoversClass(SearchProjectionScaffoldCompiler::class)]
#[CoversClass(MakeSearchProjectionHandler::class)]
#[CoversClass(MakeServiceProviderB::class)]
final class MakeSearchProjectionCustodyTest extends TestCase
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
    public function theScaffoldPublishesAsASeededUnitRecordedInGeneratedOwnership(): void
    {
        $root = $this->initializedProject();

        $tester = $this->runMake($root, ['entity-type' => 'story', '--fields' => 'body']);

        self::assertSame(0, $tester->getExitCode(), $tester->getStderr());
        $metadata = $this->ownership($root);
        $unit = $this->unit($metadata, 'scaffold:search-projection:story');
        self::assertSame(GenerationUnitDisposition::Seeded->value, $unit['disposition']);
        self::assertSame(SearchProjectionScaffoldCompiler::class, $unit['generator']['fqcn']);
        self::assertSame(SearchProjectionScaffoldCompiler::GENERATOR_VERSION, $unit['generator']['version']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $unit['input_digest']);

        $owned = [];
        foreach ($metadata['artifacts'] as $row) {
            if (($row['unit'] ?? 'site') === 'scaffold:search-projection:story') {
                $owned[] = $row['path'];
            }
        }
        self::assertSame(
            ['src/Provider/StorySearchServiceProvider.php', 'src/Search/StorySearchProjector.php', 'tests/Search/StorySearchProjectorTest.php'],
            $owned,
            'The unit owns exactly the projector, its provider binding and its companion test.',
        );
        self::assertSame(
            [['fqcn' => 'App\\Provider\\StorySearchServiceProvider', 'unit' => 'scaffold:search-projection:story']],
            $metadata['registrations'],
            'The provider registration is owned by the unit that declared it.',
        );

        // Create-on-first-use: an initialized project has neither src/Search nor
        // tests/Search, so the roster is only half the claim — the bytes must be
        // on disk, under directories this publish created.
        foreach ($owned as $relative) {
            self::assertFileExists($root . '/' . $relative);
        }
        self::assertDirectoryExists($root . '/src/Search');
        self::assertDirectoryExists($root . '/tests/Search');
    }

    #[Test]
    public function theCompilerIsAPureFunctionOfItsValidatedInput(): void
    {
        $compiler = new SearchProjectionScaffoldCompiler();

        $first = $compiler->compile('story', 'Story', ['body']);
        $second = $compiler->compile('story', 'Story', ['body']);
        $other = $compiler->compile('story', 'Story', ['body', 'summary']);

        self::assertSame($first->digest, $second->digest, 'The same validated input must compile to byte-identical plans.');
        self::assertSame($first->canonicalJson, $second->canonicalJson);
        self::assertSame(
            CanonicalJson::encode($first->toArray()),
            CanonicalJson::encode($second->toArray()),
        );
        self::assertSame($first->companionTests, $second->companionTests);
        foreach ($first->artifacts as $index => $artifact) {
            self::assertSame($artifact->path, $second->artifacts[$index]->path);
            self::assertSame($artifact->content, $second->artifacts[$index]->content, "Artifact content for {$artifact->path} must be byte-identical across runs.");
        }
        self::assertNotSame($first->digest, $other->digest, 'A different field set is a different plan.');
        self::assertSame(GenerationUnitDisposition::Seeded, $first->disposition);
        self::assertSame('scaffold:search-projection:story', $first->unitId);
        self::assertSame(['App\\Provider\\StorySearchServiceProvider'], array_map(
            static fn(object $registration): string => $registration->fqcn,
            $first->registrations,
        ));
    }

    #[Test]
    public function thePlanDeclaresItsCompanionTest(): void
    {
        $compiler = new SearchProjectionScaffoldCompiler();

        $plan = $compiler->compile('story', 'Story', ['body']);

        self::assertSame(['tests/Search/StorySearchProjectorTest.php'], $plan->companionTests);
        $paths = array_map(static fn(object $artifact): string => $artifact->path, $plan->artifacts);
        foreach ($plan->companionTests as $companionTest) {
            self::assertContains($companionTest, $paths);
        }
    }

    #[Test]
    public function aSecondSearchProviderIsRefused(): void
    {
        $root = $this->initializedProject();
        $first = $this->runMake($root, ['entity-type' => 'story', '--fields' => 'body']);
        self::assertSame(0, $first->getExitCode(), $first->getStderr());

        // A real second process boots the container `MakeServiceProviderB`
        // resolves ProvidesEntitySearchProjectorsInterface from, discovering
        // the provider the first scaffold just registered in composer.json.
        // This test's stub container never boots the application, so the
        // resolved instance is injected directly, proving the handler's own
        // refusal wiring without a real kernel boot.
        $existingProvider = $this->existingSearchProjectorProvider();

        $tester = $this->runMake($root, ['entity-type' => 'report', '--fields' => 'body'], $existingProvider);

        self::assertSame(1, $tester->getExitCode());
        $output = $tester->getStderr() !== '' ? $tester->getStderr() : $tester->getStdout();
        self::assertStringContainsString('already registered as', $output);
        self::assertStringContainsString($existingProvider::class, $output);
        self::assertFileDoesNotExist($root . '/src/Search/ReportSearchProjector.php');
    }

    #[Test]
    public function anInjectedBootResolvedProviderIsRefusedBeforeAnyWrite(): void
    {
        $root = $this->initializedProject();
        $before = $this->ownership($root);
        $existingProvider = $this->existingSearchProjectorProvider();

        $tester = $this->runMake($root, ['entity-type' => 'story', '--fields' => 'body'], $existingProvider);

        self::assertSame(1, $tester->getExitCode());
        $output = $tester->getStderr() !== '' ? $tester->getStderr() : $tester->getStdout();
        self::assertStringContainsString('already registered as', $output);
        self::assertStringContainsString($existingProvider::class, $output);
        self::assertStringContainsString('entitySearchProjectors()', $output);

        // Refusal happens before compiling or writing anything: no artifact
        // and no roster mutation, exactly like the other pre-write refusals.
        self::assertDirectoryDoesNotExist($root . '/src/Search');
        self::assertSame($before, $this->ownership($root));
    }

    #[Test]
    public function anInertUnregisteredFileMentioningTheInterfaceDoesNotFalselyRefuse(): void
    {
        $root = $this->initializedProject();
        mkdir($root . '/src/Provider', 0o755, true);
        file_put_contents(
            $root . '/src/Provider/UnrelatedNotes.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Provider;

                // Mentions ProvidesEntitySearchProjectorsInterface only in a comment; it
                // implements nothing and is never registered in composer.json. Detection
                // no longer scans source files, so this file has no effect either way.
                final class UnrelatedNotes
                {
                }

                PHP,
        );

        $tester = $this->runMake($root, ['entity-type' => 'story', '--fields' => 'body']);

        self::assertSame(0, $tester->getExitCode(), $tester->getStderr());
        self::assertFileExists($root . '/src/Search/StorySearchProjector.php');
    }

    #[Test]
    public function theRealCommandProviderRefusesItsBootResolvedSearchProvider(): void
    {
        $root = $this->initializedProject();
        $before = $this->ownership($root);
        $existingProvider = $this->existingSearchProjectorProvider();
        $services = new readonly class ($existingProvider) implements KernelServicesInterface {
            public function __construct(private ProvidesEntitySearchProjectorsInterface $existingProvider) {}

            public function get(string $abstract): ?object
            {
                return $abstract === ProvidesEntitySearchProjectorsInterface::class
                    ? $this->existingProvider
                    : null;
            }
        };

        $tester = $this->runProviderMake($root, $services);

        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('already registered as', $tester->getStderr() . $tester->getStdout());
        self::assertDirectoryDoesNotExist($root . '/src/Search');
        self::assertSame($before, $this->ownership($root));
    }

    #[Test]
    public function theRealCommandProviderFailsClosedWhenARegisteredProviderCannotResolve(): void
    {
        $root = $this->initializedProject();
        $before = $this->ownership($root);
        $services = new class implements KernelServicesInterface {
            public function get(string $abstract): ?object
            {
                if ($abstract === ProvidesEntitySearchProjectorsInterface::class) {
                    throw new \RuntimeException('sensitive provider construction detail');
                }

                return null;
            }
        };

        $tester = $this->runProviderMake($root, $services);

        self::assertSame(1, $tester->getExitCode());
        $output = $tester->getStderr() . $tester->getStdout();
        self::assertStringContainsString('could not be resolved', $output);
        self::assertStringNotContainsString('sensitive provider construction detail', $output);
        self::assertDirectoryDoesNotExist($root . '/src/Search');
        self::assertSame($before, $this->ownership($root));
    }

    /** A stand-in for whatever provider instance a real boot would resolve. */
    private function existingSearchProjectorProvider(): ProvidesEntitySearchProjectorsInterface
    {
        return new class implements ProvidesEntitySearchProjectorsInterface {
            public function entitySearchProjectors(): array
            {
                return [];
            }
        };
    }

    #[Test]
    public function anUninitializedProjectIsRefusedByCustodyWithoutWriting(): void
    {
        $root = $this->project();

        $tester = $this->runMake($root, ['entity-type' => 'story', '--fields' => 'body']);

        self::assertSame(1, $tester->getExitCode());
        self::assertDirectoryDoesNotExist($root . '/src/Search');
    }

    #[Test]
    public function theGeneratedProjectorReadsThroughTheGuardedAccessor(): void
    {
        $compiler = new SearchProjectionScaffoldCompiler();
        $plan = $compiler->compile('story', 'Story', ['body']);

        $projector = null;
        foreach ($plan->artifacts as $artifact) {
            if ($artifact->path === 'src/Search/StorySearchProjector.php') {
                $projector = $artifact->content;
            }
        }
        self::assertNotNull($projector, 'The plan must contain the generated projector.');

        self::assertStringContainsString('catch (FieldReadDenied|MissingFieldReadContext)', $projector);
        self::assertStringNotContainsString('$entity->body', $projector, 'The generated projector must not read fields via direct property access.');
        self::assertStringContainsString('new SearchDocument(', $projector);
        self::assertStringContainsString('SearchTextNormalizer::normalize(', $projector);
    }

    /** @return array<string, mixed> */
    #[Test]
    #[DataProvider('rejectedInvocations')]
    public function invalidInputIsRefusedBeforeAnythingIsPublished(array $argv, string $expected): void
    {
        $root = $this->initializedProject();

        $tester = $this->runMake($root, $argv);

        self::assertSame(1, $tester->getExitCode());
        $output = $tester->getStderr() . $tester->getStdout();
        self::assertStringContainsString($expected, $output);
        // Refusal is refusal: no artifact, and no unit recorded against the roster.
        self::assertDirectoryDoesNotExist($root . '/src/Search');
        self::assertSame([], array_filter(
            $this->ownership($root)['artifacts'],
            static fn(array $row): bool => str_starts_with((string) ($row['unit'] ?? 'site'), 'scaffold:search-projection'),
        ));
    }

    /** @return iterable<string, array{list<string>|array<string, string>, string}> */
    public static function rejectedInvocations(): iterable
    {
        yield 'field name is not snake_case' => [
            ['entity-type' => 'story', '--fields' => 'Body'],
            'Body',
        ];
        yield 'duplicate field' => [
            ['entity-type' => 'story', '--fields' => 'body,body'],
            'body',
        ];
        yield 'no fields at all' => [
            ['entity-type' => 'story', '--fields' => ' '],
            'at least one field',
        ];
    }

    #[Test]
    public function anAbsentSearchCapabilityIsRefusedAndLeavesTheApplicationUnchanged(): void
    {
        $root = $this->initializedProject();
        $before = $this->ownership($root);

        $command = new HandlerCommand(
            name: 'make:search-projection',
            description: 'Scaffold a search projector',
            arguments: [new HandlerArgument(name: 'entity-type', mode: HandlerArgumentMode::Required, description: 'entity type id')],
            options: [
                new HandlerOption(name: 'fields', mode: HandlerOptionMode::Required, description: 'fields', default: 'body'),
                new HandlerOption(name: 'force', mode: HandlerOptionMode::None, description: 'force'),
            ],
            handler: \Closure::fromCallable([
                new MakeSearchProjectionHandler(
                    projectRoot: $root,
                    // Stand in for a target without waaseyaa/search installed.
                    requiredSearchSymbols: ['Waaseyaa\\Search\\Projection\\AbsentOnPurpose'],
                ),
                'execute',
            ]),
        );
        $container = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \RuntimeException('not used');
            }

            public function has(string $id): bool
            {
                return false;
            }
        };

        $tester = CliTester::for($command, $container)->executeMap(['entity-type' => 'story', '--fields' => 'body']);

        self::assertSame(1, $tester->getExitCode());
        $output = $tester->getStderr() . $tester->getStdout();
        self::assertStringContainsString('does not provide the search extension surface', $output);
        self::assertStringContainsString('Waaseyaa\\Search\\Projection\\AbsentOnPurpose', $output);
        self::assertStringContainsString('waaseyaa/search', $output);

        // Unchanged application: no artifact, no roster mutation, no registration.
        self::assertDirectoryDoesNotExist($root . '/src/Search');
        self::assertSame($before, $this->ownership($root));
    }

    private function ownership(string $root): array
    {
        return json_decode((string) file_get_contents($root . '/.waaseyaa/generated.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function unit(array $metadata, string $id): array
    {
        foreach ($metadata['units'] ?? [] as $unit) {
            if ($unit['id'] === $id) {
                return $unit;
            }
        }

        self::fail("The generated ownership document does not record the {$id} unit.");
    }

    /** @param array<string, mixed> $argv */
    private function runMake(string $root, array $argv, ?ProvidesEntitySearchProjectorsInterface $existingSearchProjectorProvider = null): CliTester
    {
        $command = new HandlerCommand(
            name: 'make:search-projection',
            description: 'Scaffold a search projector',
            arguments: [new HandlerArgument(name: 'entity-type', mode: HandlerArgumentMode::Required, description: 'entity type id')],
            options: [
                new HandlerOption(name: 'fields', mode: HandlerOptionMode::Required, description: 'fields', default: 'body'),
                new HandlerOption(name: 'force', mode: HandlerOptionMode::None, description: 'force'),
            ],
            handler: \Closure::fromCallable([
                new MakeSearchProjectionHandler(projectRoot: $root, existingSearchProjectorProvider: $existingSearchProjectorProvider),
                'execute',
            ]),
        );
        $container = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \RuntimeException('not used');
            }

            public function has(string $id): bool
            {
                return false;
            }
        };

        return CliTester::for($command, $container)->executeMap($argv);
    }

    private function runProviderMake(string $root, KernelServicesInterface $services): CliTester
    {
        $provider = new MakeServiceProviderB();
        $provider->setKernelContext($root, [], []);
        $provider->setKernelServices($services);
        $commands = iterator_to_array($provider->consoleCommands(), false);
        $command = array_values(array_filter(
            $commands,
            static fn(HandlerCommand $candidate): bool => $candidate->getName() === 'make:search-projection',
        ))[0];

        $container = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \RuntimeException('not used');
            }

            public function has(string $id): bool
            {
                return false;
            }
        };

        return CliTester::for($command, $container)->executeMap([
            'entity-type' => 'story',
            '--fields' => 'body',
        ]);
    }

    private function project(): string
    {
        $root = sys_get_temp_dir() . '/waaseyaa_msp_custody_' . bin2hex(random_bytes(8));
        mkdir($root, 0o755, true);
        $this->roots[] = $root;
        file_put_contents($root . '/composer.lock', "{}\n");
        file_put_contents(
            $root . '/composer.json',
            (string) json_encode(['name' => 'app/app', 'autoload' => ['psr-4' => ['App\\' => 'src/']]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        );

        return $root;
    }

    private function initializedProject(): string
    {
        $root = $this->project();
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
        new SiteInitializationService($root)->initialize(
            new SiteArtifactRenderer()->render(new SiteManifestParser()->parse($manifest)),
        );

        return $root;
    }
}
