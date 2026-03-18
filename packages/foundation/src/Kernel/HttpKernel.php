<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Kernel;

use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\Routing\RequestContext;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Gate\EntityAccessGate;
use Waaseyaa\Access\Middleware\AuthorizationMiddleware;
use Waaseyaa\AI\Vector\SqliteEmbeddingStorage;
use Waaseyaa\Api\Controller\BroadcastStorage;
use Waaseyaa\Api\Http\DiscoveryApiHandler;
use Waaseyaa\Cache\Backend\DatabaseBackend;
use Waaseyaa\Cache\CacheBackendInterface;
use Waaseyaa\Cache\CacheConfigResolver;
use Waaseyaa\Cache\CacheConfiguration;
use Waaseyaa\Cache\CacheFactory;
use Waaseyaa\Foundation\Attribute\AsMiddleware;
use Waaseyaa\Foundation\Http\ControllerDispatcher;
use Waaseyaa\Foundation\Http\CorsHandler;
use Waaseyaa\Foundation\Http\ResponseSender;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\Foundation\Middleware\HttpPipeline;
use Waaseyaa\Routing\AccessChecker;
use Waaseyaa\Routing\WaaseyaaRouter;
use Waaseyaa\SSR\RenderCache;
use Waaseyaa\SSR\SsrPageHandler;
use Waaseyaa\SSR\SsrServiceProvider;
use Waaseyaa\SSR\TwigErrorPageRenderer;
use Waaseyaa\User\DevAdminAccount;
use Waaseyaa\User\Middleware\BearerAuthMiddleware;
use Waaseyaa\User\Middleware\CsrfMiddleware;
use Waaseyaa\User\Middleware\SessionMiddleware;

/**
 * HTTP front controller kernel.
 *
 * Boots the application, handles CORS, matches routes, runs the
 * authorization pipeline (Session -> Authorization), and dispatches
 * to controllers. The handle() method is terminal (returns never).
 */
final class HttpKernel extends AbstractKernel
{
    private ?RenderCache $renderCache = null;
    private ?CacheBackendInterface $discoveryCache = null;
    private ?CacheBackendInterface $mcpReadCache = null;
    private ?CacheConfigResolver $cacheConfigResolver = null;
    private ?DiscoveryApiHandler $discoveryHandler = null;
    private ?SsrPageHandler $ssrPageHandler = null;

