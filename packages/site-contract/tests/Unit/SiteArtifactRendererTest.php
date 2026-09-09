<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\SiteContract\Generation\ArtifactSetEvolution;
use Waaseyaa\SiteContract\Generation\ComposerProviderRegistration;
use Waaseyaa\SiteContract\Generation\GeneratedArtifact;
use Waaseyaa\SiteContract\Generation\GeneratedSite;
use Waaseyaa\SiteContract\Generation\GenerationUnitDisposition;
use Waaseyaa\SiteContract\Generation\SiteArtifactRenderer;
use Waaseyaa\SiteContract\Generation\SiteRecipeProviderRegistrationInterface;
use Waaseyaa\SiteContract\Generation\SiteRecipeRendererInterface;
use Waaseyaa\SiteContract\RecipeSelection;
use Waaseyaa\SiteContract\SiteManifest;
use Waaseyaa\SiteContract\SiteManifestParser;
use Waaseyaa\SiteContract\SiteManifestSchema;

#[CoversClass(SiteArtifactRenderer::class)]
#[CoversClass(GeneratedSite::class)]
#[CoversClass(GeneratedArtifact::class)]
final class SiteArtifactRendererTest extends TestCase
{
    #[Test]
    public function itRendersTheCompleteProviderNeutralSiteContractDeterministically(): void
    {
        $parser = new SiteManifestParser();
        $renderer = new SiteArtifactRenderer();

        $first = $renderer->render($parser->parse($this->manifest()));
        $second = $renderer->render($parser->parse(str_replace(
            "  name: Example Nation\n  id: example-nation",
            "  id: example-nation\n  name: Example Nation",
            $this->manifest(),
        )));

        self::assertSame($first->contents(), $second->contents());
        // Treat this list as frozen. `SiteInitializationService::prepare()`
        // compares the rendered artifact set against the recorded ownership
        // rows UNCONDITIONALLY — outside the manifest-digest guard — so adding
        // or removing one generated file permanently refuses regeneration on
        // every already-initialized project, with no override flag and no
        // migration command. Changing the *bytes* of an existing artifact is
        // recoverable by rebinding the manifest lock; changing this set is not.
        self::assertSame([
            '.waaseyaa/.gitignore',
            '.waaseyaa/generated.json',
            '.waaseyaa/site.schema.json',
            '.waaseyaa/site.yaml',
            'AGENTS.md',
            'bin/maintenance/site-verify',
            'tests/Acceptance/SiteGoldenPathTest.php',
            'tests/Architecture/SiteContractTest.php',
        ], array_keys($first->artifacts));
        self::assertSame(SiteManifestSchema::canonicalJson() . "\n", $first->artifacts['.waaseyaa/site.schema.json']->content);
        self::assertStringContainsString('waaseyaa:extension:start local-guidance', $first->artifacts['AGENTS.md']->content);
        self::assertStringContainsString('bin/maintenance/site-verify', $first->artifacts['AGENTS.md']->content);
        self::assertStringNotContainsString('github', strtolower(implode("\n", $first->contents())));
        self::assertSame(0o755, $first->artifacts['bin/maintenance/site-verify']->mode);
        self::assertStringContainsString('chdir($root)', $first->artifacts['bin/maintenance/site-verify']->content);
        self::assertStringContainsString('site:doctor --strict --format=json', $first->artifacts['bin/maintenance/site-verify']->content);
        // FW-REHYDRATION-DRIFT-01 / GitHub #3056:
        // vendor/bin/phpunit's own cacheDirectory (".phpunit.cache" in the
        // skeleton's phpunit.xml.dist) records real wall-clock test timings
        // in "test-run-history" on every run. Left at its XML default, that
        // file lands at the PROJECT ROOT — a location every artifact-bundle
        // consumer (e.g. Studio's materialization protocol) must treat as
        // part of the deliverable tree, so two verification runs against an
        // otherwise-identical project produce two different "identical"
        // bundles. Overriding --cache-directory here relocates that cache
        // under storage/, which the skeleton's own .gitignore already
        // excludes as ephemeral runtime state (matching vendor/) — so the
        // non-deterministic bytes are never produced anywhere a byte-equality
        // check inspects, instead of being produced and then filtered out.
        self::assertStringContainsString(
            "--cache-directory=' . escapeshellarg(\$root . '/storage/.phpunit.cache')",
            $first->artifacts['bin/maintenance/site-verify']->content,
        );

        // #2644: the generated acceptance test originally asserted
        // is_executable() on an extensionless file. Windows resolves
        // executability through PATHEXT, so that assertion failed there for a
        // perfectly good file — fixed by asserting the shebang on every host
        // and the execute bit only under DIRECTORY_SEPARATOR === '/'.
        //
        // FW-SITE-VERIFY-NOEXEC-01 / GitHub #3054: that POSIX-only is_executable() check itself does not hold
        // inside a hardened container whose project mount is noexec (e.g. a
        // sandboxed execution environment's tmpfs). The file is genuinely
        // mode 0755, but is_executable() reads mount policy, not just the
        // inode, and reports false. Two properties, measured two ways:
        // (1) the artifact carries the promised permission bits — read via
        // fileperms(), which reports the inode regardless of mount flags, so
        // it still fails if Framework stops chmod-ing the artifact; and
        // (2) the command actually runs through the one invocation every
        // caller uses, `PHP_BINARY <script>` — proven by really spawning it
        // with `--self-test` and asserting the exit code, not by inspecting
        // the shebang string alone.
        $acceptance = $first->artifacts['tests/Acceptance/SiteGoldenPathTest.php']->content;
        self::assertStringContainsString("DIRECTORY_SEPARATOR === '/'", $acceptance);
        self::assertStringContainsString('assertStringStartsWith(', $acceptance);
        self::assertStringContainsString("'#!/usr/bin/env php'", $acceptance);
        self::assertStringStartsWith('#!/usr/bin/env php', $first->artifacts['bin/maintenance/site-verify']->content);

        // Property 1: POSIX permission bits, measured mount-independently.
        self::assertStringContainsString('fileperms(', $acceptance);
        self::assertStringContainsString('0111', $acceptance);
        self::assertStringNotContainsString('self::assertTrue(is_executable(', $acceptance);

        // Property 2: actually runnable through the supported PHP invocation
        // (PHP_BINARY <script>), not merely shebang-prefixed text.
        self::assertStringContainsString('PHP_BINARY', $acceptance);
        self::assertStringContainsString('--self-test', $acceptance);
        self::assertStringContainsString('selfTestExitCode', $acceptance);
        self::assertStringContainsString('assertSame(', $acceptance);

        // The generated script itself must honor the `--self-test` contract
        // the acceptance test relies on, without falling into the full
        // doctor+test pipeline (which would recurse into this very test).
        self::assertStringContainsString('--self-test', $first->artifacts['bin/maintenance/site-verify']->content);

        $metadata = json_decode($first->artifacts['.waaseyaa/generated.json']->content, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('waaseyaa.generated', $metadata['schema']);
        self::assertSame(1, $metadata['generator_version']);
        self::assertCount(7, $metadata['artifacts']);
        self::assertSame('local-guidance', $metadata['artifacts'][3]['extension_region']);
        foreach ($metadata['artifacts'] as $artifact) {
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $artifact['managed_sha256']);
        }
    }

