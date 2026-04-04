<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Http;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\AI\Vector\EmbeddingProviderFactory;
use Waaseyaa\AI\Vector\SearchController;
use Waaseyaa\AI\Vector\SqliteEmbeddingStorage;
use Waaseyaa\Api\Controller\BroadcastController;
use Waaseyaa\Api\Controller\BroadcastStorage;
use Waaseyaa\Api\Controller\SchemaController;
use Waaseyaa\Api\Http\DiscoveryApiHandler;
use Waaseyaa\Api\JsonApiController;
use Waaseyaa\Api\JsonApiDocument;
use Waaseyaa\Api\OpenApi\OpenApiGenerator;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Api\Schema\SchemaPresenter;
use Waaseyaa\Cache\CacheBackendInterface;
use Waaseyaa\Entity\EntityTypeIdNormalizer;
use Waaseyaa\Entity\EntityTypeLifecycleManager;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Mcp\McpController;
use Waaseyaa\Media\File;
use Waaseyaa\Media\LocalFileRepository;
use Waaseyaa\SSR\SsrPageHandler;

/**
 * Routes a matched controller name to the appropriate handler.
 *
 * Receives the controller identifier, route params, and request context,
 * then delegates to JSON:API controllers, discovery endpoints, SSR,
 * media upload, or app-level controllers. Returns a Symfony Response
 * object; the caller (HttpKernel) is responsible for sending it.
 */
