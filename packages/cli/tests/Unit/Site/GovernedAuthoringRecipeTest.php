<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Site;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Site\Recipe\GovernedAuthoringRecipe;
use Waaseyaa\SiteContract\Generation\SiteArtifactRenderer;
use Waaseyaa\SiteContract\SiteManifestParser;

#[CoversClass(GovernedAuthoringRecipe::class)]
final class GovernedAuthoringRecipeTest extends TestCase
{
    #[Test]
    public function itGeneratesOneSharedGovernedAuthoringAuthorityForBothClients(): void
    {
        $site = new SiteArtifactRenderer([new GovernedAuthoringRecipe()])
            ->render(new SiteManifestParser()->parse($this->manifest()));

        foreach ([
            'composer.governed-authoring-recipe.json',
            'config/waaseyaa-recipes/governed-authoring.php',
            'src/Authoring/GovernedPageDefinitions.php',
            'src/Authoring/GovernedPagePreviewUrlGenerator.php',
            'src/Authoring/GovernedPageRenderer.php',
            'src/Provider/GovernedAuthoringServiceProvider.php',
            'templates/page-builder/preview.html.twig',
            'tests/Acceptance/GovernedAuthoringRecipeTest.php',
        ] as $path) {
            self::assertArrayHasKey($path, $site->artifacts);
        }

        $config = $site->artifacts['config/waaseyaa-recipes/governed-authoring.php']->content;
        self::assertStringContainsString("'clients' =>", $config);
        self::assertStringContainsString("'admin_spa'", $config);
        self::assertStringContainsString("'anokii'", $config);
        self::assertStringContainsString("'layout_field' => 'page_layout'", $config);

        $provider = $site->artifacts['src/Provider/GovernedAuthoringServiceProvider.php']->content;
        foreach (['DefinitionRegistry', 'PublishingLayoutDraftGateway', 'PublishingRevisionPreviewGateway', 'PublishingPageBuilderRevisionGateway', 'PageBuilderRevisionHistory', 'PageBuilderSurfaceRegistry', 'PageBuilderSurfaceHostInterface', "name: 'page_layout'", "register('page'"] as $required) {
            self::assertStringContainsString($required, $provider);
        }

        $acceptance = $site->artifacts['tests/Acceptance/GovernedAuthoringRecipeTest.php']->content;
        foreach (['testAdminSpaAndAnokiiShareTheSameSurface', 'testPreviewUsesTheExactRevisionAndPublicRenderer', 'testStaleDraftCannotOverwriteCurrentRevision', 'testHistoryRestoreCreatesANewDraft', 'testOnlyGovernedDefinitionsAndDesignTokensAreExposed'] as $method) {
            self::assertStringContainsString($method, $acceptance);
        }
        self::assertStringNotContainsString('raw_html', $config . $provider);
        self::assertStringNotContainsString('custom_css', $config . $provider);
        self::assertStringNotContainsString('custom_javascript', $config . $provider);
        $previewUrl = $site->artifacts['src/Authoring/GovernedPagePreviewUrlGenerator.php']->content;
        self::assertStringContainsString("return '/page-builder-preview/'", $previewUrl);
        self::assertStringNotContainsString('APP_ORIGIN', $previewUrl . $provider);

        foreach ($site->artifacts as $artifact) {
            if (str_ends_with($artifact->path, '.php')) {
                self::assertNotEmpty(token_get_all($artifact->content, TOKEN_PARSE), "Generated PHP must parse: {$artifact->path}");
            }
        }
    }

    #[Test]
    public function itRefusesASubstitutedRecipeDigest(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('governed_authoring recipe digest');

        new SiteArtifactRenderer([new GovernedAuthoringRecipe()])
            ->render(new SiteManifestParser()->parse(str_replace(GovernedAuthoringRecipe::digest(), str_repeat('b', 64), $this->manifest())));
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
              - id: post
                canonical_route: /news/{slug}
              - id: community_event
                canonical_route: /events/{slug}
              - id: job_posting
                canonical_route: /employment/{slug}
              - id: announcement
                canonical_route: /announcements/{slug}
            capabilities:
              - id: governed_authoring
                state: active
                package: waaseyaa/page-builder
                provider: site.page_builder
                configuration_authority: .waaseyaa/site.yaml#/capabilities/governed_authoring
                public_routes: [/page-builder-preview/{id}]
                data_classification: public
                lifecycle: [create, revise, preview, publish, restore]
                verification: [tests/Acceptance/GovernedAuthoringRecipeTest.php]
            personal_data_stores: []
            recipes:
              - id: governed_authoring
                version: 1
                capability: governed_authoring
                artifact_digest: %s
            verification:
              command: bin/maintenance/site-verify
            YAML, GovernedAuthoringRecipe::digest());
    }
}
