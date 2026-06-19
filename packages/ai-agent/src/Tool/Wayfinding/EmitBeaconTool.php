<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Tool\Wayfinding;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Tools\AbstractAgentTool;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\Attribute\AsAgentTool;
use Waaseyaa\Api\Controller\BroadcastStorage;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Foundation\Http\Router\SessionChannel;
use Waaseyaa\Wayfinding\Anchor\AnchorRegistry;
use Waaseyaa\Wayfinding\Http\EmitBeaconController;

/**
 * Emit one live beacon into a target user session over the bounded SSE loop
 * (Wayfinding Phase 5; the MCP analogue of the Phase-2
 * {@see EmitBeaconController}). The target session is addressed by the
 * non-secret `session_token` the SSE `connected` frame handed the user's client;
 * delivery is session-scoped (LD-1) — only that session's connection receives it.
 *
 * Authenticated MCP write tier only — `destructive: true` (hidden from public
 * `/mcp`, C-001) and `present guided content` required (FR-003, fail-closed per
 * NFR-002). The anchor is validated against the published catalog (FR-005);
 * content is transported verbatim and escaped at render time by the overlay
 * (LD-4).
 *
 * @api
 */
#[AsAgentTool(
    name: 'wayfinding_emit_beacon',
    capability: 'present guided content',
    destructive: true,
    dryRunSupported: false,
    category: 'wayfinding',
)]
final class EmitBeaconTool extends AbstractAgentTool
{
    private const int MAX_CONTENT_LENGTH = 4000;

    public function __construct(
        private readonly AnchorRegistry $anchors,
        private readonly DatabaseInterface $database,
    ) {}

    public function description(): string
    {
        return 'Emit one element-anchored beacon into a target user session over the live SSE channel. Addresses the session by its non-secret session token.';
    }

    public function inputSchema(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'properties' => [
                'session_token' => ['type' => 'string', 'description' => 'The target session token from the SSE connected frame.'],
                'anchor_id' => ['type' => 'string', 'description' => 'A data-anchor id from the published catalog.'],
                'content' => ['type' => 'string', 'description' => 'The beacon tip text (escaped at render time).'],
                'order' => ['type' => 'integer', 'description' => 'Position within the live trail (default 0).'],
            ],
            'required' => ['session_token', 'anchor_id', 'content'],
            'additionalProperties' => false,
        ];
    }

    public function execute(array $arguments, AccountInterface $account): AgentToolResult
    {
        $denied = $this->requireCapability(EmitBeaconController::CAPABILITY, $account);
        if ($denied !== null) {
            return $denied;
        }

        $token = $arguments['session_token'] ?? null;
        if (!\is_string($token) || $token === '') {
            return AgentToolResult::error(
                message: 'wayfinding_emit_beacon: missing required argument "session_token".',
                summary: 'missing argument',
            );
        }

        $anchorId = $arguments['anchor_id'] ?? null;
        if (!\is_string($anchorId) || $anchorId === '') {
            return AgentToolResult::error(
                message: 'wayfinding_emit_beacon: missing required argument "anchor_id".',
                summary: 'missing argument',
            );
        }
        if (!$this->anchors->isValid($anchorId)) {
            return AgentToolResult::error(
                message: sprintf('wayfinding_emit_beacon: anchor "%s" is not in the published catalog.', $anchorId),
                summary: 'unknown anchor',
            );
        }

        $content = $arguments['content'] ?? null;
        if (!\is_string($content) || $content === '') {
            return AgentToolResult::error(
                message: 'wayfinding_emit_beacon: missing required argument "content".',
                summary: 'missing argument',
            );
        }
        if (mb_strlen($content) > self::MAX_CONTENT_LENGTH) {
            return AgentToolResult::error(
                message: sprintf('wayfinding_emit_beacon: "content" exceeds %d characters.', self::MAX_CONTENT_LENGTH),
                summary: 'invalid argument',
            );
        }

        $order = $arguments['order'] ?? 0;
        if (!\is_int($order)) {
            return AgentToolResult::error(
                message: 'wayfinding_emit_beacon: "order" must be an integer.',
                summary: 'invalid argument',
            );
        }

        $beacon = [
            'anchor_id' => $anchorId,
            'content' => $content,
            'order' => $order,
            'emitted_by' => $account->id(),
        ];

        try {
            new BroadcastStorage($this->database)->push(
                SessionChannel::forToken($token),
                'wayfinding.beacon',
                $beacon,
            );
        } catch (\Throwable $e) {
            return AgentToolResult::error(
                message: sprintf('wayfinding_emit_beacon: [%s] %s', $e::class, $e->getMessage()),
                summary: 'emit failed',
            );
        }

        return AgentToolResult::success(
            content: [['type' => 'json', 'data' => $beacon]],
            summary: sprintf('Emitted beacon for anchor %s to the target session.', $anchorId),
        );
    }
}
