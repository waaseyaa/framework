<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Kernel;

use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
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
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\Foundation\Middleware\HttpPipeline;
use Waaseyaa\Routing\AccessChecker;
use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Routing\WaaseyaaRouter;
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

        if (in_array($origin, $allowedOrigins, true)) {
            header("Access-Control-Allow-Origin: {$origin}");
            header('Vary: Origin');
            header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, Accept, Authorization');
            header('Access-Control-Max-Age: 86400');
        } elseif ($origin !== '') {
            error_log(sprintf(
                '[Waaseyaa] CORS: origin "%s" not in allowed list (%s). '
                . 'If using Nuxt dev server on a non-standard port, update cors_origins in config/waaseyaa.php.',
                $origin,
                implode(', ', $allowedOrigins),
            ));
        }

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
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
}
