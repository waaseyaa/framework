<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Http\Router;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\AI\Vector\EmbeddingProviderInterface;
use Waaseyaa\AI\Vector\EmbeddingStorageInterface;
use Waaseyaa\AI\Vector\SearchController;
use Waaseyaa\Api\InternalFieldVisibilityPolicy;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Foundation\Http\JsonApiResponseTrait;

final class SearchRouter implements DomainRouterInterface
{
    use JsonApiResponseTrait;

    /**
     * @param (\Closure(): (array{0: EmbeddingStorageInterface, 1: ?EmbeddingProviderInterface}|null))|null $embeddingServices
     *        Resolves the storage and provider bound by ai-vector's provider
     *        (FW-AIV-COMP-01); null when waaseyaa/ai-vector isn't installed.
     */
    public function __construct(
        private readonly ?\Closure $embeddingServices = null,
        private readonly ?EntityTypeManagerInterface $entityTypeManager = null,
        private readonly ?EntityAccessHandler $accessHandler = null,
        private readonly ?InternalFieldVisibilityPolicy $internalFieldVisibility = null,
    ) {}

    public function supports(Request $request): bool
    {
        return $request->attributes->get('_controller', '') === 'search.semantic';
    }

    public function handle(Request $request): Response
    {
        $ctx = WaaseyaaContext::fromRequest($request);

        $searchQuery = is_string($ctx->query['q'] ?? null) ? trim((string) $ctx->query['q']) : '';
        $entityType = is_string($ctx->query['type'] ?? null) ? trim((string) $ctx->query['type']) : '';
        $rawLimit = $ctx->query['limit'] ?? 10;
        $validLimit = is_int($rawLimit) || (is_string($rawLimit) && preg_match('/^-?[0-9]+$/D', $rawLimit) === 1);
        $limit = $validLimit ? (int) $rawLimit : 10;

        if ($searchQuery === '' || $entityType === '' || !$validLimit) {
            return $this->jsonApiResponse(400, [
                'jsonapi' => ['version' => '1.1'],
                'errors' => [['status' => '400', 'title' => 'Bad Request', 'detail' => 'Search requires nonempty "q" and "type" and an integer "limit".']],
            ]);
        }

        try {
            return $this->handleSearch($ctx, $searchQuery, $entityType, $limit);
        } catch (\Throwable) {
            return $this->jsonApiResponse(503, [
                'jsonapi' => ['version' => '1.1'],
                'errors' => [['status' => '503', 'title' => 'Service Unavailable', 'detail' => 'Search is temporarily unavailable.', 'code' => 'SEMANTIC_SEARCH_UNAVAILABLE']],
            ]);
        }
    }

    private function handleSearch(WaaseyaaContext $ctx, string $searchQuery, string $entityType, int $limit): Response
    {
        $services = $this->embeddingServices !== null ? ($this->embeddingServices)() : null;
        if ($services === null) {
            return $this->jsonApiResponse(501, [
                'jsonapi' => ['version' => '1.1'],
                'errors' => [['status' => '501', 'title' => 'Not Implemented', 'detail' => 'Semantic search requires the waaseyaa/ai-vector package.']],
            ]);
        }

        if ($this->entityTypeManager === null) {
            return $this->jsonApiResponse(500, [
                'jsonapi' => ['version' => '1.1'],
                'errors' => [['status' => '500', 'title' => 'Internal Server Error', 'detail' => 'EntityTypeManager is required for search.']],
            ]);
        }

        [$embeddingStorage, $embeddingProvider] = $services;
        $serializer = new ResourceSerializer($this->entityTypeManager, internalFieldVisibility: $this->internalFieldVisibility);

        $searchController = new SearchController(
            entityTypeManager: $this->entityTypeManager,
            serializer: $serializer,
            embeddingStorage: $embeddingStorage,
            embeddingProvider: $embeddingProvider,
            accessHandler: $this->accessHandler,
            account: $ctx->principal,
        );

        $results = $searchController->search($searchQuery, $entityType, $limit);

        return $this->jsonApiResponse($results->statusCode, $results->toArray());
    }
}
