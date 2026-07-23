<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldAccessPolicyInterface;
use Waaseyaa\Api\Http\DiscoveryApiHandler;
use Waaseyaa\Cache\Backend\MemoryBackend;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Foundation\Http\HttpServiceResolverInterface;
use Waaseyaa\Foundation\Kernel\Http\HttpKernelServiceResolver;
use Waaseyaa\Foundation\Log\LogLevel;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\LoggerTrait;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Field\FieldFormatterInterface;
use Waaseyaa\Path\PathAlias;
use Waaseyaa\Routing\CacheConfigResolver;
use Waaseyaa\SSR\FieldFormatterRegistry;
use Waaseyaa\SSR\PageComposition\EntityPageComposerInterface;
use Waaseyaa\SSR\PageComposition\EntityPageRenderPayload;
use Waaseyaa\SSR\RenderCache;
use Waaseyaa\SSR\RenderController;
use Waaseyaa\SSR\SsrPageHandler;
use Waaseyaa\SSR\SsrServiceProvider;

#[CoversClass(SsrPageHandler::class)]
final class SsrEntityPageCompositionTest extends TestCase
{
    private DBALDatabase $db;
    private EntityTypeManager $entityTypeManager;
    private EntityRepository $pageRepository;
    private EntityRepository $aliasRepository;

    protected function setUp(): void
    {
        RecordingCompositionLogger::$messages = [];
        CountingBodyFormatter::$calls = 0;
        CountingBodyFormatter::$factoryObservedFormatting = false;
        SsrServiceProvider::setFormatterRegistry(null);
        $this->db = DBALDatabase::createSqlite();
        $dispatcher = new EventDispatcher();
        $repositories = [];

        $this->entityTypeManager = new EntityTypeManager(
            $dispatcher,
            null,
            static function (string $id) use (&$repositories): EntityRepository {
                return $repositories[$id];
            },
        );

        $pageType = new EntityType(
            id: 'composed_page',
            label: 'Composed page',
            class: ComposedPageFixture::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
            group: 'content',
            _fieldDefinitions: [
                'title' => new FieldDefinition(name: 'title', type: 'string', read: FieldReadLevel::Public),
                'body' => new FieldDefinition(name: 'body', type: 'text_long', read: FieldReadLevel::Public),
                'secret_note' => new FieldDefinition(name: 'secret_note', type: 'string', read: FieldReadLevel::Public),
                'status' => new FieldDefinition(name: 'status', type: 'boolean', read: FieldReadLevel::Public),
            ],
        );
        $aliasType = EntityType::fromClass(PathAlias::class, group: 'structure');

        foreach ([$pageType, $aliasType] as $type) {
            new SqlSchemaHandler($type, $this->db)->ensureTable();
            $repository = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
                $type,
                new SqlStorageDriver(new SingleConnectionResolver($this->db)),
                $dispatcher,
                database: $this->db,
            );
            $repositories[$type->id()] = $repository;
            $this->entityTypeManager->registerEntityType($type);
        }

        $this->pageRepository = $repositories['composed_page'];
        $this->aliasRepository = $repositories['path_alias'];

