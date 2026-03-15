<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Rpc;

final class ToolIntrospector
{
    /**
     * @param list<array<string, mixed>> $extensionRegistrations
     */
    public function __construct(
        private readonly ResponseFormatter $formatter,
        private readonly array $extensionRegistrations = [],
    ) {}

    /**
     * @return array{
     *   count: int,
     *   registered: list<array{
     *     id: string,
     *     label: string,
     *     tools: list<string>,
     *     hooks: list<string>
     *   }>,
     *   execution_path_hooks: list<string>
     * }
     */
    public function extensionsForTool(string $requestedTool, string $canonicalTool): array
    {
        $rows = [];
        foreach ($this->extensionRegistrations as $registration) {
            if (!is_array($registration)) {
                continue;
            }

            $id = is_string($registration['id'] ?? null)
                ? trim($registration['id'])
                : (is_string($registration['plugin_id'] ?? null) ? trim($registration['plugin_id']) : '');
            if ($id === '') {
                continue;
            }

            $label = is_string($registration['label'] ?? null) ? trim($registration['label']) : $id;
            if ($label === '') {
                $label = $id;
            }

            $tools = [];
            if (is_array($registration['tools'] ?? null)) {
                foreach ($registration['tools'] as $tool) {
                    if (!is_string($tool)) {
                        continue;
                    }
                    $normalizedTool = $this->formatter->canonicalToolName(strtolower(trim($tool)));
                    if ($normalizedTool !== '') {
                        $tools[] = $normalizedTool;
                    }
                }
            }
            $tools = array_values(array_unique($tools));
            sort($tools);

            $isApplicable = $tools === [] || in_array($canonicalTool, $tools, true) || in_array($requestedTool, $tools, true);
            if (!$isApplicable) {
                continue;
            }

            $hooks = [];
            if (is_array($registration['hooks'] ?? null)) {
                foreach ($registration['hooks'] as $hook) {
                    if (!is_string($hook)) {
                        continue;
                    }
                    $normalizedHook = strtolower(trim($hook));
                    if ($normalizedHook !== '') {
                        $hooks[] = $normalizedHook;
                    }
                }
            }
            if ($hooks === []) {
                $hooks = ['before_tool_call', 'after_tool_result_meta'];
            }
            $hooks = array_values(array_unique($hooks));
            sort($hooks);

            $rows[] = [
                'id' => $id,
                'label' => $label,
                'tools' => $tools,
                'hooks' => $hooks,
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            return strcmp($a['id'], $b['id']);
        });

        $executionHooks = [];
        foreach ($rows as $row) {
            foreach ($row['hooks'] as $hook) {
                $executionHooks[] = 'extensions:' . $hook;
            }
        }
        $executionHooks = array_values(array_unique($executionHooks));
        sort($executionHooks);

        return [
            'count' => count($rows),
            'registered' => $rows,
            'execution_path_hooks' => $executionHooks,
        ];
    }

