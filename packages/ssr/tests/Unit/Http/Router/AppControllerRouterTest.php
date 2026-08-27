<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Tests\Unit\Http\Router;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Api\Controller\BroadcastStorage;
use Waaseyaa\Api\Http\DiscoveryApiHandler;
use Waaseyaa\Routing\CacheConfigResolver;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\SSR\Http\Router\AppControllerRouter;
use Waaseyaa\SSR\SsrPageHandler;
use Waaseyaa\Tests\Support\RuntimeSchemaMigrations;
use Waaseyaa\User\AnonymousUser;

#[CoversClass(AppControllerRouter::class)]
final class AppControllerRouterTest extends TestCase
{
    private function createRouter(bool $debug = false): AppControllerRouter
    {
        $etm = new EntityTypeManager(new EventDispatcher());
        $db = DBALDatabase::createSqlite();
        $handler = new SsrPageHandler(
            entityTypeManager: $etm,
            database: $db,
            renderCache: null,
            cacheConfigResolver: new CacheConfigResolver(),
            discoveryHandler: new DiscoveryApiHandler($etm, $db),
            projectRoot: '/tmp',
            config: [],
        );

        return new AppControllerRouter($handler, debug: $debug);
    }

    private function requestWithController(string $controller): Request
    {
        $request = Request::create('/some/path');
        $request->attributes->set('_controller', $controller);
        $account = new AnonymousUser();
        $request->attributes->set('_account', $account);
        $request->attributes->set('_authorization_principal', $account);
        $database = DBALDatabase::createSqlite();
        RuntimeSchemaMigrations::broadcast($database);
        $request->attributes->set('_broadcast_storage', new BroadcastStorage($database));

        return $request;
    }

    #[Test]
    public function supports_namespaced_class_method_controller(): void
    {
        self::assertTrue(
            $this->createRouter()->supports($this->requestWithController('App\\Controller\\FooController::handle')),
        );
    }

    #[Test]
    public function supports_top_level_class_method_controller(): void
    {
        self::assertTrue(
            $this->createRouter()->supports($this->requestWithController('FooController::handle')),
        );
    }

    #[Test]
    public function does_not_support_render_page(): void
    {
        self::assertFalse(
            $this->createRouter()->supports($this->requestWithController('render.page')),
        );
    }

    #[Test]
    public function does_not_support_named_sentinels(): void
    {
        $router = $this->createRouter();
        $sentinels = ['broadcast', 'graphql.endpoint', 'mcp.endpoint', 'openapi', 'media.upload', 'search.semantic', 'entity_types', 'entity_type.disable', 'discovery.hub'];
        foreach ($sentinels as $controller) {
            self::assertFalse(
                $router->supports($this->requestWithController($controller)),
                "Should not support {$controller}",
            );
        }
    }

    #[Test]
    public function does_not_support_substring_class_names(): void
    {
        $router = $this->createRouter();
        foreach (['Waaseyaa\\Api\\Controller\\JsonApiController', 'Waaseyaa\\Api\\Controller\\SchemaController', 'ApiDiscoveryController'] as $controller) {
            self::assertFalse(
                $router->supports($this->requestWithController($controller)),
                "Should not support {$controller} without ::method",
            );
        }
    }

    #[Test]
    public function does_not_support_empty_or_missing_controller(): void
    {
        $router = $this->createRouter();
        $request = Request::create('/');
        self::assertFalse($router->supports($request));

        $request->attributes->set('_controller', '');
        self::assertFalse($router->supports($request));
    }

    #[Test]
    public function does_not_support_string_with_whitespace(): void
    {
        self::assertFalse(
            $this->createRouter()->supports($this->requestWithController('App\\Controller\\Foo::handle bar')),
        );
    }

    #[Test]
    public function does_not_support_string_without_double_colon(): void
    {
        self::assertFalse(
            $this->createRouter()->supports($this->requestWithController('App\\Controller\\FooController')),
        );
    }

    #[Test]
    public function does_not_support_string_with_lowercase_top_level_segment(): void
    {
        self::assertFalse(
            $this->createRouter()->supports($this->requestWithController('foo::bar')),
        );
    }

    #[Test]
    public function does_not_support_string_with_empty_method(): void
    {
        self::assertFalse(
            $this->createRouter()->supports($this->requestWithController('App\\Controller\\Foo::')),
        );
    }

    #[Test]
    public function does_not_support_string_with_empty_class(): void
    {
        self::assertFalse(
            $this->createRouter()->supports($this->requestWithController('::handle')),
        );
    }

    #[Test]
    public function resolvedDebugDecisionControlsBindingErrorDisclosure(): void
    {
        $request = $this->requestWithController('MissingController::handle');

        $redacted = $this->createRouter(debug: false)->handle($request);
        self::assertStringContainsString('A server error occurred.', (string) $redacted->getContent());
        self::assertStringNotContainsString('Request is missing _route_object', (string) $redacted->getContent());

        $detailed = $this->createRouter(debug: true)->handle($request);
        self::assertStringContainsString('Request is missing _route_object', (string) $detailed->getContent());
    }
}
