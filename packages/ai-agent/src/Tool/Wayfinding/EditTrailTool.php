<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Tool\Wayfinding;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\Attribute\AsAgentTool;

/**
 * A human authors or edits a saved trail in one language (Wayfinding Phase 5,
 * SC-005 / CL-8). Wraps {@see \Waaseyaa\Wayfinding\Trail\TrailStore::editAsHuman()}:
 * it advances the live value AND latches `origin = human`, so a later
 * {@see ReRecordTrailTool re-record} lands as a draft revision instead of
 * overwriting the human's edit. This is the authenticated surface that makes the
 * flagship "human edits are never overwritten" guarantee reachable from a running
 * app — without it, `editAsHuman()` had no MCP/HTTP/admin/CLI caller and SC-005
 * was demonstrable only from a unit test reaching around the tool layer.
 *
 * Authenticated MCP write tier only — `destructive: true` (hidden from the public
 * read-only `/mcp`, C-001) and `present guided content` required (FR-003,
 * fail-closed).
 *
 * @api
 */
#[AsAgentTool(
    name: 'wayfinding_edit_trail',
    capability: 'present guided content',
    destructive: true,
    dryRunSupported: false,
    category: 'wayfinding',
    requiresPackage: 'waaseyaa/wayfinding',
)]
final class EditTrailTool extends AbstractTrailTool
{
    public function description(): string
    {
        return 'Edit a saved trail as a human. Advances the live value and latches it human-owned, so a later re-record creates a draft revision instead of overwriting the edit.';
    }

    public function inputSchema(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'properties' => [
                'trail_id' => ['type' => 'string', 'description' => 'Id of the existing saved trail.'],
                'title' => ['type' => 'string', 'description' => 'Title for the human edit.'],
                'langcode' => ['type' => 'string', 'description' => 'Language to edit (default "en"); a new language creates that translation.'],
                'beacons' => $this->beaconsSchema(),
            ],
            'required' => ['trail_id', 'title', 'beacons'],
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

        $trailId = $arguments['trail_id'] ?? null;
        if (!\is_string($trailId) || $trailId === '') {
            return AgentToolResult::error(
                message: 'wayfinding_edit_trail: missing required argument "trail_id".',
                summary: 'missing argument',
            );
        }

        $title = $arguments['title'] ?? null;
        if (!\is_string($title) || $title === '') {
            return AgentToolResult::error(
                message: 'wayfinding_edit_trail: missing required argument "title".',
                summary: 'missing argument',
            );
        }

        $langcode = \is_string($arguments['langcode'] ?? null) && $arguments['langcode'] !== ''
            ? $arguments['langcode']
            : 'en';

        $beacons = $this->parseBeacons($arguments['beacons'] ?? null, 'wayfinding_edit_trail');
        if ($beacons instanceof AgentToolResult) {
            return $beacons;
        }

        $denied = $this->requireTrailUpdateAccess($trailId, $langcode, $account, 'wayfinding_edit_trail');
        if ($denied !== null) {
            return $denied;
        }

        try {
            $trail = $this->store()->editAsHuman($trailId, $langcode, $title, $beacons);
        } catch (\Throwable $e) {
            return AgentToolResult::error(
                message: sprintf('wayfinding_edit_trail: [%s] %s', $e::class, $e->getMessage()),
                summary: 'edit failed',
            );
        }

        return AgentToolResult::success(
            content: [['type' => 'json', 'data' => [
                'trail_id' => $trail->id,
                'langcode' => $trail->langcode,
                'title' => $trail->title,
                'origin' => $trail->origin,
                'owner_uid' => $trail->ownerUid,
                'beacon_count' => \count($trail->beacons),
            ]]],
            summary: sprintf('Edited trail %s (%s) as human; it is now human-owned and protected from re-record overwrites.', $trail->id, $trail->langcode),
        );
    }
}
