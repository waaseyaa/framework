<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Site\Blueprint;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Site\Blueprint\ApplicationBlueprintCompiler;
use Waaseyaa\CLI\Site\Blueprint\ApplicationBlueprintCompilerFactory;
use Waaseyaa\CLI\Site\Blueprint\Emitter\BlueprintArtifactEmitterInterface;
use Waaseyaa\CLI\Site\Blueprint\Emitter\BlueprintEmission;
use Waaseyaa\CLI\Site\SiteArtifactRendererFactory;
use Waaseyaa\SiteContract\Blueprint\ApplicationBlueprint;
use Waaseyaa\SiteContract\Blueprint\BlueprintEntity;
use Waaseyaa\SiteContract\Blueprint\BlueprintEntityKeys;
use Waaseyaa\SiteContract\Blueprint\BlueprintField;
use Waaseyaa\SiteContract\Blueprint\BlueprintFieldType;
use Waaseyaa\SiteContract\Blueprint\BlueprintStorage;
use Waaseyaa\SiteContract\Generation\ArtifactSetEvolution;
use Waaseyaa\SiteContract\Generation\Exception\GenerationErrorCode;
use Waaseyaa\SiteContract\Generation\Exception\GenerationRefusalException;
use Waaseyaa\SiteContract\Generation\GeneratedArtifact;
use Waaseyaa\SiteContract\Generation\GenerationUnitDisposition;
use Waaseyaa\SiteContract\Generation\SiteArtifactRenderer;
use Waaseyaa\SiteContract\SiteManifest;
use Waaseyaa\SiteContract\SiteManifestParser;

#[CoversClass(ApplicationBlueprintCompiler::class)]
final class ApplicationBlueprintCompilerTest extends TestCase
{
    #[Test]
    public function baseRowsAreByteIdenticalToTheManifestRendererMinusMetadata(): void
    {
        $manifest = $this->manifest('complete.yaml');
        $plan = ApplicationBlueprintCompilerFactory::create()->compile($manifest);

        $rendered = SiteArtifactRendererFactory::create()->render($manifest);
        foreach ($rendered->artifacts as $path => $artifact) {
            if ($path === '.waaseyaa/generated.json') {
                continue;
            }
            self::assertSame($artifact->content, $this->artifact($plan->artifacts, $path)->content, "Base artifact drifted: {$path}");
            self::assertSame($artifact->mode, $this->artifact($plan->artifacts, $path)->mode);
        }
    }

    #[Test]
    public function planIdentityIsTheCompilerFqcnSiteManagedAdditiveAndTheManifestsGeneratorVersionAndInputDigest(): void
    {
        $manifest = $this->manifest('minimal.yaml');
        $plan = ApplicationBlueprintCompilerFactory::create()->compile($manifest);

        self::assertSame(ApplicationBlueprintCompiler::class, $plan->generatorFqcn);
        self::assertSame('site', $plan->unitId);
        self::assertSame(GenerationUnitDisposition::Managed, $plan->disposition);
        self::assertSame(ArtifactSetEvolution::Additive, $plan->setEvolution);
        self::assertSame($manifest->generatorVersion, $plan->generatorVersion);
        self::assertSame($manifest->digest, $plan->inputDigest);
    }

    #[Test]
    public function twoCompilesOfTheSameManifestAreByteIdentical(): void
    {
        $manifest = $this->manifest('complete.yaml');
        $compiler = ApplicationBlueprintCompilerFactory::create();

        $first = $compiler->compile($manifest);
        $second = $compiler->compile($manifest);

        self::assertSame($first->canonicalJson, $second->canonicalJson);
        self::assertSame($first->digest, $second->digest);
    }

    #[Test]
    public function aBlueprintFreeManifestIsRefused(): void
    {
        $manifest = $this->blueprintFreeManifest();

        $this->expectException(\InvalidArgumentException::class);

        ApplicationBlueprintCompilerFactory::create()->compile($manifest);
    }

    #[Test]
    public function anEmitterOverlappingABasePathIsRefusedAtCompile(): void
    {
        $manifest = $this->manifest('minimal.yaml');
        $overlapping = new class implements BlueprintArtifactEmitterInterface {
            public function id(): string
            {
                return 'overlapping';
            }

            public function emit(ApplicationBlueprint $blueprint, SiteManifest $manifest): BlueprintEmission
            {
                return new BlueprintEmission([new GeneratedArtifact('AGENTS.md', "<!-- collides -->\n")]);
            }
        };
        $compiler = new ApplicationBlueprintCompiler(new SiteArtifactRenderer(), [$overlapping]);

        $this->expectException(\InvalidArgumentException::class);

        $compiler->compile($manifest);
    }

    #[Test]
    public function anEmitterOverlappingAnotherEmitterIsRefusedAtCompile(): void
    {
        $manifest = $this->manifest('minimal.yaml');
        $makeEmitter = static fn(string $id): BlueprintArtifactEmitterInterface => new class ($id) implements BlueprintArtifactEmitterInterface {
            public function __construct(private readonly string $emitterId) {}

            public function id(): string
            {
                return $this->emitterId;
            }

            public function emit(ApplicationBlueprint $blueprint, SiteManifest $manifest): BlueprintEmission
            {
                return new BlueprintEmission([new GeneratedArtifact('src/Generated/Shared.php', "<?php\n")]);
            }
        };
        $compiler = new ApplicationBlueprintCompiler(new SiteArtifactRenderer(), [$makeEmitter('one'), $makeEmitter('two')]);

        $this->expectException(\InvalidArgumentException::class);

        $compiler->compile($manifest);
    }

