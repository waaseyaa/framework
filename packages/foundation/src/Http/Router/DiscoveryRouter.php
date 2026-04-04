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
                'errors' => [['status' => '400', 'title' => 'Bad Request', 'detail' => 'Discovery hub requires route params "entity_type" and "id".']],
            ]);
        }

        $relationshipTypes = $this->discoveryHandler->parseRelationshipTypesQuery($ctx->query['relationship_types'] ?? null);
        $resolvedOptions = [
            'relationship_types' => $relationshipTypes,
            'status' => is_string($ctx->query['status'] ?? null) ? trim((string) $ctx->query['status']) : 'published',
            'at' => $ctx->query['at'] ?? null,
            'limit' => is_numeric($ctx->query['limit'] ?? null) ? (int) $ctx->query['limit'] : null,
            'offset' => is_numeric($ctx->query['offset'] ?? null) ? (int) $ctx->query['offset'] : null,
        ];

        $cacheKey = $this->discoveryHandler->buildDiscoveryCacheKey('hub', $entityType, (string) $entityId, $resolvedOptions);
        $cached = $this->discoveryHandler->getDiscoveryCachedResponse($cacheKey, $ctx->account);
        if ($cached !== null) {
            return $this->jsonApiResponse(200, $cached, [
                'Cache-Control' => 'public, max-age=120',
                'X-Waaseyaa-Discovery-Cache' => 'HIT',
            ]);
        }

        $service = $this->discoveryHandler->createDiscoveryService();
        $payload = $service->topicHub($entityType, (string) $entityId, $resolvedOptions);
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
            'limit' => is_numeric($ctx->query['limit'] ?? null) ? (int) $ctx->query['limit'] : null,
            'offset' => is_numeric($ctx->query['offset'] ?? null) ? (int) $ctx->query['offset'] : null,
        ];

        $cacheKey = $this->discoveryHandler->buildDiscoveryCacheKey('cluster', $entityType, (string) $entityId, $resolvedOptions);
        $cached = $this->discoveryHandler->getDiscoveryCachedResponse($cacheKey, $ctx->account);
        if ($cached !== null) {
            return $this->jsonApiResponse(200, $cached, [
                'Cache-Control' => 'public, max-age=120',
                'X-Waaseyaa-Discovery-Cache' => 'HIT',
            ]);
        }

        $service = $this->discoveryHandler->createDiscoveryService();
        $payload = $service->clusterPage($entityType, (string) $entityId, $resolvedOptions);
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

        $relationshipTypes = $this->discoveryHandler->parseRelationshipTypesQuery($ctx->query['relationship_types'] ?? null);
        $resolvedOptions = [
            'direction' => is_string($ctx->query['direction'] ?? null) ? trim((string) $ctx->query['direction']) : 'both',
            'relationship_types' => $relationshipTypes,
            'status' => is_string($ctx->query['status'] ?? null) ? trim((string) $ctx->query['status']) : 'published',
            'at' => $ctx->query['at'] ?? null,
            'from' => $ctx->query['from'] ?? null,
            'to' => $ctx->query['to'] ?? null,
            'limit' => is_numeric($ctx->query['limit'] ?? null) ? (int) $ctx->query['limit'] : null,
            'offset' => is_numeric($ctx->query['offset'] ?? null) ? (int) $ctx->query['offset'] : null,
        ];

        $cacheKey = $this->discoveryHandler->buildDiscoveryCacheKey('timeline', $entityType, (string) $entityId, $resolvedOptions);
        $cached = $this->discoveryHandler->getDiscoveryCachedResponse($cacheKey, $ctx->account);
        if ($cached !== null) {
            return $this->jsonApiResponse(200, $cached, [
                'Cache-Control' => 'public, max-age=120',
                'X-Waaseyaa-Discovery-Cache' => 'HIT',
            ]);
        }

        $service = $this->discoveryHandler->createDiscoveryService();
        $payload = $service->timeline($entityType, (string) $entityId, $resolvedOptions);
        [$dPayload, $dHeaders] = $this->discoveryHandler->prepareDiscoveryResponse(200, ['data' => $payload], $cacheKey, $ctx->account);

        return $this->jsonApiResponse(200, $dPayload, $dHeaders);
    }

    private function handleEndpoint(array $params, WaaseyaaContext $ctx): Response
    {
        $entityType = is_string($params['entity_type'] ?? null) ? trim((string) $params['entity_type']) : '';
        $entityId = $params['id'] ?? null;
        if ($entityType === '' || !is_scalar($entityId) || trim((string) $entityId) === '') {
            return $this->jsonApiResponse(400, [
                'jsonapi' => ['version' => '1.1'],
                'errors' => [['status' => '400', 'title' => 'Bad Request', 'detail' => 'Discovery endpoint requires route params "entity_type" and "id".']],
            ]);
        }

        $resolvedId = (string) $entityId;
        $resolvedEntity = $this->discoveryHandler->loadDiscoveryEntity($entityType, $resolvedId);
        if ($resolvedEntity === null || !$this->discoveryHandler->isDiscoveryEntityPublic($entityType, $resolvedEntity->toArray())) {
            return $this->jsonApiResponse(404, [
                'jsonapi' => ['version' => '1.1'],
                'errors' => [['status' => '404', 'title' => 'Not Found', 'detail' => sprintf('Discovery endpoint not publicly visible: %s:%s', $entityType, $resolvedId)]],
            ]);
        }

        $relationshipTypes = $this->discoveryHandler->parseRelationshipTypesQuery($ctx->query['relationship_types'] ?? null);
        $resolvedOptions = [
            'relationship_types' => $relationshipTypes,
            'status' => is_string($ctx->query['status'] ?? null) ? trim((string) $ctx->query['status']) : 'published',
            'at' => $ctx->query['at'] ?? null,
            'limit' => is_numeric($ctx->query['limit'] ?? null) ? (int) $ctx->query['limit'] : null,
        ];

        $cacheKey = $this->discoveryHandler->buildDiscoveryCacheKey('endpoint', $entityType, $resolvedId, $resolvedOptions);
        $cached = $this->discoveryHandler->getDiscoveryCachedResponse($cacheKey, $ctx->account);
        if ($cached !== null) {
            return $this->jsonApiResponse(200, $cached, [
                'Cache-Control' => 'public, max-age=120',
                'X-Waaseyaa-Discovery-Cache' => 'HIT',
            ]);
        }

        $service = $this->discoveryHandler->createDiscoveryService();

        if ($entityType !== 'relationship') {
            $payload = $service->endpointPage($entityType, $resolvedId, $resolvedOptions);
            [$dPayload, $dHeaders] = $this->discoveryHandler->prepareDiscoveryResponse(200, ['data' => $payload], $cacheKey, $ctx->account);
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
                'errors' => [['status' => '404', 'title' => 'Not Found', 'detail' => sprintf('Relationship endpoint pair not publicly visible for %s:%s', $entityType, $resolvedId)]],
            ]);
        }

        $payload = $service->relationshipEntityPage($values, [
            'relationship_types' => $resolvedOptions['relationship_types'],
            'status' => $resolvedOptions['status'],
            'at' => $resolvedOptions['at'],
            'limit' => $resolvedOptions['limit'],
        ]);
        [$dPayload, $dHeaders] = $this->discoveryHandler->prepareDiscoveryResponse(200, ['data' => $payload], $cacheKey, $ctx->account);
        return $this->jsonApiResponse(200, $dPayload, $dHeaders);
    }
}
