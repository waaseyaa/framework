<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Site\Blueprint;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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
#[CoversClass(ApplicationBlueprintCompilerFactory::class)]
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

    /**
     * #2788 (01E): the roster grows from three emitter ids (01D-1) to eight,
     * all additive — the compiler itself is untouched (pinned below by
     * {@see self::theCompilerFileIsByteIdenticalToThe01D1Baseline()}). Two
     * distinct `ComposerProviderRegistration` FQCNs compose without
     * collision (`ProviderRegistrationEmitter`'s content provider,
     * `GovernanceProviderEmitter`'s governance provider), and every
     * governance artifact path lands in the sorted plan.
     */
    #[Test]
    public function theEightEmitterRosterComposesAdditivelyWithTwoDistinctProviderRegistrations(): void
    {
        $manifest = $this->manifest('complete.yaml');
        $plan = ApplicationBlueprintCompilerFactory::create()->compile($manifest);

        $paths = array_map(static fn(GeneratedArtifact $artifact): string => $artifact->path, $plan->artifacts);
        self::assertContains('src/Access/ApplicationBlueprintPermissions.php', $paths);
        self::assertContains('src/Access/ArticlePolicy.php', $paths);
        self::assertContains('src/Workflow/EditorialWorkflowDefinition.php', $paths);
        self::assertContains('config/sync/workflows.assignments.yml', $paths);
        self::assertContains('src/Provider/ApplicationBlueprintGovernanceServiceProvider.php', $paths);
        self::assertContains('tests/Blueprint/GovernanceDefaultDenyTest.php', $paths);
        self::assertSame($paths, array_unique($paths), 'No two emitters may claim the same path.');

        self::assertCount(2, $plan->registrations);
        $fqcns = array_map(static fn($registration): string => $registration->fqcn, $plan->registrations);
        self::assertSame(array_unique($fqcns), $fqcns);
        self::assertContains('App\\Provider\\ApplicationBlueprintServiceProvider', $fqcns);
        self::assertContains('App\\Provider\\ApplicationBlueprintGovernanceServiceProvider', $fqcns);

        self::assertSame(
            [
                'tests/Blueprint/EntityAccessChecksTest.php',
                'tests/Blueprint/GovernanceDefaultDenyTest.php',
                'tests/Blueprint/JsonApiGovernanceChecksTest.php',
                'tests/Blueprint/RolePermissionChecksTest.php',
                'tests/Blueprint/WorkflowTransitionChecksTest.php',
            ],
            $plan->companionTests,
        );
    }

    /**
     * #2788 (01E) is additive-only per its own emitter roster (decision (f)):
     * it must never edit `ApplicationBlueprintCompiler.php` itself. Pins the
     * exact byte content accepted main carries after 01D-2 (main
     * `d64a825fc`, PR #2937 merge) so an accidental edit fails loudly here
     * instead of only showing up as an unexplained diff in review. Re-pin
     * only when a #2787 slice legitimately changes the compiler.
     */
    #[Test]
    public function theCompilerFileIsByteIdenticalToThe01D1Baseline(): void
    {
        $path = \dirname(__DIR__, 4) . '/src/Site/Blueprint/ApplicationBlueprintCompiler.php';
        self::assertSame(
            '815319f5f260ed28b1c4d7ee48e750ab1cf2326ba64cf77ec72215458d8032a7',
            hash('sha256', (string) file_get_contents($path)),
            'ApplicationBlueprintCompiler.php must not be edited by an additive emitter slice.',
        );
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

    /**
     * R2-1: an entity id can pass the SITE0xx grammar and the hyphen check
     * above and still PascalCase to a name PHP refuses to declare as a
     * class. `string` is one of the "other reserved words" the PHP manual
     * lists (semantic refusal, not a parser-level keyword) — before this
     * fix `EntityClassEmitter` produced `src/Entity/String.php` and the
     * consumer kernel fataled loading it: "Cannot use 'String' as a class
     * name as it is reserved".
     */
    #[Test]
    public function anEntityIdThatPascalCasesToAnOtherReservedWordIsRefusedGen006(): void
    {
        $manifest = $this->manifestWithBlueprint($this->blueprintWithEntities([
            $this->simpleEntity('string'),
        ]));

        try {
            ApplicationBlueprintCompilerFactory::create()->compile($manifest);
            self::fail('Expected a GenerationRefusalException.');
        } catch (GenerationRefusalException $exception) {
            self::assertSame(GenerationErrorCode::MaliciousIdentifier, $exception->violations[0]->code);
            self::assertStringContainsString('String', $exception->violations[0]->message);
        }
    }

    /**
     * R2-1: `parent` is a true PHP keyword (not merely an "other reserved
     * word" like `string`), covering the other half of the reserved-name
     * table {@see ApplicationBlueprintCompiler::RESERVED_CLASS_NAMES}
     * combines. Before this fix: "Cannot use 'Parent' as a class name as it
     * is reserved".
     */
    #[Test]
    public function anEntityIdThatPascalCasesToAKeywordReservedWordIsRefusedGen006(): void
    {
        $manifest = $this->manifestWithBlueprint($this->blueprintWithEntities([
            $this->simpleEntity('parent'),
        ]));

        try {
            ApplicationBlueprintCompilerFactory::create()->compile($manifest);
            self::fail('Expected a GenerationRefusalException.');
        } catch (GenerationRefusalException $exception) {
            self::assertSame(GenerationErrorCode::MaliciousIdentifier, $exception->violations[0]->code);
            self::assertStringContainsString('Parent', $exception->violations[0]->message);
        }
    }

    /**
     * Codex immutable review of 0d5ec6ecb: `die` and `eval` are language
     * constructs the manual lists among the reserved keywords but that were
     * missing from {@see ApplicationBlueprintCompiler::RESERVED_CLASS_NAMES}.
     * Both pass the id grammar, and PHP 8.5 rejects `final class Die`
     * / `final class Eval` at parse time, so they must be refused GEN006
     * at the identifier check, before any emitter runs.
     */
    #[Test]
    #[DataProvider('languageConstructEntityIds')]
    public function anEntityIdThatPascalCasesToALanguageConstructIsRefusedGen006BeforeAnyEmitterRuns(string $entityId, string $className): void
    {
        $manifest = $this->manifestWithBlueprint($this->blueprintWithEntities([
            $this->simpleEntity($entityId),
        ]));

        try {
            ApplicationBlueprintCompilerFactory::create()->compile($manifest);
            self::fail('Expected a GenerationRefusalException.');
        } catch (GenerationRefusalException $exception) {
            self::assertCount(1, $exception->violations);
            self::assertSame(GenerationErrorCode::MaliciousIdentifier, $exception->violations[0]->code);
            self::assertStringContainsString($className, $exception->violations[0]->message);
            self::assertSame("/application_blueprint/entities/{$entityId}/id", $exception->violations[0]->pointer);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function languageConstructEntityIds(): iterable
    {
        yield 'die' => ['die', 'Die'];
        yield 'eval' => ['eval', 'Eval'];
    }

    /**
     * R2-1/R2-2: two grammar-valid, non-colliding entity ids can still
     * PascalCase to the exact same class name — `blog_post` and
     * `blog__post` both become `BlogPost` — which `EntityClassEmitter`
     * previously only caught as an uncoded path-collision
     * `\InvalidArgumentException` deep inside the emitter.
     */
    #[Test]
    public function twoEntityIdsPascalCasingToTheSameClassNameAreRefusedGen006(): void
    {
        $manifest = $this->manifestWithBlueprint($this->blueprintWithEntities([
            $this->simpleEntity('blog_post'),
            $this->simpleEntity('blog__post'),
        ]));

        try {
            ApplicationBlueprintCompilerFactory::create()->compile($manifest);
            self::fail('Expected a GenerationRefusalException.');
        } catch (GenerationRefusalException $exception) {
            self::assertSame(GenerationErrorCode::MaliciousIdentifier, $exception->violations[0]->code);
            self::assertStringContainsString('BlogPost', $exception->violations[0]->message);
        }
    }

    /**
     * R2-1/R2-2: an enum field's declared value is free-form authored text,
     * not an identifier. `"in progress"` PascalCases to `"In progress"`
     * (`EntityClassEmitter::pascalCase()` only capitalizes after `_`, never
     * after a space), which is not a valid PHP enum case name — previously
     * an uncoded `\InvalidArgumentException` from `enumCaseName()`.
     */
    #[Test]
    public function anEnumValueThatCannotBecomeAPhpEnumCaseNameIsRefusedGen006(): void
    {
        $manifest = $this->manifestWithBlueprint($this->blueprintWithEntities([
            $this->simpleEntity('article', enumField: 'status', enumValues: ['in progress']),
        ]));

        try {
            ApplicationBlueprintCompilerFactory::create()->compile($manifest);
            self::fail('Expected a GenerationRefusalException.');
        } catch (GenerationRefusalException $exception) {
            self::assertSame(GenerationErrorCode::MaliciousIdentifier, $exception->violations[0]->code);
            self::assertStringContainsString('in progress', $exception->violations[0]->message);
        }
    }

    /**
     * R2-1: an enum case literally named `class` is a distinct PHP fatal
     * from an ungrammatical case name — "A class constant must not be
     * called 'class'" — because enum cases are backed by class constants.
     */
    #[Test]
    public function anEnumValueOfClassIsRefusedGen006(): void
    {
        $manifest = $this->manifestWithBlueprint($this->blueprintWithEntities([
            $this->simpleEntity('article', enumField: 'status', enumValues: ['class']),
        ]));

        try {
            ApplicationBlueprintCompilerFactory::create()->compile($manifest);
            self::fail('Expected a GenerationRefusalException.');
        } catch (GenerationRefusalException $exception) {
            self::assertSame(GenerationErrorCode::MaliciousIdentifier, $exception->violations[0]->code);
            self::assertStringContainsString('class', $exception->violations[0]->message);
        }
    }

    /**
     * R2-1/R2-2: two declared enum values that are distinct authored
     * strings can still PascalCase to the same case name — `draft` and
     * `Draft` both become `Draft` — which previously reached the generated
     * enum class as "Cannot redefine class constant ...::Draft".
     */
    #[Test]
    public function twoEnumValuesCollidingAfterPascalCaseAreRefusedGen006(): void
    {
        $manifest = $this->manifestWithBlueprint($this->blueprintWithEntities([
            $this->simpleEntity('article', enumField: 'status', enumValues: ['draft', 'Draft']),
        ]));

        try {
            ApplicationBlueprintCompilerFactory::create()->compile($manifest);
            self::fail('Expected a GenerationRefusalException.');
        } catch (GenerationRefusalException $exception) {
            self::assertSame(GenerationErrorCode::MaliciousIdentifier, $exception->violations[0]->code);
            self::assertStringContainsString('Draft', $exception->violations[0]->message);
        }
    }

    /**
     * R2-2: the generated enum class short name is
     * `<PascalCase(entity.id)><PascalCase(field.id)>`. Two entity/field id
     * pairs whose entity ids do NOT themselves collide can still combine to
     * the same short name case-insensitively — entity `a` field `bc` and
     * entity `ab` field `c` both PascalCase-concatenate to `ABc`/`AbC`,
     * which PHP's case-insensitive class-name resolution treats as one
     * name — previously an uncoded emitter-level path-collision exception.
     */
    #[Test]
    public function twoEntityFieldPairsProducingTheSameEnumClassNameAreRefusedGen006(): void
    {
        $manifest = $this->manifestWithBlueprint($this->blueprintWithEntities([
            $this->simpleEntity('a', enumField: 'bc', enumValues: ['x']),
            $this->simpleEntity('ab', enumField: 'c', enumValues: ['y']),
        ]));

        try {
            ApplicationBlueprintCompilerFactory::create()->compile($manifest);
            self::fail('Expected a GenerationRefusalException.');
        } catch (GenerationRefusalException $exception) {
            self::assertSame(GenerationErrorCode::MaliciousIdentifier, $exception->violations[0]->code);
        }
    }

    /** @param list<BlueprintEntity> $entities keyed by id for `ApplicationBlueprint` */
    private function blueprintWithEntities(array $entities): ApplicationBlueprint
    {
        $byId = [];
        foreach ($entities as $entity) {
            $byId[$entity->id] = $entity;
        }

        return new ApplicationBlueprint(
            contractVersion: 1,
            entities: $byId,
            relationships: [],
            permissions: [],
            roles: [],
            policies: [],
            workflows: [],
            fixtures: [],
            checks: [],
        );
    }

    /** @param list<string>|null $enumValues */
    private function simpleEntity(string $id, ?string $enumField = null, ?array $enumValues = null): BlueprintEntity
    {
        $fields = [
            'title' => new BlueprintField('title', BlueprintFieldType::String, true, 1, false, false, false),
        ];
        if ($enumField !== null) {
            $fields[$enumField] = new BlueprintField($enumField, BlueprintFieldType::Enum, false, 1, false, false, false, $enumValues ?? []);
        }

        return new BlueprintEntity(
            id: $id,
            label: 'Label',
            storage: BlueprintStorage::SqlBlob,
            revisionable: false,
            translatable: false,
            keys: new BlueprintEntityKeys(id: 'id', uuid: 'uuid', label: 'title'),
            fields: $fields,
        );
    }

    private function manifestWithBlueprint(ApplicationBlueprint $blueprint): SiteManifest
    {
        $parsed = $this->manifest('minimal.yaml');

        return new SiteManifest(
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