    public function handle(): never
    {
        try {
            $this->boot();
        } catch (\Throwable $e) {
            error_log(sprintf("[Waaseyaa] Boot failed: %s in %s:%d\n%s", $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString()));
            ResponseSender::json(500, [
                'jsonapi' => ['version' => '1.1'],
                'errors' => [['status' => '500', 'title' => 'Internal Server Error', 'detail' => 'Application failed to boot.']],
            ]);
        }
        $this->cacheConfigResolver = new CacheConfigResolver($this->config);

        $this->handleCors();

        $method = $_SERVER['REQUEST_METHOD'];
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        if (!is_string($path)) {
            ResponseSender::json(400, ['jsonapi' => ['version' => '1.1'], 'errors' => [['status' => '400', 'title' => 'Bad Request', 'detail' => 'Malformed request URI.']]]);
        }
        $queryString = $_SERVER['QUERY_STRING'] ?? '';

        // Broadcast storage for SSE.
        $broadcastStorage = new BroadcastStorage($this->database);
        $listenerRegistrar = new EventListenerRegistrar($this->dispatcher);
        $listenerRegistrar->registerBroadcastListeners($broadcastStorage);

        // Escape hatch: components that still require raw PDO (cache, embeddings).
        // These will be migrated to DBAL Connection in a future PR.
        assert($this->database instanceof \Waaseyaa\Database\DBALDatabase);
        $pdo = $this->database->getConnection()->getNativeConnection();
        assert($pdo instanceof \PDO);

        $cacheConfig = new CacheConfiguration();
        $cacheConfig->setFactoryForBin('render', fn(): DatabaseBackend => new DatabaseBackend(
            $pdo,
            'cache_render',
        ));
        $cacheConfig->setFactoryForBin('discovery', fn(): DatabaseBackend => new DatabaseBackend(
            $pdo,
            'cache_discovery',
        ));
        $cacheConfig->setFactoryForBin('mcp_read', fn(): DatabaseBackend => new DatabaseBackend(
            $pdo,
            'cache_mcp_read',
        ));
        $cacheFactory = new CacheFactory($cacheConfig);
        $this->renderCache = new RenderCache($cacheFactory->get('render'));
        $this->discoveryCache = $cacheFactory->get('discovery');
        $this->mcpReadCache = $cacheFactory->get('mcp_read');
        $listenerRegistrar->registerRenderCacheListeners($this->renderCache);
        $listenerRegistrar->registerDiscoveryCacheListeners($this->discoveryCache);
        $listenerRegistrar->registerMcpReadCacheListeners($this->mcpReadCache);
        $listenerRegistrar->registerEmbeddingLifecycleListeners(new SqliteEmbeddingStorage($pdo), $this->config);
        $this->discoveryHandler = new DiscoveryApiHandler($this->entityTypeManager, $this->database, $this->discoveryCache);
        $this->ssrPageHandler = new SsrPageHandler(
            entityTypeManager: $this->entityTypeManager,
            database: $this->database,
            renderCache: $this->renderCache,
            cacheConfigResolver: $this->cacheConfigResolver,
            discoveryHandler: $this->discoveryHandler,
            projectRoot: $this->projectRoot,
            config: $this->config,
            manifest: $this->manifest,
            serviceResolver: function (string $className): ?object {
                foreach ($this->providers as $provider) {
                    if (isset($provider->getBindings()[$className])) {
                        try {
                            return $provider->resolve($className);
                        } catch (\Throwable $e) {
                            error_log(sprintf('[Waaseyaa] Failed to resolve %s: %s', $className, $e->getMessage()));
                            return null;
                        }
                    }
                }
                return null;
            },
        );

        // Router setup.
        $context = new RequestContext('', $method);
        $router = new WaaseyaaRouter($context);
        $routeRegistrar = new BuiltinRouteRegistrar($this->entityTypeManager, $this->providers);
        $routeRegistrar->register($router);

        // Strip language prefix before routing so /oj/communities matches /communities.
        $path = $this->ssrPageHandler->stripLanguagePrefixForRouting($path);

        // Route matching.
        try {
            $params = $router->match($path);
        } catch (\Symfony\Component\Routing\Exception\ResourceNotFoundException) {
            ResponseSender::json(404, ['jsonapi' => ['version' => '1.1'], 'errors' => [['status' => '404', 'title' => 'Not Found', 'detail' => 'No route matches the requested path.']]]);
        } catch (\Symfony\Component\Routing\Exception\MethodNotAllowedException) {
            ResponseSender::json(405, ['jsonapi' => ['version' => '1.1'], 'errors' => [['status' => '405', 'title' => 'Method Not Allowed', 'detail' => "Method {$method} is not allowed for this route."]]]);
        } catch (\Throwable $e) {
            error_log(sprintf("[Waaseyaa] Routing error: %s in %s:%d\n%s", $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString()));
            ResponseSender::json(500, ['jsonapi' => ['version' => '1.1'], 'errors' => [['status' => '500', 'title' => 'Internal Server Error', 'detail' => 'A routing error occurred.']]]);
        }

        // Authorization pipeline.
        $httpRequest = HttpRequest::createFromGlobals();
        $routeName = $params['_route'] ?? '';
        $matchedRoute = $router->getRouteCollection()->get($routeName);
        if ($matchedRoute !== null) {
            $httpRequest->attributes->set('_route_object', $matchedRoute);
        }

        $userStorage = $this->entityTypeManager->getStorage('user');
        $gate = new EntityAccessGate($this->accessHandler);
        $accessChecker = new AccessChecker(gate: $gate);
        $twigEnv = SsrServiceProvider::getTwigEnvironment();
        $errorPageRenderer = $twigEnv !== null ? new TwigErrorPageRenderer($twigEnv) : null;

        $middlewares = [
            new BearerAuthMiddleware(
                $userStorage,
                (string) ($this->config['jwt_secret'] ?? ''),
                is_array($this->config['api_keys'] ?? null) ? $this->config['api_keys'] : [],
            ),
            new SessionMiddleware(
                $userStorage,
                $this->shouldUseDevFallbackAccount() ? new DevAdminAccount() : null,
            ),
            new CsrfMiddleware(),
            new AuthorizationMiddleware($accessChecker, $errorPageRenderer),
        ];

        // Collect middleware contributed by service providers.
        foreach ($this->providers as $provider) {
            foreach ($provider->middleware($this->entityTypeManager) as $mw) {
                $middlewares[] = $mw;
            }
        }

        usort($middlewares, fn(object $a, object $b) => $this->getMiddlewarePriority($b) <=> $this->getMiddlewarePriority($a));

        $pipeline = new HttpPipeline();
        foreach ($middlewares as $middleware) {
            $pipeline = $pipeline->withMiddleware($middleware);
        }

        try {
            $authResponse = $pipeline->handle(
                $httpRequest,
                new class implements HttpHandlerInterface {
                    public function handle(HttpRequest $request): HttpResponse
                    {
                        return new HttpResponse('', 200);
                    }
                },
            );
        } catch (\Throwable $e) {
            error_log(sprintf("[Waaseyaa] Authorization pipeline error: %s in %s:%d\n%s", $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString()));
            ResponseSender::json(500, ['jsonapi' => ['version' => '1.1'], 'errors' => [['status' => '500', 'title' => 'Internal Server Error', 'detail' => 'An authorization error occurred.']]]);
        }

        if ($authResponse->getStatusCode() >= 400) {
            $authResponse->send();
            exit;
        }

        $account = $httpRequest->attributes->get('_account');
        if (!$account instanceof AccountInterface) {
            error_log('[Waaseyaa] _account attribute missing or invalid after authorization pipeline.');
            ResponseSender::json(500, ['jsonapi' => ['version' => '1.1'], 'errors' => [['status' => '500', 'title' => 'Internal Server Error', 'detail' => 'Account resolution failed.']]]);
        }

        // Collect GraphQL mutation overrides from providers.
        $gqlOverrides = [];
        foreach ($this->providers as $provider) {
            foreach ($provider->graphqlMutationOverrides($this->entityTypeManager) as $name => $override) {
                $gqlOverrides[$name] = $override;
            }
        }

        // Dispatch.
        $controllerDispatcher = new ControllerDispatcher(
            entityTypeManager: $this->entityTypeManager,
            database: $this->database,
            accessHandler: $this->accessHandler,
            lifecycleManager: $this->lifecycleManager,
            discoveryHandler: $this->discoveryHandler,
            ssrPageHandler: $this->ssrPageHandler,
            mcpReadCache: $this->mcpReadCache,
            projectRoot: $this->projectRoot,
            config: $this->config,
            graphqlMutationOverrides: $gqlOverrides,
        );
        $controllerDispatcher->dispatch($method, $params, $httpRequest, $queryString, $broadcastStorage, $account);
    }