    #[Test]
    public function blueprintFreeV1OwnershipMetadataRemainsByteIdentical(): void
    {
        $site = new SiteArtifactRenderer()->render(new SiteManifestParser()->parse($this->manifest()));
        $expected = file_get_contents(__DIR__ . '/../Fixtures/Generation/blueprint-free-v1.generated.json');

        self::assertNotFalse($expected);
        self::assertSame($expected, $site->artifacts['.waaseyaa/generated.json']->content);
    }

    #[Test]
    public function itRejectsInvalidGeneratedPhpBeforePublication(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid syntax');

        new GeneratedArtifact('tests/Architecture/BrokenTest.php', '<?php this is not PHP');
    }

    #[Test]
    public function itRejectsOwnershipMetadataThatDoesNotMatchTheArtifactSet(): void
    {
        $site = new SiteArtifactRenderer()->render(new SiteManifestParser()->parse($this->manifest()));
        $artifacts = $site->artifacts;
        $artifacts['AGENTS.md'] = new GeneratedArtifact('AGENTS.md', "# substituted\n");

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ownership metadata does not match');

        new GeneratedSite($site->generatorVersion, $site->manifestDigest, $artifacts);
    }

    #[Test]
    public function renderedManifestRoundTripsNumericLookingStringsAsStrings(): void
    {
        $parser = new SiteManifestParser();
        foreach (['1.5', '.inf', '1e5'] as $name) {
            $manifest = $parser->parse(str_replace('name: Example Nation', "name: '{$name}'", $this->manifest()));
            $rendered = new SiteArtifactRenderer()->render($manifest);
            $roundTrip = $parser->parse($rendered->artifacts['.waaseyaa/site.yaml']->content);
            self::assertSame($manifest->digest, $roundTrip->digest, $name);
        }
    }

