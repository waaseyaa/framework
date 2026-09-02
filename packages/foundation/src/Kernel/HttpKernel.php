<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Kernel;

use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\RequestContext;
use Waaseyaa\Access\AccessChecker;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\ErrorPageRendererInterface;
use Waaseyaa\Access\Gate\EntityAccessGate;
use Waaseyaa\Access\Middleware\AuthorizationMiddleware;
use Waaseyaa\Access\User\UserInternalFieldReaderInterface;
use Waaseyaa\Api\Controller\BroadcastStorage;
use Waaseyaa\Api\EntityTypeApiExposurePolicy;
use Waaseyaa\Api\Http\DiscoveryApiHandler;
use Waaseyaa\Api\InternalFieldVisibilityPolicy;
use Waaseyaa\Cache\Backend\DatabaseBackend;
use Waaseyaa\Cache\CacheBackendInterface;
use Waaseyaa\Cache\CacheConfiguration;
use Waaseyaa\Cache\CacheFactory;
use Waaseyaa\Cache\EntityPayloadBoundaryConfig;
use Waaseyaa\Cache\ProjectionDeprecationDiagnostic;
use Waaseyaa\Field\FieldSchemaAuthority;
use Waaseyaa\Foundation\Community\CommunityContextInterface;
use Waaseyaa\Foundation\Community\CommunityMiddleware;
use Waaseyaa\Foundation\Http\ControllerDispatcher;
use Waaseyaa\Foundation\Http\CorsHandler;
use Waaseyaa\Foundation\Http\HttpServiceResolverInterface;
use Waaseyaa\Foundation\Http\Inertia\InertiaFullPageRendererInterface;
use Waaseyaa\Foundation\Http\JsonApiResponseTrait;
use Waaseyaa\Foundation\Http\LanguagePathStripperInterface;
use Waaseyaa\Foundation\Http\Refusal\HttpRefusal;
use Waaseyaa\Foundation\Http\Refusal\RefusalEnvelope;
use Waaseyaa\Foundation\Http\RequestContext as ListingRequestContext;
use Waaseyaa\Foundation\Http\Router as HttpRouter;
use Waaseyaa\Foundation\Kernel\Bootstrap\ProviderRegistryKernelServices;
use Waaseyaa\Foundation\Kernel\Http\HttpKernelServiceResolver;
use Waaseyaa\Foundation\Log\LogManager;
use Waaseyaa\Foundation\Log\Processor\RequestContextProcessor;
use Waaseyaa\Foundation\Maintenance\MaintenanceSettings;
use Waaseyaa\Foundation\Maintenance\MaintenanceState;
use Waaseyaa\Foundation\Middleware\BodySizeLimitMiddleware;
use Waaseyaa\Foundation\Middleware\DebugHeaderMiddleware;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\Foundation\Middleware\HttpMiddlewareStackComposer;
use Waaseyaa\Foundation\Middleware\HttpPipeline;
use Waaseyaa\Foundation\Middleware\MaintenanceModeMiddleware;
use Waaseyaa\Foundation\Middleware\RateLimitMiddleware;
use Waaseyaa\Foundation\Middleware\SecurityHeadersMiddleware;
use Waaseyaa\Foundation\RateLimit\DatabaseRateLimiter;
use Waaseyaa\Foundation\Runtime\RuntimeEpochCacheBackend;
use Waaseyaa\Foundation\Runtime\RuntimeEpochInterface;
use Waaseyaa\Foundation\Runtime\StableRuntimeEpoch;
use Waaseyaa\Foundation\Security\ApplicationSecret;
use Waaseyaa\Foundation\ServiceProvider\Capability\ConfiguresHttpKernelInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\HasHttpDomainRoutersInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\HasMiddlewareInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\HasRenderCacheListenersInterface;
use Waaseyaa\Routing\Exception\RouteMethodNotAllowedException;
use Waaseyaa\Routing\Exception\RouteNotFoundException;
use Waaseyaa\Routing\ParamConverter\EntityParamConverter;
use Waaseyaa\Routing\Redirector;
use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Routing\WaaseyaaRouter;
use Waaseyaa\User\Authentication\AuthenticationEligibilityInterface;
use Waaseyaa\User\DevAdminAccount;
use Waaseyaa\User\Middleware\BearerAuthMiddleware;
use Waaseyaa\User\Middleware\CsrfMiddleware;
use Waaseyaa\User\Middleware\ResponseCacheControlMiddleware;
use Waaseyaa\User\Middleware\SessionMiddleware;
use Waaseyaa\User\Session\SessionCookiePolicy;

