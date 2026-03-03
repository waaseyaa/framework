<?php

declare(strict_types=1);

/**
 * Waaseyaa HTTP front controller.
 *
 * Usage:
 *   php -S localhost:8080 -t public
 *
 * Routes:
 *   GET|POST        /api/{entityType}         — JSON:API collection / create
 *   GET|PATCH|DELETE /api/{entityType}/{id}    — JSON:API resource CRUD
 *   GET              /api/schema/{entity_type} — JSON Schema with widget hints
 *   GET              /api/openapi.json         — OpenAPI 3.1 specification
 *   GET              /api/entity-types         — list registered entity types
 *   GET              /api/broadcast            — SSE real-time broadcast stream
 */

// Find autoloader.
$autoloadPaths = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../../../autoload.php',
];

$autoloader = null;
foreach ($autoloadPaths as $path) {
    if (file_exists($path)) {
        $autoloader = require $path;
        break;
    }
}

if ($autoloader === null) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Composer autoloader not found. Run composer install.'], JSON_THROW_ON_ERROR);
    exit(1);
}

use Waaseyaa\Api\JsonApiController;
use Waaseyaa\Api\JsonApiDocument;
use Waaseyaa\Api\JsonApiRouteProvider;
use Waaseyaa\Api\Controller\SchemaController;
use Waaseyaa\Api\OpenApi\OpenApiGenerator;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Api\Schema\SchemaPresenter;
use Waaseyaa\Database\PdoDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\AI\Pipeline\Pipeline;
use Waaseyaa\Media\Media;
use Waaseyaa\Media\MediaType;
use Waaseyaa\Menu\Menu;
use Waaseyaa\Menu\MenuLink;
use Waaseyaa\Node\Node;
use Waaseyaa\Node\NodeType;
use Waaseyaa\Path\PathAlias;
use Waaseyaa\Routing\WaaseyaaRouter;
use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Taxonomy\Term;
use Waaseyaa\Taxonomy\Vocabulary;
use Waaseyaa\User\User;
use Waaseyaa\Workflows\Workflow;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Routing\RequestContext;
use Waaseyaa\Api\Controller\BroadcastController;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\Middleware\AuthorizationMiddleware;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\Foundation\Middleware\HttpPipeline;
use Waaseyaa\Routing\AccessChecker;
use Waaseyaa\User\Middleware\SessionMiddleware;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

// --- CORS -------------------------------------------------------------------

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigins = ['http://localhost:3000', 'http://127.0.0.1:3000'];

if (in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: {$origin}");
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept, Authorization');
header('Access-Control-Max-Age: 86400');

// Handle preflight.
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// --- Bootstrap services (mirrors bin/waaseyaa) --------------------------------

$projectRoot = dirname(__DIR__);
$dbPath = getenv('WAASEYAA_DB') ?: $projectRoot . '/waaseyaa.sqlite';

$dispatcher = new EventDispatcher();
$database = PdoDatabase::createSqlite($dbPath);

$entityTypeManager = new EntityTypeManager(
    $dispatcher,
    function (EntityType $definition) use ($database, $dispatcher): SqlEntityStorage {
        $schemaHandler = new SqlSchemaHandler($definition, $database);
        $schemaHandler->ensureTable();
        return new SqlEntityStorage($definition, $database, $dispatcher);
    },
);

$entityTypes = [
    // Layer 1: Core Data.
    new EntityType(
        id: 'user',
        label: 'User',
        class: User::class,
        keys: ['id' => 'uid', 'uuid' => 'uuid', 'label' => 'name'],
    ),

    // Layer 2: Content Types.
    new EntityType(
        id: 'node',
        label: 'Content',
        class: Node::class,
        keys: ['id' => 'nid', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'type'],
    ),
    new EntityType(
        id: 'node_type',
        label: 'Content Type',
        class: NodeType::class,
        keys: ['id' => 'type', 'label' => 'name'],
    ),
    new EntityType(
        id: 'taxonomy_term',
        label: 'Taxonomy Term',
        class: Term::class,
        keys: ['id' => 'tid', 'uuid' => 'uuid', 'label' => 'name', 'bundle' => 'vid'],
    ),
    new EntityType(
        id: 'taxonomy_vocabulary',
        label: 'Vocabulary',
        class: Vocabulary::class,
        keys: ['id' => 'vid', 'label' => 'name'],
    ),
    new EntityType(
        id: 'media',
        label: 'Media',
        class: Media::class,
        keys: ['id' => 'mid', 'uuid' => 'uuid', 'label' => 'name', 'bundle' => 'bundle'],
    ),
    new EntityType(
        id: 'media_type',
        label: 'Media Type',
        class: MediaType::class,
        keys: ['id' => 'id', 'label' => 'label'],
    ),
    new EntityType(
        id: 'path_alias',
        label: 'Path Alias',
        class: PathAlias::class,
        keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'alias', 'langcode' => 'langcode'],
    ),
    new EntityType(
        id: 'menu',
        label: 'Menu',
        class: Menu::class,
        keys: ['id' => 'id', 'label' => 'label'],
    ),
    new EntityType(
        id: 'menu_link',
        label: 'Menu Link',
        class: MenuLink::class,
        keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'menu_name'],
    ),

    // Layer 3: Services.
    new EntityType(
        id: 'workflow',
        label: 'Workflow',
        class: Workflow::class,
        keys: ['id' => 'id', 'label' => 'label'],
    ),

    // Layer 5: AI.
    new EntityType(
        id: 'pipeline',
        label: 'Pipeline',
        class: Pipeline::class,
        keys: ['id' => 'id', 'label' => 'label'],
    ),
];

