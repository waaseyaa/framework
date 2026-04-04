<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Http\Router;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Api\Http\DiscoveryApiHandler;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Http\JsonApiResponseTrait;

final class DiscoveryRouter implements DomainRouterInterface
{
    use JsonApiResponseTrait;

    public function __construct(
        private readonly DiscoveryApiHandler $discoveryHandler,
        private readonly EntityTypeManager $entityTypeManager,
    ) {}

    public function supports(Request $request): bool
    {
        $controller = $request->attributes->get('_controller', '');

        return str_starts_with($controller, 'discovery.')
            || str_contains($controller, 'ApiDiscoveryController');
    }

    public function handle(Request $request): Response
    {
        $controller = $request->attributes->get('_controller', '');
        $ctx = WaaseyaaContext::fromRequest($request);
        $params = $request->attributes->all();

        if (str_contains($controller, 'ApiDiscoveryController')) {
            $discoveryController = new \Waaseyaa\Api\ApiDiscoveryController($this->entityTypeManager);
            $result = $discoveryController->discover();
            return $this->jsonApiResponse(200, ['jsonapi' => ['version' => '1.1'], ...$result]);
        }

        return match ($controller) {
            'discovery.topic_hub' => $this->handleTopicHub($params, $ctx),
            'discovery.cluster' => $this->handleCluster($params, $ctx),
            'discovery.timeline' => $this->handleTimeline($params, $ctx),
            'discovery.endpoint' => $this->handleEndpoint($params, $ctx),
            default => $this->jsonApiResponse(404, [
                'jsonapi' => ['version' => '1.1'],
                'errors' => [['status' => '404', 'title' => 'Not Found', 'detail' => "Unknown discovery action: $controller"]],
            ]),
        };
    }

    private function handleTopicHub(array $params, WaaseyaaContext $ctx): Response
    {
        $entityType = is_string($params['entity_type'] ?? null) ? trim((string) $params['entity_type']) : '';
        $entityId = $params['id'] ?? null;
        if ($entityType === '' || !is_scalar($entityId) || trim((string) $entityId) === '') {
            return $this->jsonApiResponse(400, [
                'jsonapi' => ['version' => '1.1'],
                'errors' => [['status' => '400', 'title' => 'Bad Request', 'detail' => 'Discovery topic hub requires route params "entity_type" and "id".']],
            ]);
        }

        $relationshipTypes = $this->discoveryHandler->parseRelationshipTypesQuery($ctx->query['relationship_types'] ?? null);
        $resolvedOptions = [
            'relationship_types' => $relationshipTypes,
            'status' => is_string($ctx->query['status'] ?? null) ? trim((string) $ctx->query['status']) : 'published',
            'at' => $ctx->query['at'] ?? null,
            'limit' => is_numeric($ctx->query['limit'] ?? null) ? (int) $ctx->query['limit'] : 25,
        ];

        $cacheKey = $this->discoveryHandler->buildCacheKey('topic_hub', $entityType, (string) $entityId, $resolvedOptions);
        $cached = $this->discoveryHandler->getCachedResponse($cacheKey, $ctx->account);
        if ($cached !== null) {
            return $this->jsonApiResponse(200, $cached, ['X-Discovery-Cache' => 'hit']);
        }

        $payload = $this->discoveryHandler->topicHub($entityType, (string) $entityId, $resolvedOptions, $ctx->account);
        [$dPayload, $dHeaders] = $this->discoveryHandler->prepareDiscoveryResponse(200, ['data' => $payload], $cacheKey, $ctx->account);

        return $this->jsonApiResponse(200, $dPayload, $dHeaders);
    }

    private function handleCluster(array $params, WaaseyaaContext $ctx): Response
    {
        $entityType = is_string($params['entity_type'] ?? null) ? trim((string) $params['entity_type']) : '';
        $entityId = $params['id'] ?? null;
        if ($entityType === '' || !is_scalar($entityId) || trim((string) $entityId) === '') {
            return $this->jsonApiResponse(400, [
                'jsonapi' => ['version' => '1.1'],
                'errors' => [['status' => '400', 'title' => 'Bad Request', 'detail' => 'Discovery cluster requires route params "entity_type" and "id".']],
            ]);
        }

        $relationshipTypes = $this->discoveryHandler->parseRelationshipTypesQuery($ctx->query['relationship_types'] ?? null);
        $resolvedOptions = [
            'relationship_types' => $relationshipTypes,
            'status' => is_string($ctx->query['status'] ?? null) ? trim((string) $ctx->query['status']) : 'published',
            'at' => $ctx->query['at'] ?? null,
            'limit' => is_numeric($ctx->query['limit'] ?? null) ? (int) $ctx->query['limit'] : 25,
        ];

        $cacheKey = $this->discoveryHandler->buildCacheKey('cluster', $entityType, (string) $entityId, $resolvedOptions);
        $cached = $this->discoveryHandler->getCachedResponse($cacheKey, $ctx->account);
        if ($cached !== null) {
            return $this->jsonApiResponse(200, $cached, ['X-Discovery-Cache' => 'hit']);
        }

        $payload = $this->discoveryHandler->cluster($entityType, (string) $entityId, $resolvedOptions, $ctx->account);
        [$dPayload, $dHeaders] = $this->discoveryHandler->prepareDiscoveryResponse(200, ['data' => $payload], $cacheKey, $ctx->account);

        return $this->jsonApiResponse(200, $dPayload, $dHeaders);
    }

