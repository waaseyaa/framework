<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Site;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Site\Recipe\PublishedContentRecipe;
use Waaseyaa\SiteContract\Generation\SiteArtifactRenderer;
use Waaseyaa\SiteContract\SiteManifestParser;

#[CoversClass(PublishedContentRecipe::class)]
final class PublishedContentRecipeTest extends TestCase
{
    #[Test]
    public function itRendersTheCompletePublishedContentRecipe(): void
    {
        $site = $this->renderer()->render(new SiteManifestParser()->parse($this->manifest()));

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
        foreach (['implements HasListingsInterface', 'new ListingDefinition(', 'pageSize: 24', "accessOps: ['view']", 'RouteBuilder::create(', 'PathAliasResolver::class', 'SitemapGenerator::class', 'MetaTagBuilder::class', 'EntitySchemaOrgMapper::class', 'BundleTemplateCompiler::class', '$this->singleton('] as $required) {
            self::assertStringContainsString($required, $provider);
        }

        $bundle = $site->artifacts['src/Content/Bundle/PageBundle.php']->content;
        self::assertStringContainsString("#[BundleTemplate(entityType: 'node', bundle: 'page')]", $bundle);
        self::assertStringContainsString("#[FieldTemplate(key: 'summary'", $bundle);
        self::assertStringContainsString("#[FieldTemplate(key: 'body'", $bundle);

        $resolver = $site->artifacts['src/Content/CanonicalContentRouteResolver.php']->content;
        self::assertStringContainsString('canonicalDetailUrl', $resolver);
        self::assertStringContainsString('sitemapUrl', $resolver);
        self::assertStringNotContainsString('/node/', $config . $resolver . $provider);

        $controller = $site->artifacts['src/Controller/PublishedContentController.php']->content;
        foreach (['viewableLabel', 'checkFieldAccess', 'resolveInboundAlias', 'collectFromEntityTypes', 'toXml', 'buildHeadSnippet', 'toScriptTag'] as $required) {
            self::assertStringContainsString($required, $controller);
        }

        $acceptance = $site->artifacts['tests/Acceptance/PublishedContentRecipeTest.php']->content;
        foreach (['testListingIsAccessAwareAndPageable', 'testCanonicalRoutesDriveSitemapUrls', 'testMetadataAndJsonLdUseTheCanonicalUrl', 'testInternalEntityPathsNeverEnterTheSitemap'] as $method) {
            self::assertStringContainsString($method, $acceptance);
        }
        self::assertStringContainsString('tests/Acceptance/PublishedContentRecipeTest.php', $site->artifacts['bin/maintenance/site-verify']->content);
    }

    #[Test]
    public function itRefusesASubstitutedRecipeDigest(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('published_content recipe digest');

        $this->renderer()->render(new SiteManifestParser()->parse(str_replace(PublishedContentRecipe::digest(), str_repeat('a', 64), $this->manifest())));
    }

    #[Test]
    public function itRefusesRoutesThatCannotShareTheSlugAuthority(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('terminal {slug}');

        $this->renderer()->render(new SiteManifestParser()->parse(str_replace('/{slug}', '/news/{id}', $this->manifest())));
    }

    private function renderer(): SiteArtifactRenderer
    {
        return new SiteArtifactRenderer([new PublishedContentRecipe()]);
    }

    private function manifest(): string
    {
        return sprintf(<<<'YAML'
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
              - id: published_content
                state: active
                package: waaseyaa/listing
                provider: site.published_content
                configuration_authority: .waaseyaa/site.yaml#/capabilities/published_content
                public_routes: [/{slug}]
                data_classification: public
                lifecycle: [create, revise, publish, archive]
                verification: [tests/Acceptance/PublishedContentRecipeTest.php]
            personal_data_stores: []
            recipes:
              - id: published_content
                version: 1
                capability: published_content
                artifact_digest: %s
            verification:
              command: bin/maintenance/site-verify
            YAML, PublishedContentRecipe::digest());
    }
}