foreach ($entityTypes as $entityType) {
    try {
        $entityTypeManager->registerEntityType($entityType);
    } catch (\Throwable $e) {
        error_log(sprintf(
            '[Waaseyaa] Failed to register entity type "%s": %s in %s:%d',
            $entityType->getId(),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
        ));
        sendJson(500, [
            'jsonapi' => ['version' => '1.1'],
            'errors' => [[
                'status' => '500',
                'title' => 'Internal Server Error',
                'detail' => sprintf('Failed to register entity type: %s', $entityType->getId()),
            ]],
        ]);
    }
}

// --- Broadcast storage for SSE ------------------------------------------------

$broadcastStorage = new \Waaseyaa\Api\Controller\BroadcastStorage($database);

// Push entity lifecycle events into the broadcast log.
$dispatcher->addListener('waaseyaa.entity.post_save', function (object $event) use ($broadcastStorage): void {
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

$dispatcher->addListener('waaseyaa.entity.post_delete', function (object $event) use ($broadcastStorage): void {
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

// --- Router setup -----------------------------------------------------------

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (!is_string($path)) {
    sendJson(400, ['jsonapi' => ['version' => '1.1'], 'errors' => [['status' => '400', 'title' => 'Bad Request', 'detail' => 'Malformed request URI.']]]);
}
$queryString = $_SERVER['QUERY_STRING'] ?? '';

$context = new RequestContext('', $method);
$router = new WaaseyaaRouter($context);

// Register JSON:API CRUD routes.
$routeProvider = new JsonApiRouteProvider($entityTypeManager);
$routeProvider->registerRoutes($router);

// Schema route: GET /api/schema/{entity_type}
$router->addRoute(
    'api.schema.show',
    RouteBuilder::create('/api/schema/{entity_type}')
        ->controller('Waaseyaa\\Api\\Controller\\SchemaController::show')
        ->methods('GET')
        ->build(),
);

// OpenAPI route: GET /api/openapi.json
$router->addRoute(
    'api.openapi',
    RouteBuilder::create('/api/openapi.json')
        ->controller('openapi')
        ->methods('GET')
        ->build(),
);

// Entity types listing: GET /api/entity-types
$router->addRoute(
    'api.entity_types',
    RouteBuilder::create('/api/entity-types')
        ->controller('entity_types')
        ->methods('GET')
        ->build(),
);

// SSE broadcast: GET /api/broadcast
$router->addRoute(
    'api.broadcast',
    RouteBuilder::create('/api/broadcast')
        ->controller('broadcast')
        ->methods('GET')
        ->build(),
);

// --- Route matching ---------------------------------------------------------

$serializer = new ResourceSerializer($entityTypeManager);
$schemaPresenter = new SchemaPresenter();

try {
    $params = $router->match($path);
} catch (\Symfony\Component\Routing\Exception\ResourceNotFoundException) {
    sendJson(404, ['jsonapi' => ['version' => '1.1'], 'errors' => [['status' => '404', 'title' => 'Not Found', 'detail' => 'No route matches the requested path.']]]);
} catch (\Symfony\Component\Routing\Exception\MethodNotAllowedException) {
    sendJson(405, ['jsonapi' => ['version' => '1.1'], 'errors' => [['status' => '405', 'title' => 'Method Not Allowed', 'detail' => "Method {$method} is not allowed for this route."]]]);
} catch (\Throwable $e) {
    error_log(sprintf('[Waaseyaa] Routing error: %s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine()));
    sendJson(500, ['jsonapi' => ['version' => '1.1'], 'errors' => [['status' => '500', 'title' => 'Internal Server Error', 'detail' => 'A routing error occurred.']]]);
}

// --- Authorization pipeline -------------------------------------------------

$httpRequest = HttpRequest::createFromGlobals();
$routeName = $params['_route'] ?? '';
$matchedRoute = $router->getRouteCollection()->get($routeName);
if ($matchedRoute !== null) {
    $httpRequest->attributes->set('_route_object', $matchedRoute);
}

$userStorage = $entityTypeManager->getStorage('user');
$accessChecker = new AccessChecker();
$pipeline = (new HttpPipeline())
    ->withMiddleware(new SessionMiddleware($userStorage))
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
    sendJson(500, ['jsonapi' => ['version' => '1.1'], 'errors' => [['status' => '500', 'title' => 'Internal Server Error', 'detail' => 'An authorization error occurred.']]]);
}

if ($authResponse->getStatusCode() >= 400) {
    $authResponse->send();
    exit;
}

// --- Field-level access context ------------------------------------------------

$account = $httpRequest->attributes->get('_account');
// TODO: Populate with field-access policies from discovery/registry.
// With an empty policy set, open-by-default semantics apply: all fields are accessible.
$accessHandler = new EntityAccessHandler([]);

// --- Dispatch ---------------------------------------------------------------

$controller = $params['_controller'] ?? '';

// Parse request body for POST/PATCH (use $httpRequest to avoid double-reading php://input).
$body = null;
if (in_array($method, ['POST', 'PATCH'], true)) {
    $raw = $httpRequest->getContent();
    if ($raw !== '') {
        try {
            $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            sendJson(400, ['jsonapi' => ['version' => '1.1'], 'errors' => [['status' => '400', 'title' => 'Bad Request', 'detail' => 'Invalid JSON in request body.']]]);
        }
    } else {
        $body = [];
    }
}

// Parse query parameters.
parse_str($queryString, $query);

try {
    match (true) {
        // OpenAPI spec — returns directly, no JsonApiDocument.
        $controller === 'openapi' => (function () use ($entityTypeManager): never {
            $openApi = new OpenApiGenerator($entityTypeManager);
            sendJson(200, $openApi->generate());
        })(),

        // Entity types listing — returns directly, no JsonApiDocument.
        $controller === 'entity_types' => (function () use ($entityTypeManager): never {
            $types = [];
            foreach ($entityTypeManager->getDefinitions() as $id => $def) {
                $types[] = [
                    'id' => $id,
                    'label' => $def->getLabel(),
                    'keys' => $def->getKeys(),
                    'translatable' => $def->isTranslatable(),
                    'revisionable' => $def->isRevisionable(),
                ];
            }
            sendJson(200, ['data' => $types]);
        })(),

        // SSE broadcast stream — polls BroadcastStorage for new messages.
        $controller === 'broadcast' => (function () use ($broadcastStorage, $query): never {
            $channels = BroadcastController::parseChannels($query['channels'] ?? 'admin');
            if ($channels === []) {
                $channels = ['admin'];
            }

            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no');

            // Send initial connected event.
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
                    usleep(5_000_000); // Back off 5s on error.
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

                usleep(500_000); // Poll every 500ms.
            }
            exit;
        })(),

        // Schema controller.
        str_contains($controller, 'SchemaController') => (function () use ($entityTypeManager, $schemaPresenter, $accessHandler, $account, $params): never {
            $schemaController = new SchemaController($entityTypeManager, $schemaPresenter, $accessHandler, $account);
            $document = $schemaController->show($params['entity_type']);
            sendJson($document->statusCode, $document->toArray());
        })(),

        // JSON:API controller.
        str_contains($controller, 'JsonApiController') => (function () use ($entityTypeManager, $serializer, $accessHandler, $account, $params, $query, $body, $method): never {
            $jsonApiController = new JsonApiController($entityTypeManager, $serializer, $accessHandler, $account);
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
            sendJson($document->statusCode, $document->toArray());
        })(),

        default => (function () use ($controller): never {
            error_log(sprintf('[Waaseyaa] Unknown controller: %s', $controller));
            sendJson(500, ['jsonapi' => ['version' => '1.1'], 'errors' => [['status' => '500', 'title' => 'Internal Server Error', 'detail' => 'Unknown route handler.']]]);
        })(),
    };

} catch (\Throwable $e) {
    error_log(sprintf('[Waaseyaa] Unhandled exception: %s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine()));
    sendJson(500, [
        'jsonapi' => ['version' => '1.1'],
        'errors' => [[
            'status' => '500',
            'title' => 'Internal Server Error',
            'detail' => 'An unexpected error occurred.',
        ]],
    ]);
}

// --- Helpers ----------------------------------------------------------------

function sendJson(int $status, array $data): never
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
