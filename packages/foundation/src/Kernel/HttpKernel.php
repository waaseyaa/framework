<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Kernel;

use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\Routing\RequestContext;
use Waaseyaa\Access\AccessChecker;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Gate\EntityAccessGate;
use Waaseyaa\Access\Middleware\AuthorizationMiddleware;
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
use Waaseyaa\Foundation\Http\JsonApiResponseTrait;
use Waaseyaa\Foundation\Http\Router as HttpRouter;
use Waaseyaa\Foundation\Log\LogManager;
use Waaseyaa\Foundation\Log\Processor\RequestContextProcessor;
use Waaseyaa\Foundation\Middleware\DebugHeaderMiddleware;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\Foundation\Middleware\HttpPipeline;
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
 * to controllers. Returns a Symfony Response for the caller to send.
 */
final class HttpKernel extends AbstractKernel
{
    use JsonApiResponseTrait;

    private ?RenderCache $renderCache = null;
    private ?CacheBackendInterface $discoveryCache = null;
    private ?CacheBackendInterface $mcpReadCache = null;
    private ?CacheConfigResolver $cacheConfigResolver = null;
    private ?DiscoveryApiHandler $discoveryHandler = null;
    private ?SsrPageHandler $ssrPageHandler = null;

    public function handle(): HttpResponse
    {
        try {
            $this->boot();
        } catch (\Throwable $e) {
            $this->logger->critical(sprintf("Boot failed: %s in %s:%d\n%s", $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString()));

            return $this->jsonApiResponse(500, [
                'jsonapi' => ['version' => '1.1'],
                'errors' => [['status' => '500', 'title' => 'Internal Server Error', 'detail' => 'Application failed to boot.']],
            ]);
        }

        try {
            return $this->serveHttpRequest();
        } catch (\Throwable $e) {
            $this->logger->critical(sprintf(
                '[Waaseyaa] Unhandled HTTP exception: %s in %s:%d%s',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                PHP_EOL . $e->getTraceAsString(),
            ));

            return $this->jsonApiResponse(500, [
                'jsonapi' => ['version' => '1.1'],
                'errors' => [['status' => '500', 'title' => 'Internal Server Error', 'detail' => 'An unexpected error occurred.']],
            ]);
        }
    }