    /**
     * @return array{
     *   handler: string,
     *   category: string,
     *   cache_tags: list<string>,
     *   visibility_source_access: string,
     *   workflow_policy: string,
     *   permission_boundaries: list<string>,
     *   execution_path: list<string>,
     *   failure_modes: list<string>
     * }
     */
    public function diagnosticsDescriptor(string $tool): array
    {
        return match ($tool) {
            'search_entities' => [
                'handler' => 'toolSearchEntities',
                'category' => 'semantic_read',
                'cache_tags' => ['mcp_read', 'mcp_read:tool:search_entities'],
                'visibility_source_access' => 'entity_view_access',
                'workflow_policy' => 'visibility-aware',
                'permission_boundaries' => ['entity:view'],
                'execution_path' => ['rpc:tools/call', 'resolver:toolSearchEntities', 'meta:stable_contract', 'response:format_tool_content'],
                'failure_modes' => ['invalid_query_type', 'embedding_provider_failure'],
            ],
            'ai_discover' => [
                'handler' => 'toolAiDiscover',
                'category' => 'discovery_read',
                'cache_tags' => ['mcp_read', 'mcp_read:tool:ai_discover'],
                'visibility_source_access' => 'entity_view_access',
                'workflow_policy' => 'published_only',
                'permission_boundaries' => ['entity:view'],
                'execution_path' => ['rpc:tools/call', 'resolver:toolAiDiscover', 'graph:optional_anchor_context', 'meta:stable_contract', 'response:format_tool_content'],
                'failure_modes' => ['missing_query', 'hidden_anchor_entity', 'non_public_anchor_entity', 'semantic_search_failure'],
            ],
            'get_entity' => [
                'handler' => 'toolGetEntity',
                'category' => 'entity_read',
                'cache_tags' => ['mcp_read:disabled'],
                'visibility_source_access' => 'entity_view_access',
                'workflow_policy' => 'visibility-aware',
                'permission_boundaries' => ['entity:view'],
                'execution_path' => ['rpc:tools/call', 'resolver:toolGetEntity', 'response:format_tool_content'],
                'failure_modes' => ['unknown_entity_type', 'entity_not_found', 'access_denied'],
            ],
            'list_entity_types' => [
                'handler' => 'toolListEntityTypes',
                'category' => 'schema_read',
                'cache_tags' => ['mcp_read:disabled'],
                'visibility_source_access' => 'none',
                'workflow_policy' => 'not_applicable',
                'permission_boundaries' => ['schema:list'],
                'execution_path' => ['rpc:tools/call', 'resolver:toolListEntityTypes', 'response:format_tool_content'],
                'failure_modes' => ['definition_resolution_failure'],
            ],
            'traverse_relationships' => [
                'handler' => 'toolTraverseRelationships',
                'category' => 'graph_read',
                'cache_tags' => ['mcp_read', 'mcp_read:tool:traverse_relationships'],
                'visibility_source_access' => 'source_view_required',
                'workflow_policy' => 'relationship_visibility_filter',
                'permission_boundaries' => ['entity:view', 'relationship:view'],
                'execution_path' => ['rpc:tools/call', 'resolver:toolTraverseRelationships', 'graph:collectTraversalRows', 'meta:stable_contract', 'response:format_tool_content'],
                'failure_modes' => ['missing_source_arguments', 'unknown_source_type', 'hidden_source_entity'],
            ],
            'get_related_entities' => [
                'handler' => 'toolGetRelatedEntities',
                'category' => 'graph_read',
                'cache_tags' => ['mcp_read', 'mcp_read:tool:get_related_entities'],
                'visibility_source_access' => 'source_view_required',
                'workflow_policy' => 'relationship_visibility_filter',
                'permission_boundaries' => ['entity:view', 'relationship:view'],
                'execution_path' => ['rpc:tools/call', 'resolver:toolGetRelatedEntities', 'graph:collectTraversalRows', 'meta:stable_contract', 'response:format_tool_content'],
                'failure_modes' => ['missing_source_arguments', 'unknown_source_type', 'hidden_source_entity'],
            ],
            'get_knowledge_graph' => [
                'handler' => 'toolGetKnowledgeGraph',
                'category' => 'graph_read',
                'cache_tags' => ['mcp_read', 'mcp_read:tool:get_knowledge_graph'],
                'visibility_source_access' => 'source_view_required',
                'workflow_policy' => 'relationship_visibility_filter',
                'permission_boundaries' => ['entity:view', 'relationship:view'],
                'execution_path' => ['rpc:tools/call', 'resolver:toolGetKnowledgeGraph', 'graph:collectTraversalRows_or_service', 'meta:stable_contract', 'response:format_tool_content'],
                'failure_modes' => ['missing_source_arguments', 'unknown_source_type', 'hidden_source_entity'],
            ],
            'editorial_transition' => [
                'handler' => 'toolEditorialTransition',
                'category' => 'editorial_write',
                'cache_tags' => ['mcp_read:disabled'],
                'visibility_source_access' => 'entity_update_access',
                'workflow_policy' => 'workflow_transition_enforced',
                'permission_boundaries' => ['entity:update', 'workflow:transition', 'storage:save'],
                'execution_path' => ['rpc:tools/call', 'resolver:toolEditorialTransition', 'workflow:validate_transition', 'storage:save', 'meta:stable_contract', 'response:format_tool_content'],
                'failure_modes' => ['missing_target_state', 'unknown_target_state', 'transition_unauthorized', 'validation_failed'],
            ],
            'editorial_validate' => [
                'handler' => 'toolEditorialValidate',
                'category' => 'editorial_read',
                'cache_tags' => ['mcp_read:disabled'],
                'visibility_source_access' => 'entity_update_access',
                'workflow_policy' => 'workflow_transition_enforced',
                'permission_boundaries' => ['entity:update', 'workflow:transition'],
                'execution_path' => ['rpc:tools/call', 'resolver:toolEditorialValidate', 'workflow:validate_transition', 'meta:stable_contract', 'response:format_tool_content'],
                'failure_modes' => ['missing_entity_identity', 'unknown_target_state', 'transition_unauthorized'],
            ],
            'editorial_publish' => [
                'handler' => 'toolEditorialPublish',
                'category' => 'editorial_write',
                'cache_tags' => ['mcp_read:disabled'],
                'visibility_source_access' => 'entity_update_access',
                'workflow_policy' => 'workflow_transition_enforced',
                'permission_boundaries' => ['entity:update', 'workflow:publish', 'storage:save'],
                'execution_path' => ['rpc:tools/call', 'resolver:toolEditorialPublish', 'resolver:toolEditorialTransition', 'storage:save', 'meta:stable_contract', 'response:format_tool_content'],
                'failure_modes' => ['transition_unauthorized', 'validation_failed'],
            ],
            'editorial_archive' => [
                'handler' => 'toolEditorialArchive',
                'category' => 'editorial_write',
                'cache_tags' => ['mcp_read:disabled'],
                'visibility_source_access' => 'entity_update_access',
                'workflow_policy' => 'workflow_transition_enforced',
                'permission_boundaries' => ['entity:update', 'workflow:archive', 'storage:save'],
                'execution_path' => ['rpc:tools/call', 'resolver:toolEditorialArchive', 'resolver:toolEditorialTransition', 'storage:save', 'meta:stable_contract', 'response:format_tool_content'],
                'failure_modes' => ['transition_unauthorized', 'validation_failed'],
            ],
            default => [
                'handler' => 'unknown',
                'category' => 'unknown',
                'cache_tags' => ['mcp_read:disabled'],
                'visibility_source_access' => 'unknown',
                'workflow_policy' => 'unknown',
                'permission_boundaries' => [],
                'execution_path' => ['rpc:tools/call'],
                'failure_modes' => ['unknown_tool'],
            ],
        };
    }
}
