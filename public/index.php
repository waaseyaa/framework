<?php

declare(strict_types=1);

/**
 * Aurora CMS HTTP front controller.
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
    echo json_encode(['error' => 'Composer autoloader not found. Run composer install.']);
    exit(1);
}

use Aurora\Api\JsonApiController;
use Aurora\Api\JsonApiDocument;
use Aurora\Api\JsonApiRouteProvider;
use Aurora\Api\Controller\SchemaController;
use Aurora\Api\OpenApi\OpenApiGenerator;
use Aurora\Api\ResourceSerializer;
use Aurora\Api\Schema\SchemaPresenter;
use Aurora\Database\PdoDatabase;
use Aurora\Entity\EntityType;
use Aurora\Entity\EntityTypeManager;
use Aurora\EntityStorage\SqlEntityStorage;
use Aurora\EntityStorage\SqlSchemaHandler;
use Aurora\Node\Node;
use Aurora\Routing\AuroraRouter;
use Aurora\Routing\RouteBuilder;
use Aurora\User\User;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Routing\RequestContext;

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

// --- Bootstrap services (mirrors bin/aurora) --------------------------------

$projectRoot = dirname(__DIR__);
$dbPath = getenv('AURORA_DB') ?: $projectRoot . '/aurora.sqlite';

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
    new EntityType(
        id: 'node',
        label: 'Content',
        class: Node::class,
        keys: ['id' => 'nid', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'type'],
    ),
    new EntityType(
        id: 'user',
        label: 'User',
        class: User::class,
        keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'name'],
    ),
];

foreach ($entityTypes as $entityType) {
    $entityTypeManager->registerEntityType($entityType);
}

// --- Router setup -----------------------------------------------------------

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (!is_string($path)) {
    sendJson(400, ['jsonapi' => ['version' => '1.1'], 'errors' => [['status' => '400', 'title' => 'Bad Request', 'detail' => 'Malformed request URI.']]]);
}
$queryString = $_SERVER['QUERY_STRING'] ?? '';

$context = new RequestContext('', $method);
$router = new AuroraRouter($context);

// Register JSON:API CRUD routes.
$routeProvider = new JsonApiRouteProvider($entityTypeManager);
$routeProvider->registerRoutes($router);

// Schema route: GET /api/schema/{entity_type}
$router->addRoute(
    'api.schema.show',
    RouteBuilder::create('/api/schema/{entity_type}')
        ->controller('Aurora\\Api\\Controller\\SchemaController::show')
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

// --- Dispatch ---------------------------------------------------------------

$serializer = new ResourceSerializer($entityTypeManager);
$schemaPresenter = new SchemaPresenter();

try {
    $params = $router->match($path);
} catch (\Symfony\Component\Routing\Exception\ResourceNotFoundException) {
    sendJson(404, ['jsonapi' => ['version' => '1.1'], 'errors' => [['status' => '404', 'title' => 'Not Found', 'detail' => 'No route matches the requested path.']]]);
} catch (\Symfony\Component\Routing\Exception\MethodNotAllowedException) {
    sendJson(405, ['jsonapi' => ['version' => '1.1'], 'errors' => [['status' => '405', 'title' => 'Method Not Allowed', 'detail' => "Method {$method} is not allowed for this route."]]]);
} catch (\Throwable $e) {
    error_log(sprintf('[Aurora] Routing error: %s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine()));
    sendJson(500, ['jsonapi' => ['version' => '1.1'], 'errors' => [['status' => '500', 'title' => 'Internal Server Error', 'detail' => 'A routing error occurred.']]]);
}

$controller = $params['_controller'] ?? '';

// Parse request body for POST/PATCH.
$body = null;
if (in_array($method, ['POST', 'PATCH'], true)) {
    $raw = file_get_contents('php://input');
    if ($raw !== '') {
        $body = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            sendJson(400, ['jsonapi' => ['version' => '1.1'], 'errors' => [['status' => '400', 'title' => 'Bad Request', 'detail' => 'Invalid JSON in request body.']]]);
        }
    } else {
        $body = [];
    }
}

// Parse query parameters.
parse_str($queryString, $query);

try {
    $document = match (true) {
        // OpenAPI spec.
        $controller === 'openapi' => null,

        // Entity types listing.
        $controller === 'entity_types' => null,

        // Schema controller.
        str_contains($controller, 'SchemaController') => (function () use ($entityTypeManager, $schemaPresenter, $params): JsonApiDocument {
            $schemaController = new SchemaController($entityTypeManager, $schemaPresenter);
            return $schemaController->show($params['entity_type']);
        })(),

        // JSON:API controller.
        str_contains($controller, 'JsonApiController') => (function () use ($entityTypeManager, $serializer, $params, $query, $body, $method): JsonApiDocument {
            $jsonApiController = new JsonApiController($entityTypeManager, $serializer);
            $entityTypeId = $params['_entity_type'];
            $id = $params['id'] ?? null;

            return match (true) {
                $method === 'GET' && $id === null => $jsonApiController->index($entityTypeId, $query),
                $method === 'GET' && $id !== null => $jsonApiController->show($entityTypeId, $id),
                $method === 'POST' => $jsonApiController->store($entityTypeId, $body ?? []),
                $method === 'PATCH' && $id !== null => $jsonApiController->update($entityTypeId, $id, $body ?? []),
                $method === 'DELETE' && $id !== null => $jsonApiController->destroy($entityTypeId, $id),
                default => JsonApiDocument::fromErrors(
                    [new \Aurora\Api\JsonApiError('400', 'Bad Request', 'Unhandled method/resource combination.')],
                    statusCode: 400,
                ),
            };
        })(),

        default => JsonApiDocument::fromErrors(
            [new \Aurora\Api\JsonApiError('500', 'Internal Server Error', "Unknown controller: {$controller}")],
            statusCode: 500,
        ),
    };

    // Handle non-document responses.
    if ($controller === 'openapi') {
        $openApi = new OpenApiGenerator($entityTypeManager);
        sendJson(200, $openApi->generate());
    }

    if ($controller === 'entity_types') {
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
    }

    // Send JSON:API document response.
    sendJson($document->statusCode, $document->toArray());

} catch (\Throwable $e) {
    error_log(sprintf('[Aurora] Unhandled exception: %s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine()));
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
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}
