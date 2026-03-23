<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\AI\Vector\EmbeddingProviderInterface;
use Waaseyaa\AI\Vector\EmbeddingStorageInterface;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Cache\CacheBackendInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Mcp\Cache\ReadCache;
use Waaseyaa\Mcp\Rpc\ResponseFormatter;
use Waaseyaa\Mcp\Rpc\ToolIntrospector;
use Waaseyaa\Mcp\Tools\DiscoveryTools;
use Waaseyaa\Mcp\Tools\EditorialTools;
use Waaseyaa\Mcp\Tools\EntityTools;
use Waaseyaa\Mcp\Tools\TraversalTools;
use Waaseyaa\Relationship\RelationshipTraversalService;
use Waaseyaa\Workflows\EditorialTransitionAccessResolver;
use Waaseyaa\Workflows\EditorialWorkflowPreset;
use Waaseyaa\Workflows\Workflow;
use Waaseyaa\Workflows\WorkflowVisibility;

final class McpController
{
    private const string CONTRACT_VERSION = 'v1.0';
    private const string CONTRACT_STABILITY = 'stable';
    private readonly ResponseFormatter $formatter;
    private readonly ToolIntrospector $introspector;
    private readonly ReadCache $readCacheHandler;
    private readonly EntityTools $entityTools;
    private readonly DiscoveryTools $discoveryTools;
    private readonly TraversalTools $traversalTools;
    private readonly EditorialTools $editorialTools;
    private readonly Workflow $editorialWorkflow;
    private readonly EditorialTransitionAccessResolver $editorialTransitionResolver;
    private readonly WorkflowVisibility $workflowVisibility;

    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly ResourceSerializer $serializer,
        private readonly EntityAccessHandler $accessHandler,
        private readonly AccountInterface $account,
        private readonly EmbeddingStorageInterface $embeddingStorage,
        private readonly ?EmbeddingProviderInterface $embeddingProvider = null,
        private readonly ?RelationshipTraversalService $relationshipTraversal = null,
        private readonly ?CacheBackendInterface $readCache = null,
        private readonly array $extensionRegistrations = [],
    ) {
        $this->formatter = new ResponseFormatter();
        $this->introspector = new ToolIntrospector($this->formatter, $this->extensionRegistrations);
        $this->readCacheHandler = new ReadCache(account: $this->account, backend: $this->readCache);
        $this->entityTools = new EntityTools(
            entityTypeManager: $this->entityTypeManager,
            serializer: $this->serializer,
            accessHandler: $this->accessHandler,
            account: $this->account,
        );
        $this->editorialWorkflow = EditorialWorkflowPreset::create();
        $this->editorialTransitionResolver = new EditorialTransitionAccessResolver($this->editorialWorkflow);
        $this->workflowVisibility = new WorkflowVisibility($this->editorialWorkflow);
        $this->discoveryTools = new DiscoveryTools(
            entityTypeManager: $this->entityTypeManager,
            serializer: $this->serializer,
            accessHandler: $this->accessHandler,
            account: $this->account,
            embeddingStorage: $this->embeddingStorage,
            embeddingProvider: $this->embeddingProvider,
            workflowVisibility: $this->workflowVisibility,
        );
        $this->traversalTools = new TraversalTools(
            entityTypeManager: $this->entityTypeManager,
            serializer: $this->serializer,
            accessHandler: $this->accessHandler,
            account: $this->account,
            relationshipTraversal: $this->relationshipTraversal,
        );
        $this->editorialTools = new EditorialTools(
            entityTypeManager: $this->entityTypeManager,
            serializer: $this->serializer,
            accessHandler: $this->accessHandler,
            account: $this->account,
            editorialWorkflow: $this->editorialWorkflow,
            editorialTransitionResolver: $this->editorialTransitionResolver,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function manifest(): array
    {
        return [
            'protocolVersion' => '2024-11-05',
            'server' => [
                'name' => 'Waaseyaa MCP',
                'version' => $this->formatter->contractVersion(),
            ],
            'tools' => [
                ['name' => 'search_entities', 'description' => 'Stable semantic/keyword search contract for entities'],
                ['name' => 'ai_discover', 'description' => 'Blend semantic search and graph context for deterministic discovery recommendations'],
                ['name' => 'get_entity', 'description' => 'Fetch a single entity by type and ID'],
                ['name' => 'list_entity_types', 'description' => 'List available entity types and schemas'],
                ['name' => 'traverse_relationships', 'description' => 'Traverse relationship entities for a source entity with direction/type/temporal filters'],
                ['name' => 'get_related_entities', 'description' => 'Resolve related entities via relationship traversal with optional edge payloads'],
                ['name' => 'get_knowledge_graph', 'description' => 'Return directional relationship graph surface for an entity'],
                ['name' => 'editorial_transition', 'description' => 'Apply an editorial workflow transition to a node entity'],
                ['name' => 'editorial_validate', 'description' => 'Validate editorial workflow transition eligibility without mutating state'],
                ['name' => 'editorial_publish', 'description' => 'Publish a node through editorial workflow rules'],
                ['name' => 'editorial_archive', 'description' => 'Archive a node through editorial workflow rules'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $rpc
     * @return array<string, mixed>
     */
    public function handleRpc(array $rpc): array
    {
        $id = $rpc['id'] ?? null;
        $method = is_string($rpc['method'] ?? null) ? $rpc['method'] : '';
        $params = is_array($rpc['params'] ?? null) ? $rpc['params'] : [];

        return match ($method) {
            'tools/list' => $this->formatter->result($id, ['tools' => $this->manifest()['tools']]),
            'tools/introspect' => $this->handleToolIntrospection($id, $params),
            'tools/call' => $this->handleToolCall($id, $params),
            default => $this->formatter->error($id, -32601, "Method not found: {$method}"),
        };
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function handleToolIntrospection(mixed $id, array $params): array
    {
        $requestedTool = is_string($params['name'] ?? null) ? trim($params['name']) : '';
        if ($requestedTool === '') {
            return $this->formatter->error($id, -32602, 'Missing tool name.');
        }

        $knownTools = array_map(
            static fn(array $tool): string => (string) ($tool['name'] ?? ''),
            $this->manifest()['tools'],
        );
        if (!in_array($requestedTool, $knownTools, true)) {
            return $this->formatter->error($id, -32602, "Unknown tool: {$requestedTool}");
        }

        $canonicalTool = $this->formatter->canonicalToolName($requestedTool);
        $descriptor = $this->introspector->diagnosticsDescriptor($canonicalTool);
        $extensions = $this->introspector->extensionsForTool($requestedTool, $canonicalTool);
        $executionPath = $descriptor['execution_path'];
        foreach ($extensions['execution_path_hooks'] as $hook) {
            if (!in_array($hook, $executionPath, true)) {
                $executionPath[] = $hook;
            }
        }
        $accountContext = $this->readCacheHandler->accountContext();
        $stableMeta = [
            'contract_version' => self::CONTRACT_VERSION,
            'contract_stability' => self::CONTRACT_STABILITY,
            'tool_invoked' => $requestedTool,
            'tool' => $canonicalTool,
        ];
        if ($requestedTool !== $canonicalTool) {
            $stableMeta['deprecated_alias'] = $requestedTool;
        }

        return $this->formatter->result($id, [
            'tool' => [
                'requested' => $requestedTool,
                'canonical' => $canonicalTool,
                'is_alias' => $requestedTool !== $canonicalTool,
                'handler' => $descriptor['handler'],
                'category' => $descriptor['category'],
            ],
            'contract' => [
                'protocol_version' => '2024-11-05',
                'contract_version' => self::CONTRACT_VERSION,
                'contract_stability' => self::CONTRACT_STABILITY,
                'stable_meta' => $stableMeta,
            ],
            'cache' => [
                'read_cacheable' => $this->readCacheHandler->isCacheableTool($canonicalTool),
                'read_cache_enabled' => $this->readCacheHandler->isEnabled(),
                'scope' => $accountContext['authenticated'] ? 'authenticated' : 'anonymous',
                'account_context' => $accountContext,
                'cache_key_dimensions' => ['contract_version', 'tool', 'arguments', 'account'],
                'cache_tags' => $descriptor['cache_tags'],
            ],
            'visibility' => [
                'source_access' => $descriptor['visibility_source_access'],
                'workflow_policy' => $descriptor['workflow_policy'],
            ],
            'permissions' => [
                'boundaries' => $descriptor['permission_boundaries'],
            ],
            'extensions' => $extensions,
            'diagnostics' => [
                'execution_path' => $executionPath,
                'failure_modes' => $descriptor['failure_modes'],
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function handleToolCall(mixed $id, array $params): array
    {
        $tool = is_string($params['name'] ?? null) ? $params['name'] : '';
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        if ($tool === '') {
            return $this->formatter->error($id, -32602, 'Missing tool name.');
        }

        $cacheKey = $this->readCacheHandler->buildKeyForTool($tool, $arguments);
        if ($cacheKey !== null) {
            $cachedResult = $this->readCacheHandler->get($cacheKey);
            if ($cachedResult !== null) {
                return $this->formatter->result($id, $this->formatter->formatToolContent($cachedResult));
            }
        }

        try {
            $result = match ($tool) {
                'search_entities' => $this->discoveryTools->searchEntities($arguments),
                'ai_discover' => $this->discoveryTools->aiDiscover($arguments),
                'get_entity' => $this->entityTools->getEntity($arguments),
                'list_entity_types' => $this->entityTools->listEntityTypes(),
                'traverse_relationships' => $this->traversalTools->traverse($arguments),
                'get_related_entities' => $this->traversalTools->getRelated($arguments),
                'get_knowledge_graph' => $this->traversalTools->knowledgeGraph($arguments),
                'editorial_transition' => $this->editorialTools->transition($arguments),
                'editorial_validate' => $this->editorialTools->validate($arguments),
                'editorial_publish' => $this->editorialTools->publish($arguments),
                'editorial_archive' => $this->editorialTools->archive($arguments),
                default => null,
            };
        } catch (\InvalidArgumentException $e) {
            return $this->formatter->error($id, -32602, $e->getMessage());
        } catch (\Throwable $e) {
            return $this->formatter->error($id, -32000, $e->getMessage());
        }

        if ($result === null) {
            return $this->formatter->error($id, -32602, "Unknown tool: {$tool}");
        }
        $result = $this->formatter->withStableContractMeta($result, $tool);
        if ($cacheKey !== null) {
            $this->readCacheHandler->set($cacheKey, $tool, $arguments, $result);
        }

        return $this->formatter->result($id, $this->formatter->formatToolContent($result));
    }
}
