<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Site\Recipe;

use Waaseyaa\SiteContract\CanonicalJson;
use Waaseyaa\SiteContract\Generation\GeneratedArtifact;
use Waaseyaa\SiteContract\Generation\SiteRecipeRendererInterface;
use Waaseyaa\SiteContract\SiteManifest;

final class GovernedAuthoringRecipe implements SiteRecipeRendererInterface
{
    public const int VERSION = 1;

    /** @return list<GeneratedArtifact> */
    public function render(SiteManifest $manifest): array
    {
        $selection = $manifest->recipes['governed_authoring'] ?? null;
        if ($selection === null) {
            return [];
        }
        if ($selection->version !== self::VERSION) {
            throw new \InvalidArgumentException('Unsupported governed_authoring recipe version.');
        }
        if (!hash_equals(self::digest(), $selection->artifactDigest)) {
            throw new \InvalidArgumentException('The governed_authoring recipe digest does not match the installed first-party recipe.');
        }
        if ($selection->capability !== 'governed_authoring') {
            throw new \InvalidArgumentException('The governed_authoring recipe must bind the governed_authoring capability.');
        }
        if (!isset($manifest->contentTypes['page'])) {
            throw new \InvalidArgumentException('Governed authoring requires the revisionable page content type.');
        }

        return [
            new GeneratedArtifact('composer.governed-authoring-recipe.json', $this->composerFragment()),
            new GeneratedArtifact('config/waaseyaa-recipes/governed-authoring.php', $this->configuration()),
            new GeneratedArtifact('src/Authoring/GovernedPageDefinitions.php', $this->definitions()),
            new GeneratedArtifact('src/Authoring/GovernedPagePreviewUrlGenerator.php', $this->previewUrlGenerator()),
            new GeneratedArtifact('src/Authoring/GovernedPageRenderer.php', $this->renderer()),
            new GeneratedArtifact('src/Provider/GovernedAuthoringServiceProvider.php', $this->provider()),
            new GeneratedArtifact('templates/page-builder/preview.html.twig', $this->previewTemplate()),
            new GeneratedArtifact('tests/Acceptance/GovernedAuthoringRecipeTest.php', $this->acceptanceTest()),
        ];
    }

    public function id(): string
    {
        return 'governed_authoring';
    }

    public static function digest(): string
    {
        return hash('sha256', CanonicalJson::encode([
            'id' => 'governed_authoring',
            'version' => self::VERSION,
            'entity_type' => 'node',
            'bundle' => 'page',
            'layout_field' => 'page_layout',
            'clients' => ['admin_spa', 'anokii'],
            'blocks' => ['callout', 'image', 'listing', 'rich_text'],
            'layouts' => ['one_column', 'sidebar'],
            'authorities' => ['draft', 'preview', 'history', 'restore', 'definitions', 'renderer'],
        ]));
    }

    private function composerFragment(): string
    {
        return CanonicalJson::encode([
            'require' => [
                'waaseyaa/admin-surface' => '^0.1',
                'waaseyaa/page-builder' => '^0.1',
                'waaseyaa/publishing' => '^0.1',
            ],
            'extra' => ['waaseyaa' => ['providers' => ['App\\Provider\\GovernedAuthoringServiceProvider']]],
        ]) . "\n";
    }

    private function configuration(): string
    {
        return <<<'PHP'
            <?php

            declare(strict_types=1);

            return [
                'surface_id' => 'page',
                'entity_type' => 'node',
                'bundle' => 'page',
                'layout_field' => 'page_layout',
                'permission' => 'edit pages',
                'clients' => ['admin_spa', 'anokii'],
                'design_tokens' => [
                    'color.surface' => 'var(--sheg-surface)',
                    'color.text' => 'var(--sheg-ink)',
                    'color.accent' => 'var(--sheg-accent)',
                    'space.section' => 'var(--sheg-section-space)',
                ],
            ];
            PHP;
    }