/**
 * HTTP front controller kernel.
 *
 * Boots the application, handles CORS, matches routes, runs the
 * HTTP middleware pipeline (Session -> Authorization -> dispatch), and returns
 * a Symfony Response for the caller to send. Response-side middleware unwinds
 * over the real controller/domain-router response.
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


    public function handle(): HttpResponse
    {
        // Quiesce gate — runs BEFORE boot() so a maintenance 503 is served
        // without opening or querying the database (boot() runs migrations and
        // schema validation). This is the single, deterministic invocation of
        // the maintenance middleware; it is never wired into the post-boot
        // pipeline. See MaintenanceModeMiddleware and #2122.
        $maintenanceResponse = $this->maintenanceGate();
        if ($maintenanceResponse !== null) {
            return $maintenanceResponse;
        }

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

    /**
     * Evaluate the maintenance flag before boot and return a branded 503 when
     * the app is quiesced, otherwise null to proceed with normal boot + serve.
     *
     * The gate request is built via `HttpRequest::create()` from `$_SERVER`
     * (NOT `createFromGlobals()`), so `php://input` is never consumed — POST
     * bodies survive for the real request built later in matchRoute().
     *
     * Fail-closed discipline: the flag read is fully defensive inside
     * {@see MaintenanceState::read()} (ambiguity → 503). The ONLY throw risk is
     * `HttpRequest::create()` on a malformed request URI; that is caught and
     * replaced with a synthetic NON-exempt request, so an active flag still
     * yields a 503 (never a bypass), while an inactive flag still proceeds to
     * boot. There is deliberately no broad fail-open catch.
     */
    private function maintenanceGate(): ?HttpResponse
    {
        $settings = MaintenanceSettings::fromEnvironment($this->projectRoot);

        try {
            $request = HttpRequest::create(
                is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/',
                is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : 'GET',
                server: $_SERVER,
            );
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('Maintenance gate: request construction failed, failing closed: %s', $e->getMessage()));
            // Synthetic, non-loopback, non-health request: an active flag serves
            // a 503; an inactive flag still returns null and boots normally.
            $request = HttpRequest::create('/', 'GET', server: ['REMOTE_ADDR' => '0.0.0.0']);
        }

        $middleware = new MaintenanceModeMiddleware(
            new MaintenanceState($settings->flagPath),
            $settings->healthPaths,
            $settings->trustLocalhost,
            $settings->customPagePath,
        );

        return $middleware->maintenanceResponse($request);
    }

    protected function finalizeBoot(): void
    {
        assert($this->database instanceof \Waaseyaa\Database\DBALDatabase);
        $pdo = $this->database->getConnection()->getNativeConnection();
        assert($pdo instanceof \PDO);

        $cacheConfig = new CacheConfiguration();
        $cacheHmacKey = $this->applicationSecret()->derive(ApplicationSecret::PURPOSE_CACHE_PAYLOAD_HMAC);
        $projectionDiagnostic = ProjectionDeprecationDiagnostic::forEntityPayloads(
            function (string $channel, array $context): void {
                $this->logger->notice($channel, $context);
            },
            EntityPayloadBoundaryConfig::enforced(),
        );
        $cacheConfig->setFactoryForBin('render', fn(): DatabaseBackend => new DatabaseBackend(
            $pdo,
            'cache_render',
            hmacKey: $cacheHmacKey,
            projectionDiagnostic: $projectionDiagnostic,
        ));
        $cacheConfig->setFactoryForBin('discovery', fn(): DatabaseBackend => new DatabaseBackend(
            $pdo,
            'cache_discovery',
            hmacKey: $cacheHmacKey,
            projectionDiagnostic: $projectionDiagnostic,
        ));
        $runtimeEpoch = $this->getHttpServiceResolver()->resolve(RuntimeEpochInterface::class);
        if (!$runtimeEpoch instanceof RuntimeEpochInterface) {
            if (!$this->isDevelopmentMode()) {
                throw new \LogicException('The MCP read cache requires a composed runtime epoch authority.');
            }
            $runtimeEpoch = new StableRuntimeEpoch();
        }
        $cacheConfig->setFactoryForBin('mcp_read', fn(): RuntimeEpochCacheBackend => new RuntimeEpochCacheBackend(
            new DatabaseBackend(
                $pdo,
                'cache_mcp_read',
                hmacKey: $cacheHmacKey,
                projectionDiagnostic: $projectionDiagnostic,
            ),
            $runtimeEpoch->fingerprint(),
        ));
        $cacheFactory = new CacheFactory($cacheConfig, $projectionDiagnostic);
        $this->renderCacheBackend = $cacheFactory->get('render');
        $this->discoveryCache = $cacheFactory->get('discovery');
        $this->mcpReadCache = $cacheFactory->get('mcp_read');

        // $this->accessHandler is populated by discoverAccessPolicies() earlier
        // in AbstractKernel::boot(), before finalizeBoot() runs — threading it
        // here makes the discovery/browse API path gate disclosed endpoint
        // identities on per-account 'view' access, not publish status alone
        // (audit R5 residual #1, R7 WP2).
        $this->discoveryHandler = new DiscoveryApiHandler($this->entityTypeManager, $this->database, $this->discoveryCache, $this->accessHandler);

        $listenerRegistrar = new EventListenerRegistrar($this->dispatcher, $this->logger, $this->secretResolverRegistry());
        foreach ($this->providers as $provider) {
            if (!$provider instanceof HasRenderCacheListenersInterface) {
                continue;
            }
            $provider->registerRenderCacheListeners($this->dispatcher, $this->renderCacheBackend);
        }
        $listenerRegistrar->registerDiscoveryCacheListeners($this->discoveryCache);
        $listenerRegistrar->registerMcpReadCacheListeners($this->mcpReadCache);
        if (class_exists(\Waaseyaa\AI\Vector\SqliteEmbeddingStorage::class)) {
            // CW-v1 option-1 (#1920 PR-2): threading entityTypeManager
            // through lets EntityEmbeddingListener re-source served
            // content via repository->find() instead of trusting the
            // in-memory event entity (design §3.3).
            $listenerRegistrar->registerEmbeddingLifecycleListeners(new \Waaseyaa\AI\Vector\SqliteEmbeddingStorage($pdo), $this->config, $this->entityTypeManager);
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
                fieldReadScope: $this->fieldReadScope(),
                requestContext: $this->requestContextForProviders(),
                communityContext: $this->communityContext,
                secretResolverRegistry: $this->secretResolverRegistry(),
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
    /**
     * The live request's query parameters, as the Listing pipeline's
     * {@see ListingRequestContext} (#2167).
     *
     * Aliased on import because Symfony's routing `RequestContext` already
     * holds that name in this file and means something entirely different.
     *
     * Before this existed, `packages/listing`'s ServiceProvider bound an
     * anonymous `new RequestContext()` and its comment promised that "CLI and
     * HTTP kernels override the binding" — an override that was never written.
     * `ListingResolver` therefore never saw `?page=`, so pagination was
     * unreachable for every listing in every application while `hasNext` still
     * reported `true`.
     *
     * Read from `$_GET`, which PHP populated from the request line before any
     * framework code ran. The kernel is the correct place for that read: it
     * already consults `$_SERVER` here, and doing it here is what keeps
     * `ListingResolver` free of globals.
     *
     * `RequestContext::getQueryParams()` is declared `array<string, string>`,
     * so non-scalar values (`?a[]=1&a[]=2`) are reduced to their last scalar
     * leaf rather than breaking the contract. Repeated scalar parameters
     * (`?a=1&a=2`) follow PHP's own last-wins semantics, because `$_GET`
     * already collapsed them.
     */
    protected function requestContextForProviders(): ListingRequestContext
    {
        $query = [];
        foreach ($_GET as $name => $value) {
            $flattened = self::flattenQueryValue($value);
            if ($flattened !== null) {
                $query[(string) $name] = $flattened;
            }
        }

        return new ListingRequestContext(queryParams: $query);
    }

    /**
     * Reduce one query value to a single string, or null when it carries none.
     */
    private static function flattenQueryValue(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }
        if (is_array($value)) {
            $last = null;
            foreach ($value as $nested) {
                $candidate = self::flattenQueryValue($nested);
                if ($candidate !== null) {
                    $last = $candidate;
                }
            }

            return $last;
        }

        return null;
    }

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
        $listenerRegistrar = new EventListenerRegistrar($this->dispatcher, $this->logger, $this->secretResolverRegistry());
        $listenerRegistrar->registerBroadcastListeners($broadcastStorage);

        $path = $this->stripLanguagePrefixForHttpRouting($path);

        $matchResult = $this->matchRoute($path, $method);
        if ($matchResult instanceof HttpResponse) {
            return $matchResult;
        }
        $httpRequest = $matchResult;

        $pipeline = $this->buildMiddlewareStack();
        $terminalFailure = new class {
            public ?\Throwable $exception = null;
        };
        $terminalDispatch = function (HttpRequest $request) use ($broadcastStorage, $terminalFailure): HttpResponse {
            try {
                return $this->dispatchMatchedRequest($request, $broadcastStorage);
            } catch (\Throwable $e) {
                // Keep terminal dispatch on HttpKernel::handle()'s established
                // unhandled-exception surface. The local catch below remains
                // responsible only for middleware failures, as it was before
                // dispatch became the pipeline's real terminal handler.
                $terminalFailure->exception = $e;

                throw $e;
            }
        };

        try {
            return $pipeline->handle(
                $httpRequest,
                new class ($terminalDispatch) implements HttpHandlerInterface {
                    /** @param \Closure(HttpRequest): HttpResponse $dispatch */
                    public function __construct(private readonly \Closure $dispatch) {}

                    public function handle(HttpRequest $request): HttpResponse
                    {
                        return ($this->dispatch)($request);
                    }
                },
            );
        } catch (\Throwable $e) {
            if ($e === $terminalFailure->exception) {
                throw $e;
            }

            $this->logger->critical(sprintf("Authorization pipeline error: %s in %s:%d\n%s", $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString()));

            return $this->jsonApiResponse(500, ['jsonapi' => ['version' => '1.1'], 'errors' => [['status' => '500', 'title' => 'Internal Server Error', 'detail' => 'An authorization error occurred.']]]);
        }
    }

    /**
     * Dispatch the matched request as the middleware pipeline's terminal handler.
     *
     * Middleware can therefore short-circuit before dispatch and, on success,
     * unwind over the real controller response for response-side work.
     */
    private function dispatchMatchedRequest(HttpRequest $httpRequest, BroadcastStorage $broadcastStorage): HttpResponse
    {
        $account = $httpRequest->attributes->get('_account');
        if (!$account instanceof AccountInterface) {
            $this->logger->error('_account attribute missing or invalid after HTTP middleware authentication.');

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

        return $dispatcher->dispatch($httpRequest);
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
            $routeName = $params['_route'] ?? '';
            $matchedRoute = $router->getRouteCollection()->get($routeName);
            if ($matchedRoute !== null) {
                $params = new EntityParamConverter($this->entityTypeManager)->convert($params, $matchedRoute);
            }
        } catch (RouteNotFoundException) {
            return $this->jsonApiResponse(404, ['jsonapi' => ['version' => '1.1'], 'errors' => [['status' => '404', 'title' => 'Not Found', 'detail' => 'No route matches the requested path.']]]);
        } catch (ResourceNotFoundException) {
            return $this->jsonApiResponse(404, ['jsonapi' => ['version' => '1.1'], 'errors' => [['status' => '404', 'title' => 'Not Found', 'detail' => 'The requested entity was not found.']]]);
        } catch (RouteMethodNotAllowedException) {
            return $this->jsonApiResponse(405, ['jsonapi' => ['version' => '1.1'], 'errors' => [['status' => '405', 'title' => 'Method Not Allowed', 'detail' => "Method {$method} is not allowed for this route."]]]);
        } catch (\Throwable $e) {
            $this->logger->critical(sprintf("Routing error: %s in %s:%d\n%s", $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString()));

            return $this->jsonApiResponse(500, ['jsonapi' => ['version' => '1.1'], 'errors' => [['status' => '500', 'title' => 'Internal Server Error', 'detail' => 'A routing error occurred.']]]);
        }

        $httpRequest = HttpRequest::createFromGlobals();
        foreach ($params as $key => $value) {
            $httpRequest->attributes->set(
                $key,
                $key === '_controller' ? RouteBuilder::normalizeControllerDefault($value) : $value,
            );
        }
        if ($matchedRoute !== null) {
            $httpRequest->attributes->set('_route_object', $matchedRoute);
            // Resolve the route's refusal vocabulary once, here, so every
            // kernel-level refusal downstream — middleware and the kernel's own
            // JSON pre-parse alike — speaks the transport the matched endpoint
            // advertises rather than shadowing it with JSON:API (#2594).
            $httpRequest->attributes->set(
                RefusalEnvelope::REQUEST_ATTRIBUTE,
                RefusalEnvelope::fromRouteOptions($matchedRoute->getOptions()),
            );
        }
        $httpRequest->attributes->set(Redirector::REQUEST_ATTRIBUTE, new Redirector($router));

        return $httpRequest;
    }

    /**
     * Build the ordered middleware pipeline around real request dispatch.
     *
     * Collects the explicit built-ins, optional debug header middleware, and
     * provider contributions through one exactly-once composer. Attribute
     * discovery supplies priority metadata; it is not a construction path.
     */
    private function buildMiddlewareStack(): HttpPipeline
    {
        // C-22 WP3: read path now goes through the canonical repository.
        $userRepository = $this->entityTypeManager->getRepository('user');
        $gate = new EntityAccessGate($this->accessHandler, null, $this->fieldReadScope(), $this->accountContext());
        $accessChecker = new AccessChecker(gate: $gate);
        $errorPageRenderer = $this->resolveErrorPageRenderer();
        $communityContext = $this->getHttpServiceResolver()->resolve(CommunityContextInterface::class);
        if (!$communityContext instanceof CommunityContextInterface) {
            throw new \LogicException('The HTTP pipeline requires the Foundation community context binding.');
        }
        $internalFields = $this->getHttpServiceResolver()->resolve(UserInternalFieldReaderInterface::class);
        if (!$internalFields instanceof UserInternalFieldReaderInterface) {
            throw new \LogicException('The HTTP pipeline requires the audited User internal-field reader.');
        }
        $authenticationEligibility = $this->getHttpServiceResolver()->resolve(
            'Waaseyaa\\Auth\\Authentication\\VerifiedEmailAuthenticationEligibility',
        );
        if (!$authenticationEligibility instanceof AuthenticationEligibilityInterface) {
            $configured = is_array($this->config['auth'] ?? null)
                ? ($this->config['auth']['require_verified_email'] ?? false)
                : false;
            if ($configured !== false) {
                throw new \LogicException('auth.require_verified_email requires the canonical authentication eligibility policy.');
            }
            $authenticationEligibility = null;
        }

        $builtIns = [
            // Outermost response policy: it unwinds after every cookie writer
            // and replaces public cache directives on session-bound responses.
            new ResponseCacheControlMiddleware(),
            new SecurityHeadersMiddleware(
                csp: is_array($this->config['security_headers'] ?? null)
                    && is_string($this->config['security_headers']['csp'] ?? null)
                    ? $this->config['security_headers']['csp']
                    : null,
                hstsEnabled: is_array($this->config['security_headers'] ?? null)
                    && ($this->config['security_headers']['hsts_enabled'] ?? false) === true,
                hstsMaxAge: is_array($this->config['security_headers'] ?? null)
                    && is_int($this->config['security_headers']['hsts_max_age'] ?? null)
                    ? $this->config['security_headers']['hsts_max_age']
                    : 31_536_000,
                frameOptions: is_array($this->config['security_headers'] ?? null)
                    && is_string($this->config['security_headers']['frame_options'] ?? null)
                    ? $this->config['security_headers']['frame_options']
                    : 'SAMEORIGIN',
            ),
            ...($this->httpSecurityControlEnabled('rate_limit') ? [
                new RateLimitMiddleware(
                    new DatabaseRateLimiter($this->database),
                    $this->httpSecurityPositiveInt('rate_limit', 'max_attempts', 60),
                    $this->httpSecurityPositiveInt('rate_limit', 'window_seconds', 60),
                ),
            ] : []),
            ...($this->httpSecurityControlEnabled('body_size_limit') ? [
                new BodySizeLimitMiddleware(
                    $this->httpSecurityPositiveInt('body_size_limit', 'max_bytes', 1_048_576),
                ),
            ] : []),
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
                statelessPathPrefixes: $this->sessionStatelessPaths(),
                internalFields: $internalFields,
                authenticationEligibility: $authenticationEligibility,
            ),
            new CommunityMiddleware($communityContext),
            // Same resolved session.cookie policy as SessionMiddleware, so a
            // forced `secure => true` governs the XSRF-TOKEN cookie too (#2149).
            new CsrfMiddleware(new SessionCookiePolicy($this->sessionCookieOptions())),
            new AuthorizationMiddleware($accessChecker, $errorPageRenderer),
        ];

        if ($this->isDebugMode()) {
            $builtIns[] = new DebugHeaderMiddleware(
                startTime: $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true),
            );
        }

        $providerMiddleware = [];
        foreach ($this->providers as $provider) {
            if (!$provider instanceof HasMiddlewareInterface) {
                continue;
            }
            foreach ($provider->middleware($this->entityTypeManager) as $mw) {
                $providerMiddleware[] = ['middleware' => $mw, 'provider' => $provider::class];
            }
        }

        $middlewares = new HttpMiddlewareStackComposer()->compose($builtIns, $providerMiddleware);

        $pipeline = new HttpPipeline();
        foreach ($middlewares as $middleware) {
            $pipeline = $pipeline->withMiddleware($middleware);
        }

        return $pipeline;
    }

    private function httpSecurityControlEnabled(string $control): bool
    {
        $httpSecurity = $this->config['http_security'] ?? [];
        if (!is_array($httpSecurity)) {
            throw new \LogicException('http_security configuration must be an object.');
        }
        $controlConfig = $httpSecurity[$control] ?? [];
        if (!is_array($controlConfig)) {
            throw new \LogicException(sprintf('http_security.%s configuration must be an object.', $control));
        }
        $enabled = $controlConfig['enabled'] ?? true;
        if (!is_bool($enabled)) {
            throw new \LogicException(sprintf('http_security.%s.enabled must be a boolean.', $control));
        }

        return $enabled;
    }

    private function httpSecurityPositiveInt(string $control, string $setting, int $default): int
    {
        $httpSecurity = $this->config['http_security'] ?? [];
        $controlConfig = is_array($httpSecurity) ? ($httpSecurity[$control] ?? []) : [];
        $value = is_array($controlConfig) ? ($controlConfig[$setting] ?? $default) : $default;
        if (!is_int($value) || $value < 1) {
            throw new \LogicException(sprintf('http_security.%s.%s must be a positive integer.', $control, $setting));
        }

        return $value;
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
        $resolvedExposurePolicy = $this->getHttpServiceResolver()->resolve(EntityTypeApiExposurePolicy::class);
        $exposurePolicy = $resolvedExposurePolicy instanceof EntityTypeApiExposurePolicy
            ? $resolvedExposurePolicy
            : null;
        $resolvedInternalFieldVisibility = $this->getHttpServiceResolver()->resolve(InternalFieldVisibilityPolicy::class);
        $internalFieldVisibility = $resolvedInternalFieldVisibility instanceof InternalFieldVisibilityPolicy
            ? $resolvedInternalFieldVisibility
            : null;
        $resolvedFieldSchemaAuthority = $this->getHttpServiceResolver()->resolve(FieldSchemaAuthority::class);
        $fieldSchemaAuthority = $resolvedFieldSchemaAuthority instanceof FieldSchemaAuthority
            ? $resolvedFieldSchemaAuthority
            : null;
        $foundationRouters = [
            new HttpRouter\TranslationRouter($this->entityTypeManager, $this->accessHandler, $exposurePolicy, $internalFieldVisibility),
            new HttpRouter\JsonApiRouter(
                $this->entityTypeManager,
                $this->accessHandler,
                exposurePolicy: $exposurePolicy,
                internalFieldVisibility: $internalFieldVisibility,
            ),
            new HttpRouter\EntityTypeLifecycleRouter($this->entityTypeManager, $this->lifecycleManager),
            new HttpRouter\SchemaRouter(
                $this->entityTypeManager,
                $this->accessHandler,
                $this->fieldRegistry,
                $exposurePolicy,
                $fieldSchemaAuthority,
            ),
            new HttpRouter\WorkflowDefinitionsApiRouter(),
            new HttpRouter\SearchRouter(
                $this->config,
                $this->database,
                $this->entityTypeManager,
                $this->accessHandler,
                $this->secretResolverRegistry(),
                internalFieldVisibility: $internalFieldVisibility,
            ),
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
     * Path prefixes whose anonymous GET/HEAD requests never start a
     * session (config `session.stateless_paths`, issue #2146). Empty by
     * default: every existing application keeps its current behavior.
     *
     * @return list<string>
     */
    private function sessionStatelessPaths(): array
    {
        $paths = [];
        $session = $this->config['session'] ?? null;
        if (is_array($session) && is_array($session['stateless_paths'] ?? null)) {
            $paths = array_values(array_filter(
                $session['stateless_paths'],
                static fn($path): bool => is_string($path) && $path !== '',
            ));
        }

        $paths[] = '/.well-known/api-catalog';
        $aiCatalog = $this->config['ai_catalog'] ?? null;
        if (is_array($aiCatalog) && ($aiCatalog['enabled'] ?? false) === true) {
            $paths[] = '/.well-known/ai-catalog.json';
        }

        // The opt-in public search endpoint is read-only and never needs to
        // create an anonymous session. Requests carrying a session cookie are
        // still resumed by SessionMiddleware, preserving authenticated search.
        $api = $this->config['api'] ?? null;
        $contentSearch = is_array($api) ? ($api['content_search'] ?? null) : null;
        if (is_array($contentSearch) && ($contentSearch['enabled'] ?? false) === true) {
            $paths[] = '/api/content/search';
        }

        return array_values(array_unique($paths));
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
            // Same seam as the body-size guard: the kernel pre-parses the body
            // ahead of the controller, so a JSON-RPC endpoint's own parse-error
            // refusal is unreachable unless this one speaks its vocabulary
            // (#2594).
            return RefusalEnvelope::forRequest($request)->refuse(new HttpRefusal(
                status: 400,
                reason: RefusalEnvelope::REASON_PARSE_ERROR,
                title: 'Bad Request',
                detail: 'Invalid JSON in request body.',
                transportMessage: 'Parse error',
            ));
        }
    }

    private function bootFailureJsonResponse(\Throwable $e): HttpResponse
    {
        $showDetail = false;
        try {
            $showDetail = $this->isDebugMode();
        } catch (\Throwable) {
            // A failure while resolving the canonical policy must not create a
            // second, process-global disclosure path. Keep the fail-closed
            // default; catastrophic pre-kernel failures are handled by the
            // front-controller boundary.
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
