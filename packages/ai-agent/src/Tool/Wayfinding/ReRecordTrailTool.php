<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Tool\Wayfinding;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\Attribute\AsAgentTool;

/**
 * Re-record a live trail onto an existing saved trail (Wayfinding Phase 5,
 * FR-011). Wraps {@see \Waaseyaa\Wayfinding\Trail\TrailStore::reRecord()}: if the
 * target is human-owned the re-recording lands as a **draft revision** and the
 * live value is left untouched (`promoted: false`); otherwise it safely advances
 * the live value (`promoted: true`). Either way a new revision is created and
 * human edits are never silently overwritten.
 *
 * Authenticated MCP write tier only — `destructive: true` (hidden from public
 * `/mcp`, C-001) and `present guided content` required (FR-003, fail-closed).
 *
 * @api
 */
#[AsAgentTool(
    name: 'wayfinding_rerecord_trail',
    capability: 'present guided content',
    destructive: true,
    dryRunSupported: false,
    category: 'wayfinding',
)]
final class ReRecordTrailTool extends AbstractTrailTool
{
    public function description(): string
    {
        return 'Re-record a live trail onto an existing saved trail. Never overwrites human edits: re-recording a human-owned trail creates a draft revision instead.';
    }

    public function inputSchema(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'properties' => [
                'trail_id' => ['type' => 'string', 'description' => 'Id of the existing saved trail.'],
                'title' => ['type' => 'string', 'description' => 'Title for the re-recording.'],
                'langcode' => ['type' => 'string', 'description' => 'Language to re-record in (default "en").'],
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
                message: 'wayfinding_rerecord_trail: missing required argument "trail_id".',
                summary: 'missing argument',
            );
        }

        $title = $arguments['title'] ?? null;
        if (!\is_string($title) || $title === '') {
            return AgentToolResult::error(
                message: 'wayfinding_rerecord_trail: missing required argument "title".',
                summary: 'missing argument',
            );
        }

        $langcode = \is_string($arguments['langcode'] ?? null) && $arguments['langcode'] !== ''
            ? $arguments['langcode']
            : 'en';

        $beacons = $this->parseBeacons($arguments['beacons'] ?? null, 'wayfinding_rerecord_trail');
        if ($beacons instanceof AgentToolResult) {
            return $beacons;
        }

        $denied = $this->requireTrailUpdateAccess($trailId, $langcode, $account, 'wayfinding_rerecord_trail');
        if ($denied !== null) {
            return $denied;
        }

        try {
            $result = $this->store()->reRecord($trailId, $langcode, $title, $beacons);
        } catch (\Throwable $e) {
            return AgentToolResult::error(
                message: sprintf('wayfinding_rerecord_trail: [%s] %s', $e::class, $e->getMessage()),
                summary: 're-record failed',
            );
        }

        return AgentToolResult::success(
            content: [['type' => 'json', 'data' => [
                'trail_id' => $trailId,
                'langcode' => $langcode,
                'promoted' => $result->promoted,
                'revision_id' => $result->revisionId,
            ]]],
            summary: $result->promoted
                ? sprintf('Re-recording promoted to the live trail %s (%s).', $trailId, $langcode)
                : sprintf('Re-recording saved as draft revision %d on human-owned trail %s (%s); live value untouched.', $result->revisionId, $trailId, $langcode),
        );
    }
}
