<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Controller;

use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Api\McpAdmin\RecentInvocation;
use Waaseyaa\Api\McpAdmin\ServerConfigReadModelInterface;
use Waaseyaa\Api\McpAdmin\ToolRegistryReadModelInterface;

/**
 * Admin-only read controller for the MCP endpoint admin surface (M5C WP01 T002).
 *
 * Three actions back the admin pages:
 *   - `tools`        — tool registry index
 *   - `tool($name)`  — per-tool detail with input schema + recent invocations
 *   - `serverConfig` — registered clients + server capabilities
 *
 * Both read-model deps are nullable: when either adapter is absent (slimmed-down
 * install without `waaseyaa/mcp`) the controller returns an empty-shape response
 * rather than crashing kernel boot (FR-001 empty-shape guarantee).
 *
 * Access control: enforced by `_role: admin` route option in
 * `BuiltinRouteRegistrar`. The controller does NOT re-check the role (NFR-001 /
 * DIR-004).
 *
 * camelCase JSON keys match the WP02 frontend contract.
 *
 * NFR-003: no plaintext bearer token ever appears in any response.
 *
 * @api
 */
final class McpAdminController
{
    public function __construct(
        private readonly ?ToolRegistryReadModelInterface $registry = null,
        private readonly ?ServerConfigReadModelInterface $config = null,
        private readonly ?EntityAccessHandler $accessHandler = null,
        private readonly ?AccountInterface $account = null,
    ) {}

    /**
     * `GET /api/mcp/tools` — tool registry index.
     *
     * @return array{data: array{rows: list<array{name: string, summary: string|null, category: string, requiredCapabilities: list<string>}>}}
     */
    public function tools(Request $request): array
    {
        if ($this->registry === null) {
            return ['data' => ['rows' => []]];
        }

        $rows = array_map(
            static fn($row): array => [
                'name' => $row->name,
                'summary' => $row->summary,
                'category' => $row->category,
                'requiredCapabilities' => $row->requiredCapabilities,
            ],
            $this->registry->listTools(),
        );

        return ['data' => ['rows' => $rows]];
    }

    /**
     * `GET /api/mcp/tools/{name}` — per-tool detail.
     *
     * The `{name}` route segment is URL-decoded once before lookup so that
     * tool names containing dots (e.g. `bimaaji.search_specs`) survive URL
     * encoding by the SPA client.
     *
     * @return array{data: array{tool: array<string, mixed>|null}}
     */
    public function tool(Request $request, string $name): array
    {
        if ($this->registry === null) {
            return ['data' => ['tool' => null]];
        }

        // URL-decode once — the router already decoded the path segment once;
        // explicit rawurldecode handles a double-encoded dot in `bimaaji.search_specs`.
        $decodedName = rawurldecode($name);

        $detail = $this->registry->findTool($decodedName);
        if ($detail === null) {
            return ['data' => ['tool' => null]];
        }

        $invocations = $this->serializeInvocations($detail->recentInvocations);

        return [
            'data' => [
                'tool' => [
                    'name' => $detail->name,
                    'summary' => $detail->summary,
                    'description' => $detail->description,
                    'category' => $detail->category,
                    'requiredCapabilities' => $detail->requiredCapabilities,
                    'inputSchema' => $detail->inputSchema,
                    'recentInvocations' => $invocations,
                ],
            ],
        ];
    }

    /**
     * `GET /api/mcp/server-config` — server configuration snapshot.
     *
     * @return array{data: array{config: array<string, mixed>|null}}
     */
    public function serverConfig(Request $request): array
    {
        if ($this->config === null) {
            return ['data' => ['config' => null]];
        }

        $snapshot = $this->config->serverConfig();

        $clients = array_map(
            static fn($client): array => [
                'clientId' => $client->clientId,
                'addedAt' => $client->addedAt,
                'lastSeenAt' => $client->lastSeenAt,
                'tokenFingerprint' => $client->tokenFingerprint,
            ],
            $snapshot->registeredClients,
        );

        return [
            'data' => [
                'config' => [
                    'transport' => $snapshot->transport,
                    'protocolVersion' => $snapshot->protocolVersion,
                    'registeredClients' => $clients,
                    'serverCapabilities' => $snapshot->serverCapabilities,
                ],
            ],
        ];
    }

    /**
     * Serialize RecentInvocation DTOs.
     *
     * When both `$accessHandler` and `$account` are present the invocations
     * pass through field-access gating: rows for which the account lacks view
     * access receive `_redacted: true` and have sensitive fields nulled out.
     * This implements the M-A5 field-access policy wiring for recentInvocations.
     *
     * @param list<RecentInvocation> $invocations
     * @return list<array<string, mixed>>
     */
    private function serializeInvocations(array $invocations): array
    {
        $out = [];
        foreach ($invocations as $inv) {
            $row = [
                'traceUuid' => $inv->traceUuid,
                'invokedAt' => $inv->invokedAt,
                'account' => $inv->account,
                'outcome' => $inv->outcome,
                'errorMessage' => $inv->errorMessage,
                'latencyMs' => $inv->latencyMs,
            ];

            // Field-access gating: when EntityAccessHandler + account are wired,
            // check if the account may view trace detail. If access is denied,
            // redact and mark the row so the SPA can render a placeholder.
            if ($this->accessHandler !== null && $this->account !== null) {
                // The `recentInvocations` field does not map to a concrete entity —
                // we use the handler's `checkFieldAccess` indirectly by checking a
                // boolean permission the account must hold to view trace data.
                if (!$this->account->hasPermission('ai_observability.view_traces')) {
                    $row['account'] = null;
                    $row['errorMessage'] = null;
                    $row['_redacted'] = true;
                }
            }

            $out[] = $row;
        }

        return $out;
    }
}