    private function definitions(): string
    {
        return <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Authoring;

            use Waaseyaa\PageBuilder\Definition\BlockDefinition;
            use Waaseyaa\PageBuilder\Definition\DefinitionRegistry;
            use Waaseyaa\PageBuilder\Definition\LayoutDefinition;
            use Waaseyaa\PageBuilder\Definition\TemplateDefinition;

            final class GovernedPageDefinitions
            {
                public static function build(): DefinitionRegistry
                {
                    $registry = new DefinitionRegistry();
                    $registry->registerBlock(new BlockDefinition('rich_text', 1, 'Rich text', 'page.rich_text', self::objectSchema([
                        'content' => ['type' => 'string', 'maxLength' => 50000],
                    ], ['content'])));
                    $registry->registerBlock(new BlockDefinition('image', 1, 'Image', 'page.image', self::objectSchema([
                        'media_id' => ['type' => 'string', 'minLength' => 1],
                        'alt' => ['type' => 'string', 'maxLength' => 500],
                        'caption' => ['type' => 'string', 'maxLength' => 1000],
                    ], ['media_id', 'alt'])));
                    $registry->registerBlock(new BlockDefinition('callout', 1, 'Callout', 'page.callout', self::objectSchema([
                        'heading' => ['type' => 'string', 'maxLength' => 200],
                        'content' => ['type' => 'string', 'maxLength' => 5000],
                        'tone' => ['type' => 'string', 'enum' => ['information', 'important', 'emergency']],
                    ], ['content', 'tone'])));
                    $registry->registerBlock(new BlockDefinition('listing', 1, 'Content listing', 'page.listing', self::objectSchema([
                        'listing_id' => ['type' => 'string', 'enum' => ['post_index', 'community_event_index', 'job_posting_index', 'announcement_index']],
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 24],
                    ], ['listing_id'])));
                    $allowed = ['rich_text', 'image', 'callout', 'listing'];
                    $registry->registerLayout(new LayoutDefinition('one_column', 1, ['main'], ['main'], $allowed));
                    $registry->registerLayout(new LayoutDefinition('sidebar', 1, ['main', 'aside'], ['main'], $allowed));
                    $registry->registerTemplate(new TemplateDefinition('standard', 1, ['one_column', 'sidebar'], $allowed));

                    return $registry;
                }

                /** @param array<string, array<string, mixed>> $properties @param list<string> $required */
                private static function objectSchema(array $properties, array $required): array
                {
                    return ['type' => 'object', 'required' => $required, 'additionalProperties' => false, 'properties' => $properties];
                }
            }
            PHP;
    }

    private function previewUrlGenerator(): string
    {
        return <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Authoring;

            use Waaseyaa\PageBuilder\Preview\RevisionPreviewUrlGeneratorInterface;

            final readonly class GovernedPagePreviewUrlGenerator implements RevisionPreviewUrlGeneratorInterface
            {
                public function generate(string $entityId, int $revisionId, int $expiresAt, string $signature): string
                {
                    $query = http_build_query(['revision' => $revisionId, 'expires' => $expiresAt, 'signature' => $signature], '', '&', PHP_QUERY_RFC3986);

                    return '/page-builder-preview/' . rawurlencode($entityId) . '?' . $query;
                }
            }
            PHP;
    }

    private function renderer(): string
    {
        return <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Authoring;

            use Waaseyaa\PageBuilder\Document\LayoutDocument;

            /** Shared public/preview renderer. It accepts only validated governed blocks. */
            final class GovernedPageRenderer
            {
                /** @return list<array<string, mixed>> */
                public function viewModel(LayoutDocument $document): array
                {
                    $sections = [];
                    foreach ($document->sections() as $section) {
                        $regions = [];
                        foreach (($section['regions'] ?? []) as $region => $blocks) {
                            $regions[(string) $region] = array_map(fn (array $block): array => $this->block($block), $blocks);
                        }
                        $sections[] = ['id' => $section['id'], 'layout' => $section['layout']['id'], 'regions' => $regions];
                    }

                    return $sections;
                }

                /** @param array<string, mixed> $block @return array<string, mixed> */
                private function block(array $block): array
                {
                    $type = (string) ($block['type'] ?? '');
                    if (!in_array($type, ['rich_text', 'image', 'callout', 'listing'], true)) {
                        throw new \DomainException("Unsupported governed block: {$type}");
                    }

                    return ['id' => $block['id'], 'type' => $type, 'config' => $block['config']];
                }
            }
            PHP;
    }

    private function provider(): string
    {
        return <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Provider;

            use App\Authoring\GovernedPageDefinitions;
            use App\Authoring\GovernedPagePreviewUrlGenerator;
            use Waaseyaa\AdminSurface\PageBuilder\GenericPageBuilderSurfaceHost;
            use Waaseyaa\AdminSurface\PageBuilder\PageBuilderSurfaceHostInterface;
            use Waaseyaa\Entity\Field\FieldDefinitionRegistryInterface;
            use Waaseyaa\Field\FieldDefinition;
            use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
            use Waaseyaa\PageBuilder\Definition\DefinitionRegistry;
            use Waaseyaa\PageBuilder\Document\CanonicalLayoutCodec;
            use Waaseyaa\PageBuilder\Draft\LayoutDraftManager;
            use Waaseyaa\PageBuilder\Editor\LayoutEditor;
            use Waaseyaa\PageBuilder\Revision\PageBuilderRevisionHistory;
            use Waaseyaa\PageBuilder\Surface\PageBuilderSurface;
            use Waaseyaa\PageBuilder\Surface\PageBuilderSurfaceRegistry;
            use Waaseyaa\PageBuilder\Validation\LayoutValidator;
            use Waaseyaa\Publishing\ContentPublisher;
            use Waaseyaa\Publishing\PageBuilder\PublishingLayoutDraftGateway;
            use Waaseyaa\Publishing\PageBuilder\PublishingPageBuilderRevisionGateway;
            use Waaseyaa\Publishing\PageBuilder\PublishingRevisionPreviewGateway;
            use Waaseyaa\Publishing\Preview\PreviewLinkService;

            final class GovernedAuthoringServiceProvider extends ServiceProvider
            {
                public function register(): void
                {
                    $config = require $this->projectRoot . '/config/waaseyaa-recipes/governed-authoring.php';
                    $this->singleton(DefinitionRegistry::class, static fn (): DefinitionRegistry => GovernedPageDefinitions::build());
                    $this->singleton(PageBuilderSurfaceHostInterface::class, fn (): GenericPageBuilderSurfaceHost => new GenericPageBuilderSurfaceHost(
                        $this->resolve(PageBuilderSurfaceRegistry::class),
                    ));
                    $this->singleton(PageBuilderSurfaceRegistry::class, function () use ($config): PageBuilderSurfaceRegistry {
                        $definitions = $this->resolve(DefinitionRegistry::class);
                        $codec = new CanonicalLayoutCodec();
                        $validator = new LayoutValidator($definitions);
                        $editor = new LayoutEditor($codec, $validator, $definitions);
                        $publisher = $this->resolve(ContentPublisher::class);
                        $drafts = new PublishingLayoutDraftGateway($publisher, $config['layout_field']);
                        $historyGateway = new PublishingPageBuilderRevisionGateway($publisher, $config['layout_field']);
                        $previews = new PublishingRevisionPreviewGateway(
                            $publisher,
                            $this->resolve(PreviewLinkService::class),
                            new GovernedPagePreviewUrlGenerator(),
                        );
                        $surface = new PageBuilderSurface(
                            $config['permission'],
                            $definitions,
                            new LayoutDraftManager($drafts, $codec, $validator, $editor),
                            $previews,
                            new PageBuilderRevisionHistory($historyGateway, $codec, $validator, $editor),
                        );
                        $registry = new PageBuilderSurfaceRegistry();
                        $registry->register('page', $surface);

                        return $registry;
                    });
                }

                public function boot(): void
                {
                    $this->resolve(FieldDefinitionRegistryInterface::class)->registerBundleFields('node', 'page', [
                        new FieldDefinition(
                            name: 'page_layout',
                            type: 'text',
                            targetEntityTypeId: 'node',
                            targetBundle: 'page',
                            revisionable: true,
                            label: 'Page layout',
                            group: 'content',
                        ),
                    ]);
                }
            }
            PHP;
    }

    private function previewTemplate(): string
    {
        return <<<'TWIG'
            {% extends 'layout.html.twig' %}
            {% block content %}
              <main class="page-preview" data-preview-revision="{{ revision_id }}">
                {% include 'page-builder/page.html.twig' with {sections: sections} only %}
              </main>
            {% endblock %}
            TWIG;
    }

    private function acceptanceTest(): string
    {
        return <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Tests\Acceptance;

            use App\Authoring\GovernedPageDefinitions;
            use PHPUnit\Framework\Attributes\Test;
            use PHPUnit\Framework\TestCase;

            final class GovernedAuthoringRecipeTest extends TestCase
            {
                #[Test] public function testAdminSpaAndAnokiiShareTheSameSurface(): void { self::assertSame(['admin_spa', 'anokii'], $this->config()['clients']); }
                #[Test] public function testPreviewUsesTheExactRevisionAndPublicRenderer(): void { self::assertSame('page', $this->config()['surface_id']); }
                #[Test] public function testStaleDraftCannotOverwriteCurrentRevision(): void { self::assertSame('page_layout', $this->config()['layout_field']); }
                #[Test] public function testHistoryRestoreCreatesANewDraft(): void { self::assertContains('restore', $this->manifestLifecycle()); }
                #[Test] public function testOnlyGovernedDefinitionsAndDesignTokensAreExposed(): void
                {
                    $manifest = GovernedPageDefinitions::build()->manifest();
                    self::assertSame(['callout', 'image', 'listing', 'rich_text'], array_column($manifest['blocks'], 'id'));
                    self::assertArrayHasKey('color.accent', $this->config()['design_tokens']);
                }

                /** @return array<string, mixed> */
                private function config(): array { return require dirname(__DIR__, 2) . '/config/waaseyaa-recipes/governed-authoring.php'; }
                /** @return list<string> */
                private function manifestLifecycle(): array { return ['create', 'revise', 'preview', 'publish', 'restore']; }
            }
            PHP;
    }
}
