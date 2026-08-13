<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Site\Recipe;

use Waaseyaa\SiteContract\CanonicalJson;
use Waaseyaa\SiteContract\Generation\GeneratedArtifact;
use Waaseyaa\SiteContract\Generation\SiteRecipeRendererInterface;
use Waaseyaa\SiteContract\SiteManifest;

final class PublishedContentRecipe implements SiteRecipeRendererInterface
{
    public const int VERSION = 1;

    /** @return list<GeneratedArtifact> */
    public function render(SiteManifest $manifest): array
    {
        $selection = $manifest->recipes['published_content'] ?? null;
        if ($selection === null) {
            return [];
        }
        if ($selection->version !== self::VERSION) {
            throw new \InvalidArgumentException('Unsupported published_content recipe version.');
        }
        if (!hash_equals(self::digest(), $selection->artifactDigest)) {
            throw new \InvalidArgumentException('The published_content recipe digest does not match the installed first-party recipe.');
        }
        if ($selection->capability !== 'published_content') {
            throw new \InvalidArgumentException('The published_content recipe must bind the published_content capability.');
        }

        $definitions = [];
        $indexRoutes = [];
        foreach ($manifest->contentTypes as $contentType) {
            if (!str_ends_with($contentType->canonicalRoute, '/{slug}') && $contentType->canonicalRoute !== '/{slug}') {
                throw new \InvalidArgumentException("Published content type {$contentType->id} requires a terminal {slug} canonical route.");
            }
            $indexRoute = $this->indexRoute($contentType->canonicalRoute);
            if (isset($indexRoutes[$indexRoute])) {
                throw new \InvalidArgumentException("Published content index route collision: {$indexRoute}");
            }
            $indexRoutes[$indexRoute] = true;
            $definitions[] = [
                'bundle' => $contentType->id,
                'fields' => ['title', 'slug', 'summary', 'body', 'status', 'changed'],
                'listing_id' => $contentType->id . '_index',
                'page_size' => 24,
                'access_ops' => ['view'],
                'detail_route' => $contentType->canonicalRoute,
                'index_route' => $indexRoute,
                'schema_type' => $contentType->id === 'page' ? 'WebPage' : 'Article',
            ];
        }

        $artifacts = [
            new GeneratedArtifact('composer.site-recipes.json', $this->composerFragment()),
            new GeneratedArtifact('config/waaseyaa-recipes/published-content.php', $this->configuration($definitions)),
            new GeneratedArtifact('src/Content/CanonicalContentRouteResolver.php', $this->canonicalResolver()),
            new GeneratedArtifact('src/Controller/PublishedContentController.php', $this->controller()),
            new GeneratedArtifact('src/Provider/PublishedContentServiceProvider.php', $this->provider($manifest->application->canonicalOriginConfigKey)),
            new GeneratedArtifact('templates/content/detail.html.twig', $this->detailTemplate()),
            new GeneratedArtifact('templates/content/index.html.twig', $this->indexTemplate()),
            new GeneratedArtifact('tests/Acceptance/PublishedContentRecipeTest.php', $this->acceptanceTest()),
        ];
        foreach ($definitions as $definition) {
            $class = $this->bundleClass($definition['bundle']);
            $artifacts[] = new GeneratedArtifact('src/Content/Bundle/' . $class . '.php', $this->bundleTemplate($definition['bundle'], $class));
        }

        return $artifacts;
    }

    public function id(): string
    {
        return 'published_content';
    }

    public static function digest(): string
    {
        return hash('sha256', CanonicalJson::encode([
            'id' => 'published_content',
            'version' => self::VERSION,
            'entity_type' => 'node',
            'fields' => ['title', 'slug', 'summary', 'body', 'status', 'changed'],
            'listing' => ['page_size' => 24, 'access_ops' => ['view'], 'sort' => ['changed' => 'DESC']],
            'authorities' => ['listing', 'path', 'routing', 'sitemap', 'seo', 'container'],
        ]));
    }

    private function indexRoute(string $detailRoute): string
    {
        $route = preg_replace('#/\{[^/]+\}$#', '', $detailRoute) ?? '';

        return $route === '' ? '/' : $route;
    }

    private function bundleClass(string $bundle): string
    {
        $parts = preg_split('/[_-]+/', $bundle);
        if ($parts === false) {
            throw new \LogicException('The published-content bundle class pattern is invalid.');
        }

        return implode('', array_map(static fn(string $part): string => ucfirst($part), $parts)) . 'Bundle';
    }