        SsrServiceProvider::setTwigEnvironment(new Environment(new ArrayLoader([
            'entity.html.twig' => '<framework>{{ title }}'
                . '{% for name, field in fields %}<field name="{{ name }}">{{ field.formatted|raw }}</field>{% endfor %}'
                . '</framework>',
            '403.html.twig' => '<error status="403">Forbidden</error>',
            '404.html.twig' => '<error status="404">Not found</error>',
        ])));
    }

    protected function tearDown(): void
    {
        SsrServiceProvider::setTwigEnvironment(null);
        SsrServiceProvider::setFormatterRegistry(null);
    }

    #[Test]
    public function registered_composer_wraps_an_authorized_alias_with_only_safe_render_fragments(): void
    {
        $entity = $this->createPage();
        $this->createAlias('/2026/07/10/community-update', $entity);
        $composer = new RecordingEntityPageComposer(
            static fn(EntityPageRenderPayload $page): Response => new Response(
                '<app-shell><h1>' . $page->title . '</h1>' . $page->bodyHtml() . '</app-shell>',
                200,
                ['X-App-Shell' => 'sfn'],
            ),
        );

        $result = $this->handler($this->fieldFilteringHandler(), $composer)->handleRenderPage(
            '/2026/07/10/community-update/',
            $this->anonymous(),
            Request::create('/2026/07/10/community-update/'),
        );

        self::assertSame(200, $result['status'], implode("\n", RecordingCompositionLogger::$messages));
        self::assertStringContainsString('<app-shell>', (string) $result['content']);
        self::assertStringContainsString('<p>Community body</p>', (string) $result['content']);
        self::assertStringNotContainsString('private-note', (string) $result['content']);
        self::assertStringNotContainsString('<script', (string) $result['content']);
        self::assertSame('sfn', $result['headers']['x-app-shell']);
        self::assertCount(1, $composer->payloads);

        $payload = $composer->payloads[0];
        self::assertSame('/2026/07/10/community-update/', $payload->requestPath);
        self::assertSame('composed_page', $payload->entityType);
        self::assertSame('composed_page', $payload->bundle);
        self::assertSame('full', $payload->viewMode);
        self::assertSame('en', $payload->langcode);
        self::assertNull($payload->field('secret_note'));
        self::assertNotNull($payload->field('body'));
        self::assertStringContainsString('application/ld+json', $payload->schemaOrgJsonLd);
        self::assertStringContainsString('class="pb-band pb-component"', $payload->bodyCompositionHtml);
        self::assertStringContainsString('src="/media/community.jpg"', $payload->bodyCompositionHtml);
        self::assertStringNotContainsString('onerror', $payload->bodyCompositionHtml);
        self::assertStringNotContainsString('javascript:', $payload->bodyCompositionHtml);
        self::assertStringNotContainsString('<script', $payload->bodyCompositionHtml);
        self::assertSame('private, no-store', $result['headers']['Cache-Control']);
        self::assertArrayNotHasKey('Surrogate-Key', $result['headers']);
        self::assertSame('Accept', $result['headers']['Vary']);
    }

    #[Test]
    public function an_unregistered_resolver_is_byte_identical_to_no_resolver(): void
    {
        $entity = $this->createPage();
        $path = '/composed_page/' . $entity->id();
        $account = $this->anonymous();

        $withoutResolver = $this->handler($this->fieldFilteringHandler())->handleRenderPage(
            $path,
            $account,
            Request::create($path),
        );
        $withEmptyResolver = $this->handler(
            $this->fieldFilteringHandler(),
            null,
            new CallbackServiceResolver(static fn(string $class): ?object => null),
        )->handleRenderPage($path, $account, Request::create($path));

        self::assertSame($withoutResolver, $withEmptyResolver);
        self::assertStringContainsString('<framework>', (string) $withoutResolver['content']);
    }

    #[Test]
    public function production_no_binding_preserves_one_format_on_a_miss_and_zero_formats_on_a_cache_hit(): void
    {
        SsrServiceProvider::setFormatterRegistry(new FieldFormatterRegistry([
            'text_long' => CountingBodyFormatter::class,
        ]));
        $entity = $this->createPage();
        $path = '/composed_page/' . $entity->id();
        $cache = new RenderCache(new MemoryBackend());

        $baseline = $this->handler(
            $this->fieldFilteringHandler(),
            renderCache: $cache,
        )->handleRenderPage($path, $this->anonymous(), Request::create($path));
        self::assertSame(1, CountingBodyFormatter::$calls);

        CountingBodyFormatter::$calls = 0;
        $cached = $this->handler(
            $this->fieldFilteringHandler(),
            serviceResolver: $this->kernelResolver([]),
            renderCache: $cache,
        )->handleRenderPage($path, $this->anonymous(), Request::create($path));

        self::assertSame($baseline, $cached);
        self::assertSame(0, CountingBodyFormatter::$calls, 'an absent production binding must not format fields before cache lookup');

        CountingBodyFormatter::$calls = 0;
        $miss = $this->handler(
            $this->fieldFilteringHandler(),
            serviceResolver: $this->kernelResolver([]),
            renderCache: new RenderCache(new MemoryBackend()),
        )->handleRenderPage($path, $this->anonymous(), Request::create($path));

        self::assertSame($baseline, $miss);
        self::assertSame(1, CountingBodyFormatter::$calls, 'an absent production binding must format fields exactly once on a miss');
    }

    #[Test]
    public function production_composer_factory_runs_only_after_the_authorized_payload_is_formatted(): void
    {
        SsrServiceProvider::setFormatterRegistry(new FieldFormatterRegistry([
            'text_long' => CountingBodyFormatter::class,
        ]));
        $entity = $this->createPage();
        $path = '/composed_page/' . $entity->id();
        $composer = new RecordingEntityPageComposer(
            static fn(): Response => new Response('<app-shell>ordered</app-shell>'),
        );
        $provider = $this->composerProvider(static function () use ($composer): EntityPageComposerInterface {
            CountingBodyFormatter::$factoryObservedFormatting = CountingBodyFormatter::$calls > 0;

            return $composer;
        });

        $result = $this->handler(
            $this->fieldFilteringHandler(),
            serviceResolver: $this->kernelResolver([$provider]),
        )->handleRenderPage($path, $this->anonymous(), Request::create($path));

        self::assertSame(200, $result['status']);
        self::assertTrue(CountingBodyFormatter::$factoryObservedFormatting);
        self::assertCount(1, $composer->payloads);
    }

    #[Test]
    public function the_public_render_controller_has_no_caller_supplied_raw_bag_entry_point(): void
    {
        self::assertFalse(method_exists(RenderController::class, 'renderEntityBag'));
    }

    #[Test]
    public function registered_decline_and_failure_fallbacks_reuse_one_authorized_formatting_pass(): void
    {
        SsrServiceProvider::setFormatterRegistry(new FieldFormatterRegistry([
            'text_long' => CountingBodyFormatter::class,
        ]));
        $entity = $this->createPage();
        $path = '/composed_page/' . $entity->id();
        $cases = [
            'decline' => static fn(): ?Response => null,
            'failure' => static function (): Response {
                throw new \RuntimeException('formatter-count failure');
            },
        ];

        foreach ($cases as $name => $callback) {
            CountingBodyFormatter::$calls = 0;
            $result = $this->handler(
                $this->fieldFilteringHandler(),
                new RecordingEntityPageComposer($callback),
            )->handleRenderPage($path, $this->anonymous(), Request::create($path));

            self::assertSame(200, $result['status'], $name);
            self::assertStringContainsString('<framework>', (string) $result['content'], $name);
            self::assertSame(1, CountingBodyFormatter::$calls, $name);
        }
    }

    #[Test]
    public function throwing_empty_redirecting_and_non_html_composers_fall_back_to_complete_framework_html(): void
    {
        $entity = $this->createPage();
        $path = '/composed_page/' . $entity->id();
        $cases = [
            'throw' => static function (): Response {
                throw new \RuntimeException('broken app renderer');
            },
            'empty' => static fn(): Response => new Response(''),
            'redirect' => static fn(): Response => new Response('moved', 302, ['Location' => '/elsewhere']),
            'json' => static fn(): Response => new Response('{}', 200, ['Content-Type' => 'application/json']),
            'html-prefix' => static fn(): Response => new Response(
                '<app-shell>invalid media token</app-shell>',
                200,
                ['Content-Type' => 'text/htmlfoo'],
            ),
        ];

        foreach ($cases as $name => $callback) {
            $composer = new RecordingEntityPageComposer($callback);
            $result = $this->handler($this->fieldFilteringHandler(), $composer)->handleRenderPage(
                $path,
                $this->anonymous(),
                Request::create($path),
            );

            self::assertSame(200, $result['status'], $name);
            self::assertStringContainsString('<framework>', (string) $result['content'], $name);
            self::assertStringContainsString('Community body', (string) $result['content'], $name);
            self::assertStringNotContainsString('private-note', (string) $result['content'], $name);
            self::assertSame('private, no-store', $result['headers']['Cache-Control'], $name);
            self::assertArrayNotHasKey('Surrogate-Key', $result['headers'], $name);
        }
    }

    #[Test]
    public function a_null_decline_uses_the_framework_renderer(): void
    {
        $entity = $this->createPage();
        $path = '/composed_page/' . $entity->id();
        $composer = new RecordingEntityPageComposer(static fn(): ?Response => null);

        $result = $this->handler($this->fieldFilteringHandler(), $composer)->handleRenderPage(
            $path,
            $this->anonymous(),
            Request::create($path),
        );

        self::assertSame(200, $result['status']);
        self::assertStringContainsString('<framework>', (string) $result['content']);
        self::assertCount(1, $composer->payloads);
    }

    #[Test]
    public function a_throwing_composer_service_resolution_uses_the_framework_renderer(): void
    {
        $entity = $this->createPage();
        $path = '/composed_page/' . $entity->id();
        $resolutions = 0;
        $resolver = new CallbackServiceResolver(static function (string $class) use (&$resolutions): ?object {
            if ($class === EntityPageComposerInterface::class) {
                ++$resolutions;
                throw new \RuntimeException('broken binding');
            }

            return null;
        });

        $handler = $this->handler(
            $this->fieldFilteringHandler(),
            serviceResolver: $resolver,
            renderCache: new RenderCache(new MemoryBackend()),
        );
        $first = $handler->handleRenderPage($path, $this->anonymous(), Request::create($path));
        $second = $handler->handleRenderPage($path, $this->anonymous(), Request::create($path));

        self::assertSame(200, $first['status']);
        self::assertSame(200, $second['status']);
        self::assertStringContainsString('<framework>', (string) $first['content']);
        self::assertStringContainsString('<framework>', (string) $second['content']);
        self::assertSame(2, $resolutions, 'a broken binding fallback must not become sticky in render cache');
        self::assertSame('private, no-store', $first['headers']['Cache-Control']);
        self::assertSame('private, no-store', $second['headers']['Cache-Control']);
        self::assertArrayNotHasKey('Surrogate-Key', $first['headers']);
        self::assertStringContainsString('composer resolution failed', implode("\n", RecordingCompositionLogger::$messages));
    }

    #[Test]
    public function production_throwing_composer_factory_is_distinguished_from_no_binding_and_never_cached(): void
    {
        $entity = $this->createPage();
        $path = '/composed_page/' . $entity->id();
        $attempts = 0;
        $provider = $this->composerProvider(static function () use (&$attempts): EntityPageComposerInterface {
            ++$attempts;
            throw new \RuntimeException('production factory failure');
        });
        $handler = $this->handler(
            $this->fieldFilteringHandler(),
            serviceResolver: $this->kernelResolver([$provider]),
            renderCache: new RenderCache(new MemoryBackend()),
        );

        $first = $handler->handleRenderPage($path, $this->anonymous(), Request::create($path));
        $second = $handler->handleRenderPage($path, $this->anonymous(), Request::create($path));

        self::assertSame(2, $attempts);
        self::assertStringContainsString('<framework>', (string) $first['content']);
        self::assertStringContainsString('<framework>', (string) $second['content']);
        self::assertSame('private, no-store', $first['headers']['Cache-Control']);
        self::assertSame('private, no-store', $second['headers']['Cache-Control']);
        self::assertArrayNotHasKey('Surrogate-Key', $first['headers']);
    }

    #[Test]
    public function a_composer_failure_fallback_is_not_cached(): void
    {
        $entity = $this->createPage();
        $path = '/composed_page/' . $entity->id();
        $composer = new RecordingEntityPageComposer(static function (): Response {
            throw new \RuntimeException('transient failure');
        });
        $handler = $this->handler(
            $this->fieldFilteringHandler(),
            $composer,
            renderCache: new RenderCache(new MemoryBackend()),
        );

        $first = $handler->handleRenderPage($path, $this->anonymous(), Request::create($path));
        $second = $handler->handleRenderPage($path, $this->anonymous(), Request::create($path));

        self::assertSame(200, $first['status']);
        self::assertSame(200, $second['status']);
        self::assertStringContainsString('<framework>', (string) $first['content']);
        self::assertStringContainsString('<framework>', (string) $second['content']);
        self::assertCount(2, $composer->payloads, 'the second request must retry composition instead of reading a cached failure fallback');
        self::assertSame('private, no-store', $first['headers']['Cache-Control']);
        self::assertSame('private, no-store', $second['headers']['Cache-Control']);
        self::assertArrayNotHasKey('Surrogate-Key', $first['headers']);
    }

    #[Test]
    public function accepted_composed_headers_are_preserved_but_the_document_is_never_shared_or_persisted(): void
    {
        $entity = $this->createPage();
        $path = '/composed_page/' . $entity->id();
        $composer = new RecordingEntityPageComposer(static function (): Response {
            $response = new Response(
                '<app-shell>session-sensitive chrome</app-shell>',
                200,
                ['Vary' => 'Cookie'],
            );
            $response->headers->setCookie(Cookie::create('shell', 'one')->withHttpOnly());
            $response->headers->setCookie(Cookie::create('theme', 'night')->withHttpOnly());

            return $response;
        });
        $handler = $this->handler(
            $this->fieldFilteringHandler(),
            $composer,
            renderCache: new RenderCache(new MemoryBackend()),
        );

        $first = $handler->handleRenderPage($path, $this->anonymous(), Request::create($path));
        $second = $handler->handleRenderPage($path, $this->anonymous(), Request::create($path));

        self::assertSame(200, $first['status']);
        self::assertSame(200, $second['status']);
        self::assertCount(2, $composer->payloads, 'opaque app-shell dependencies must be recomposed for every request');
        self::assertSame('private, no-store', $first['headers']['Cache-Control']);
        self::assertSame('Cookie, Accept', $first['headers']['Vary']);
        self::assertIsArray($first['headers']['set-cookie']);
        self::assertCount(2, $first['headers']['set-cookie']);
        self::assertStringStartsWith('shell=one;', $first['headers']['set-cookie'][0]);
        self::assertStringStartsWith('theme=night;', $first['headers']['set-cookie'][1]);
        self::assertArrayNotHasKey('Surrogate-Key', $first['headers']);
    }

    #[Test]
    public function alias_and_canonical_requests_preserve_their_inbound_paths_without_redirecting(): void
    {
        $entity = $this->createPage();
        $this->createAlias('/community-update', $entity);
        $composer = new RecordingEntityPageComposer(
            static fn(EntityPageRenderPayload $page): Response => new Response('<app-shell>' . $page->requestPath . '</app-shell>'),
        );
        $handler = $this->handler(
            $this->fieldFilteringHandler(),
            $composer,
            renderCache: new RenderCache(new MemoryBackend()),
        );

        $alias = $handler->handleRenderPage('/community-update/', $this->anonymous(), Request::create('/community-update/'));
        $canonicalPath = '/composed_page/' . $entity->id();
        $canonical = $handler->handleRenderPage($canonicalPath, $this->anonymous(), Request::create($canonicalPath));

        self::assertSame(200, $alias['status']);
        self::assertSame(200, $canonical['status']);
        self::assertSame('/community-update/', $composer->payloads[0]->requestPath);
        self::assertSame($canonicalPath, $composer->payloads[1]->requestPath);
        self::assertArrayNotHasKey('location', $alias['headers']);
        self::assertArrayNotHasKey('location', $canonical['headers']);
    }

    #[Test]
    public function existing_missing_and_view_denied_branches_never_invoke_composition(): void
    {
        $entity = $this->createPage();
        $composer = new RecordingEntityPageComposer(
            static fn(): Response => new Response('<app-shell>must not render</app-shell>'),
        );
        $resolutions = 0;
        $resolver = new CallbackServiceResolver(
            static function (string $class) use (&$resolutions, $composer): ?object {
                if ($class === EntityPageComposerInterface::class) {
                    ++$resolutions;

                    return $composer;
                }

                return null;
            },
        );

        $missing = $this->handler($this->fieldFilteringHandler(), serviceResolver: $resolver)->handleRenderPage(
            '/composed_page/999999',
            $this->anonymous(),
            Request::create('/composed_page/999999'),
        );
        $denied = $this->handler($this->viewDeniedHandler(), serviceResolver: $resolver)->handleRenderPage(
            '/composed_page/' . $entity->id(),
            $this->anonymous(),
            Request::create('/composed_page/' . $entity->id()),
        );

        self::assertSame(404, $missing['status']);
        self::assertSame(403, $denied['status']);
        self::assertStringNotContainsString('app-shell', (string) $missing['content']);
        self::assertStringNotContainsString('app-shell', (string) $denied['content']);
        self::assertSame(0, $resolutions);
        self::assertCount(0, $composer->payloads);
    }

    #[Test]
    public function a_field_access_forbidden_label_and_field_never_reach_the_composer(): void
    {
        $entity = $this->createPage();
        $path = '/composed_page/' . $entity->id();
        $composer = new RecordingEntityPageComposer(
            static fn(EntityPageRenderPayload $page): Response => new Response(
                '<app-shell>' . $page->title . '|' . implode(',', array_keys($page->fields)) . '</app-shell>',
            ),
        );

        $result = $this->handler($this->fieldFilteringHandler(forbidTitle: true), $composer)->handleRenderPage(
            $path,
            $this->anonymous(),
            Request::create($path),
        );

        self::assertSame(200, $result['status']);
        self::assertSame('composed_page', $composer->payloads[0]->title);
        self::assertNull($composer->payloads[0]->field('title'));
        self::assertNull($composer->payloads[0]->field('secret_note'));
        self::assertStringNotContainsString('Community update', (string) $result['content']);
        self::assertStringNotContainsString('private-note', (string) $result['content']);
    }

    #[Test]
    public function a_forbidden_body_has_neither_a_formatted_fragment_nor_a_composition_html_channel(): void
    {
        $entity = $this->createPage();
        $path = '/composed_page/' . $entity->id();
        $composer = new RecordingEntityPageComposer(
            static fn(): Response => new Response('<app-shell>redacted</app-shell>'),
        );

        $result = $this->handler($this->fieldFilteringHandler(forbidBody: true), $composer)->handleRenderPage(
            $path,
            $this->anonymous(),
            Request::create($path),
        );

        self::assertSame(200, $result['status']);
        self::assertNull($composer->payloads[0]->field('body'));
        self::assertSame('', $composer->payloads[0]->bodyHtml());
        self::assertSame('', $composer->payloads[0]->bodyCompositionHtml);
    }

    #[Test]
    public function markdown_never_invokes_the_html_composer(): void
    {
        $entity = $this->createPage();
        $path = '/composed_page/' . $entity->id();
        $composer = new RecordingEntityPageComposer(
            static fn(): Response => new Response('<app-shell>must not render</app-shell>'),
        );

        $result = $this->handler($this->fieldFilteringHandler(), $composer)->handleRenderPage(
            $path,
            $this->anonymous(),
            Request::create($path, 'GET', [], [], [], ['HTTP_ACCEPT' => 'text/markdown']),
        );

        self::assertSame(200, $result['status']);
        self::assertStringNotContainsString('app-shell', (string) $result['content']);
        self::assertCount(0, $composer->payloads);
    }

    private function createPage(): EntityInterface
    {
        $entity = $this->pageRepository->create([
            'title' => 'Community update',
            'body' => '<section class="pb-band pb-component"><img src="/media/community.jpg" alt="Community" onerror="alert(1)">'
                . '<p>Community body</p><a href="javascript:alert(2)">unsafe link</a></section>'
                . '<script>alert("unsafe")</script>',
            'secret_note' => 'private-note',
            'status' => true,
        ]);
        $this->pageRepository->save($entity, validate: false);

        return $entity;
    }

    private function createAlias(string $aliasPath, EntityInterface $entity): void
    {
        $alias = $this->aliasRepository->create([
            'alias' => $aliasPath,
            'path' => '/composed_page/' . $entity->id(),
            'langcode' => 'en',
            'status' => true,
        ]);
        $this->aliasRepository->save($alias, validate: false);
    }

    private function anonymous(): AccountInterface
    {
        $account = $this->createMock(AuthorizationPrincipalInterface::class);
        $account->method('isAuthenticated')->willReturn(false);
        $account->method('hasPermission')->willReturn(false);

        return $account;
    }

    private function fieldFilteringHandler(bool $forbidTitle = false, bool $forbidBody = false): EntityAccessHandler
    {
        $handler = new EntityAccessHandler([
            new class ($forbidTitle, $forbidBody) implements AccessPolicyInterface, FieldAccessPolicyInterface {
                public function __construct(
                    private readonly bool $forbidTitle,
                    private readonly bool $forbidBody,
                ) {}

                public function appliesTo(string $entityTypeId): bool
                {
                    return $entityTypeId === 'composed_page';
                }

                public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
                {
                    return $operation === 'view' ? AccessResult::allowed() : AccessResult::neutral();
                }

                public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
                {
                    return AccessResult::neutral();
                }

                public function fieldAccess(EntityInterface $entity, string $fieldName, string $operation, AccountInterface $account): AccessResult
                {
                    return $fieldName === 'secret_note'
                        || ($this->forbidTitle && $fieldName === 'title')
                        || ($this->forbidBody && $fieldName === 'body')
                        ? AccessResult::forbidden()
                        : AccessResult::neutral();
                }
            },
        ]);

        return $handler;
    }

    private function viewDeniedHandler(): EntityAccessHandler
    {
        return new EntityAccessHandler([
            new class implements AccessPolicyInterface {
                public function appliesTo(string $entityTypeId): bool
                {
                    return $entityTypeId === 'composed_page';
                }

                public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
                {
                    return $operation === 'view' ? AccessResult::forbidden() : AccessResult::neutral();
                }

                public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
                {
                    return AccessResult::neutral();
                }
            },
        ]);
    }

    private function handler(
        EntityAccessHandler $accessHandler,
        ?EntityPageComposerInterface $composer = null,
        ?HttpServiceResolverInterface $serviceResolver = null,
        ?RenderCache $renderCache = null,
    ): SsrPageHandler {
        if ($serviceResolver === null && $composer !== null) {
            $serviceResolver = new CallbackServiceResolver(
                static fn(string $class): ?object => $class === EntityPageComposerInterface::class ? $composer : null,
            );
        }

        return new SsrPageHandler(
            entityTypeManager: $this->entityTypeManager,
            database: $this->db,
            renderCache: $renderCache,
            cacheConfigResolver: new CacheConfigResolver([]),
            discoveryHandler: new DiscoveryApiHandler($this->entityTypeManager, $this->db),
            projectRoot: '/tmp/test-project',
            config: [],
            serviceResolver: $serviceResolver,
            logger: new RecordingCompositionLogger(),
            accessHandler: $accessHandler,
        );
    }

    /** @param list<ServiceProvider> $providers */
    private function kernelResolver(array $providers): HttpKernelServiceResolver
    {
        $database = $this->db;

        return new HttpKernelServiceResolver(
            providersAccessor: static fn(): array => $providers,
            kernelServices: new class ($database) implements KernelServicesInterface {
                public function __construct(private readonly DBALDatabase $database) {}

                public function get(string $abstract): ?object
                {
                    return $abstract === \Waaseyaa\Database\DatabaseInterface::class ? $this->database : null;
                }
            },
            logger: new NullLogger(),
        );
    }

    /** @param \Closure(): EntityPageComposerInterface $factory */
    private function composerProvider(\Closure $factory): ServiceProvider
    {
        $provider = new class ($factory) extends ServiceProvider {
            public function __construct(private readonly \Closure $factory) {}

            public function register(): void
            {
                $this->singleton(EntityPageComposerInterface::class, $this->factory);
            }
        };
        $provider->register();

        return $provider;
    }
}

