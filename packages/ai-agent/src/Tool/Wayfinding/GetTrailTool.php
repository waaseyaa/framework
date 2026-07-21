<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Tool\Wayfinding;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\Attribute\AsAgentTool;

/**
 * Read the live value of a saved trail in one language (Wayfinding Phase 5).
 * Wraps {@see \Waaseyaa\Wayfinding\Trail\TrailStore::current()}.
 *
 * Read-only, but a trail-management surface — it is gated by the same
 * `present guided content` capability so trail reads stay on the authenticated
 * tier rather than the anonymous public surface. Not `destructive`.
 *
 * @api
 */
#[AsAgentTool(
    name: 'wayfinding_get_trail',
    capability: 'present guided content',
    destructive: false,
    dryRunSupported: false,
    category: 'wayfinding',
    requiresPackage: 'waaseyaa/wayfinding',
)]
final class GetTrailTool extends AbstractTrailTool
{
    public function description(): string
    {
        return 'Read the current (live) value of a saved trail in one language, including its ordered beacons and origin.';
    }

    public function inputSchema(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'properties' => [
                'trail_id' => ['type' => 'string', 'description' => 'Id of the saved trail.'],
                'langcode' => ['type' => 'string', 'description' => 'Language to read (default "en").'],
            ],
            'required' => ['trail_id'],
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
                message: 'wayfinding_get_trail: missing required argument "trail_id".',
                summary: 'missing argument',
            );
        }

        $langcode = \is_string($arguments['langcode'] ?? null) && $arguments['langcode'] !== ''
            ? $arguments['langcode']
            : 'en';

        try {
            $trail = $this->store()->current($trailId, $langcode);
        } catch (\Throwable $e) {
            return AgentToolResult::error(
                message: sprintf('wayfinding_get_trail: [%s] %s', $e::class, $e->getMessage()),
                summary: 'read failed',
            );
        }

        if ($trail === null) {
            return AgentToolResult::error(
                message: sprintf('wayfinding_get_trail: no trail %s in language "%s".', $trailId, $langcode),
                summary: 'not found',
            );
        }

        return AgentToolResult::success(
            content: [['type' => 'json', 'data' => [
                'trail_id' => $trail->id,
                'langcode' => $trail->langcode,
                'title' => $trail->title,
                'origin' => $trail->origin,
                'owner_uid' => $trail->ownerUid,
                'beacons' => $trail->beacons,
            ]]],
            summary: sprintf('Trail %s (%s): %d beacon(s), origin %s.', $trail->id, $trail->langcode, \count($trail->beacons), $trail->origin),
        );
    }
}
