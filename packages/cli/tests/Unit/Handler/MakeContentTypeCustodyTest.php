<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\CLI\Command\HandlerArgument;
use Waaseyaa\CLI\Command\HandlerArgumentMode;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Handler\MakeContentTypeHandler;
use Waaseyaa\CLI\Site\Scaffold\ContentTypeScaffoldCompiler;
use Waaseyaa\CLI\Site\SiteInitializationService;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\SiteContract\Generation\ArtifactSetEvolution;
use Waaseyaa\SiteContract\Generation\GenerationUnitDisposition;
use Waaseyaa\SiteContract\Generation\SiteArtifactRenderer;
use Waaseyaa\SiteContract\SiteManifestParser;

/**
 * #2789 phase 2: `make:content-type` publishes through the shared generation
 * custody instead of writing files and rewriting `composer.json` itself.
 *
 * ADR-025 D-2.2 makes the scaffold a **seeded** unit — published exactly once,
 * then owned by the developer and never re-rendered — and D-6.6 carries its
 * provider registration as a plan-borne merge instruction enacted inside the
 * same transaction as the file writes, rather than a `json_decode`/mutate/
 * `json_encode` of the application's own manifest.
 */
#[CoversClass(ContentTypeScaffoldCompiler::class)]
final class MakeContentTypeCustodyTest extends TestCase
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

        $tester = $this->runMake($root, ['name' => 'story', '--fields' => 'title:string,body:text']);

        self::assertSame(0, $tester->getExitCode(), $tester->getStderr());
        $metadata = $this->ownership($root);
        $unit = $this->unit($metadata, 'scaffold:content-type:story');
        self::assertSame(GenerationUnitDisposition::Seeded->value, $unit['disposition']);
        self::assertSame(ContentTypeScaffoldCompiler::class, $unit['generator']['fqcn']);
        self::assertSame(ContentTypeScaffoldCompiler::GENERATOR_VERSION, $unit['generator']['version']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $unit['input_digest']);

        $owned = [];
        foreach ($metadata['artifacts'] as $row) {
            if (($row['unit'] ?? 'site') === 'scaffold:content-type:story') {
                $owned[] = $row['path'];
            }
        }
        self::assertSame(['src/Entity/Story.php', 'src/Provider/StoryServiceProvider.php'], $owned);
        self::assertSame(
            [['fqcn' => 'App\\Provider\\StoryServiceProvider', 'unit' => 'scaffold:content-type:story']],
            $metadata['registrations'],
            'The provider registration is owned by the unit that declared it.',
        );
    }

    #[Test]
    public function theCompilerIsAPureFunctionOfItsValidatedInput(): void
    {
        $fields = [['name' => 'title', 'type' => 'string', 'target' => null]];
        $compiler = new ContentTypeScaffoldCompiler(new \Waaseyaa\Field\FieldScaffoldProjection(new \Waaseyaa\Field\FieldTypeManager()));

        $first = $compiler->compile('story', 'Story', $fields);
        $second = $compiler->compile('story', 'Story', $fields);
        $other = $compiler->compile('story', 'Story', [...$fields, ['name' => 'body', 'type' => 'text', 'target' => null]]);

        self::assertSame($first->digest, $second->digest, 'The same validated input must compile to byte-identical plans.');
        self::assertSame($first->canonicalJson, $second->canonicalJson);
        self::assertNotSame($first->digest, $other->digest, 'A different field set is a different plan.');
        self::assertSame(GenerationUnitDisposition::Seeded, $first->disposition);
        self::assertSame(ArtifactSetEvolution::Frozen, $first->setEvolution);
        self::assertSame('scaffold:content-type:story', $first->unitId);
        self::assertSame(['App\\Provider\\StoryServiceProvider'], array_map(
            static fn(object $registration): string => $registration->fqcn,
            $first->registrations,
        ));
    }

    #[Test]
    public function theGeneratorIsAdmittedToCreateSeededUnits(): void
    {
        $admitted = new \ReflectionClass(SiteInitializationService::class)->getConstant('SEEDED_COMPILERS');

        self::assertContains(
            ContentTypeScaffoldCompiler::class,
            $admitted,
            'A seeded unit may only be created by a compiler on the closed admission list.',
        );
    }

    #[Test]
    public function anUnownedCollidingTargetIsRefusedByCustodyAndItsBytesSurvive(): void
    {
        $root = $this->initializedProject();
        mkdir($root . '/src/Entity', 0o755, true);
        $foreign = "<?php\n\n// hand-written, not generated\n";
        file_put_contents($root . '/src/Entity/Story.php', $foreign);

        $tester = $this->runMake($root, ['name' => 'story', '--fields' => 'title:string', '--force' => true]);

        self::assertSame(1, $tester->getExitCode());
        self::assertSame($foreign, file_get_contents($root . '/src/Entity/Story.php'));
        self::assertFileDoesNotExist($root . '/src/Provider/StoryServiceProvider.php');
        self::assertSame([], $this->ownedPaths($root), 'A refused scaffold records no ownership.');
    }

    #[Test]
    public function aSeededUnitIsNeverReRenderedSoDeveloperEditsSurvive(): void
    {
        $root = $this->initializedProject();
        $this->runMake($root, ['name' => 'story', '--fields' => 'title:string']);
        $entityPath = $root . '/src/Entity/Story.php';
        $edited = (string) file_get_contents($entityPath) . "\n// developer edit\n";
        file_put_contents($entityPath, $edited);

        $tester = $this->runMake($root, ['name' => 'story', '--fields' => 'title:string', '--force' => true]);

        self::assertSame(0, $tester->getExitCode(), $tester->getStderr());
        self::assertSame($edited, file_get_contents($entityPath), 'A seeded unit is published once and then owned by the developer.');
    }

    #[Test]
    public function theProviderRegistrationTravelsInThePlanAndPreservesForeignComposerBytes(): void
    {
        $root = $this->initializedProject();
        $composerPath = $root . '/composer.json';
        $authored = "{\n  \"name\": \"app/app\",\n  \"autoload\": {\n    \"psr-4\": {\n      \"App\\\\\": \"src/\"\n    }\n  }\n}\n";
        file_put_contents($composerPath, $authored);

        $tester = $this->runMake($root, ['name' => 'story', '--fields' => 'title:string']);

        self::assertSame(0, $tester->getExitCode(), $tester->getStderr());
        $merged = (string) file_get_contents($composerPath);
        self::assertStringContainsString('App\\\\Provider\\\\StoryServiceProvider', $merged);
        // The leading newline matters: a whole-file re-encode would indent this
        // line with four spaces and still satisfy a bare substring match.
        self::assertStringContainsString("\n  \"name\": \"app/app\",\n", $merged, 'The application\'s own two-space formatting survives the merge.');
        $decoded = json_decode($merged, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['App\\Provider\\StoryServiceProvider'], $decoded['extra']['waaseyaa']['providers']);
        self::assertSame(['psr-4' => ['App\\' => 'src/']], $decoded['autoload'], 'Foreign manifest keys are untouched.');
    }

    #[Test]
    public function anUninitializedProjectIsRefusedByCustodyWithoutWriting(): void
    {
        $root = $this->project();

        $tester = $this->runMake($root, ['name' => 'story', '--fields' => 'title:string']);

        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('requires an initialized site', $tester->getStderr());
        self::assertDirectoryDoesNotExist($root . '/src/Entity');
        self::assertDirectoryDoesNotExist($root . '/src/Provider');
    }

    /** @return array<string, mixed> */
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

    /** @return list<string> */
    private function ownedPaths(string $root): array
    {
        $paths = [];
        foreach ($this->ownership($root)['artifacts'] as $row) {
            if (($row['unit'] ?? 'site') !== 'site') {
                $paths[] = $row['path'];
            }
        }

        return $paths;
    }

    /** @param array<string, mixed> $argv */
    private function runMake(string $root, array $argv): CliTester
    {
        $command = new HandlerCommand(
            name: 'make:content-type',
            description: 'Scaffold a content type',
            arguments: [new HandlerArgument(name: 'name', mode: HandlerArgumentMode::Required, description: 'name')],
            options: [
                new HandlerOption(name: 'fields', mode: HandlerOptionMode::Required, description: 'fields', default: 'title:string'),
                new HandlerOption(name: 'force', mode: HandlerOptionMode::None, description: 'force'),
            ],
            handler: \Closure::fromCallable([new MakeContentTypeHandler(projectRoot: $root, fieldProjection: new \Waaseyaa\Field\FieldScaffoldProjection(new \Waaseyaa\Field\FieldTypeManager())), 'execute']),
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

    private function project(): string
    {
        $root = sys_get_temp_dir() . '/waaseyaa_mct_custody_' . bin2hex(random_bytes(8));
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
