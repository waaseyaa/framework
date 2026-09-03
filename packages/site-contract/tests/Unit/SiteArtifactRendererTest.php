<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\SiteContract\Generation\GeneratedArtifact;
use Waaseyaa\SiteContract\Generation\GeneratedSite;
use Waaseyaa\SiteContract\Generation\SiteArtifactRenderer;
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

        // #2644: the generated acceptance test asserted is_executable() on an
        // extensionless file. Windows resolves executability through PATHEXT,
        // so that assertion failed there for a perfectly good file. The
        // portability property it stood for — the command is runnable PHP — is
        // now asserted on every host, and the execute bit only on POSIX.
        $acceptance = $first->artifacts['tests/Acceptance/SiteGoldenPathTest.php']->content;
        self::assertStringContainsString("DIRECTORY_SEPARATOR === '/'", $acceptance);
        self::assertStringContainsString('assertStringStartsWith(', $acceptance);
        self::assertStringContainsString("'#!/usr/bin/env php'", $acceptance);
        self::assertStringStartsWith('#!/usr/bin/env php', $first->artifacts['bin/maintenance/site-verify']->content);

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