    /**
     * Runs CORS, routing, middleware, and controller dispatch. Returns a
     * Symfony Response; uncaught throwables bubble to handle().
     */
    private function serveHttpRequest(): HttpResponse
    {
        $this->cacheConfigResolver = new CacheConfigResolver($this->config);

        $corsResponse = $this->handleCors();
        if ($corsResponse !== null) {
            return $corsResponse;
        }

        $method = $_SERVER['REQUEST_METHOD'];
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        if (!is_string($path)) {
            return $this->jsonApiResponse(400, ['jsonapi' => ['version' => '1.1'], 'errors' => [['status' => '400', 'title' => 'Bad Request', 'detail' => 'Malformed request URI.']]]);
        }
        // Register request context on the logger so all subsequent log entries carry HTTP context.
        // Note: if RequestIdProcessor is also active (via config), it writes request_id independently.
        // This processor does not pass a request_id to avoid overwriting the config-driven one.
        if ($this->logger instanceof LogManager) {
            $this->logger->addGlobalProcessor(new RequestContextProcessor($method, $path));
        }

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
        if (class_exists(\Waaseyaa\AI\Vector\SqliteEmbeddingStorage::class)) {
            $listenerRegistrar->registerEmbeddingLifecycleListeners(new \Waaseyaa\AI\Vector\SqliteEmbeddingStorage($pdo), $this->config);
        }
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
                // First check provider bindings.
                foreach ($this->providers as $provider) {
                    if (isset($provider->getBindings()[$className])) {
                        try {
                            return $provider->resolve($className);
                        } catch (\Throwable $e) {
                            $this->logger->error(sprintf('Failed to resolve %s: %s', $className, $e->getMessage()));
                            return null;
                        }
                    }
                }

                // Fall through to kernel-level services (DatabaseInterface, etc.).
                $kernelServices = [
                    \Waaseyaa\Database\DatabaseInterface::class => $this->database,
                ];
                return $kernelServices[$className] ?? null;
            },
            gate: new EntityAccessGate($this->accessHandler),
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
            return $this->jsonApiResponse(404, ['jsonapi' => ['version' => '1.1'], 'errors' => [['status' => '404', 'title' => 'Not Found', 'detail' => 'No route matches the requested path.']]]);
        } catch (\Symfony\Component\Routing\Exception\MethodNotAllowedException) {
            return $this->jsonApiResponse(405, ['jsonapi' => ['version' => '1.1'], 'errors' => [['status' => '405', 'title' => 'Method Not Allowed', 'detail' => "Method {$method} is not allowed for this route."]]]);
        } catch (\Throwable $e) {
            $this->logger->critical(sprintf("Routing error: %s in %s:%d\n%s", $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString()));
            return $this->jsonApiResponse(500, ['jsonapi' => ['version' => '1.1'], 'errors' => [['status' => '500', 'title' => 'Internal Server Error', 'detail' => 'A routing error occurred.']]]);
        }

        // Authorization pipeline.
        $httpRequest = HttpRequest::createFromGlobals();
        $routeName = $params['_route'] ?? '';
        $matchedRoute = $router->getRouteCollection()->get($routeName);
        // Populate request attributes from route match (controller, route params, etc.).
        foreach ($params as $key => $value) {
            $httpRequest->attributes->set($key, $value);
        }
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
                $this->logger,
                $this->sessionCookieOptions(),
                is_array($this->config['trusted_proxies'] ?? null) ? $this->config['trusted_proxies'] : [],
            ),
            new CsrfMiddleware(),
            new AuthorizationMiddleware($accessChecker, $errorPageRenderer),
        ];

        if ($this->isDebugMode()) {
            $middlewares[] = new DebugHeaderMiddleware(
                startTime: $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true),
            );
        }

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
            $this->logger->critical(sprintf("Authorization pipeline error: %s in %s:%d\n%s", $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString()));

            return $this->jsonApiResponse(500, ['jsonapi' => ['version' => '1.1'], 'errors' => [['status' => '500', 'title' => 'Internal Server Error', 'detail' => 'An authorization error occurred.']]]);
        }

        if ($authResponse->getStatusCode() >= 400) {
            return $authResponse;
        }

        $account = $httpRequest->attributes->get('_account');
        if (!$account instanceof AccountInterface) {
            $this->logger->error('_account attribute missing or invalid after authorization pipeline.');

            return $this->jsonApiResponse(500, ['jsonapi' => ['version' => '1.1'], 'errors' => [['status' => '500', 'title' => 'Internal Server Error', 'detail' => 'Account resolution failed.']]]);
        }

        // Collect GraphQL mutation overrides from providers.
        $gqlOverrides = [];
        foreach ($this->providers as $provider) {
            foreach ($provider->graphqlMutationOverrides($this->entityTypeManager) as $name => $override) {
                $gqlOverrides[$name] = $override;
            }
        }

        // Populate request attributes for WaaseyaaContext::fromRequest().
        $httpRequest->attributes->set('_broadcast_storage', $broadcastStorage);
        $httpRequest->attributes->set('_parsed_body', $this->parseJsonBody($httpRequest));

        // Build the deterministic router chain.
        $routers = [
            new HttpRouter\JsonApiRouter($this->entityTypeManager, $this->accessHandler),
            new HttpRouter\EntityTypeLifecycleRouter($this->entityTypeManager, $this->lifecycleManager),
            new HttpRouter\SchemaRouter($this->entityTypeManager, $this->accessHandler),
            new HttpRouter\DiscoveryRouter($this->discoveryHandler, $this->entityTypeManager),
            new HttpRouter\SearchRouter($this->config, $this->database, $this->entityTypeManager),
            new HttpRouter\MediaRouter($this->projectRoot, $this->config),
            new HttpRouter\GraphQlRouter($this->entityTypeManager, $this->accessHandler, $gqlOverrides),
            new HttpRouter\McpRouter($this->entityTypeManager, $this->accessHandler, $this->database, $this->config, $this->mcpReadCache),
            new HttpRouter\SsrRouter($this->ssrPageHandler),
            new HttpRouter\BroadcastRouter($this->logger),
        ];

        $dispatcher = new ControllerDispatcher($routers, $this->config, $this->logger);

        return $dispatcher->dispatch($httpRequest);
    }

    /**
     * Optional session cookie ini overrides from config/waaseyaa.php under session.cookie.
     *
     * @return array<string, mixed>|null
     */
    private function sessionCookieOptions(): ?array
    {
        $session = $this->config['session'] ?? null;
        if (!is_array($session)) {
            return null;
        }
        $cookie = $session['cookie'] ?? null;

        return is_array($cookie) ? $cookie : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseJsonBody(HttpRequest $request): ?array
    {
        $contentType = $request->headers->get('Content-Type', '');
        if (!str_contains($contentType, 'application/json') && !str_contains($contentType, 'application/vnd.api+json')) {
            return null;
        }

        $raw = $request->getContent();
        if ($raw === '') {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : null;
        } catch (\JsonException) {
            return null;
        }
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

    private function handleCors(): ?HttpResponse
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

        $corsHeaders = [];
        foreach ($corsHandler->resolveCorsHeaders($origin) as $header) {
            header($header);
            [$name, $value] = explode(': ', $header, 2);
            $corsHeaders[$name] = $value;
        }

        if ($corsHandler->isCorsPreflightRequest($_SERVER['REQUEST_METHOD'] ?? 'GET')) {
            return new HttpResponse('', 204, $corsHeaders);
        }

        return null;
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
