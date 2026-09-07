<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Site;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Audit\Contract\AuditWriterInterface;
use Waaseyaa\CLI\Site\Recipe\GovernedAuthoringRecipe;
use Waaseyaa\CLI\Site\Recipe\PublishedContentRecipe;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Field\FieldDefinitionRegistryInterface;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\Field\BundleTemplateCompiler;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\PageBuilder\Surface\PageBuilderSurface;
use Waaseyaa\PageBuilder\Surface\PageBuilderSurfaceRegistry;
use Waaseyaa\Publishing\ContentPublicationTransitionerInterface;
use Waaseyaa\Publishing\Preview\PreviewLinkService;
use Waaseyaa\SiteContract\Generation\SiteArtifactRenderer;
use Waaseyaa\SiteContract\RecipeSelection;
use Waaseyaa\SiteContract\SiteManifest;
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
    public function itsGeneratedSurfaceComposesAPageScopedPublisherFromCanonicalServices(): void
    {
        $site = new SiteArtifactRenderer([new GovernedAuthoringRecipe()])
            ->render(new SiteManifestParser()->parse($this->manifest()));
        $publishedSite = new SiteArtifactRenderer([new PublishedContentRecipe()])
            ->render(new SiteManifestParser()->parse($this->publishedManifest()));
        $root = sys_get_temp_dir() . '/waaseyaa-governed-authoring-provider-' . bin2hex(random_bytes(6));
        $filesystem = new Filesystem();

        try {
            foreach ([
                'config/waaseyaa-recipes/governed-authoring.php',
                'src/Authoring/GovernedPageDefinitions.php',
                'src/Authoring/GovernedPagePreviewUrlGenerator.php',
                'src/Provider/GovernedAuthoringServiceProvider.php',
            ] as $path) {
                $target = $root . '/' . $path;
                if (!is_dir(dirname($target))) {
                    self::assertTrue(mkdir(dirname($target), 0o755, true));
                }
                self::assertNotFalse(file_put_contents($target, $site->artifacts[$path]->content));
            }

            foreach ([
                'config/waaseyaa-recipes/published-content.php',
                'src/Content/Bundle/PageBundle.php',
                'src/Provider/PublishedContentServiceProvider.php',
            ] as $path) {
                $target = $root . '/' . $path;
                if (!is_dir(dirname($target))) {
                    self::assertTrue(mkdir(dirname($target), 0o755, true));
                }
                self::assertNotFalse(file_put_contents($target, $publishedSite->artifacts[$path]->content));
            }

            require $root . '/src/Content/Bundle/PageBundle.php';
            require $root . '/src/Provider/PublishedContentServiceProvider.php';
            require $root . '/src/Authoring/GovernedPageDefinitions.php';
            require $root . '/src/Authoring/GovernedPagePreviewUrlGenerator.php';
            require $root . '/src/Provider/GovernedAuthoringServiceProvider.php';

            $repository = new \ReflectionClass(EntityRepository::class)->newInstanceWithoutConstructor();
            $entityTypes = $this->createStub(EntityTypeManagerInterface::class);
            $entityTypes->method('getRepository')->willReturnCallback(static function (string $entityTypeId) use ($repository): EntityRepository {
                self::assertSame('node', $entityTypeId);

                return $repository;
            });
            $fieldRegistry = new FieldDefinitionRegistry();
            $services = [
                EntityTypeManagerInterface::class => $entityTypes,
                FieldDefinitionRegistryInterface::class => $fieldRegistry,
                BundleTemplateCompiler::class => new BundleTemplateCompiler($fieldRegistry),
                DatabaseInterface::class => $this->createStub(DatabaseInterface::class),
                AuditWriterInterface::class => $this->createStub(AuditWriterInterface::class),
                EntityAccessHandler::class => new \ReflectionClass(EntityAccessHandler::class)->newInstanceWithoutConstructor(),
                ContentPublicationTransitionerInterface::class => $this->createStub(ContentPublicationTransitionerInterface::class),
                PreviewLinkService::class => new \ReflectionClass(PreviewLinkService::class)->newInstanceWithoutConstructor(),
            ];

            $kernelServices = new class ($services) implements KernelServicesInterface {
                /** @param array<string, object> $services */
                public function __construct(private array $services) {}

                public function get(string $abstract): ?object
                {
                    return $this->services[$abstract] ?? null;
                }
            };
            // ArtifactPlan sorts ungrouped provider registrations by FQCN, so
            // GovernedAuthoring registers before PublishedContent in the real root manifest.
            $provider = new \App\Provider\GovernedAuthoringServiceProvider();
            $provider->setKernelContext($root, [], []);
            $provider->setKernelServices($kernelServices);
            $provider->register();
            $publishedProvider = new \App\Provider\PublishedContentServiceProvider();
            $publishedProvider->setKernelContext($root, [], []);
            $publishedProvider->setKernelServices($kernelServices);
            $publishedProvider->register();

            $fieldNames = array_keys($fieldRegistry->bundleFieldsFor('node', 'page'));
            sort($fieldNames, SORT_STRING);
            self::assertSame(['body', 'page_layout', 'summary'], $fieldNames);

            $registry = $provider->resolve(PageBuilderSurfaceRegistry::class);

            self::assertInstanceOf(PageBuilderSurfaceRegistry::class, $registry);
            self::assertInstanceOf(PageBuilderSurface::class, $registry->get('page'));
        } finally {
            $filesystem->remove($root);
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

    #[Test]
    public function itRendersNothingWhenTheRecipeIsNotSelected(): void
    {
        $manifest = new SiteManifestParser()->parse($this->manifest());

        self::assertSame([], new GovernedAuthoringRecipe()->render($this->with($manifest, recipes: [])));
    }

    #[Test]
    public function itRefusesAnUnsupportedRecipeVersion(): void
    {
        $manifest = new SiteManifestParser()->parse($this->manifest());
        $this->expectExceptionMessage('Unsupported governed_authoring recipe version');

        new GovernedAuthoringRecipe()->render($this->with($manifest, recipes: [
            'governed_authoring' => new RecipeSelection('governed_authoring', 2, 'governed_authoring', GovernedAuthoringRecipe::digest()),
        ]));
    }

    #[Test]
    public function itRefusesARecipeBoundToAnotherCapability(): void
    {
        $manifest = new SiteManifestParser()->parse($this->manifest());
        $this->expectExceptionMessage('must bind the governed_authoring capability');

        new GovernedAuthoringRecipe()->render($this->with($manifest, recipes: [
            'governed_authoring' => new RecipeSelection('governed_authoring', 1, 'published_content', GovernedAuthoringRecipe::digest()),
        ]));
    }

    #[Test]
    public function itRefusesACompositionWithoutThePageContentType(): void
    {
        $manifest = new SiteManifestParser()->parse($this->manifest());
        $contentTypes = $manifest->contentTypes;
        unset($contentTypes['page']);
        $this->expectExceptionMessage('requires the revisionable page content type');

        new GovernedAuthoringRecipe()->render($this->with($manifest, contentTypes: $contentTypes));
    }

    /** ADR-025 D-15.2: the recipe's fixed provider registration, consumed by {@see SiteArtifactRenderer::compile()}. */
    #[Test]
    public function itReturnsNoProviderRegistrationWhenNotSelected(): void
    {
        $manifest = new SiteManifestParser()->parse($this->manifest());

        self::assertSame([], new GovernedAuthoringRecipe()->providerRegistrations($this->with($manifest, recipes: [])));
    }

    #[Test]
    public function itReturnsItsFixedProviderRegistrationWhenSelected(): void
    {
        $manifest = new SiteManifestParser()->parse($this->manifest());

        $registrations = new GovernedAuthoringRecipe()->providerRegistrations($manifest);

        self::assertCount(1, $registrations);
        self::assertSame('App\\Provider\\GovernedAuthoringServiceProvider', $registrations[0]->fqcn);
        self::assertNull($registrations[0]->group);
    }

    /**
     * @param array<string, \Waaseyaa\SiteContract\ContentTypeDeclaration>|null $contentTypes
     * @param array<string, RecipeSelection>|null $recipes
     */
    private function with(SiteManifest $manifest, ?array $contentTypes = null, ?array $recipes = null): SiteManifest
    {
        return new SiteManifest(
            $manifest->schemaVersion,
            $manifest->generatorVersion,
            $manifest->application,
            $manifest->framework,
            $contentTypes ?? $manifest->contentTypes,
            $manifest->capabilities,
            $manifest->personalDataStores,
            $recipes ?? $manifest->recipes,
            $manifest->verificationCommand,
            $manifest->canonicalJson,
            $manifest->digest,
        );
    }

    private function publishedManifest(): string
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
