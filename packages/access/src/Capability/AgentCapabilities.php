<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Capability;

/**
 * Capability seed for the Agent Executor v1 surface.
 *
 * "Capabilities" in the agent-executor spec map to Waaseyaa **permissions**
 * (see {@see \Waaseyaa\Access\AccountInterface::hasPermission()}). This class
 * is the single source of truth for the permission identifiers that
 * {@see \Waaseyaa\AI\Agent\Access\AgentRunAccessPolicy} and the agent-tool
 * surface check at runtime. Consumers register the seed with their
 * {@see \Waaseyaa\Access\PermissionHandlerInterface} at boot, then grant
 * the resulting permissions to roles via the per-app access-control
 * configuration.
 *
 * Defaults (per data-model.md § Capabilities and plan resolution R-002):
 *
 *   - `agent.run` — required for every `/api/ai/agent/run*` route.
 *   - `agent.run.approve` — granted to the same audience as `agent.run`
 *     in the default seed; apps that want a separate gate can override.
 *   - `agent.run.bypass_ownership` — admin-only; lets a holder view or
 *     cancel another user's run.
 *   - `tool.entity.{read,list,create,update,delete,search}` — per-tool gates
 *     on the entity-CRUD tool family. Destructive (`create`, `update`,
 *     `delete`) defaults to admins/authors only.
 *   - `tool.relationship.traverse`, `tool.vector.search` — semantic / graph
 *     surfaces, granted per-app.
 *
 * Remote MCP-server-derived capabilities (`tool.mcp.<server>.<name>`) are
 * registered at boot when remote servers are discovered (mission WP-07);
 * they do not appear in this static seed.
 *
 * @api
 */
final class AgentCapabilities
{
    public const PERMISSION_RUN = 'agent.run';
    public const PERMISSION_APPROVE = 'agent.run.approve';
    public const PERMISSION_BYPASS_OWNERSHIP = 'agent.run.bypass_ownership';

    public const PERMISSION_TOOL_ENTITY_READ = 'tool.entity.read';
    public const PERMISSION_TOOL_ENTITY_LIST = 'tool.entity.list';
    public const PERMISSION_TOOL_ENTITY_CREATE = 'tool.entity.create';
    public const PERMISSION_TOOL_ENTITY_UPDATE = 'tool.entity.update';
    public const PERMISSION_TOOL_ENTITY_DELETE = 'tool.entity.delete';
    public const PERMISSION_TOOL_ENTITY_SEARCH = 'tool.entity.search';
    public const PERMISSION_TOOL_CONTENT_SEARCH = 'tool.content.search';
    public const PERMISSION_RESOURCE_CONTENT_READ = 'resource.content.read';
    public const PERMISSION_TOOL_RELATIONSHIP_TRAVERSE = 'tool.relationship.traverse';
    public const PERMISSION_TOOL_VECTOR_SEARCH = 'tool.vector.search';

    /**
     * The thirteen static capability identifiers shipped with the agent
     * executor. Order matches data-model.md § Capabilities.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::PERMISSION_RUN,
            self::PERMISSION_APPROVE,
            self::PERMISSION_BYPASS_OWNERSHIP,
            self::PERMISSION_TOOL_ENTITY_READ,
            self::PERMISSION_TOOL_ENTITY_LIST,
            self::PERMISSION_TOOL_ENTITY_CREATE,
            self::PERMISSION_TOOL_ENTITY_UPDATE,
            self::PERMISSION_TOOL_ENTITY_DELETE,
            self::PERMISSION_TOOL_ENTITY_SEARCH,
            self::PERMISSION_TOOL_CONTENT_SEARCH,
            self::PERMISSION_RESOURCE_CONTENT_READ,
            self::PERMISSION_TOOL_RELATIONSHIP_TRAVERSE,
            self::PERMISSION_TOOL_VECTOR_SEARCH,
        ];
    }

    /**
     * Capability descriptors keyed by permission id.
     *
     * Shape matches the {@see \Waaseyaa\Access\PermissionHandler::registerPermission()}
     * signature: `[id => ['title' => …, 'description' => …]]`.
     *
     * @return array<string, array{title: string, description: string}>
     */
    public static function seed(): array
    {
        return [
            self::PERMISSION_RUN => [
                'title' => 'Run agents',
                'description' => 'Invoke the agent executor via the API, CLI, or admin SPA.',
            ],
            self::PERMISSION_APPROVE => [
                'title' => 'Approve destructive agent steps',
                'description' => 'Grant or deny human-in-the-loop approval for paused runs. '
                    . 'Defaults to the same audience as agent.run in the seed; apps may '
                    . 'narrow this to a smaller subset.',
            ],
            self::PERMISSION_BYPASS_OWNERSHIP => [
                'title' => 'Bypass agent-run ownership',
                'description' => 'View, cancel, or audit any user\'s agent run regardless of initiator. Admin-only.',
            ],
            self::PERMISSION_TOOL_ENTITY_READ => [
                'title' => 'Tool: read entities',
                'description' => 'Use the entity-read tool from inside an agent run.',
            ],
            self::PERMISSION_TOOL_ENTITY_LIST => [
                'title' => 'Tool: list entities',
                'description' => 'Use the entity-list tool from inside an agent run.',
            ],
            self::PERMISSION_TOOL_ENTITY_CREATE => [
                'title' => 'Tool: create entities',
                'description' => 'Destructive — use the entity-create tool from inside an agent run.',
            ],
            self::PERMISSION_TOOL_ENTITY_UPDATE => [
                'title' => 'Tool: update entities',
                'description' => 'Destructive — use the entity-update tool from inside an agent run.',
            ],
            self::PERMISSION_TOOL_ENTITY_DELETE => [
                'title' => 'Tool: delete entities',
                'description' => 'Destructive — use the entity-delete tool from inside an agent run.',
            ],
            self::PERMISSION_TOOL_ENTITY_SEARCH => [
                'title' => 'Tool: full-text entity search',
                'description' => 'Use the entity-search tool from inside an agent run.',
            ],
            self::PERMISSION_TOOL_CONTENT_SEARCH => [
                'title' => 'Tool: search accessible CMS content',
                'description' => 'Use the ranked, principal-safe content-search tool from inside an agent run.',
            ],
            self::PERMISSION_RESOURCE_CONTENT_READ => [
                'title' => 'Resource: read accessible CMS content',
                'description' => 'List and read bounded principal-safe CMS content resources.',
            ],
            self::PERMISSION_TOOL_RELATIONSHIP_TRAVERSE => [
                'title' => 'Tool: graph relationship traversal',
                'description' => 'Use the relationship-traverse tool from inside an agent run.',
            ],
            self::PERMISSION_TOOL_VECTOR_SEARCH => [
                'title' => 'Tool: vector / semantic search',
                'description' => 'Use the vector-search tool from inside an agent run.',
            ],
        ];
    }

    /**
     * Register the seed against a {@see \Waaseyaa\Access\PermissionHandler}.
     *
     * Convenience wrapper for consumers who keep a single
     * `PermissionHandler` and want to register the agent permissions in
     * one call from their service provider.
     */
    public static function register(\Waaseyaa\Access\PermissionHandler $handler): void
    {
        foreach (self::seed() as $id => $descriptor) {
            $handler->registerPermission($id, $descriptor['title'], $descriptor['description']);
        }
    }
}