    #[Test]
    public function itRefusesAnUninstalledRecipeInsteadOfSilentlyIgnoringIt(): void
    {
        $manifest = str_replace(
            "recipes: []",
            "recipes:\n  - id: private_fork\n    version: 1\n    capability: published_content\n    artifact_digest: aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
            str_replace('id: governed_authoring', 'id: published_content', $this->manifest()),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported first-party recipe: private_fork');

        new SiteArtifactRenderer()->render(new SiteManifestParser()->parse($manifest));
    }

    /** ADR-025 D-15.2: {@see SiteArtifactRenderer::compile()} is the root-unit plan the execution authority publishes. */
    #[Test]
    public function itCompilesABaseArtifactPlanWithNoRegistrationsWhenNoRecipeContributesOne(): void
    {
        $manifest = new SiteManifestParser()->parse($this->manifest());
        $renderer = new SiteArtifactRenderer();

        $plan = $renderer->compile($manifest);
        $rendered = $renderer->render($manifest);

        self::assertSame(SiteArtifactRenderer::class, $plan->generatorFqcn);
        self::assertSame('site', $plan->unitId);
        self::assertSame(GenerationUnitDisposition::Managed, $plan->disposition);
        self::assertSame(ArtifactSetEvolution::Additive, $plan->setEvolution);
        self::assertSame($manifest->generatorVersion, $plan->generatorVersion);
        self::assertSame($manifest->digest, $plan->inputDigest);
        self::assertSame([], $plan->registrations);

        $expectedPaths = array_values(array_filter(
            array_keys($rendered->artifacts),
            static fn(string $path): bool => $path !== '.waaseyaa/generated.json',
        ));
        sort($expectedPaths, SORT_STRING);
        self::assertSame($expectedPaths, array_map(static fn(GeneratedArtifact $artifact): string => $artifact->path, $plan->artifacts));
        foreach ($plan->artifacts as $artifact) {
            self::assertSame($rendered->artifacts[$artifact->path]->content, $artifact->content, $artifact->path);
            self::assertSame($rendered->artifacts[$artifact->path]->mode, $artifact->mode, $artifact->path);
        }
    }

    /** Exactly one registration per recipe that contributes one — not one per recipe wired, and not zero when a recipe is selected. */
    #[Test]
    public function itCompilesExactlyOneRegistrationPerRecipeThatContributesOne(): void
    {
        $makeRecipe = static fn(string $id, string $fqcn): SiteRecipeRendererInterface&SiteRecipeProviderRegistrationInterface => new class($id, $fqcn) implements SiteRecipeRendererInterface, SiteRecipeProviderRegistrationInterface {
            public function __construct(private string $recipeId, private string $providerFqcn) {}

            public function id(): string
            {
                return $this->recipeId;
            }

            public function render(SiteManifest $manifest): array
            {
                return isset($manifest->recipes[$this->recipeId]) ? [new GeneratedArtifact("stub/{$this->recipeId}.php", "<?php\n")] : [];
            }

            public function providerRegistrations(SiteManifest $manifest): array
            {
                return isset($manifest->recipes[$this->recipeId]) ? [new ComposerProviderRegistration($this->providerFqcn)] : [];
            }
        };
        $renderer = new SiteArtifactRenderer([
            $makeRecipe('stub_a', 'App\\Provider\\StubAProvider'),
            $makeRecipe('stub_b', 'App\\Provider\\StubBProvider'),
        ]);
        $manifest = $this->withRecipes(new SiteManifestParser()->parse($this->manifest()), [
            'stub_a' => new RecipeSelection('stub_a', 1, 'stub_a', str_repeat('a', 64)),
        ]);

        $plan = $renderer->compile($manifest);

        self::assertCount(1, $plan->registrations);
        self::assertSame('App\\Provider\\StubAProvider', $plan->registrations[0]->fqcn);
    }

    /** D-2.1a rule 2: an fqcn appears at most once across the entire roster — two recipes declaring the same provider is a plan-construction refusal, not a silent merge. */
    #[Test]
    public function duplicateProviderRegistrationsAcrossRecipesAreRefused(): void
    {
        $makeRecipe = static fn(string $id): SiteRecipeRendererInterface&SiteRecipeProviderRegistrationInterface => new class($id) implements SiteRecipeRendererInterface, SiteRecipeProviderRegistrationInterface {
            public function __construct(private string $recipeId) {}

            public function id(): string
            {
                return $this->recipeId;
            }

            public function render(SiteManifest $manifest): array
            {
                return [];
            }

            public function providerRegistrations(SiteManifest $manifest): array
            {
                return isset($manifest->recipes[$this->recipeId]) ? [new ComposerProviderRegistration('App\\Provider\\SharedProvider')] : [];
            }
        };
        $renderer = new SiteArtifactRenderer([$makeRecipe('stub_a'), $makeRecipe('stub_b')]);
        $manifest = $this->withRecipes(new SiteManifestParser()->parse($this->manifest()), [
            'stub_a' => new RecipeSelection('stub_a', 1, 'stub_a', str_repeat('a', 64)),
            'stub_b' => new RecipeSelection('stub_b', 1, 'stub_b', str_repeat('b', 64)),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('registrations must declare each fqcn once');

        $renderer->compile($manifest);
    }

    /** @param array<string, RecipeSelection> $recipes */
    private function withRecipes(SiteManifest $manifest, array $recipes): SiteManifest
    {
        return new SiteManifest(
            $manifest->schemaVersion,
            $manifest->generatorVersion,
            $manifest->application,
            $manifest->framework,
            $manifest->contentTypes,
            $manifest->capabilities,
            $manifest->personalDataStores,
            $recipes,
            $manifest->verificationCommand,
            $manifest->canonicalJson,
            $manifest->digest,
        );
    }

    private function manifest(): string
    {
        return <<<'YAML'
            schema: waaseyaa.site
            version: 1
            generator_version: 1
            application:
              name: Example Nation
              id: example-nation
              canonical_origin:
                config_key: APP_ORIGIN
            framework:
              revision_policy: exact-lock
              observed_lock_sha256: aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
            content_types:
              - id: page
                canonical_route: /{slug}
            capabilities:
              - id: governed_authoring
                state: active
                package: waaseyaa/page-builder
                provider: site.page_builder
                configuration_authority: .waaseyaa/site.yaml#/capabilities/governed_authoring
                public_routes: []
                data_classification: public
                lifecycle: [create, revise, publish, archive]
                verification: [tests/Acceptance/SiteGoldenPathTest.php]
            personal_data_stores: []
            recipes: []
            verification:
              command: bin/maintenance/site-verify
            YAML;
    }
}
