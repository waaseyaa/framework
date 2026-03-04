<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Kernel;

use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\RequestContext;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Gate\EntityAccessGate;
use Waaseyaa\Access\Middleware\AuthorizationMiddleware;
use Waaseyaa\Api\Controller\BroadcastController;
use Waaseyaa\Api\Controller\BroadcastStorage;
use Waaseyaa\Api\Controller\SchemaController;
use Waaseyaa\Api\JsonApiController;
use Waaseyaa\Api\JsonApiDocument;
use Waaseyaa\Api\JsonApiRouteProvider;
use Waaseyaa\Api\OpenApi\OpenApiGenerator;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Api\Schema\SchemaPresenter;
use Waaseyaa\Cache\Backend\DatabaseBackend;
use Waaseyaa\Cache\CacheFactory;
use Waaseyaa\Cache\CacheConfiguration;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\Foundation\Middleware\HttpPipeline;
use Waaseyaa\Media\File;
use Waaseyaa\Media\LocalFileRepository;
use Waaseyaa\Path\PathAliasResolver;
use Waaseyaa\Routing\AccessChecker;
use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Routing\WaaseyaaRouter;
use Waaseyaa\SSR\ArrayViewModeConfig;
use Waaseyaa\SSR\EntityRenderer;
use Waaseyaa\SSR\FieldFormatterRegistry;
use Waaseyaa\SSR\RenderController;
use Waaseyaa\SSR\RenderCache;
use Waaseyaa\SSR\SsrServiceProvider;
use Waaseyaa\SSR\ViewMode;
use Waaseyaa\AI\Vector\EmbeddingProviderFactory;
use Waaseyaa\AI\Vector\SearchController;
use Waaseyaa\AI\Vector\SqliteEmbeddingStorage;
use Waaseyaa\User\Middleware\BearerAuthMiddleware;
use Waaseyaa\User\DevAdminAccount;
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

    public function handle(): never
    {
        $this->boot();

        $this->handleCors();

        $method = $_SERVER['REQUEST_METHOD'];
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        if (!is_string($path)) {
            $this->sendJson(400, ['jsonapi' => ['version' => '1.1'], 'errors' => [['status' => '400', 'title' => 'Bad Request', 'detail' => 'Malformed request URI.']]]);
        }
        $queryString = $_SERVER['QUERY_STRING'] ?? '';

        // Broadcast storage for SSE.
        $broadcastStorage = new BroadcastStorage($this->database);
        $this->registerBroadcastListeners($broadcastStorage);
        $cacheConfig = new CacheConfiguration();
        $cacheConfig->setFactoryForBin('render', fn(): DatabaseBackend => new DatabaseBackend(
            $this->database->getPdo(),
            'cache_render',
        ));
        $this->renderCache = new RenderCache((new CacheFactory($cacheConfig))->get('render'));
        $this->registerRenderCacheListeners($this->renderCache);

        // Router setup.
        $context = new RequestContext('', $method);
        $router = new WaaseyaaRouter($context);
        $this->registerRoutes($router);

        // Route matching.
        try {
            $params = $router->match($path);
        } catch (\Symfony\Component\Routing\Exception\ResourceNotFoundException) {
            $this->sendJson(404, ['jsonapi' => ['version' => '1.1'], 'errors' => [['status' => '404', 'title' => 'Not Found', 'detail' => 'No route matches the requested path.']]]);
        } catch (\Symfony\Component\Routing\Exception\MethodNotAllowedException) {
            $this->sendJson(405, ['jsonapi' => ['version' => '1.1'], 'errors' => [['status' => '405', 'title' => 'Method Not Allowed', 'detail' => "Method {$method} is not allowed for this route."]]]);
        } catch (\Throwable $e) {
            error_log(sprintf('[Waaseyaa] Routing error: %s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine()));
            $this->sendJson(500, ['jsonapi' => ['version' => '1.1'], 'errors' => [['status' => '500', 'title' => 'Internal Server Error', 'detail' => 'A routing error occurred.']]]);
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
        $pipeline = (new HttpPipeline())
            ->withMiddleware(new BearerAuthMiddleware(
                $userStorage,
                (string) ($this->config['jwt_secret'] ?? ''),
                is_array($this->config['api_keys'] ?? null) ? $this->config['api_keys'] : [],
            ))
            ->withMiddleware(new SessionMiddleware(
                $userStorage,
                PHP_SAPI === 'cli-server' ? new DevAdminAccount() : null,
            ))
            ->withMiddleware(new AuthorizationMiddleware($accessChecker));

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
            error_log(sprintf('[Waaseyaa] Authorization pipeline error: %s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine()));
            $this->sendJson(500, ['jsonapi' => ['version' => '1.1'], 'errors' => [['status' => '500', 'title' => 'Internal Server Error', 'detail' => 'An authorization error occurred.']]]);
        }

        if ($authResponse->getStatusCode() >= 400) {
            $authResponse->send();
            exit;
        }

        $account = $httpRequest->attributes->get('_account');
        if (!$account instanceof AccountInterface) {
            error_log('[Waaseyaa] _account attribute missing or invalid after authorization pipeline.');
            $this->sendJson(500, ['jsonapi' => ['version' => '1.1'], 'errors' => [['status' => '500', 'title' => 'Internal Server Error', 'detail' => 'Account resolution failed.']]]);
        }

        // Dispatch.
        $this->dispatch($method, $params, $httpRequest, $queryString, $broadcastStorage, $account);
    }

    /**
     * Handle CORS preflight and headers.
     *
     * Origins are configurable via waaseyaa.php 'cors_origins'. Defaults to
     * Nuxt dev server ports. If the dev server binds to a different port,
     * add it to the config array.
     */
    private function handleCors(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $allowedOrigins = $this->config['cors_origins'] ?? ['http://localhost:3000', 'http://127.0.0.1:3000'];
        $overrideOrigin = getenv('WAASEYAA_CORS_ORIGIN');
        if (is_string($overrideOrigin) && trim($overrideOrigin) !== '') {
            $allowedOrigins = [trim($overrideOrigin)];
        }
        $allowDevLocalhostPorts = $this->isDevelopmentMode();

        foreach ($this->resolveCorsHeaders($origin, $allowedOrigins, $allowDevLocalhostPorts) as $header) {
            header($header);
        }

        if ($this->isCorsPreflightRequest($_SERVER['REQUEST_METHOD'] ?? 'GET')) {
            http_response_code(204);
            exit;
        }
    }

    /**
     * @param list<string> $allowedOrigins
     * @return list<string>
     */
    private function resolveCorsHeaders(string $origin, array $allowedOrigins, bool $allowDevLocalhostPorts = false): array
    {
        if ($this->isOriginAllowed($origin, $allowedOrigins, $allowDevLocalhostPorts)) {
            return [
                "Access-Control-Allow-Origin: {$origin}",
                'Vary: Origin',
                'Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS',
                'Access-Control-Allow-Headers: Content-Type, Accept, Authorization',
                'Access-Control-Max-Age: 86400',
            ];
        }

        if ($origin !== '') {
            error_log(sprintf(
                '[Waaseyaa] CORS: origin "%s" not in allowed list (%s). '
                . 'If using Nuxt dev server on a non-standard port, update cors_origins in config/waaseyaa.php.',
                $origin,
                implode(', ', $allowedOrigins),
            ));
        }

        return [];
    }

    /**
     * @param list<string> $allowedOrigins
     */
    private function isOriginAllowed(string $origin, array $allowedOrigins, bool $allowDevLocalhostPorts): bool
    {
        if (in_array($origin, $allowedOrigins, true)) {
            return true;
        }

        if (!$allowDevLocalhostPorts) {
            return false;
        }

        return preg_match('#^https?://(localhost|127\.0\.0\.1):\d+$#', $origin) === 1;
    }

    private function isCorsPreflightRequest(string $method): bool
    {
        return strtoupper($method) === 'OPTIONS';
    }

    private function isDevelopmentMode(): bool
    {
        $env = $this->config['environment'] ?? getenv('APP_ENV') ?: '';
        if (!is_string($env)) {
            return false;
        }

        return in_array(strtolower($env), ['dev', 'development', 'local'], true);
    }

    private function registerRoutes(WaaseyaaRouter $router): void
    {
        $routeProvider = new JsonApiRouteProvider($this->entityTypeManager);
        $routeProvider->registerRoutes($router);

        $router->addRoute(
            'api.schema.show',
            RouteBuilder::create('/api/schema/{entity_type}')
                ->controller('Waaseyaa\\Api\\Controller\\SchemaController::show')
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'api.openapi',
            RouteBuilder::create('/api/openapi.json')
                ->controller('openapi')
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'api.entity_types',
            RouteBuilder::create('/api/entity-types')
                ->controller('entity_types')
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'api.broadcast',
            RouteBuilder::create('/api/broadcast')
                ->controller('broadcast')
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'api.media.upload',
            RouteBuilder::create('/api/media/upload')
                ->controller('media.upload')
                ->requirePermission('access media')
                ->methods('POST')
                ->build(),
        );

        $router->addRoute(
            'api.search',
            RouteBuilder::create('/api/search')
                ->controller('search.semantic')
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'public.home',
            RouteBuilder::create('/')
                ->controller('render.page')
                ->allowAll()
                ->render()
                ->methods('GET')
                ->default('path', '/')
                ->build(),
        );

        $router->addRoute(
            'public.page',
            RouteBuilder::create('/{path}')
                ->controller('render.page')
                ->allowAll()
                ->render()
                ->methods('GET')
                ->requirement('path', '(?!api(?:/|$)).+')
                ->build(),
        );
    }

    private function registerBroadcastListeners(BroadcastStorage $broadcastStorage): void
    {
        $this->dispatcher->addListener('waaseyaa.entity.post_save', function (object $event) use ($broadcastStorage): void {
            try {
                $entity = $event->entity;
                $broadcastStorage->push(
                    'admin',
                    'entity.saved',
                    ['entityType' => $entity->getEntityTypeId(), 'id' => (string) ($entity->uuid() ?: $entity->id())],
                );
            } catch (\Throwable $e) {
                error_log(sprintf('[Waaseyaa] Failed to broadcast entity.saved: %s', $e->getMessage()));
            }
        });

        $this->dispatcher->addListener('waaseyaa.entity.post_delete', function (object $event) use ($broadcastStorage): void {
            try {
                $entity = $event->entity;
                $broadcastStorage->push(
                    'admin',
                    'entity.deleted',
                    ['entityType' => $entity->getEntityTypeId(), 'id' => (string) ($entity->uuid() ?: $entity->id())],
                );
            } catch (\Throwable $e) {
                error_log(sprintf('[Waaseyaa] Failed to broadcast entity.deleted: %s', $e->getMessage()));
            }
        });
    }

    private function dispatch(
        string $method,
        array $params,
        HttpRequest $httpRequest,
        string $queryString,
        BroadcastStorage $broadcastStorage,
        AccountInterface $account,
    ): never {
        $controller = $params['_controller'] ?? '';
        $serializer = new ResourceSerializer($this->entityTypeManager);
        $schemaPresenter = new SchemaPresenter();

        $body = null;
        if (in_array($method, ['POST', 'PATCH'], true)) {
            $raw = $httpRequest->getContent();
            if ($raw !== '') {
                try {
                    $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    $this->sendJson(400, ['jsonapi' => ['version' => '1.1'], 'errors' => [['status' => '400', 'title' => 'Bad Request', 'detail' => 'Invalid JSON in request body.']]]);
                }
            } else {
                $body = [];
            }
        }

        parse_str($queryString, $query);

        try {
            match (true) {
                $controller === 'openapi' => (function (): never {
                    $openApi = new OpenApiGenerator($this->entityTypeManager);
                    $this->sendJson(200, $openApi->generate());
                })(),

                $controller === 'entity_types' => (function (): never {
                    $types = [];
                    foreach ($this->entityTypeManager->getDefinitions() as $id => $def) {
                        $types[] = [
                            'id' => $id,
                            'label' => $def->getLabel(),
                            'keys' => $def->getKeys(),
                            'translatable' => $def->isTranslatable(),
                            'revisionable' => $def->isRevisionable(),
                        ];
                    }
                    $this->sendJson(200, ['data' => $types]);
                })(),

                $controller === 'broadcast' => (function () use ($broadcastStorage, $query): never {
                    $channels = BroadcastController::parseChannels($query['channels'] ?? 'admin');
                    if ($channels === []) {
                        $channels = ['admin'];
                    }

                    header('Content-Type: text/event-stream');
                    header('Cache-Control: no-cache');
                    header('Connection: keep-alive');
                    header('X-Accel-Buffering: no');

                    echo "event: connected\ndata: " . json_encode(['channels' => $channels], JSON_THROW_ON_ERROR) . "\n\n";
                    if (ob_get_level() > 0) { ob_flush(); }
                    flush();

                    $cursor = 0;
                    $lastKeepalive = time();

                    while (connection_aborted() === 0) {
                        try {
                            $messages = $broadcastStorage->poll($cursor, $channels);
                        } catch (\Throwable $e) {
                            error_log(sprintf('[Waaseyaa] SSE poll error: %s', $e->getMessage()));
                            echo "event: error\ndata: " . json_encode(['message' => 'Broadcast poll failed'], JSON_THROW_ON_ERROR) . "\n\n";
                            if (ob_get_level() > 0) { ob_flush(); }
                            flush();
                            usleep(5_000_000);
                            continue;
                        }

                        foreach ($messages as $msg) {
                            $cursor = $msg['id'];
                            try {
                                $frame = "event: {$msg['event']}\ndata: " . json_encode($msg, JSON_THROW_ON_ERROR) . "\n\n";
                                echo $frame;
                            } catch (\JsonException $e) {
                                error_log(sprintf('[Waaseyaa] SSE json_encode error for event %s: %s', $msg['event'] ?? 'unknown', $e->getMessage()));
                            }
                        }

                        if ($messages !== []) {
                            if (ob_get_level() > 0) { ob_flush(); }
                            flush();
                        }

                        $now = time();
                        if (($now - $lastKeepalive) >= 30) {
                            echo ": keepalive\n\n";
                            if (ob_get_level() > 0) { ob_flush(); }
                            flush();
                            $lastKeepalive = $now;
                            try {
                                $broadcastStorage->prune(300);
                            } catch (\Throwable $e) {
                                error_log(sprintf('[Waaseyaa] SSE prune error: %s', $e->getMessage()));
                            }
                        }

                        usleep(500_000);
                    }
                    exit;
                })(),

                $controller === 'media.upload' => (function () use ($httpRequest, $account, $serializer): never {
                    $this->handleMediaUpload($httpRequest, $account, $serializer);
                })(),

                $controller === 'search.semantic' => (function () use ($query, $account, $serializer): never {
                    $searchQuery = is_string($query['q'] ?? null) ? trim((string) $query['q']) : '';
                    $entityType = is_string($query['type'] ?? null) ? trim((string) $query['type']) : '';
                    $limit = is_numeric($query['limit'] ?? null) ? (int) $query['limit'] : 10;

                    if ($searchQuery === '' || $entityType === '') {
                        $this->sendJson(400, [
                            'jsonapi' => ['version' => '1.1'],
                            'errors' => [[
                                'status' => '400',
                                'title' => 'Bad Request',
                                'detail' => 'Search requires query parameters "q" and "type".',
                            ]],
                        ]);
                    }

                    $provider = EmbeddingProviderFactory::fromConfig($this->config);
                    $embeddingStorage = new SqliteEmbeddingStorage($this->database->getPdo());
                    $controller = new SearchController(
                        entityTypeManager: $this->entityTypeManager,
                        serializer: $serializer,
                        embeddingStorage: $embeddingStorage,
                        embeddingProvider: $provider,
                        accessHandler: $this->accessHandler,
                        account: $account,
                    );

                    $document = $controller->search($searchQuery, $entityType, $limit);
                    $this->sendJson($document->statusCode, $document->toArray());
                })(),

                $controller === 'render.page' => (function () use ($params, $query, $account): never {
                    $requestedViewMode = is_string($query['view_mode'] ?? null)
                        ? trim((string) $query['view_mode'])
                        : 'full';
                    $this->handleRenderPage((string) ($params['path'] ?? '/'), $account, $requestedViewMode);
                })(),

                str_contains($controller, 'SchemaController') => (function () use ($account, $params, $schemaPresenter): never {
                    $schemaController = new SchemaController($this->entityTypeManager, $schemaPresenter, $this->accessHandler, $account);
                    $document = $schemaController->show($params['entity_type']);
                    $this->sendJson($document->statusCode, $document->toArray());
                })(),

                str_contains($controller, 'JsonApiController') => (function () use ($serializer, $account, $params, $query, $body, $method): never {
                    $jsonApiController = new JsonApiController($this->entityTypeManager, $serializer, $this->accessHandler, $account);
                    $entityTypeId = $params['_entity_type'];
                    $id = $params['id'] ?? null;

                    $document = match (true) {
                        $method === 'GET' && $id === null => $jsonApiController->index($entityTypeId, $query),
                        $method === 'GET' && $id !== null => $jsonApiController->show($entityTypeId, $id),
                        $method === 'POST' => $jsonApiController->store($entityTypeId, $body ?? []),
                        $method === 'PATCH' && $id !== null => $jsonApiController->update($entityTypeId, $id, $body ?? []),
                        $method === 'DELETE' && $id !== null => $jsonApiController->destroy($entityTypeId, $id),
                        default => JsonApiDocument::fromErrors(
                            [new \Waaseyaa\Api\JsonApiError('400', 'Bad Request', 'Unhandled method/resource combination.')],
                            statusCode: 400,
                        ),
                    };
                    $this->sendJson($document->statusCode, $document->toArray());
                })(),

                default => (function () use ($controller): never {
                    error_log(sprintf('[Waaseyaa] Unknown controller: %s', $controller));
                    $this->sendJson(500, ['jsonapi' => ['version' => '1.1'], 'errors' => [['status' => '500', 'title' => 'Internal Server Error', 'detail' => 'Unknown route handler.']]]);
                })(),
            };
        } catch (\Throwable $e) {
            error_log(sprintf('[Waaseyaa] Unhandled exception: %s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine()));
            $this->sendJson(500, [
                'jsonapi' => ['version' => '1.1'],
                'errors' => [[
                    'status' => '500',
                    'title' => 'Internal Server Error',
                    'detail' => 'An unexpected error occurred.',
                ]],
            ]);
        }
    }

    private function handleRenderPage(string $path, AccountInterface $account, string $requestedViewMode = 'full'): never
    {
        $twig = SsrServiceProvider::getTwigEnvironment();
        if ($twig === null) {
            try {
                $twig = SsrServiceProvider::createTwigEnvironment($this->projectRoot, $this->config);
            } catch (\Throwable $e) {
                error_log(sprintf('[Waaseyaa] Twig environment initialization failed: %s', $e->getMessage()));
                $this->sendJson(500, [
                    'jsonapi' => ['version' => '1.1'],
                    'errors' => [[
                        'status' => '500',
                        'title' => 'Internal Server Error',
                        'detail' => 'SSR environment is unavailable.',
                    ]],
                ]);
            }
        }

        try {
            $cacheMaxAge = $this->resolveRenderCacheMaxAge();
            $cacheControlHeader = $this->cacheControlHeaderForRender($account, $cacheMaxAge);

            $normalizedPath = $path;
            if ($normalizedPath === '' || $normalizedPath === '/') {
                $response = (new RenderController($twig))->renderPath('/');
                $headers = $response->headers;
                $headers['Cache-Control'] = $cacheControlHeader;
                $this->sendHtml($response->statusCode, $response->content, $headers);
            }
            if (!str_starts_with($normalizedPath, '/')) {
                $normalizedPath = '/' . $normalizedPath;
            }

            $aliasResolver = new PathAliasResolver($this->entityTypeManager->getStorage('path_alias'));
            $resolved = $aliasResolver->resolve($normalizedPath);
            if ($resolved === null) {
                $response = (new RenderController($twig))->renderNotFound($normalizedPath);
                $headers = $response->headers;
                $headers['Cache-Control'] = $cacheControlHeader;
                $this->sendHtml($response->statusCode, $response->content, $headers);
            }

            $targetStorage = $this->entityTypeManager->getStorage($resolved->entityTypeId);
            $entity = $targetStorage->load($resolved->entityId);
            if ($entity === null) {
                $response = (new RenderController($twig))->renderNotFound($normalizedPath);
                $headers = $response->headers;
                $headers['Cache-Control'] = $cacheControlHeader;
                $this->sendHtml($response->statusCode, $response->content, $headers);
            }

            $formatterRegistry = SsrServiceProvider::getFormatterRegistry()
                ?? new FieldFormatterRegistry($this->manifest->formatters);
            $viewModeConfig = new ArrayViewModeConfig(
                is_array($this->config['view_modes'] ?? null) ? $this->config['view_modes'] : [],
            );
            $entityRenderer = new EntityRenderer($this->entityTypeManager, $formatterRegistry, $viewModeConfig);
            $safeViewMode = preg_replace('/[^a-z0-9_]+/i', '', strtolower($requestedViewMode)) ?: 'full';
            $viewMode = new ViewMode($safeViewMode);

            if (!$account->isAuthenticated() && $this->renderCache !== null && $entity->id() !== null) {
                $cached = $this->renderCache->get(
                    $resolved->entityTypeId,
                    $entity->id(),
                    $viewMode->name,
                    $entity->language(),
                );
                if ($cached !== null) {
                    $headers = $cached->headers;
                    $headers['Cache-Control'] = $cacheControlHeader;
                    $this->sendHtml($cached->statusCode, $cached->content, $headers);
                }
            }

            $response = (new RenderController($twig, $entityRenderer))->renderEntity($entity, $viewMode);
            if (!$account->isAuthenticated() && $this->renderCache !== null && $entity->id() !== null && $response->statusCode === 200) {
                $this->renderCache->set(
                    $resolved->entityTypeId,
                    $entity->id(),
                    $viewMode->name,
                    $entity->language(),
                    $response,
                    $cacheMaxAge,
                );
            }

            $headers = $response->headers;
            $headers['Cache-Control'] = $cacheControlHeader;
            $this->sendHtml($response->statusCode, $response->content, $headers);
        } catch (\Throwable $e) {
            error_log(sprintf('[Waaseyaa] Render pipeline failed: %s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine()));
            $this->sendJson(500, [
                'jsonapi' => ['version' => '1.1'],
                'errors' => [[
                    'status' => '500',
                    'title' => 'Internal Server Error',
                    'detail' => 'Failed to render page.',
                ]],
            ]);
        }
    }

    private function registerRenderCacheListeners(RenderCache $renderCache): void
    {
        $invalidate = function (object $event) use ($renderCache): void {
            if (!$event instanceof EntityEvent) {
                return;
            }

            $renderCache->invalidateEntity(
                $event->entity->getEntityTypeId(),
                $event->entity->id(),
            );
        };

        $this->dispatcher->addListener(EntityEvents::POST_SAVE->value, $invalidate);
        $this->dispatcher->addListener(EntityEvents::POST_DELETE->value, $invalidate);
    }

    private function resolveRenderCacheMaxAge(): int
    {
        $ssrConfig = $this->config['ssr'] ?? null;
        if (is_array($ssrConfig) && isset($ssrConfig['cache_max_age']) && is_numeric($ssrConfig['cache_max_age'])) {
            return max(0, (int) $ssrConfig['cache_max_age']);
        }

        return 300;
    }

    private function cacheControlHeaderForRender(AccountInterface $account, int $maxAge): string
    {
        if ($account->isAuthenticated()) {
            return 'private, no-store';
        }

        return 'public, max-age=' . max(0, $maxAge);
    }

    private function handleMediaUpload(
        HttpRequest $httpRequest,
        AccountInterface $account,
        ResourceSerializer $serializer,
    ): never {
        $contentType = strtolower((string) $httpRequest->headers->get('Content-Type', ''));
        if (!str_starts_with($contentType, 'multipart/form-data')) {
            $this->sendJson(415, [
                'jsonapi' => ['version' => '1.1'],
                'errors' => [[
                    'status' => '415',
                    'title' => 'Unsupported Media Type',
                    'detail' => 'Expected multipart/form-data upload.',
                ]],
            ]);
        }

        $uploadedFile = $httpRequest->files->get('file');
        if (!$uploadedFile instanceof UploadedFile) {
            $this->sendJson(400, [
                'jsonapi' => ['version' => '1.1'],
                'errors' => [[
                    'status' => '400',
                    'title' => 'Bad Request',
                    'detail' => 'Missing uploaded file under "file".',
                ]],
            ]);
        }

        if (!$uploadedFile->isValid()) {
            $this->sendJson(400, [
                'jsonapi' => ['version' => '1.1'],
                'errors' => [[
                    'status' => '400',
                    'title' => 'Bad Request',
                    'detail' => sprintf('Upload failed: %s', $uploadedFile->getErrorMessage()),
                ]],
            ]);
        }

        $maxBytes = $this->resolveUploadMaxBytes();
        $size = (int) ($uploadedFile->getSize() ?? 0);
        if ($size > $maxBytes) {
            $this->sendJson(413, [
                'jsonapi' => ['version' => '1.1'],
                'errors' => [[
                    'status' => '413',
                    'title' => 'Payload Too Large',
                    'detail' => sprintf('Uploaded file exceeds %d bytes.', $maxBytes),
                ]],
            ]);
        }

        $mimeType = (string) ($uploadedFile->getMimeType() ?: $uploadedFile->getClientMimeType() ?: 'application/octet-stream');
        $allowedMimeTypes = $this->resolveAllowedUploadMimeTypes();
        if (!$this->isAllowedMimeType($mimeType, $allowedMimeTypes)) {
            $this->sendJson(415, [
                'jsonapi' => ['version' => '1.1'],
                'errors' => [[
                    'status' => '415',
                    'title' => 'Unsupported Media Type',
                    'detail' => sprintf('Disallowed MIME type: %s', $mimeType),
                ]],
            ]);
        }

        $filesRoot = $this->resolveFilesRootDir();
        $publicRoot = rtrim($filesRoot, '/') . '/public';
        if (!is_dir($publicRoot) && !mkdir($publicRoot, 0755, true) && !is_dir($publicRoot)) {
            $this->sendJson(500, [
                'jsonapi' => ['version' => '1.1'],
                'errors' => [[
                    'status' => '500',
                    'title' => 'Internal Server Error',
                    'detail' => 'Unable to initialize upload directory.',
                ]],
            ]);
        }

        $originalName = (string) ($uploadedFile->getClientOriginalName() ?: $uploadedFile->getFilename() ?: 'upload.bin');
        $safeName = $this->sanitizeUploadFilename($originalName);
        $relativePath = date('Y/m') . '/' . uniqid('upload_', true) . '_' . $safeName;
        $targetDir = $publicRoot . '/' . dirname($relativePath);
        if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            $this->sendJson(500, [
                'jsonapi' => ['version' => '1.1'],
                'errors' => [[
                    'status' => '500',
                    'title' => 'Internal Server Error',
                    'detail' => 'Unable to create upload target directory.',
                ]],
            ]);
        }

        try {
            $uploadedFile->move($targetDir, basename($relativePath));
        } catch (\Throwable $e) {
            error_log(sprintf('[Waaseyaa] Upload move failed: %s', $e->getMessage()));
            $this->sendJson(500, [
                'jsonapi' => ['version' => '1.1'],
                'errors' => [[
                    'status' => '500',
                    'title' => 'Internal Server Error',
                    'detail' => 'Failed to persist uploaded file.',
                ]],
            ]);
        }

        $uri = 'public://' . str_replace('\\', '/', $relativePath);
        $fileUrl = $this->buildPublicFileUrl($uri);
        $ownerId = is_numeric((string) $account->id()) ? (int) $account->id() : null;
        $createdTime = time();

        $fileRepository = new LocalFileRepository($filesRoot);
        $fileRepository->save(new File(
            uri: $uri,
            filename: basename($relativePath),
            mimeType: $mimeType,
            size: $size,
            ownerId: $ownerId,
            createdTime: $createdTime,
        ));

        $bundle = $httpRequest->request->get('bundle');
        if (!is_string($bundle) || trim($bundle) === '') {
            $bundle = str_starts_with($mimeType, 'image/') ? 'image' : 'file';
        }

        $createAccess = $this->accessHandler->checkCreateAccess('media', $bundle, $account);
        if (!$createAccess->isAllowed()) {
            $this->sendJson(403, [
                'jsonapi' => ['version' => '1.1'],
                'errors' => [[
                    'status' => '403',
                    'title' => 'Forbidden',
                    'detail' => 'Access denied for creating media items.',
                ]],
            ]);
        }

        $mediaStorage = $this->entityTypeManager->getStorage('media');
        $name = $httpRequest->request->get('name');
        if (!is_string($name) || trim($name) === '') {
            $name = pathinfo($originalName, PATHINFO_FILENAME) ?: $originalName;
        }

        $media = $mediaStorage->create([
            'name' => $name,
            'bundle' => $bundle,
            'uid' => $ownerId,
            'status' => true,
            'created' => $createdTime,
            'changed' => $createdTime,
            'file_uri' => $uri,
            'file_url' => $fileUrl,
            'mime_type' => $mimeType,
            'file_size' => $size,
        ]);
        $mediaStorage->save($media);

        $resource = $serializer->serialize($media, $this->accessHandler, $account);
        $this->sendJson(201, [
            'jsonapi' => ['version' => '1.1'],
            'data' => $resource->toArray(),
            'links' => ['self' => "/api/media/{$resource->id}"],
            'meta' => [
                'uploaded' => true,
                'file_url' => $fileUrl,
            ],
        ]);
    }

    private function resolveFilesRootDir(): string
    {
        $configured = $this->config['files_dir'] ?? null;
        if (is_string($configured) && trim($configured) !== '') {
            return $configured;
        }

        return $this->projectRoot . '/files';
    }

    private function resolveUploadMaxBytes(): int
    {
        $configured = $this->config['upload_max_bytes'] ?? null;
        if (is_numeric($configured) && (int) $configured > 0) {
            return (int) $configured;
        }

        return 10 * 1024 * 1024;
    }

    /**
     * @return list<string>
     */
    private function resolveAllowedUploadMimeTypes(): array
    {
        $configured = $this->config['upload_allowed_mime_types'] ?? null;
        if (is_array($configured) && $configured !== []) {
            $values = [];
            foreach ($configured as $value) {
                if (is_string($value) && trim($value) !== '') {
                    $values[] = trim($value);
                }
            }
            if ($values !== []) {
                return $values;
            }
        }

        return [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/svg+xml',
            'application/pdf',
            'text/plain',
            'application/octet-stream',
        ];
    }

    /**
     * @param list<string> $allowedMimeTypes
     */
    private function isAllowedMimeType(string $mimeType, array $allowedMimeTypes): bool
    {
        foreach ($allowedMimeTypes as $allowed) {
            if ($allowed === $mimeType) {
                return true;
            }

            if (str_ends_with($allowed, '/*')) {
                $prefix = substr($allowed, 0, -1);
                if (str_starts_with($mimeType, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function sanitizeUploadFilename(string $name): string
    {
        $basename = basename($name);
        $clean = preg_replace('/[^A-Za-z0-9._-]/', '_', $basename);
        if (!is_string($clean) || $clean === '' || $clean === '.' || $clean === '..') {
            return 'upload.bin';
        }

        return $clean;
    }

    private function buildPublicFileUrl(string $uri): string
    {
        $prefix = 'public://';
        if (!str_starts_with($uri, $prefix)) {
            return '/files/' . ltrim($uri, '/');
        }

        $path = substr($uri, strlen($prefix));
        if (!is_string($path)) {
            return '/files/';
        }

        return '/files/' . ltrim($path, '/');
    }

    private function sendJson(int $status, array $data): never
    {
        http_response_code($status);
        header('Content-Type: application/vnd.api+json');
        try {
            echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            error_log(sprintf('[Waaseyaa] JSON encoding failed in sendJson: %s', $e->getMessage()));
            echo '{"jsonapi":{"version":"1.1"},"errors":[{"status":"500","title":"Internal Server Error","detail":"Response encoding failed."}]}';
        }
        exit;
    }

    /**
     * @param array<string, string> $headers
     */
    private function sendHtml(int $status, string $html, array $headers = []): never
    {
        http_response_code($status);
        $contentType = $headers['Content-Type'] ?? 'text/html; charset=UTF-8';
        header('Content-Type: ' . $contentType);

        foreach ($headers as $name => $value) {
            if (strtolower($name) === 'content-type') {
                continue;
            }
            header($name . ': ' . $value);
        }

        echo $html;
        exit;
    }
}
