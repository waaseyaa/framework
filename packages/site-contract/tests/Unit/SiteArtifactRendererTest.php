<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\SiteContract\Generation\GeneratedArtifact;
use Waaseyaa\SiteContract\Generation\GeneratedSite;
use Waaseyaa\SiteContract\Generation\PublishedContentRecipe;
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
        self::assertStringContainsString('site:doctor --strict --format=json', $first->artifacts['bin/maintenance/site-verify']->content);

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
    public function itRendersTheCompletePublishedContentRecipe(): void
    {
        $manifest = str_replace(
            "recipes: []",
            sprintf(
                "recipes:\n  - id: published_content\n    version: 1\n    capability: published_content\n    artifact_digest: %s",
                PublishedContentRecipe::digest(),
            ),
            str_replace(
                'id: governed_authoring',
                'id: published_content',
                $this->manifest(),
            ),
        );
        $site = new SiteArtifactRenderer()->render(new SiteManifestParser()->parse($manifest));

        foreach ([
            'composer.site-recipes.json',
            'config/waaseyaa-recipes/published-content.php',
            'src/Content/Bundle/PageBundle.php',
            'src/Content/CanonicalContentRouteResolver.php',
            'src/Controller/PublishedContentController.php',
            'src/Provider/PublishedContentServiceProvider.php',
            'templates/content/detail.html.twig',
            'templates/content/index.html.twig',
            'tests/Acceptance/PublishedContentRecipeTest.php',
        ] as $path) {
            self::assertArrayHasKey($path, $site->artifacts);
        }

        $config = $site->artifacts['config/waaseyaa-recipes/published-content.php']->content;
        self::assertStringContainsString("'bundle' => 'page'", $config);
        foreach (['title', 'slug', 'summary', 'body', 'status', 'changed'] as $field) {
            self::assertStringContainsString("'{$field}'", $config);
        }
        self::assertStringContainsString("'listing_id' => 'page_index'", $config);
        self::assertStringContainsString("'page_size' => 24", $config);
        self::assertStringContainsString("'access_ops' =>", $config);
        self::assertStringContainsString("'view'", $config);
        self::assertStringContainsString("'detail_route' => '/{slug}'", $config);
        self::assertStringContainsString("'index_route' => '/'", $config);

        $provider = $site->artifacts['src/Provider/PublishedContentServiceProvider.php']->content;
        self::assertStringContainsString('implements HasListingsInterface', $provider);
        self::assertStringContainsString('new ListingDefinition(', $provider);
        self::assertStringContainsString('pageSize: 24', $provider);
        self::assertStringContainsString("accessOps: ['view']", $provider);
        self::assertStringContainsString('RouteBuilder::create(', $provider);
        self::assertStringContainsString('PathAliasResolver::class', $provider);
        self::assertStringContainsString('SitemapGenerator::class', $provider);
        self::assertStringContainsString('MetaTagBuilder::class', $provider);
        self::assertStringContainsString('EntitySchemaOrgMapper::class', $provider);
        self::assertStringContainsString('$this->singleton(', $provider);
        self::assertStringContainsString('BundleTemplateCompiler::class', $provider);

        $bundle = $site->artifacts['src/Content/Bundle/PageBundle.php']->content;
        self::assertStringContainsString("#[BundleTemplate(entityType: 'node', bundle: 'page')]", $bundle);
        self::assertStringContainsString("#[FieldTemplate(key: 'summary'", $bundle);
        self::assertStringContainsString("#[FieldTemplate(key: 'body'", $bundle);

        $resolver = $site->artifacts['src/Content/CanonicalContentRouteResolver.php']->content;
        self::assertStringContainsString('canonicalDetailUrl', $resolver);
        self::assertStringContainsString('sitemapUrl', $resolver);
        self::assertStringNotContainsString('/node/', $config . $resolver . $provider);

        $acceptance = $site->artifacts['tests/Acceptance/PublishedContentRecipeTest.php']->content;
        foreach (['testListingIsAccessAwareAndPageable', 'testCanonicalRoutesDriveSitemapUrls', 'testMetadataAndJsonLdUseTheCanonicalUrl', 'testInternalEntityPathsNeverEnterTheSitemap'] as $method) {
            self::assertStringContainsString($method, $acceptance);
        }
        self::assertStringContainsString('tests/Acceptance/PublishedContentRecipeTest.php', $site->artifacts['bin/maintenance/site-verify']->content);
    }

    #[Test]
    public function itRefusesAnUnknownOrSubstitutedPublishedContentRecipe(): void
    {
        $manifest = str_replace(
            "recipes: []",
            "recipes:\n  - id: published_content\n    version: 1\n    capability: published_content\n    artifact_digest: aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
            str_replace('id: governed_authoring', 'id: published_content', $this->manifest()),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('published_content recipe digest');

        new SiteArtifactRenderer()->render(new SiteManifestParser()->parse($manifest));
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

    #[Test]
    public function publishedContentRecipeRefusesRoutesThatCannotShareTheSlugAuthority(): void
    {
        $base = str_replace(
            "recipes: []",
            sprintf(
                "recipes:\n  - id: published_content\n    version: 1\n    capability: published_content\n    artifact_digest: %s",
                PublishedContentRecipe::digest(),
            ),
            str_replace('id: governed_authoring', 'id: published_content', $this->manifest()),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('terminal {slug}');

        new SiteArtifactRenderer()->render(new SiteManifestParser()->parse(str_replace('/{slug}', '/news/{id}', $base)));
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
