<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Http\Router;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\AI\Vector\EmbeddingProviderFactory;
use Waaseyaa\AI\Vector\SqliteEmbeddingStorage;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Cache\CacheBackendInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Http\JsonApiResponseTrait;
use Waaseyaa\Mcp\McpController;

final class McpRouter implements DomainRouterInterface
{
    use JsonApiResponseTrait;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly EntityAccessHandler $accessHandler,
        private readonly \Waaseyaa\Database\DBALDatabase $database,
        private readonly array $config,
        private readonly ?CacheBackendInterface $mcpReadCache,
    ) {}

    public function supports(Request $request): bool
    {
        return $request->attributes->get('_controller', '') === 'mcp.endpoint';
    }

    public function handle(Request $request): Response
    {
        if (!class_exists(SqliteEmbeddingStorage::class)) {
            return $this->jsonApiResponse(501, [
                'jsonapi' => ['version' => '1.1'],
                'errors' => [['status' => '501', 'title' => 'Not Implemented', 'detail' => 'MCP endpoint requires the waaseyaa/ai-vector package.']],
            ]);
        }

        $ctx = WaaseyaaContext::fromRequest($request);
        $serializer = new ResourceSerializer($this->entityTypeManager);
        $embeddingProvider = EmbeddingProviderFactory::fromConfig($this->config);

        $embeddingStorage = new SqliteEmbeddingStorage($this->database->getConnection()->getNativeConnection());

        $mcp = new McpController(
            entityTypeManager: $this->entityTypeManager,
            serializer: $serializer,
            accessHandler: $this->accessHandler,
            account: $ctx->account,
            embeddingStorage: $embeddingStorage,
            embeddingProvider: $embeddingProvider,
            readCache: $this->mcpReadCache,
        );

        if ($ctx->method === 'GET') {
            return $this->jsonApiResponse(200, $mcp->manifest());
        }

        $raw = trim($request->getContent());
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

        if (!is_array($rpc) || !isset($rpc['method'])) {
            return $this->jsonApiResponse(400, [
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => ['code' => -32600, 'message' => 'Invalid request'],
            ]);
        }

        return $this->jsonApiResponse(200, $mcp->handleRpc($rpc));
    }
}