final class RecordingEntityPageComposer implements EntityPageComposerInterface
{
    /** @var list<EntityPageRenderPayload> */
    public array $payloads = [];

    /** @var \Closure(EntityPageRenderPayload): ?Response */
    private \Closure $callback;

    /** @param callable(EntityPageRenderPayload): ?Response $callback */
    public function __construct(callable $callback)
    {
        $this->callback = \Closure::fromCallable($callback);
    }

    public function compose(EntityPageRenderPayload $page): ?Response
    {
        $this->payloads[] = $page;

        return ($this->callback)($page);
    }
}

final class CallbackServiceResolver implements HttpServiceResolverInterface
{
    /** @var \Closure(string): ?object */
    private \Closure $callback;

    /** @param callable(string): ?object $callback */
    public function __construct(callable $callback)
    {
        $this->callback = \Closure::fromCallable($callback);
    }

    public function resolve(string $className): ?object
    {
        return ($this->callback)($className);
    }
}

final class ComposedPageFixture extends ContentEntityBase
{
}

final class RecordingCompositionLogger implements LoggerInterface
{
    use LoggerTrait;

    /** @var list<string> */
    public static array $messages = [];

    public function log(LogLevel $level, string|\Stringable $message, array $context = []): void
    {
        self::$messages[] = $level->value . ': ' . (string) $message;
    }
}

final class CountingBodyFormatter implements FieldFormatterInterface
{
    public static int $calls = 0;
    public static bool $factoryObservedFormatting = false;

    public function format(mixed $value, array $settings = []): string
    {
        ++self::$calls;

        return is_string($value) ? $value : '';
    }
}
