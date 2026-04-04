<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Http\Router;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\AI\Vector\EmbeddingProviderFactory;
use Waaseyaa\AI\Vector\SearchController;
use Waaseyaa\AI\Vector\SqliteEmbeddingStorage;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Http\JsonApiResponseTrait;

final class SearchRouter implements DomainRouterInterface
{
    use JsonApiResponseTrait;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly array $config,
        private readonly DatabaseInterface $database,
        private readonly ?EntityTypeManager $entityTypeManager = null,
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
        $limit = is_numeric($ctx->query['limit'] ?? null) ? (int) $ctx->query['limit'] : 10;

        if ($searchQuery === '' || $entityType === '') {
            return $this->jsonApiResponse(400, [
                'jsonapi' => ['version' => '1.1'],
                'errors' => [['status' => '400', 'title' => 'Bad Request', 'detail' => 'Search requires query parameters "q" and "type".']],
            ]);
        }

        if (!class_exists(SqliteEmbeddingStorage::class)) {
            return $this->jsonApiResponse(501, [
                'jsonapi' => ['version' => '1.1'],
                'errors' => [['status' => '501', 'title' => 'Not Implemented', 'detail' => 'Semantic search requires the waaseyaa/ai-vector package.']],
            ]);
        }

        $embeddingProvider = EmbeddingProviderFactory::fromConfig($this->config);
        assert($this->database instanceof \Waaseyaa\Database\DBALDatabase);
        $embeddingStorage = new SqliteEmbeddingStorage($this->database->getConnection()->getNativeConnection());

        $searchController = new SearchController($embeddingStorage, $embeddingProvider);
        $results = $searchController->search($searchQuery, $entityType, $limit);

        $serializer = $this->entityTypeManager !== null
            ? new ResourceSerializer($this->entityTypeManager)
            : null;

        return $this->jsonApiResponse(200, [
            'jsonapi' => ['version' => '1.1'],
            'data' => $results,
        ]);
    }
}