final class ControllerDispatcher
{
    use JsonApiResponseTrait;

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly \Waaseyaa\Database\DatabaseInterface $database,
        private readonly EntityAccessHandler $accessHandler,
        private readonly EntityTypeLifecycleManager $lifecycleManager,
        private readonly DiscoveryApiHandler $discoveryHandler,
        private readonly SsrPageHandler $ssrPageHandler,
        private readonly ?CacheBackendInterface $mcpReadCache,
        private readonly string $projectRoot,
        /** @var array<string, mixed> */
        private readonly array $config,
        /** @var array<string, array{args?: array<string, mixed>, resolve?: callable}> */
        private readonly array $graphqlMutationOverrides = [],
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Dispatch the matched route to its controller.
     *
     * @param array<string, mixed> $params  Route parameters from the router
     */
    public function dispatch(
        string $method,
        array $params,
        HttpRequest $httpRequest,
        string $queryString,
        BroadcastStorage $broadcastStorage,
        AccountInterface $account,
    ): HttpResponse {
        $controller = $params['_controller'] ?? '';
        $serializer = new ResourceSerializer($this->entityTypeManager);
        $schemaPresenter = new SchemaPresenter();

        // Parse JSON body for requests that send JSON content.
        // Routes with _json_api option always get JSON parsing (backward-compat).
        // Other routes get JSON parsing when Content-Type is application/json.
        $body = null;
        $matchedRoute = $httpRequest->attributes->get('_route_object');
        $isJsonApi = $matchedRoute !== null && $matchedRoute->getOption('_json_api') === true;
        $isJsonContent = str_starts_with(
            (string) $httpRequest->headers->get('Content-Type', ''),
            'application/json',
        );
        if (($isJsonApi || $isJsonContent) && in_array($method, ['POST', 'PATCH', 'PUT', 'DELETE'], true)) {
            $raw = $httpRequest->getContent();
            if ($raw !== '') {
                try {
                    $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    return $this->jsonApiResponse(400, ['jsonapi' => ['version' => '1.1'], 'errors' => [['status' => '400', 'title' => 'Bad Request', 'detail' => 'Invalid JSON in request body.']]]);
                }
            } else {
                $body = [];
            }
        }

        parse_str($queryString, $query);

        // Handle callable controllers (invocable objects or closures registered by service providers).
        if (is_callable($controller)) {
            $result = $controller($httpRequest, ...array_filter($params, fn($k) => !str_starts_with($k, '_'), ARRAY_FILTER_USE_KEY));
            if ($result instanceof \Waaseyaa\SSR\SsrResponse) {
                return $this->htmlResponse($result->statusCode, $result->content, $result->headers);
            }
            if ($result instanceof \Waaseyaa\Inertia\InertiaResponse) {
                $pageObject = $result->toPageObject();
                $pageObject['url'] = $httpRequest->getRequestUri();

                if ($httpRequest->headers->get('X-Inertia') === 'true') {
                    return $this->jsonApiResponse(200, $pageObject, [
                        'X-Inertia' => 'true',
                        'Vary' => 'X-Inertia',
                    ]);
                }

                $renderer = new \Waaseyaa\Inertia\RootTemplateRenderer();
                return $this->htmlResponse(200, $renderer->render($pageObject));
            }
            if ($result instanceof \Symfony\Component\HttpFoundation\RedirectResponse
                && $httpRequest->headers->get('X-Inertia') === 'true'
                && in_array($httpRequest->getMethod(), ['PUT', 'PATCH', 'DELETE'], true)
            ) {
                $result->setStatusCode(303);
                return $result;
            }
            if ($result instanceof \Symfony\Component\HttpFoundation\Response) {
                return $result;
            }
            if (is_array($result)) {
                return $this->jsonApiResponse($result['statusCode'] ?? 200, $result['body'] ?? $result);
            }
            return $this->jsonApiResponse(200, ['data' => $result]);
        }

        try {
            return match (true) {
                $controller === 'openapi' => (function (): HttpResponse {
                    $openApi = new OpenApiGenerator($this->entityTypeManager);
                    return $this->jsonApiResponse(200, $openApi->generate());
                })(),

                $controller === 'entity_types' => (function (): HttpResponse {
                    $disabledIds = $this->lifecycleManager->getDisabledTypeIds();
                    $types = [];
                    foreach ($this->entityTypeManager->getDefinitions() as $id => $def) {
                        $types[] = [
                            'id' => $id,
                            'label' => $def->getLabel(),
                            'keys' => $def->getKeys(),
                            'translatable' => $def->isTranslatable(),
                            'revisionable' => $def->isRevisionable(),
                            'group' => $def->getGroup(),
                            'disabled' => in_array($id, $disabledIds, true),
                        ];
                    }
                    return $this->jsonApiResponse(200, ['data' => $types]);
                })(),

                $controller === 'entity_type.disable' => (function () use ($params, $query, $account): HttpResponse {
                    $rawTypeId = (string) ($params['entity_type'] ?? '');
                    $normalizer = new EntityTypeIdNormalizer($this->entityTypeManager);
                    $typeId = $normalizer->normalize($rawTypeId);
                    $force = filter_var($query['force'] ?? false, FILTER_VALIDATE_BOOL);

                    if ($rawTypeId === '' || !$this->entityTypeManager->hasDefinition($typeId)) {
                        return $this->jsonApiResponse(404, [
                            'errors' => [[
                                'status' => '404',
                                'title' => 'Not Found',
                                'detail' => sprintf('Unknown entity type: "%s".', $rawTypeId),
                            ]],
                        ]);
                    }

                    if ($this->lifecycleManager->isDisabled($typeId)) {
                        return $this->jsonApiResponse(200, ['data' => ['id' => $typeId, 'disabled' => true]]);
                    }

                    $definitions = array_keys($this->entityTypeManager->getDefinitions());
                    $disabledIds = $this->lifecycleManager->getDisabledTypeIds();
                    $enabledCount = count(array_filter(
                        $definitions,
                        static fn(string $id): bool => $id !== $typeId && !in_array($id, $disabledIds, true),
                    ));

                    if ($enabledCount === 0 && !$force) {
                        return $this->jsonApiResponse(409, [
                            'errors' => [[
                                'status' => '409',
                                'title' => 'Conflict',
                                'detail' => 'Cannot disable the last enabled content type. Enable another type first.',
                            ]],
                        ]);
                    }

                    $this->lifecycleManager->disable($typeId, (string) $account->id());
                    return $this->jsonApiResponse(200, ['data' => ['id' => $typeId, 'disabled' => true]]);
                })(),

                $controller === 'entity_type.enable' => (function () use ($params, $account): HttpResponse {
                    $rawTypeId = (string) ($params['entity_type'] ?? '');
                    $normalizer = new EntityTypeIdNormalizer($this->entityTypeManager);
                    $typeId = $normalizer->normalize($rawTypeId);

                    if ($rawTypeId === '' || !$this->entityTypeManager->hasDefinition($typeId)) {
                        return $this->jsonApiResponse(404, [
                            'errors' => [[
                                'status' => '404',
                                'title' => 'Not Found',
                                'detail' => sprintf('Unknown entity type: "%s".', $rawTypeId),
                            ]],
                        ]);
                    }

                    if (!$this->lifecycleManager->isDisabled($typeId)) {
                        return $this->jsonApiResponse(200, ['data' => ['id' => $typeId, 'disabled' => false]]);
                    }

                    $this->lifecycleManager->enable($typeId, (string) $account->id());
                    return $this->jsonApiResponse(200, ['data' => ['id' => $typeId, 'disabled' => false]]);
                })(),

                $controller === 'broadcast' => (function () use ($broadcastStorage, $query): HttpResponse {
                    $channels = BroadcastController::parseChannels($query['channels'] ?? 'admin');
                    if ($channels === []) {
                        $channels = ['admin'];
                    }

                    return new StreamedResponse(function () use ($broadcastStorage, $channels): void {
                        echo "event: connected\ndata: " . json_encode(['channels' => $channels], JSON_THROW_ON_ERROR) . "\n\n";
                        if (ob_get_level() > 0) {
                            ob_flush();
                        }
                        flush();

                        $cursor = 0;
                        $lastKeepalive = time();

                        while (connection_aborted() === 0) {
                            try {
                                $messages = $broadcastStorage->poll($cursor, $channels);
                            } catch (\Throwable $e) {
                                $this->logger->error(sprintf('SSE poll error: %s', $e->getMessage()));
                                echo "event: error\ndata: " . json_encode(['message' => 'Broadcast poll failed'], JSON_THROW_ON_ERROR) . "\n\n";
                                if (ob_get_level() > 0) {
                                    ob_flush();
                                }
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
                                    $this->logger->error(sprintf('SSE json_encode error for event %s: %s', $msg['event'] ?? 'unknown', $e->getMessage()));
                                }
                            }

                            if ($messages !== []) {
                                if (ob_get_level() > 0) {
                                    ob_flush();
                                }
                                flush();
                            }

                            $now = time();
                            if (($now - $lastKeepalive) >= 30) {
                                echo ": keepalive\n\n";
                                if (ob_get_level() > 0) {
                                    ob_flush();
                                }
                                flush();
                                $lastKeepalive = $now;
                                try {
                                    $broadcastStorage->prune(300);
                                } catch (\Throwable $e) {
                                    $this->logger->warning(sprintf('SSE prune error: %s', $e->getMessage()));
                                }
                            }

                            usleep(500_000);
                        }
                    }, 200, [
                        'Content-Type' => 'text/event-stream',
                        'Cache-Control' => 'no-cache',
                        'Connection' => 'keep-alive',
                        'X-Accel-Buffering' => 'no',
                    ]);
                })(),

                $controller === 'media.upload' => (function () use ($httpRequest, $account, $serializer): HttpResponse {
                    return $this->handleMediaUpload($httpRequest, $account, $serializer);
                })(),

                $controller === 'search.semantic' => (function () use ($query, $account, $serializer): HttpResponse {
                    $searchQuery = is_string($query['q'] ?? null) ? trim((string) $query['q']) : '';
                    $entityType = is_string($query['type'] ?? null) ? trim((string) $query['type']) : '';
                    $limit = is_numeric($query['limit'] ?? null) ? (int) $query['limit'] : 10;

                    if ($searchQuery === '' || $entityType === '') {
                        return $this->jsonApiResponse(400, [
                            'jsonapi' => ['version' => '1.1'],
                            'errors' => [[
                                'status' => '400',
                                'title' => 'Bad Request',
                                'detail' => 'Search requires query parameters "q" and "type".',
                            ]],
                        ]);
                    }

                    if (!class_exists(SqliteEmbeddingStorage::class)) {
                        return $this->jsonApiResponse(501, [
                            'jsonapi' => ['version' => '1.1'],
                            'errors' => [[
                                'status' => '501',
                                'title' => 'Not Implemented',
                                'detail' => 'Semantic search requires the waaseyaa/ai-vector package.',
                            ]],
                        ]);
                    }

                    $provider = EmbeddingProviderFactory::fromConfig($this->config);
                    assert($this->database instanceof \Waaseyaa\Database\DBALDatabase);
                    $embeddingStorage = new SqliteEmbeddingStorage($this->database->getConnection()->getNativeConnection());
                    $controller = new SearchController(
                        entityTypeManager: $this->entityTypeManager,
                        serializer: $serializer,
                        embeddingStorage: $embeddingStorage,
                        embeddingProvider: $provider,
                        accessHandler: $this->accessHandler,
                        account: $account,
                    );

                    $document = $controller->search($searchQuery, $entityType, $limit);
                    return $this->jsonApiResponse($document->statusCode, $document->toArray());
                })(),

                $controller === 'discovery.topic_hub' => (function () use ($params, $query, $account): HttpResponse {
                    $entityType = is_string($params['entity_type'] ?? null) ? trim((string) $params['entity_type']) : '';
                    $entityId = $params['id'] ?? null;
                    if ($entityType === '' || !is_scalar($entityId) || trim((string) $entityId) === '') {
                        return $this->jsonApiResponse(400, [
                            'jsonapi' => ['version' => '1.1'],
                            'errors' => [[
                                'status' => '400',
                                'title' => 'Bad Request',
                                'detail' => 'Discovery hub requires route params "entity_type" and "id".',
                            ]],
                        ]);
                    }

                    $relationshipTypes = $this->discoveryHandler->parseRelationshipTypesQuery($query['relationship_types'] ?? null);
                    $resolvedOptions = [
                        'relationship_types' => $relationshipTypes,
                        'status' => is_string($query['status'] ?? null) ? trim((string) $query['status']) : 'published',
                        'at' => $query['at'] ?? null,
                        'limit' => is_numeric($query['limit'] ?? null) ? (int) $query['limit'] : null,
                        'offset' => is_numeric($query['offset'] ?? null) ? (int) $query['offset'] : null,
                    ];
                    $cacheKey = $this->discoveryHandler->buildDiscoveryCacheKey('hub', $entityType, (string) $entityId, $resolvedOptions);
                    $cached = $this->discoveryHandler->getDiscoveryCachedResponse($cacheKey, $account);
                    if ($cached !== null) {
                        return $this->jsonApiResponse(200, $cached, [
                            'Cache-Control' => 'public, max-age=120',
                            'X-Waaseyaa-Discovery-Cache' => 'HIT',
                        ]);
                    }
                    $service = $this->discoveryHandler->createDiscoveryService();
                    $payload = $service->topicHub($entityType, (string) $entityId, $resolvedOptions);

                    [$dPayload, $dHeaders] = $this->discoveryHandler->prepareDiscoveryResponse(200, ['data' => $payload], $cacheKey, $account);
                    return $this->jsonApiResponse(200, $dPayload, $dHeaders);
                })(),

                $controller === 'discovery.cluster' => (function () use ($params, $query, $account): HttpResponse {
                    $entityType = is_string($params['entity_type'] ?? null) ? trim((string) $params['entity_type']) : '';
                    $entityId = $params['id'] ?? null;
                    if ($entityType === '' || !is_scalar($entityId) || trim((string) $entityId) === '') {
                        return $this->jsonApiResponse(400, [
                            'jsonapi' => ['version' => '1.1'],
                            'errors' => [[
                                'status' => '400',
                                'title' => 'Bad Request',
                                'detail' => 'Discovery cluster requires route params "entity_type" and "id".',
                            ]],
                        ]);
                    }

                    $relationshipTypes = $this->discoveryHandler->parseRelationshipTypesQuery($query['relationship_types'] ?? null);
                    $resolvedOptions = [
                        'relationship_types' => $relationshipTypes,
                        'status' => is_string($query['status'] ?? null) ? trim((string) $query['status']) : 'published',
                        'at' => $query['at'] ?? null,
                        'limit' => is_numeric($query['limit'] ?? null) ? (int) $query['limit'] : null,
                        'offset' => is_numeric($query['offset'] ?? null) ? (int) $query['offset'] : null,
                    ];
                    $cacheKey = $this->discoveryHandler->buildDiscoveryCacheKey('cluster', $entityType, (string) $entityId, $resolvedOptions);
                    $cached = $this->discoveryHandler->getDiscoveryCachedResponse($cacheKey, $account);
                    if ($cached !== null) {
                        return $this->jsonApiResponse(200, $cached, [
                            'Cache-Control' => 'public, max-age=120',
                            'X-Waaseyaa-Discovery-Cache' => 'HIT',
                        ]);
                    }
                    $service = $this->discoveryHandler->createDiscoveryService();
                    $payload = $service->clusterPage($entityType, (string) $entityId, $resolvedOptions);

                    [$dPayload, $dHeaders] = $this->discoveryHandler->prepareDiscoveryResponse(200, ['data' => $payload], $cacheKey, $account);
                    return $this->jsonApiResponse(200, $dPayload, $dHeaders);
                })(),

                $controller === 'discovery.timeline' => (function () use ($params, $query, $account): HttpResponse {
                    $entityType = is_string($params['entity_type'] ?? null) ? trim((string) $params['entity_type']) : '';
                    $entityId = $params['id'] ?? null;
                    if ($entityType === '' || !is_scalar($entityId) || trim((string) $entityId) === '') {
                        return $this->jsonApiResponse(400, [
                            'jsonapi' => ['version' => '1.1'],
                            'errors' => [[
                                'status' => '400',
                                'title' => 'Bad Request',
                                'detail' => 'Discovery timeline requires route params "entity_type" and "id".',
                            ]],
                        ]);
                    }

                    $relationshipTypes = $this->discoveryHandler->parseRelationshipTypesQuery($query['relationship_types'] ?? null);
                    $resolvedOptions = [
                        'direction' => is_string($query['direction'] ?? null) ? trim((string) $query['direction']) : 'both',
                        'relationship_types' => $relationshipTypes,
                        'status' => is_string($query['status'] ?? null) ? trim((string) $query['status']) : 'published',
                        'at' => $query['at'] ?? null,
                        'from' => $query['from'] ?? null,
                        'to' => $query['to'] ?? null,
                        'limit' => is_numeric($query['limit'] ?? null) ? (int) $query['limit'] : null,
                        'offset' => is_numeric($query['offset'] ?? null) ? (int) $query['offset'] : null,
                    ];
                    $cacheKey = $this->discoveryHandler->buildDiscoveryCacheKey('timeline', $entityType, (string) $entityId, $resolvedOptions);
                    $cached = $this->discoveryHandler->getDiscoveryCachedResponse($cacheKey, $account);
                    if ($cached !== null) {
                        return $this->jsonApiResponse(200, $cached, [
                            'Cache-Control' => 'public, max-age=120',
                            'X-Waaseyaa-Discovery-Cache' => 'HIT',
                        ]);
                    }
                    $service = $this->discoveryHandler->createDiscoveryService();
                    $payload = $service->timeline($entityType, (string) $entityId, $resolvedOptions);

                    [$dPayload, $dHeaders] = $this->discoveryHandler->prepareDiscoveryResponse(200, ['data' => $payload], $cacheKey, $account);
                    return $this->jsonApiResponse(200, $dPayload, $dHeaders);
                })(),

                $controller === 'discovery.endpoint' => (function () use ($params, $query, $account): HttpResponse {
                    $entityType = is_string($params['entity_type'] ?? null) ? trim((string) $params['entity_type']) : '';
                    $entityId = $params['id'] ?? null;
                    if ($entityType === '' || !is_scalar($entityId) || trim((string) $entityId) === '') {
                        return $this->jsonApiResponse(400, [
                            'jsonapi' => ['version' => '1.1'],
                            'errors' => [[
                                'status' => '400',
                                'title' => 'Bad Request',
                                'detail' => 'Discovery endpoint requires route params "entity_type" and "id".',
                            ]],
                        ]);
                    }

                    $resolvedId = (string) $entityId;
                    $resolvedEntity = $this->discoveryHandler->loadDiscoveryEntity($entityType, $resolvedId);
                    if ($resolvedEntity === null || !$this->discoveryHandler->isDiscoveryEntityPublic($entityType, $resolvedEntity->toArray())) {
                        return $this->jsonApiResponse(404, [
                            'jsonapi' => ['version' => '1.1'],
                            'errors' => [[
                                'status' => '404',
                                'title' => 'Not Found',
                                'detail' => sprintf('Discovery endpoint not publicly visible: %s:%s', $entityType, $resolvedId),
                            ]],
                        ]);
                    }

                    $relationshipTypes = $this->discoveryHandler->parseRelationshipTypesQuery($query['relationship_types'] ?? null);
                    $resolvedOptions = [
                        'relationship_types' => $relationshipTypes,
                        'status' => is_string($query['status'] ?? null) ? trim((string) $query['status']) : 'published',
                        'at' => $query['at'] ?? null,
                        'limit' => is_numeric($query['limit'] ?? null) ? (int) $query['limit'] : null,
                    ];
                    $cacheKey = $this->discoveryHandler->buildDiscoveryCacheKey('endpoint', $entityType, $resolvedId, $resolvedOptions);
                    $cached = $this->discoveryHandler->getDiscoveryCachedResponse($cacheKey, $account);
                    if ($cached !== null) {
                        return $this->jsonApiResponse(200, $cached, [
                            'Cache-Control' => 'public, max-age=120',
                            'X-Waaseyaa-Discovery-Cache' => 'HIT',
                        ]);
                    }
                    $service = $this->discoveryHandler->createDiscoveryService();

                    if ($entityType !== 'relationship') {
                        $payload = $service->endpointPage($entityType, $resolvedId, $resolvedOptions);
                        [$dPayload, $dHeaders] = $this->discoveryHandler->prepareDiscoveryResponse(200, ['data' => $payload], $cacheKey, $account);
                        return $this->jsonApiResponse(200, $dPayload, $dHeaders);
                    }

                    $values = $resolvedEntity->toArray();
                    $fromType = trim((string) ($values['from_entity_type'] ?? ''));
                    $fromId = trim((string) ($values['from_entity_id'] ?? ''));
                    $toType = trim((string) ($values['to_entity_type'] ?? ''));
                    $toId = trim((string) ($values['to_entity_id'] ?? ''));
                    if (
                        $fromType === ''
                        || $fromId === ''
                        || $toType === ''
                        || $toId === ''
                        || !$this->discoveryHandler->isDiscoveryEndpointPairPublic($fromType, $fromId, $toType, $toId)
                    ) {
                        return $this->jsonApiResponse(404, [
                            'jsonapi' => ['version' => '1.1'],
                            'errors' => [[
                                'status' => '404',
                                'title' => 'Not Found',
                                'detail' => sprintf('Relationship endpoint pair not publicly visible for %s:%s', $entityType, $resolvedId),
                            ]],
                        ]);
                    }

                    $payload = $service->relationshipEntityPage($values, [
                        'relationship_types' => $resolvedOptions['relationship_types'],
                        'status' => $resolvedOptions['status'],
                        'at' => $resolvedOptions['at'],
                        'limit' => $resolvedOptions['limit'],
                    ]);
                    [$dPayload, $dHeaders] = $this->discoveryHandler->prepareDiscoveryResponse(200, ['data' => $payload], $cacheKey, $account);
                    return $this->jsonApiResponse(200, $dPayload, $dHeaders);
                })(),

                $controller === 'mcp.endpoint' => (function () use ($method, $httpRequest, $account, $serializer): HttpResponse {
                    if (!class_exists(SqliteEmbeddingStorage::class)) {
                        return $this->jsonApiResponse(501, [
                            'jsonapi' => ['version' => '1.1'],
                            'errors' => [[
                                'status' => '501',
                                'title' => 'Not Implemented',
                                'detail' => 'MCP endpoint requires the waaseyaa/ai-vector package.',
                            ]],
                        ]);
                    }

                    $embeddingProvider = EmbeddingProviderFactory::fromConfig($this->config);
                    assert($this->database instanceof \Waaseyaa\Database\DBALDatabase);
                    $embeddingStorage = new SqliteEmbeddingStorage($this->database->getConnection()->getNativeConnection());
                    $mcp = new McpController(
                        entityTypeManager: $this->entityTypeManager,
                        serializer: $serializer,
                        accessHandler: $this->accessHandler,
                        account: $account,
                        embeddingStorage: $embeddingStorage,
                        embeddingProvider: $embeddingProvider,
                        readCache: $this->mcpReadCache,
                    );

                    if ($method === 'GET') {
                        return $this->jsonApiResponse(200, $mcp->manifest());
                    }

                    $raw = trim($httpRequest->getContent());
                    if ($raw === '') {
                        return $this->jsonApiResponse(400, [
                            'jsonrpc' => '2.0',
                            'id' => null,
                            'error' => ['code' => -32700, 'message' => 'Parse error'],
                        ]);
                    }

                    try {
                        $rpc = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                    } catch (\JsonException) {
                        return $this->jsonApiResponse(400, [
                            'jsonrpc' => '2.0',
                            'id' => null,
                            'error' => ['code' => -32700, 'message' => 'Parse error'],
                        ]);
                    }

                    if (!is_array($rpc)) {
                        return $this->jsonApiResponse(400, [
                            'jsonrpc' => '2.0',
                            'id' => null,
                            'error' => ['code' => -32600, 'message' => 'Invalid request'],
                        ]);
                    }

                    return $this->jsonApiResponse(200, $mcp->handleRpc($rpc));
                })(),

                $controller === 'graphql.endpoint' => (function () use ($method, $httpRequest, $account, $queryString): HttpResponse {
                    // Prefer the middleware-resolved session account over the
                    // route-level $account, which is AnonymousUser on allowAll() routes.
                    $resolvedAccount = $httpRequest->attributes->get('_account');
                    $graphqlAccount = ($resolvedAccount instanceof \Waaseyaa\Access\AccountInterface && $resolvedAccount->isAuthenticated())
                        ? $resolvedAccount
                        : $account;
                    $endpoint = new \Waaseyaa\GraphQL\GraphQlEndpoint(
                        entityTypeManager: $this->entityTypeManager,
                        accessHandler: $this->accessHandler,
                        account: $graphqlAccount,
                    );
                    if ($this->graphqlMutationOverrides !== []) {
                        $endpoint = $endpoint->withMutationOverrides($this->graphqlMutationOverrides);
                    }
                    parse_str($queryString, $queryParams);
                    $result = $endpoint->handle($method, $httpRequest->getContent(), $queryParams);
                    return $this->jsonApiResponse($result['statusCode'], $result['body']);
                })(),

                $controller === 'render.page' => (function () use ($params, $query, $account, $httpRequest): HttpResponse {
                    $requestedViewMode = is_string($query['view_mode'] ?? null)
                        ? trim((string) $query['view_mode'])
                        : 'full';
                    $result = $this->ssrPageHandler->handleRenderPage((string) ($params['path'] ?? '/'), $account, $httpRequest, $requestedViewMode);
                    if ($result['type'] === 'json') {
                        return $this->jsonApiResponse($result['status'], $result['content'], $result['headers']);
                    }
                    return $this->htmlResponse($result['status'], $result['content'], $result['headers']);
                })(),

                str_contains($controller, 'SchemaController') => (function () use ($account, $params, $schemaPresenter): HttpResponse {
                    $schemaController = new SchemaController($this->entityTypeManager, $schemaPresenter, $this->accessHandler, $account);
                    $document = $schemaController->show($params['entity_type']);
                    return $this->jsonApiResponse($document->statusCode, $document->toArray());
                })(),

                str_contains($controller, 'ApiDiscoveryController') => (function () use ($account): HttpResponse {
                    $discoveryController = new \Waaseyaa\Api\ApiDiscoveryController($this->entityTypeManager);
                    $result = $discoveryController->discover();
                    return $this->jsonApiResponse(200, ['jsonapi' => ['version' => '1.1'], ...$result]);
                })(),

                str_contains($controller, 'JsonApiController') => (function () use ($serializer, $account, $params, $query, $body, $method): HttpResponse {
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
                    return $this->jsonApiResponse($document->statusCode, $document->toArray());
                })(),

                str_contains($controller, '::') => (function () use ($controller, $params, $query, $account, $httpRequest): HttpResponse {
                    $result = $this->ssrPageHandler->dispatchAppController($controller, $params, $query, $account, $httpRequest);
                    if ($result instanceof HttpResponse) {
                        return $result;
                    }
                    if ($result['type'] === 'json') {
                        return $this->jsonApiResponse($result['status'], $result['content'], $result['headers']);
                    }
                    return $this->htmlResponse($result['status'], $result['content'], $result['headers']);
                })(),

                default => (function () use ($controller): HttpResponse {
                    $this->logger->error(sprintf('Unknown controller: %s', $controller));
                    return $this->jsonApiResponse(500, ['jsonapi' => ['version' => '1.1'], 'errors' => [['status' => '500', 'title' => 'Internal Server Error', 'detail' => 'Unknown route handler.']]]);
                })(),
            };
        } catch (\Throwable $e) {
            $this->logger->critical(sprintf("Unhandled exception: %s in %s:%d\n%s", $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString()));

            $debug = filter_var($this->config['debug'] ?? getenv('WAASEYAA_DEBUG') ?: false, FILTER_VALIDATE_BOOLEAN);
            $detail = $debug
                ? sprintf('%s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine())
                : 'An unexpected error occurred.';

            $error = [
                'status' => '500',
                'title' => 'Internal Server Error',
                'detail' => $detail,
            ];

            if ($debug) {
                $error['meta'] = [
                    'exception' => $e::class,
                    'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 20),
                ];
            }

            return $this->jsonApiResponse(500, [
                'jsonapi' => ['version' => '1.1'],
                'errors' => [$error],
            ]);
        }
    }