    #[Test]
    public function aDuplicateEmitterIdIsRefusedAtCompile(): void
    {
        $manifest = $this->manifest('minimal.yaml');
        $duplicate = new class implements BlueprintArtifactEmitterInterface {
            public function id(): string
            {
                return 'entity-class';
            }

            public function emit(ApplicationBlueprint $blueprint, SiteManifest $manifest): BlueprintEmission
            {
                return new BlueprintEmission([new GeneratedArtifact('src/Generated/Dup.php', "<?php\n")]);
            }
        };
        $compiler = new ApplicationBlueprintCompiler(new SiteArtifactRenderer(), [$duplicate, $duplicate]);

        $this->expectException(\InvalidArgumentException::class);

        $compiler->compile($manifest);
    }

    #[Test]
    public function aManifestRequiringATokenOutsideTheCompilersRosterIsGen007(): void
    {
        $parsed = $this->manifest('minimal.yaml');
        // ApplicationBlueprintCompiler::GENERATOR_FEATURES is the closed,
        // fixed roster ['site-application-blueprint-v1']; today's real
        // parser only ever derives that one token. A manifest requiring an
        // additional token the compiler does not advertise is constructed
        // directly to exercise "outside the roster" as its own case, distinct
        // from the "empty roster" case negotiation already covers at the
        // site:init boundary.
        $manifest = new SiteManifest(
            $parsed->schemaVersion,
            $parsed->generatorVersion,
            $parsed->application,
            $parsed->framework,
            $parsed->contentTypes,
            $parsed->capabilities,
            $parsed->personalDataStores,
            $parsed->recipes,
            $parsed->verificationCommand,
            $parsed->canonicalJson,
            $parsed->digest,
            $parsed->applicationBlueprint,
            [...$parsed->requiredGeneratorFeatures, 'some-future-feature-v1'],
        );

        try {
            ApplicationBlueprintCompilerFactory::create()->compile($manifest);
            self::fail('Expected a GenerationRefusalException.');
        } catch (GenerationRefusalException $exception) {
            self::assertSame(GenerationErrorCode::UnsupportedDeclaration, $exception->violations[0]->code);
            self::assertStringContainsString('some-future-feature-v1', $exception->violations[0]->message);
        }
    }

    /**
     * F3: the SITE0xx grammar (`^[a-z][a-z0-9_-]*$`) permits a hyphen a PHP
     * identifier cannot represent. Before this fix, a hyphenated entity id
     * reached `EntityClassEmitter::safeId()` and crashed with a bare
     * `\InvalidArgumentException` no CLI envelope could carry a code or
     * pointer for. The compiler now refuses it itself, coded, before
     * invoking any emitter.
     */
    #[Test]
    public function aHyphenatedEntityIdIsRefusedGen006BeforeAnyEmitterRuns(): void
    {
        $blueprint = new ApplicationBlueprint(
            contractVersion: 1,
            entities: [
                'blog-post' => new BlueprintEntity(
                    id: 'blog-post',
                    label: 'Blog Post',
                    storage: BlueprintStorage::SqlBlob,
                    revisionable: false,
                    translatable: false,
                    keys: new BlueprintEntityKeys(id: 'id', uuid: 'uuid', label: 'title'),
                    fields: [
                        'title' => new BlueprintField('title', BlueprintFieldType::String, true, 1, false, false, false),
                    ],
                ),
            ],
            relationships: [],
            permissions: [],
            roles: [],
            policies: [],
            workflows: [],
            fixtures: [],
            checks: [],
        );
        $parsed = $this->manifest('minimal.yaml');
        $manifest = new SiteManifest(
            $parsed->schemaVersion,
            $parsed->generatorVersion,
            $parsed->application,
            $parsed->framework,
            $parsed->contentTypes,
            $parsed->capabilities,
            $parsed->personalDataStores,
            $parsed->recipes,
            $parsed->verificationCommand,
            $parsed->canonicalJson,
            $parsed->digest,
            $blueprint,
            [ApplicationBlueprintCompiler::GENERATOR_FEATURES[0]],
        );

        try {
            ApplicationBlueprintCompilerFactory::create()->compile($manifest);
            self::fail('Expected a GenerationRefusalException.');
        } catch (GenerationRefusalException $exception) {
            self::assertSame(GenerationErrorCode::MaliciousIdentifier, $exception->violations[0]->code);
            self::assertStringContainsString('blog-post', $exception->violations[0]->message);
        }
    }

    private function artifact(array $artifacts, string $path): GeneratedArtifact
    {
        foreach ($artifacts as $artifact) {
            if ($artifact->path === $path) {
                return $artifact;
            }
        }
        self::fail("No plan artifact at {$path}");
    }

    private function manifest(string $fixture): SiteManifest
    {
        $yaml = (string) file_get_contents(
            \dirname(__DIR__, 5) . '/site-contract/tests/Fixtures/Blueprint/valid/' . $fixture,
        );

        return new SiteManifestParser()->parse($yaml, $fixture);
    }

    private function blueprintFreeManifest(): SiteManifest
    {
        return new SiteManifestParser()->parse(<<<'YAML'
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
                state: not_needed
                reason: Not needed for this test.
            personal_data_stores: []
            recipes: []
            verification:
              command: bin/maintenance/site-verify
            YAML);
    }
}
