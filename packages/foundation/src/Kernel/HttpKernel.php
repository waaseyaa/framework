<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Kernel;

use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\Routing\RequestContext;
use Waaseyaa\Access\AccessChecker;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\ErrorPageRendererInterface;
use Waaseyaa\Access\Gate\EntityAccessGate;
use Waaseyaa\Access\Middleware\AuthorizationMiddleware;
use Waaseyaa\Api\CodifiedContext\CodifiedContextSessionStoreInterface;
use Waaseyaa\Api\Controller\BroadcastStorage;
use Waaseyaa\Api\Http\DiscoveryApiHandler;
use Waaseyaa\Cache\Backend\DatabaseBackend;
use Waaseyaa\Cache\CacheBackendInterface;
use Waaseyaa\Cache\CacheConfiguration;
use Waaseyaa\Cache\CacheFactory;
use Waaseyaa\Foundation\Attribute\AsMiddleware;
use Waaseyaa\Foundation\Http\ControllerDispatcher;
use Waaseyaa\Foundation\Http\CorsHandler;
use Waaseyaa\Foundation\Http\HttpServiceResolverInterface;
use Waaseyaa\Foundation\Http\Inertia\InertiaFullPageRendererInterface;
use Waaseyaa\Foundation\Http\JsonApiResponseTrait;
use Waaseyaa\Foundation\Http\LanguagePathStripperInterface;
use Waaseyaa\Foundation\Http\Router as HttpRouter;
use Waaseyaa\Foundation\Kernel\Bootstrap\ProviderRegistryKernelServices;
use Waaseyaa\Foundation\Kernel\Http\HttpKernelServiceResolver;
use Waaseyaa\Foundation\Log\LogManager;
use Waaseyaa\Foundation\Log\Processor\RequestContextProcessor;
use Waaseyaa\Foundation\Middleware\DebugHeaderMiddleware;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\Foundation\Middleware\HttpPipeline;
use Waaseyaa\Foundation\Middleware\SecurityHeadersMiddleware;
use Waaseyaa\Foundation\ServiceProvider\Capability\ConfiguresHttpKernelInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\HasHttpDomainRoutersInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\HasMiddlewareInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\HasRenderCacheListenersInterface;
use Waaseyaa\Routing\Exception\RouteMethodNotAllowedException;
use Waaseyaa\Routing\Exception\RouteNotFoundException;
use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Routing\WaaseyaaRouter;
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
 *
 * Error surface: JSON:API (`application/vnd.api+json`) for boot failures and
 * for unhandled exceptions after boot. See docs/specs/infrastructure.md
 * "HTTP error surface (JSON-first)".
 * @api
 */
final class HttpKernel extends AbstractKernel
{
    use JsonApiResponseTrait;

    private ?CacheBackendInterface $renderCacheBackend = null;
    private ?CacheBackendInterface $discoveryCache = null;
    private ?CacheBackendInterface $mcpReadCache = null;
    private ?DiscoveryApiHandler $discoveryHandler = null;

    private ?CodifiedContextSessionStoreInterface $codifiedContextSessionStore = null;