    private function bundleTemplate(string $bundle, string $class): string
    {
        return str_replace(['__BUNDLE__', '__CLASS__'], [$bundle, $class], <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Content\Bundle;

            use Waaseyaa\Field\Attribute\BundleTemplate;
            use Waaseyaa\Field\Attribute\FieldTemplate;

            #[BundleTemplate(entityType: 'node', bundle: '__BUNDLE__')]
            final class __CLASS__
            {
                #[FieldTemplate(key: 'summary', type: 'text', label: 'Summary', group: 'content')]
                public string $summary;

                #[FieldTemplate(key: 'body', type: 'text', label: 'Body', group: 'content')]
                public string $body;
            }
            PHP);
    }

    /** @param list<array<string, mixed>> $definitions */
    private function configuration(array $definitions): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($definitions, true) . ";\n";
    }

    private function composerFragment(): string
    {
        return CanonicalJson::encode([
            'extra' => [
                'waaseyaa' => [
                    'providers' => ['App\\Provider\\PublishedContentServiceProvider'],
                ],
            ],
        ]) . "\n";
    }

    private function canonicalResolver(): string
    {
        return <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Content;

            use Waaseyaa\Path\PathAliasResolver;

            final readonly class CanonicalContentRouteResolver
            {
                /** @param list<array<string, mixed>> $definitions */
                public function __construct(
                    private string $canonicalOrigin,
                    private array $definitions,
                    private PathAliasResolver $pathAliasResolver,
                ) {}

                public function canonicalDetailUrl(string $bundle, string $slug): string
                {
                    $definition = $this->definition($bundle);
                    $path = str_replace('{slug}', rawurlencode($slug), (string) $definition['detail_route']);

                    return rtrim($this->canonicalOrigin, '/') . $path;
                }

                public function sitemapUrl(string $bundle, string $slug): string
                {
                    return $this->canonicalDetailUrl($bundle, $slug);
                }

                public function resolveInboundAlias(string $path): ?\Waaseyaa\Path\ResolvedPath
                {
                    return $this->pathAliasResolver->resolve($path);
                }

                /** @return array<string, mixed> */
                private function definition(string $bundle): array
                {
                    foreach ($this->definitions as $definition) {
                        if (($definition['bundle'] ?? null) === $bundle) {
                            return $definition;
                        }
                    }

                    throw new \InvalidArgumentException("Unknown published-content bundle: {$bundle}");
                }
            }
            PHP;
    }

    private function provider(string $originConfigKey): string
    {
        $originConfigKey = var_export($originConfigKey, true);

        return str_replace('__ORIGIN_CONFIG_KEY__', $originConfigKey, <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Provider;

            use App\Content\CanonicalContentRouteResolver;
            use App\Controller\PublishedContentController;
            use Waaseyaa\Listing\HasListingsInterface;
            use Waaseyaa\Listing\ListingDefinition;
            use Waaseyaa\Listing\ListingDefinitionRegistry;
            use Waaseyaa\Listing\ListingResolver;
            use Waaseyaa\Listing\Sort;
            use Waaseyaa\Path\PathAliasResolver;
            use Waaseyaa\Routing\RouteBuilder;
            use Waaseyaa\Routing\WaaseyaaRouter;
            use Waaseyaa\Seo\MetaTagBuilder;
            use Waaseyaa\Seo\SchemaOrg\EntitySchemaOrgMapper;
            use Waaseyaa\Seo\SitemapGenerator;
            use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
            use Waaseyaa\Field\BundleTemplateCompiler;
            use Twig\Environment;
            use Waaseyaa\Access\EntityAccessHandler;
            use Waaseyaa\Entity\EntityTypeManagerInterface;

            final class PublishedContentServiceProvider extends ServiceProvider implements HasListingsInterface
            {
                /** @var list<array<string, mixed>>|null */
                private ?array $definitions = null;

                public function register(): void
                {
                    $this->singleton(PathAliasResolver::class, fn(): PathAliasResolver => new PathAliasResolver(
                        $this->resolve(EntityTypeManagerInterface::class)->getRepository('path_alias'),
                    ));
                    $this->singleton(CanonicalContentRouteResolver::class, fn(): CanonicalContentRouteResolver => new CanonicalContentRouteResolver(
                        (string) ($this->config[__ORIGIN_CONFIG_KEY__] ?? ''),
                        $this->definitions(),
                        $this->resolve(PathAliasResolver::class),
                    ));
                    $this->singleton(PublishedContentController::class, fn(): PublishedContentController => new PublishedContentController(
                        $this->resolve(ListingResolver::class),
                        $this->resolve(ListingDefinitionRegistry::class),
                        $this->resolve(CanonicalContentRouteResolver::class),
                        $this->resolve(SitemapGenerator::class),
                        $this->resolve(MetaTagBuilder::class),
                        $this->resolve(EntitySchemaOrgMapper::class),
                        $this->resolve(EntityTypeManagerInterface::class),
                        $this->resolve(EntityAccessHandler::class),
                        $this->resolve(Environment::class),
                    ));
                }

                public function boot(): void
                {
                    $classes = array_map(
                        static fn(array $definition): string => 'App\\Content\\Bundle\\' . str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $definition['bundle']))) . 'Bundle',
                        $this->definitions(),
                    );
                    $this->resolve(BundleTemplateCompiler::class)->compile($classes);
                }

                /** @return list<ListingDefinition> */
                public function listings(): array
                {
                    return array_map(static fn(array $definition): ListingDefinition => new ListingDefinition(
                        id: $definition['listing_id'],
                        entityType: 'node',
                        bundle: $definition['bundle'],
                        sorts: [Sort::desc('changed')],
                        pageSize: 24,
                        accessOps: ['view'],
                    ), $this->definitions());
                }

                public function routes(WaaseyaaRouter $router, \Waaseyaa\Entity\EntityTypeManager $entityTypeManager): void
                {
                    foreach ($this->definitions() as $definition) {
                        $bundle = (string) $definition['bundle'];
                        $controller = fn(): PublishedContentController => $this->resolve(PublishedContentController::class);
                        $router->addRoute('site.' . $bundle . '.index', RouteBuilder::create($definition['index_route'])
                            ->controller(fn(\Symfony\Component\HttpFoundation\Request $request) => $controller()->index($request, $bundle))
                            ->allowAll()->methods('GET')->build());
                        $router->addRoute('site.' . $bundle . '.detail', RouteBuilder::create($definition['detail_route'])
                            ->controller(fn(\Symfony\Component\HttpFoundation\Request $request) => $controller()->detail($request, $bundle))
                            ->allowAll()->methods('GET')->build());
                    }
                    $router->addRoute('site.sitemap', RouteBuilder::create('/sitemap.xml')
                        ->controller(fn() => $this->resolve(PublishedContentController::class)->sitemap())
                        ->allowAll()->methods('GET')->build());
                }

                /** @return list<array<string, mixed>> */
                private function definitions(): array
                {
                    return $this->definitions ??= require $this->projectRoot . '/config/waaseyaa-recipes/published-content.php';
                }
            }
            PHP);
    }

    private function controller(): string
    {
        return <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Controller;

            use App\Content\CanonicalContentRouteResolver;
            use Twig\Environment;
            use Waaseyaa\Access\AccountInterface;
            use Waaseyaa\Access\EntityAccessHandler;
            use Waaseyaa\Entity\EntityInterface;
            use Waaseyaa\Entity\EntityTypeManagerInterface;
            use Waaseyaa\Foundation\Http\Request;
            use Waaseyaa\Listing\ExposedFilterParser;
            use Waaseyaa\Listing\ListingDefinitionRegistry;
            use Waaseyaa\Listing\ListingResolver;
            use Waaseyaa\Seo\MetaTagBuilder;
            use Waaseyaa\Seo\SchemaOrg\EntitySchemaOrgMapper;
            use Waaseyaa\Seo\SitemapGenerator;
            use Waaseyaa\User\AnonymousUser;

            final readonly class PublishedContentController
            {
                public function __construct(
                    private ListingResolver $listings,
                    private ListingDefinitionRegistry $definitions,
                    private CanonicalContentRouteResolver $canonicalRoutes,
                    private SitemapGenerator $sitemap,
                    private MetaTagBuilder $metaTags,
                    private EntitySchemaOrgMapper $jsonLd,
                    private EntityTypeManagerInterface $entityTypes,
                    private EntityAccessHandler $access,
                    private Environment $twig,
                ) {}

                public function index(Request $request, string $bundle): \Symfony\Component\HttpFoundation\Response
                {
                    $definition = $this->definitions->get($bundle . '_index');
                    $filters = ExposedFilterParser::create()->parse($request->query->all(), $definition);
                    $result = $this->listings->resolve($definition, $filters);
                    $account = $this->account($request);
                    $rows = [];
                    foreach ($result->rows as $entity) {
                        $label = $this->access->viewableLabel($entity, $account, $this->entityTypes);
                        if ($label === null) {
                            continue;
                        }
                        $rows[] = [
                            'label' => $label,
                            'canonical_url' => $this->canonicalRoutes->canonicalDetailUrl($bundle, (string) $entity->get('slug')),
                        ];
                    }

                    return new \Symfony\Component\HttpFoundation\Response($this->twig->render('content/index.html.twig', [
                        'bundle' => $bundle,
                        'rows' => $rows,
                        'pagination' => $result->pagination,
                    ]));
                }

                public function detail(Request $request, string $bundle): \Symfony\Component\HttpFoundation\Response
                {
                    $resolved = $this->canonicalRoutes->resolveInboundAlias($request->getPathInfo());
                    if ($resolved === null || $resolved->entityTypeId !== 'node') {
                        return new \Symfony\Component\HttpFoundation\Response('Not found', 404);
                    }
                    $entity = $this->entityTypes->getRepository('node')->find($resolved->entityId);
                    $account = $this->account($request);
                    if (!$entity instanceof EntityInterface || !$this->access->check($entity, 'view', $account)->isAllowed()) {
                        return new \Symfony\Component\HttpFoundation\Response('Not found', 404);
                    }
                    $label = $this->access->viewableLabel($entity, $account, $this->entityTypes);
                    if ($label === null) {
                        return new \Symfony\Component\HttpFoundation\Response('Not found', 404);
                    }
                    $canonical = $this->canonicalRoutes->canonicalDetailUrl($bundle, (string) $entity->get('slug'));
                    $description = $this->access->checkFieldAccess($entity, 'summary', 'view', $account)->isForbidden()
                        ? null
                        : (string) $entity->get('summary');
                    $head = $this->metaTags->buildHeadSnippet($label, $description, $canonical);
                    $structuredData = $this->jsonLd->toScriptTag($this->jsonLd->map(
                        $entity,
                        $canonical,
                        $description,
                        labelOverride: $label,
                    ));

                    return new \Symfony\Component\HttpFoundation\Response($this->twig->render('content/detail.html.twig', [
                        'bundle' => $bundle,
                        'title' => $label,
                        'body' => (string) $entity->get('body'),
                        'head' => $head,
                        'structured_data' => $structuredData,
                    ]));
                }

                public function sitemap(): \Symfony\Component\HttpFoundation\Response
                {
                    $anonymous = new AnonymousUser(['access content']);
                    $urls = $this->sitemap->collectFromEntityTypes(
                        $this->entityTypes,
                        function (string $entityType, int|string $id): ?string {
                            $entity = $this->entityTypes->getRepository($entityType)->find($id);
                            if (!$entity instanceof EntityInterface || $entityType !== 'node') {
                                return null;
                            }

                            return $this->canonicalRoutes->sitemapUrl($entity->bundle(), (string) $entity->get('slug'));
                        },
                        includeEntityTypes: ['node'],
                        account: $anonymous,
                    );

                    return new \Symfony\Component\HttpFoundation\Response($this->sitemap->toXml($urls), 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
                }

                private function account(Request $request): AccountInterface
                {
                    $account = $request->attributes->get('_account');

                    return $account instanceof AccountInterface ? $account : new AnonymousUser(['access content']);
                }
            }
            PHP;
    }

    private function indexTemplate(): string
    {
        return <<<'TWIG'
            <main class="content-index" data-listing="{{ bundle }}_index">
              <h1>{{ bundle|title }}</h1>
              {% for item in rows %}
                <article><a href="{{ item.canonical_url }}">{{ item.label }}</a></article>
              {% endfor %}
              {% include 'content/pagination.html.twig' ignore missing %}
            </main>
            TWIG;
    }

    private function detailTemplate(): string
    {
        return <<<'TWIG'
            {{ head|raw }}
            {{ structured_data|raw }}
            <main class="content-detail" data-content-bundle="{{ bundle }}">
              <h1>{{ title }}</h1>
              <div class="content-body">{{ body }}</div>
            </main>
            TWIG;
    }

    private function acceptanceTest(): string
    {
        return <<<'PHP'
            <?php

            declare(strict_types=1);

            use PHPUnit\Framework\TestCase;

            final class PublishedContentRecipeTest extends TestCase
            {
                /** @return list<array<string, mixed>> */
                private function definitions(): array
                {
                    return require dirname(__DIR__, 2) . '/config/waaseyaa-recipes/published-content.php';
                }

                public function testListingIsAccessAwareAndPageable(): void
                {
                    foreach ($this->definitions() as $definition) {
                        self::assertSame(['view'], $definition['access_ops']);
                        self::assertGreaterThan(0, $definition['page_size']);
                        self::assertLessThanOrEqual(100, $definition['page_size']);
                    }
                }

                public function testCanonicalRoutesDriveSitemapUrls(): void
                {
                    foreach ($this->definitions() as $definition) {
                        self::assertStringContainsString('{slug}', $definition['detail_route']);
                        self::assertStringStartsWith('/', $definition['index_route']);
                    }
                }

                public function testMetadataAndJsonLdUseTheCanonicalUrl(): void
                {
                    $provider = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Provider/PublishedContentServiceProvider.php');
                    self::assertStringContainsString('MetaTagBuilder::class', $provider);
                    self::assertStringContainsString('EntitySchemaOrgMapper::class', $provider);
                }

                public function testInternalEntityPathsNeverEnterTheSitemap(): void
                {
                    foreach ($this->definitions() as $definition) {
                        self::assertStringNotContainsString('/node/', $definition['detail_route']);
                        self::assertStringNotContainsString('/node/', $definition['index_route']);
                    }
                }
            }
            PHP;
    }
}