    private function getMiddlewarePriority(object $middleware): int
    {
        $reflection = new \ReflectionClass($middleware);
        $attributes = $reflection->getAttributes(AsMiddleware::class);
        if (empty($attributes)) {
            return 0;
        }
        $instance = $attributes[0]->newInstance();
        if ($instance->pipeline !== 'http') {
            return 0;
        }
        return $instance->priority;
    }

    private function handleCors(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $allowedOrigins = $this->config['cors_origins'] ?? ['http://localhost:3000', 'http://127.0.0.1:3000'];
        $overrideOrigin = getenv('WAASEYAA_CORS_ORIGIN');
        if (is_string($overrideOrigin) && trim($overrideOrigin) !== '') {
            $allowedOrigins = [trim($overrideOrigin)];
        }

        $corsHandler = new CorsHandler(
            allowedOrigins: $allowedOrigins,
            allowDevLocalhostPorts: $this->isDevelopmentMode(),
        );

        foreach ($corsHandler->resolveCorsHeaders($origin) as $header) {
            header($header);
        }

        if ($corsHandler->isCorsPreflightRequest($_SERVER['REQUEST_METHOD'] ?? 'GET')) {
            http_response_code(204);
            exit;
        }
    }

    private function isDevelopmentMode(): bool
    {
        $env = $this->config['environment'] ?? getenv('APP_ENV') ?: '';
        if (!is_string($env)) {
            return false;
        }

        return in_array(strtolower($env), ['dev', 'development', 'local'], true);
    }

    private function shouldUseDevFallbackAccount(?string $sapi = null): bool
    {
        $resolvedSapi = $sapi ?? PHP_SAPI;
        if ($resolvedSapi !== 'cli-server') {
            return false;
        }

        if (!$this->isDevelopmentMode()) {
            return false;
        }

        $authConfig = $this->config['auth'] ?? null;
        if (!is_array($authConfig)) {
            return false;
        }

        return ($authConfig['dev_fallback_account'] ?? false) === true;
    }



}