    public function handle(): HttpResponse
    {
        try {
            $this->boot();
        } catch (\Throwable $e) {
            $this->logger->critical(sprintf("Boot failed: %s in %s:%d\n%s", $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString()));

            return $this->bootFailureJsonResponse($e);
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

    protected function finalizeBoot(): void
    {
        assert($this->database instanceof \Waaseyaa\Database\DBALDatabase);
        $pdo = $this->database->getConnection()->getNativeConnection();
        assert($pdo instanceof \PDO);

        $cacheConfig = new CacheConfiguration();
        $cacheHmacKey = is_string($this->config['cache']['hmac_key'] ?? null) ? $this->config['cache']['hmac_key'] : null;
        $cacheConfig->setFactoryForBin('render', fn(): DatabaseBackend => new DatabaseBackend(
            $pdo,
            'cache_render',
            hmacKey: $cacheHmacKey,
        ));
        $cacheConfig->setFactoryForBin('discovery', fn(): DatabaseBackend => new DatabaseBackend(
            $pdo,
            'cache_discovery',
            hmacKey: $cacheHmacKey,
        ));
        $cacheConfig->setFactoryForBin('mcp_read', fn(): DatabaseBackend => new DatabaseBackend(
            $pdo,
            'cache_mcp_read',
            hmacKey: $cacheHmacKey,
        ));
        $cacheFactory = new CacheFactory($cacheConfig);
        $this->renderCacheBackend = $cacheFactory->get('render');
        $this->discoveryCache = $cacheFactory->get('discovery');
        $this->mcpReadCache = $cacheFactory->get('mcp_read');

        $this->discoveryHandler = new DiscoveryApiHandler($this->entityTypeManager, $this->database, $this->discoveryCache);

        $listenerRegistrar = new EventListenerRegistrar($this->dispatcher, $this->logger);
        foreach ($this->providers as $provider) {
            if (!$provider instanceof HasRenderCacheListenersInterface) {
                continue;
            }
            $provider->registerRenderCacheListeners($this->dispatcher, $this->renderCacheBackend);
        }
        $listenerRegistrar->registerDiscoveryCacheListeners($this->discoveryCache);
        $listenerRegistrar->registerMcpReadCacheListeners($this->mcpReadCache);
        if (class_exists(\Waaseyaa\AI\Vector\SqliteEmbeddingStorage::class)) {
            $listenerRegistrar->registerEmbeddingLifecycleListeners(new \Waaseyaa\AI\Vector\SqliteEmbeddingStorage($pdo), $this->config);
        }

        foreach ($this->providers as $provider) {
            if (!$provider instanceof ConfiguresHttpKernelInterface) {
                continue;
            }
            $provider->configureHttpKernel($this);
        }
    }

    public function getDiscoveryApiHandler(): DiscoveryApiHandler
    {
        if ($this->discoveryHandler === null) {
            throw new \LogicException('DiscoveryApiHandler is unavailable before kernel boot completes.');
        }

        return $this->discoveryHandler;
    }

    /**
     * Inertia full-document renderer when waaseyaa/inertia is installed, else null.
     */
    public function getInertiaFullPageRenderer(): ?InertiaFullPageRendererInterface
    {
        return $this->resolveInertiaFullPageRenderer();
    }

    public function getCodifiedContextSessionStore(): ?CodifiedContextSessionStoreInterface
    {
        return $this->codifiedContextSessionStore;
    }

    public function setCodifiedContextSessionStore(?CodifiedContextSessionStoreInterface $store): void
    {
        $this->codifiedContextSessionStore = $store;
    }

    private ?HttpServiceResolverInterface $httpServiceResolver = null;

    /**
     * Returns the SSR controller-method dependency resolver.
     *
     * Replaces the legacy `\Closure(string): ?object` shape with a typed
     * interface; semantics unchanged (provider walk + narrow kernel-services
     * fallback via {@see ProviderRegistryKernelServices}). Mirrors the typed-resolver
     * pattern introduced for {@see \Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface}
     * in mission #824 WP02 surface A.
     */
    public function getHttpServiceResolver(): HttpServiceResolverInterface
    {
        return $this->httpServiceResolver ??= new HttpKernelServiceResolver(
            providersAccessor: fn(): array => $this->providers,
            kernelServices: new ProviderRegistryKernelServices(
                entityTypeManager: $this->entityTypeManager,
                database: $this->database,
                dispatcher: $this->dispatcher,
                logger: $this->logger,
                providersAccessor: fn(): array => $this->providers,
                manifest: $this->manifest,
            ),
            logger: $this->logger,
        );
    }

    private function resolveErrorPageRenderer(): ?ErrorPageRendererInterface
    {
        foreach ($this->providers as $provider) {
            if (!isset($provider->getBindings()[ErrorPageRendererInterface::class])) {
                continue;
            }
            try {
                $resolved = $provider->resolve(ErrorPageRendererInterface::class);
                if ($resolved instanceof ErrorPageRendererInterface) {
                    return $resolved;
                }
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private function resolveInertiaFullPageRenderer(): ?InertiaFullPageRendererInterface
    {
        foreach ($this->providers as $provider) {
            if (!isset($provider->getBindings()[InertiaFullPageRendererInterface::class])) {
                continue;
            }
            try {
                $resolved = $provider->resolve(InertiaFullPageRendererInterface::class);
                if ($resolved instanceof InertiaFullPageRendererInterface) {
                    return $resolved;
                }
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private function stripLanguagePrefixForHttpRouting(string $path): string
    {
        foreach ($this->providers as $provider) {
            if ($provider instanceof LanguagePathStripperInterface) {
                return $provider->stripLanguagePrefixForRouting($path);
            }
        }

        return $path;
    }

    /**
     * Runs CORS, routing, middleware, and controller dispatch. Returns a
     * Symfony Response; uncaught throwables bubble to handle().
     */
    private function serveHttpRequest(): HttpResponse
    {
        // Configure trusted reverse proxies BEFORE any code reads
        // $request->isSecure() or other forwarded-header derived values.
        // Required so that $request->isSecure() honors X-Forwarded-Proto
        // when the framework runs behind a TLS-terminating proxy (Caddy,
        // nginx). See #1394 and contracts/csrf-token-cookie.md §1.
        $this->applyTrustedProxiesFromConfig();

        $corsResponse = $this->handleCors();
        if ($corsResponse !== null) {
            return $corsResponse;
        }

        $method = $_SERVER['REQUEST_METHOD'];
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        if (!is_string($path)) {
            return $this->jsonApiResponse(400, ['jsonapi' => ['version' => '1.1'], 'errors' => [['status' => '400', 'title' => 'Bad Request', 'detail' => 'Malformed request URI.']]]);
        }
        if ($this->logger instanceof LogManager) {
            $this->logger->addGlobalProcessor(new RequestContextProcessor($method, $path));
        }

        $broadcastStorage = new BroadcastStorage($this->database);
        $listenerRegistrar = new EventListenerRegistrar($this->dispatcher, $this->logger);
        $listenerRegistrar->registerBroadcastListeners($broadcastStorage);

        $path = $this->stripLanguagePrefixForHttpRouting($path);

        $matchResult = $this->matchRoute($path, $method);
        if ($matchResult instanceof HttpResponse) {
            return $matchResult;
        }
        $httpRequest = $matchResult;

        $pipeline = $this->buildMiddlewareStack();

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

        // The pipeline's inner handler returns 200 with an empty body on success.
        // Short-circuit responses (302 login redirect, 401/403 JSON, etc.) must be returned as-is.
        if ($authResponse->getStatusCode() !== 200) {
            return $authResponse;
        }

        $account = $httpRequest->attributes->get('_account');
        if (!$account instanceof AccountInterface) {
            $this->logger->error('_account attribute missing or invalid after authorization pipeline.');

            return $this->jsonApiResponse(500, ['jsonapi' => ['version' => '1.1'], 'errors' => [['status' => '500', 'title' => 'Internal Server Error', 'detail' => 'Account resolution failed.']]]);
        }

        $httpRequest->attributes->set('_broadcast_storage', $broadcastStorage);

        $parsedBody = $this->parseJsonBody($httpRequest);
        if ($parsedBody instanceof HttpResponse) {
            return $parsedBody;
        }
        $httpRequest->attributes->set('_parsed_body', $parsedBody);

        if (!$this->database instanceof \Waaseyaa\Database\DBALDatabase) {
            $this->logger->critical('HTTP dispatch requires DBALDatabase for MCP routing.');

            return $this->jsonApiResponse(500, [
                'jsonapi' => ['version' => '1.1'],
                'errors' => [['status' => '500', 'title' => 'Internal Server Error', 'detail' => 'Database configuration is invalid.']],
            ]);
        }

        $dispatcher = $this->buildRouterChain();

        $finalResponse = $dispatcher->dispatch($httpRequest);

        // Attach the XSRF-TOKEN cookie to HTML responses after controller
        // dispatch. CsrfMiddleware runs in the auth pipeline (before the
        // controller), so it only sees the empty 200 pass-through response,
        // not the real controller response. We call the middleware's static
        // helper here to satisfy contract §1 (cookie on every text/html
        // response) once the actual Content-Type is known.
        CsrfMiddleware::attachCookieIfHtml($httpRequest, $finalResponse);

        // Apply framing / MIME-sniffing security headers to the dispatched
        // response (#1651). SecurityHeadersMiddleware never reaches the real
        // response through the authorization pipeline (its inner handler is a
        // stub 200), so — like the CSRF cookie above — the headers are applied
        // here, post-dispatch. X-Frame-Options defaults to SAMEORIGIN (blocks
        // cross-origin clickjacking, preserves same-origin previews) and is
        // configurable via security_headers.frame_options; routes that must be
        // embeddable cross-origin set the _frame_exempt request attribute to opt
        // out. CSP/HSTS remain opt-in via the middleware constructor.
        $frameOptions = is_array($this->config['security_headers'] ?? null)
            && is_string($this->config['security_headers']['frame_options'] ?? null)
            ? $this->config['security_headers']['frame_options']
            : 'SAMEORIGIN';
        SecurityHeadersMiddleware::applyResponseDefaults($httpRequest, $finalResponse, $frameOptions);

        return $finalResponse;
    }

    /**
     * Build and return the route-matched HttpRequest with all route parameters
     * set as attributes. Returns an HttpResponse on routing errors (404, 405, 500).
     *
     * @return HttpRequest|HttpResponse HttpRequest on success, HttpResponse on routing error.
     */
    private function matchRoute(string $path, string $method): HttpRequest|HttpResponse
    {
        $context = new RequestContext('', $method);
        $router = new WaaseyaaRouter($context);
        $routeRegistrar = new BuiltinRouteRegistrar($this->entityTypeManager, $this->providers);
        $routeRegistrar->register($router);

        try {
            $params = $router->match($path);
        } catch (RouteNotFoundException) {
            return $this->jsonApiResponse(404, ['jsonapi' => ['version' => '1.1'], 'errors' => [['status' => '404', 'title' => 'Not Found', 'detail' => 'No route matches the requested path.']]]);
        } catch (RouteMethodNotAllowedException) {
            return $this->jsonApiResponse(405, ['jsonapi' => ['version' => '1.1'], 'errors' => [['status' => '405', 'title' => 'Method Not Allowed', 'detail' => "Method {$method} is not allowed for this route."]]]);
        } catch (\Throwable $e) {
            $this->logger->critical(sprintf("Routing error: %s in %s:%d\n%s", $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString()));

            return $this->jsonApiResponse(500, ['jsonapi' => ['version' => '1.1'], 'errors' => [['status' => '500', 'title' => 'Internal Server Error', 'detail' => 'A routing error occurred.']]]);
        }

        $httpRequest = HttpRequest::createFromGlobals();
        $routeName = $params['_route'] ?? '';
        $matchedRoute = $router->getRouteCollection()->get($routeName);
        foreach ($params as $key => $value) {
            $httpRequest->attributes->set(
                $key,
                $key === '_controller' ? RouteBuilder::normalizeControllerDefault($value) : $value,
            );
        }
        if ($matchedRoute !== null) {
            $httpRequest->attributes->set('_route_object', $matchedRoute);
        }

        return $httpRequest;
    }

    /**
     * Build the ordered middleware pipeline for the authorization phase.
     *
     * Collects built-in middleware (BearerAuth, Session, CSRF, Authorization),
     * optional debug header middleware, and any provider-contributed middleware,
     * sorts by priority (highest first — outermost onion layer), and returns
     * the assembled HttpPipeline.
     */
    private function buildMiddlewareStack(): HttpPipeline
    {
        // C-22 WP3: read path now goes through the canonical repository.
        $userRepository = $this->entityTypeManager->getRepository('user');
        $gate = new EntityAccessGate($this->accessHandler);
        $accessChecker = new AccessChecker(gate: $gate);
        $errorPageRenderer = $this->resolveErrorPageRenderer();

        $middlewares = [
            new BearerAuthMiddleware(
                $userRepository,
                (string) ($this->config['jwt_secret'] ?? ''),
                is_array($this->config['api_keys'] ?? null) ? $this->config['api_keys'] : [],
            ),
            new SessionMiddleware(
                $userRepository,
                $this->shouldUseDevFallbackAccount() ? new DevAdminAccount() : null,
                $this->logger,
                $this->sessionCookieOptions(),
                is_array($this->config['trusted_proxies'] ?? null) ? $this->config['trusted_proxies'] : [],
                // The kernel's single acting-account context — the middleware
                // mirrors `_account` into it on every request (FR-002).
                accountContext: $this->accountContext(),
            ),
            new CsrfMiddleware(),
            new AuthorizationMiddleware($accessChecker, $errorPageRenderer),
        ];

        if ($this->isDebugMode()) {
            $middlewares[] = new DebugHeaderMiddleware(
                startTime: $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true),
            );
        }

        foreach ($this->providers as $provider) {
            if (!$provider instanceof HasMiddlewareInterface) {
                continue;
            }
            foreach ($provider->middleware($this->entityTypeManager) as $mw) {
                $middlewares[] = $mw;
            }
        }

        usort($middlewares, fn(object $a, object $b) => $this->getMiddlewarePriority($b) <=> $this->getMiddlewarePriority($a));

        $pipeline = new HttpPipeline();
        foreach ($middlewares as $middleware) {
            $pipeline = $pipeline->withMiddleware($middleware);
        }

        return $pipeline;
    }

    /**
     * Assemble the domain-router chain and wrap it in a ControllerDispatcher.
     *
     * Merges foundation routers, provider-contributed routers, and the
     * BroadcastRouter (always last), then returns a ready-to-dispatch
     * ControllerDispatcher.
     */
    private function buildRouterChain(): ControllerDispatcher
    {
        $foundationRouters = [
            new HttpRouter\TranslationRouter($this->entityTypeManager, $this->accessHandler),
            new HttpRouter\JsonApiRouter($this->entityTypeManager, $this->accessHandler),
            new HttpRouter\EntityTypeLifecycleRouter($this->entityTypeManager, $this->lifecycleManager),
            new HttpRouter\SchemaRouter($this->entityTypeManager, $this->accessHandler, $this->fieldRegistry),
            new HttpRouter\CodifiedContextApiRouter($this->codifiedContextSessionStore),
            new HttpRouter\WorkflowDefinitionsApiRouter(),
            new HttpRouter\SearchRouter($this->config, $this->database, $this->entityTypeManager, $this->accessHandler),
        ];

        $providerRouters = [];
        foreach ($this->providers as $provider) {
            if (!$provider instanceof HasHttpDomainRoutersInterface) {
                continue;
            }
            foreach ($provider->httpDomainRouters($this) as $domainRouter) {
                $providerRouters[] = $domainRouter;
            }
        }

        // Wire the SSE subscriber-tracking path so BroadcastRouter records each
        // connection (the write side the monitor dashboard reads) AND can enforce
        // the per-account concurrent-stream cap (#1704). Resolved identically to
        // MercureMonitorServiceProvider's read side (same flag + path) so the two
        // never diverge; null when the monitor is disabled, which also disables
        // the cap.
        $broadcastMonitorEnabled = $this->config['broadcasting']['monitor']['enabled'] ?? true;
        $broadcastSubscribersPath = $broadcastMonitorEnabled === false
            ? null
            : ($this->config['broadcasting']['monitor']['subscribers_path']
                ?? (($this->config['storage_path'] ?? './storage') . '/broadcast/subscribers.json'));

        $routers = array_merge($foundationRouters, $providerRouters, [
            new HttpRouter\BroadcastRouter($this->logger, $broadcastSubscribersPath),
        ]);

        return new ControllerDispatcher(
            $routers,
            $this->config,
            $this->logger,
            $this->resolveInertiaFullPageRenderer(),
        );
    }

    /**
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
     * @return array<string, mixed>|HttpResponse|null
     */
    private function parseJsonBody(HttpRequest $request): array|HttpResponse|null
    {
        if (!in_array($request->getMethod(), ['POST', 'PATCH', 'PUT', 'DELETE'], true)) {
            return null;
        }

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
            return $this->jsonApiResponse(400, [
                'jsonapi' => ['version' => '1.1'],
                'errors' => [['status' => '400', 'title' => 'Bad Request', 'detail' => 'Invalid JSON in request body.']],
            ]);
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

    private function bootFailureJsonResponse(\Throwable $e): HttpResponse
    {
        $showDetail = false;
        try {
            $showDetail = $this->isDebugMode();
        } catch (\Throwable) {
            $appDebugEnv = getenv('APP_DEBUG');
            $showDetail = filter_var($appDebugEnv === false ? '' : $appDebugEnv, FILTER_VALIDATE_BOOLEAN);
        }

        $detail = $showDetail
            ? $e->getMessage()
            : new BootFailureMessageFormatter()->format($e);

        try {
            $body = json_encode([
                'jsonapi' => ['version' => '1.1'],
                'errors' => [['status' => '500', 'title' => 'Internal Server Error', 'detail' => $detail]],
            ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $body = '{"jsonapi":{"version":"1.1"},"errors":[{"status":"500","title":"Internal Server Error","detail":"Application failed to boot."}]}';
        }

        return new HttpResponse($body, 500, ['Content-Type' => 'application/vnd.api+json']);
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

    /**
     * Apply the configured trusted-proxy list to Symfony's Request.
     *
     * Resolution order:
     *  1. `$this->config['trusted_proxies']` (array of strings)
     *  2. `getenv('TRUSTED_PROXIES')` — comma-separated CIDRs / IPs / the
     *     Symfony sentinel `REMOTE_ADDR` (meaning "trust the connecting
     *     peer, resolved at request time by Symfony").
     *
     * When the resolved list is empty, no call is made and Symfony's
     * default behavior (ignore all X-Forwarded-* headers) is preserved —
     * the safe default for setups without a TLS terminator.
     *
     * The standard X-Forwarded-* header set is enabled when proxies are
     * configured. `TRUSTED_HEADER_SET` is intentionally undocumented
     * (advanced operators only) and is not surfaced as an env knob here.
     */
    private function applyTrustedProxiesFromConfig(): void
    {
        $trustedProxies = $this->resolveTrustedProxies();
        if ($trustedProxies === []) {
            return;
        }

        HttpRequest::setTrustedProxies(
            $trustedProxies,
            HttpRequest::HEADER_X_FORWARDED_FOR
            | HttpRequest::HEADER_X_FORWARDED_HOST
            | HttpRequest::HEADER_X_FORWARDED_PROTO
            | HttpRequest::HEADER_X_FORWARDED_PORT,
        );
    }

    /**
     * Resolve the effective trusted-proxy list from config + env.
     *
     * Config wins when set; env var is the fallback. Whitespace around
     * comma-separated env entries is trimmed; empty entries are dropped.
     * The Symfony `REMOTE_ADDR` sentinel is passed through verbatim —
     * Symfony resolves it at request time, not at setTrustedProxies time.
     *
     * @return list<string>
     */
    private function resolveTrustedProxies(): array
    {
        $configured = $this->config['trusted_proxies'] ?? null;
        if (is_array($configured) && $configured !== []) {
            $normalized = [];
            foreach ($configured as $entry) {
                if (!is_string($entry)) {
                    continue;
                }
                $trimmed = trim($entry);
                if ($trimmed !== '') {
                    $normalized[] = $trimmed;
                }
            }

            return $normalized;
        }

        $envValue = getenv('TRUSTED_PROXIES');
        if (!is_string($envValue) || $envValue === '') {
            return [];
        }

        $entries = array_map('trim', explode(',', $envValue));

        return array_values(array_filter($entries, static fn(string $e): bool => $e !== ''));
    }

    /**
     * SAPIs under which the dev fallback admin account may be enabled.
     *
     * `cli-server` is `php -S`. `frankenphp` is the FrankenPHP runtime, which is
     * also used in production — so the SAPI alone is not a safe gate there. The
     * real gates remain {@see isDevelopmentMode()} (APP_ENV must be a dev
     * environment) and the explicit `auth.dev_fallback_account` opt-in below; a
     * production FrankenPHP deployment satisfies neither and stays locked.
     */
    private const array DEV_FALLBACK_SAPIS = ['cli-server', 'frankenphp'];

    private function shouldUseDevFallbackAccount(?string $sapi = null): bool
    {
        $resolvedSapi = $sapi ?? PHP_SAPI;
        if (!in_array($resolvedSapi, self::DEV_FALLBACK_SAPIS, true)) {
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