    private function handleTimeline(array $params, WaaseyaaContext $ctx): Response
    {
        $entityType = is_string($params['entity_type'] ?? null) ? trim((string) $params['entity_type']) : '';
        $entityId = $params['id'] ?? null;
        if ($entityType === '' || !is_scalar($entityId) || trim((string) $entityId) === '') {
            return $this->jsonApiResponse(400, [
                'jsonapi' => ['version' => '1.1'],
                'errors' => [['status' => '400', 'title' => 'Bad Request', 'detail' => 'Discovery timeline requires route params "entity_type" and "id".']],
            ]);
        }

        $resolvedOptions = [
            'status' => is_string($ctx->query['status'] ?? null) ? trim((string) $ctx->query['status']) : 'published',
            'at' => $ctx->query['at'] ?? null,
            'limit' => is_numeric($ctx->query['limit'] ?? null) ? (int) $ctx->query['limit'] : 25,
        ];

        $cacheKey = $this->discoveryHandler->buildCacheKey('timeline', $entityType, (string) $entityId, $resolvedOptions);
        $cached = $this->discoveryHandler->getCachedResponse($cacheKey, $ctx->account);
        if ($cached !== null) {
            return $this->jsonApiResponse(200, $cached, ['X-Discovery-Cache' => 'hit']);
        }

        $payload = $this->discoveryHandler->timeline($entityType, (string) $entityId, $resolvedOptions, $ctx->account);
        [$dPayload, $dHeaders] = $this->discoveryHandler->prepareDiscoveryResponse(200, ['data' => $payload], $cacheKey, $ctx->account);

        return $this->jsonApiResponse(200, $dPayload, $dHeaders);
    }

    private function handleEndpoint(array $params, WaaseyaaContext $ctx): Response
    {
        $entityType = is_string($params['entity_type'] ?? null) ? trim((string) $params['entity_type']) : '';
        $entityId = $params['id'] ?? null;

        if ($entityType === '') {
            return $this->jsonApiResponse(400, [
                'jsonapi' => ['version' => '1.1'],
                'errors' => [['status' => '400', 'title' => 'Bad Request', 'detail' => 'Discovery endpoint requires route param "entity_type".']],
            ]);
        }

        $resolvedOptions = [
            'status' => is_string($ctx->query['status'] ?? null) ? trim((string) $ctx->query['status']) : 'published',
            'at' => $ctx->query['at'] ?? null,
            'limit' => is_numeric($ctx->query['limit'] ?? null) ? (int) $ctx->query['limit'] : 25,
        ];

        if ($entityId === null || (is_string($entityId) && trim($entityId) === '')) {
            // List endpoint
            if (!$this->entityTypeManager->hasDefinition($entityType)) {
                return $this->jsonApiResponse(404, [
                    'jsonapi' => ['version' => '1.1'],
                    'errors' => [['status' => '404', 'title' => 'Not Found', 'detail' => sprintf('Unknown entity type: "%s".', $entityType)]],
                ]);
            }

            $cacheKey = $this->discoveryHandler->buildCacheKey('endpoint_list', $entityType, '', $resolvedOptions);
            $cached = $this->discoveryHandler->getCachedResponse($cacheKey, $ctx->account);
            if ($cached !== null) {
                return $this->jsonApiResponse(200, $cached, ['X-Discovery-Cache' => 'hit']);
            }

            $payload = $this->discoveryHandler->endpointList($entityType, $resolvedOptions, $ctx->account);
            [$dPayload, $dHeaders] = $this->discoveryHandler->prepareDiscoveryResponse(200, ['data' => $payload], $cacheKey, $ctx->account);

            return $this->jsonApiResponse(200, $dPayload, $dHeaders);
        }

        // Single endpoint
        $cacheKey = $this->discoveryHandler->buildCacheKey('endpoint', $entityType, (string) $entityId, $resolvedOptions);
        $cached = $this->discoveryHandler->getCachedResponse($cacheKey, $ctx->account);
        if ($cached !== null) {
            return $this->jsonApiResponse(200, $cached, ['X-Discovery-Cache' => 'hit']);
        }

        $entity = $this->discoveryHandler->resolveEntity($entityType, (string) $entityId, $ctx->account);
        if ($entity === null) {
            return $this->jsonApiResponse(404, [
                'jsonapi' => ['version' => '1.1'],
                'errors' => [['status' => '404', 'title' => 'Not Found', 'detail' => sprintf('Entity %s/%s not found.', $entityType, $entityId)]],
            ]);
        }

        $payload = $this->discoveryHandler->endpoint($entityType, (string) $entityId, $resolvedOptions, $ctx->account);
        [$dPayload, $dHeaders] = $this->discoveryHandler->prepareDiscoveryResponse(200, ['data' => $payload], $cacheKey, $ctx->account);

        return $this->jsonApiResponse(200, $dPayload, $dHeaders);
    }
}
