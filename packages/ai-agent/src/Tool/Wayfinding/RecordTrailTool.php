<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Tool\Wayfinding;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\Attribute\AsAgentTool;

/**
 * Record a live trail to a new, human-owned saved trail (Wayfinding Phase 5,
 * FR-010). Wraps {@see \Waaseyaa\Wayfinding\Trail\TrailStore::record()}: the new
 * trail is owned by the calling account and its live value originates as
 * `recorded`.
 *
 * Authenticated MCP write tier only — `destructive: true` hides it from the
 * public read-only `/mcp` surface (C-001), and `present guided content` is
 * required (FR-003, fail-closed per NFR-002).
 *
 * @api
 */
#[AsAgentTool(
    name: 'wayfinding_record_trail',
    capability: 'present guided content',
    destructive: true,
    dryRunSupported: false,
    category: 'wayfinding',
    requiresPackage: 'waaseyaa/wayfinding',
)]
final class RecordTrailTool extends AbstractTrailTool
{
    public function description(): string
    {
        return 'Record a live trail to a new saved trail (versioned, translatable). The trail is owned by the calling account.';
    }

    public function inputSchema(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string', 'description' => 'Human-readable trail title.'],
                'langcode' => ['type' => 'string', 'description' => 'Language to record in (default "en").'],
                'beacons' => $this->beaconsSchema(),
            ],
            'required' => ['title', 'beacons'],
            'additionalProperties' => false,
        ];
    }

    /** @param \Waaseyaa\Access\AuthorizationPrincipalInterface $account */

    public function execute(array $arguments, AccountInterface $account): AgentToolResult
    {
        $denied = $this->requireCapability(self::CAPABILITY, $account);
        if ($denied !== null) {
            return $denied;
        }

        $title = $arguments['title'] ?? null;
        if (!\is_string($title) || $title === '') {
            return AgentToolResult::error(
                message: 'wayfinding_record_trail: missing required argument "title".',
                summary: 'missing argument',
            );
        }

        $langcode = \is_string($arguments['langcode'] ?? null) && $arguments['langcode'] !== ''
            ? $arguments['langcode']
            : 'en';

        $beacons = $this->parseBeacons($arguments['beacons'] ?? null, 'wayfinding_record_trail');
        if ($beacons instanceof AgentToolResult) {
            return $beacons;
        }

        try {
            $trail = $this->store()->record($langcode, $title, $beacons, (int) $account->id());
        } catch (\Throwable $e) {
            return AgentToolResult::error(
                message: sprintf('wayfinding_record_trail: [%s] %s', $e::class, $e->getMessage()),
                summary: 'record failed',
            );
        }

        $payload = [
            'trail_id' => $trail->id,
            'langcode' => $trail->langcode,
            'title' => $trail->title,
            'origin' => $trail->origin,
            'owner_uid' => $trail->ownerUid,
            'beacon_count' => \count($trail->beacons),
        ];

        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            return $this->internalError('wayfinding_record_trail', $e);
        }

        return AgentToolResult::success(
            content: [['type' => 'text', 'text' => $json]],
            summary: sprintf('Recorded trail %s (%s) with %d beacon(s).', $trail->id, $trail->langcode, \count($trail->beacons)),
            structuredContent: $payload,
        );
    }
}
