<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Capability;

/**
 * Capability seed for the MCP write-tier approval decision surface (#2177 F1
 * slice C1b).
 *
 * "Capabilities" map to Waaseyaa **permissions** (see
 * {@see \Waaseyaa\Access\AccountInterface::hasPermission()}), exactly like
 * {@see AgentCapabilities}. This class is the single source of truth for the
 * permission identifiers the `/api/mcp/approvals` admin endpoints require at
 * the route level (`_permission`).
 *
 * **Seed-only contract (deliberate, mirrors {@see AgentCapabilities}):** the
 * framework binds no `PermissionHandlerInterface` and calls `register()`
 * nowhere in production — enforcement needs only the permission STRING on the
 * route (`AccessChecker` asks `$account->hasPermission()` directly; the
 * registry is a discovery/UI surface, e.g. the `permission:list` CLI command).
 * An application that keeps a `PermissionHandler` registers this seed at boot
 * and grants the resulting permissions to operator roles via its per-app
 * access-control configuration.
 *
 *   - `mcp.approval.view` — read the bounded pending-approval queue
 *     (`GET /api/mcp/approvals`).
 *   - `mcp.approval.decide` — durably approve or deny a pending request
 *     (`POST /api/mcp/approvals/{id}/decision`). Deliberately separate from
 *     `view` so an app can grant a read-only triage audience.
 *
 * @api
 */
final class McpApprovalCapabilities
{
    public const PERMISSION_VIEW = 'mcp.approval.view';
    public const PERMISSION_DECIDE = 'mcp.approval.decide';

    /**
     * The two static capability identifiers of the approval decision surface.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::PERMISSION_VIEW,
            self::PERMISSION_DECIDE,
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
            self::PERMISSION_VIEW => [
                'title' => 'View pending MCP approval requests',
                'description' => 'Read the bounded queue of pending human-approval requests '
                    . 'for destructive MCP write-tier calls.',
            ],
            self::PERMISSION_DECIDE => [
                'title' => 'Decide MCP approval requests',
                'description' => 'Durably approve or deny a pending destructive MCP write-tier call. '
                    . 'Separation of duties: the deciding operator must not be the requesting '
                    . 'principal — self-approval is refused unless the deployment explicitly '
                    . 'enables mcp.write_tier.approval.allow_self_approval.',
            ],
        ];
    }

    /**
     * Register the seed against a {@see \Waaseyaa\Access\PermissionHandler}.
     */
    public static function register(\Waaseyaa\Access\PermissionHandler $handler): void
    {
        foreach (self::seed() as $id => $descriptor) {
            $handler->registerPermission($id, $descriptor['title'], $descriptor['description']);
        }
    }
}
