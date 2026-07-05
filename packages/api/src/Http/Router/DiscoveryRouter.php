<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Http\Router;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Api\ApiDiscoveryController;
use Waaseyaa\Api\Http\DiscoveryApiHandler;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\EntityValues;
use Waaseyaa\Foundation\Http\JsonApiResponseTrait;
use Waaseyaa\Foundation\Http\Router\DomainRouterInterface;
use Waaseyaa\Foundation\Http\Router\WaaseyaaContext;

final class DiscoveryRouter implements DomainRouterInterface
{
    use JsonApiResponseTrait;

    public function __construct(
        private readonly DiscoveryApiHandler $discoveryHandler,
        private readonly EntityTypeManager $entityTypeManager,
    ) {}

    /**
     * Clamp the requested discovery `status` to what the caller is
     * authorized to see.
     *
     * `status=all` bypasses RelationshipTraversalService::browse()'s
     * per-account visibility filter entirely (it is the "system-context,
     * unfiltered" spelling), and `status=unpublished` surfaces draft edges.
     * Both are privileged views — an anonymous or unauthorized caller must
     * only ever get 'published' regardless of what it requested, otherwise
     * it receives unpublished/private related-entity identities
     * (`to_entity_type`/`to_entity_id`) and edge metadata it must not see
     * (audit R2 WP2).
     *
     * The gate mirrors RelationshipAccessPolicy's own admin bypass
     * (`hasPermission('administer nodes')`, see
     * packages/relationship/src/RelationshipAccessPolicy.php), so the
     * discovery status gate stays consistent with the entity-level
     * relationship access policy.
     */
    private function resolveDiscoveryStatus(WaaseyaaContext $ctx): string
    {
        $requested = is_string($ctx->query['status'] ?? null) ? trim((string) $ctx->query['status']) : 'published';
        $authorized = $ctx->account->isAuthenticated() && $ctx->account->hasPermission('administer nodes');
        if (!$authorized && $requested !== 'published') {
            return 'published';
        }

        return $requested;
    }

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
            $discoveryController = new ApiDiscoveryController($this->entityTypeManager, account: $ctx->account);
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
            'status' => $this->resolveDiscoveryStatus($ctx),
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

        $service = $this->discoveryHandler->createDiscoveryService($ctx->account);
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
            'status' => $this->resolveDiscoveryStatus($ctx),
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

        $service = $this->discoveryHandler->createDiscoveryService($ctx->account);
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
            'status' => $this->resolveDiscoveryStatus($ctx),
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

        $service = $this->discoveryHandler->createDiscoveryService($ctx->account);
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
        if ($resolvedEntity === null || !$this->discoveryHandler->isDiscoveryEntityPublic($resolvedEntity, $ctx->account)) {
            return $this->jsonApiResponse(404, [
                'jsonapi' => ['version' => '1.1'],
                'errors' => [['status' => '404', 'title' => 'Not Found', 'detail' => sprintf('Discovery endpoint not publicly visible: %s:%s', $entityType, $resolvedId)]],
            ]);
        }

        $relationshipTypes = $this->discoveryHandler->parseRelationshipTypesQuery($ctx->query['relationship_types'] ?? null);
        $resolvedOptions = [
            'relationship_types' => $relationshipTypes,
            'status' => $this->resolveDiscoveryStatus($ctx),
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

        $service = $this->discoveryHandler->createDiscoveryService($ctx->account);

        if ($entityType !== 'relationship') {
            $payload = $service->endpointPage($entityType, $resolvedId, $resolvedOptions);
            [$dPayload, $dHeaders] = $this->discoveryHandler->prepareDiscoveryResponse(200, ['data' => $payload], $cacheKey, $ctx->account);

            return $this->jsonApiResponse(200, $dPayload, $dHeaders);
        }

        $values = EntityValues::toCastAwareMap($resolvedEntity);
        $fromType = trim((string) ($values['from_entity_type'] ?? ''));
        $fromId = trim((string) ($values['from_entity_id'] ?? ''));
        $toType = trim((string) ($values['to_entity_type'] ?? ''));
        $toId = trim((string) ($values['to_entity_id'] ?? ''));
        if (
            $fromType === ''
            || $fromId === ''
            || $toType === ''
            || $toId === ''
            || !$this->discoveryHandler->isDiscoveryEndpointPairPublic($fromType, $fromId, $toType, $toId, $ctx->account)
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