    private function handleMediaUpload(
        HttpRequest $httpRequest,
        AccountInterface $account,
        ResourceSerializer $serializer,
    ): HttpResponse {
        $contentType = strtolower((string) $httpRequest->headers->get('Content-Type', ''));
        if (!str_starts_with($contentType, 'multipart/form-data')) {
            return $this->jsonApiResponse(415, [
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
            return $this->jsonApiResponse(400, [
                'jsonapi' => ['version' => '1.1'],
                'errors' => [[
                    'status' => '400',
                    'title' => 'Bad Request',
                    'detail' => 'Missing uploaded file under "file".',
                ]],
            ]);
        }

        if (!$uploadedFile->isValid()) {
            return $this->jsonApiResponse(400, [
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
            return $this->jsonApiResponse(413, [
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
            return $this->jsonApiResponse(415, [
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
            return $this->jsonApiResponse(500, [
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
            return $this->jsonApiResponse(500, [
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
            $this->logger->error(sprintf('Upload move failed: %s', $e->getMessage()));
            return $this->jsonApiResponse(500, [
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
            return $this->jsonApiResponse(403, [
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
        return $this->jsonApiResponse(201, [
            'jsonapi' => ['version' => '1.1'],
            'data' => $resource->toArray(),
            'links' => ['self' => "/api/media/{$resource->id}"],
            'meta' => [
                'uploaded' => true,
                'file_url' => $fileUrl,
            ],
        ]);
    }

    public function resolveFilesRootDir(): string
    {
        $configured = $this->config['files_dir'] ?? null;
        if (is_string($configured) && trim($configured) !== '') {
            return $configured;
        }

        return $this->projectRoot . '/storage/files';
    }

    public function resolveUploadMaxBytes(): int
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
    public function resolveAllowedUploadMimeTypes(): array
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
    public function isAllowedMimeType(string $mimeType, array $allowedMimeTypes): bool
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

    public function sanitizeUploadFilename(string $name): string
    {
        $basename = basename($name);
        $clean = preg_replace('/[^A-Za-z0-9._-]/', '_', $basename);
        if (!is_string($clean) || $clean === '' || $clean === '.' || $clean === '..') {
            return 'upload.bin';
        }

        return $clean;
    }

    /**
     * Maps a file URI to its public HTTP path (e.g. public://images/a.jpg -> /files/images/a.jpg).
     *
     * Note: the /files/ URL prefix is intentionally independent of the filesystem storage root
     * (resolveFilesRootDir()). The web server or front controller is responsible for mapping
     * /files/* requests to the configured storage directory.
     */
    public function buildPublicFileUrl(string $uri): string
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


    /**
     * Build an HTML response.
     *
     * @param array<string, string> $headers
     */
    private function htmlResponse(int $status, string $html, array $headers = []): HttpResponse
    {
        return new HttpResponse($html, $status, array_merge(['Content-Type' => 'text/html; charset=UTF-8'], $headers));
    }
}
