<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Tool\Wayfinding;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Tools\AbstractAgentTool;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\Wayfinding\Http\EmitBeaconController;
use Waaseyaa\Wayfinding\Trail\TrailStore;

/**
 * Shared base for the Wayfinding saved-trail MCP write tools (Phase 5).
 *
 * Resolves the `wayfinding_trail` {@see TrailStore} from the kernel-bound
 * {@see EntityTypeManagerInterface} and provides the common beacon-list parser.
 * Each concrete tool is `#[AsAgentTool]`-discovered, gated by the
 * `present guided content` capability ({@see EmitBeaconController::CAPABILITY},
 * FR-003), and structurally hidden from the public read-only `/mcp` surface
 * (`destructive: true` ⇒ filtered by `ReadOnlyToolRegistry`, C-001).
 *
 * @internal Shared base for the wayfinding trail tools — not a public extension
 *           point (the concrete `#[AsAgentTool]` tools are the public surface).
 *
 * @phpstan-type BeaconShape array{anchor_id: string, content: string, order: int}
 */
abstract class AbstractTrailTool extends AbstractAgentTool
{
    protected const string CAPABILITY = EmitBeaconController::CAPABILITY;

    protected const string TRAIL_ENTITY_TYPE = 'wayfinding_trail';

    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
    ) {}

    protected function store(): TrailStore
    {
        return new TrailStore($this->repository());
    }

    /**
     * Load the target trail and enforce the per-entity 'update' AccessPolicy
     * ({@see \Waaseyaa\Wayfinding\Access\TrailAccessPolicy}, owner-only for
     * `update`) BEFORE a write tool touches it. Closes the cross-account
     * overwrite hole: {@see EditTrailTool} and {@see ReRecordTrailTool} used to
     * gate only the coarse `present guided content` capability and then write
     * to whatever `trail_id` was supplied, so any capability holder could edit
     * or re-record another account's trail. `requireEntityAccess()` fails
     * closed (denies) when enforcement is required but no access handler is
     * wired, so a wiring gap can never silently degrade to "allow all".
     *
     * Mirrors {@see \Waaseyaa\Wayfinding\Trail\TrailStore::resolveOwner()}: try
     * the language-specific peer row first (the row the write would touch),
     * then fall back to the entity's default-language row — a trail's
     * ownership is inherited from that row and does not vary per language, so
     * either row carries the correct `owner_uid` for the policy check.
     */
    protected function requireTrailUpdateAccess(string $trailId, string $langcode, AccountInterface $account, string $toolName): ?AgentToolResult
    {
        $repository = $this->repository();
        $trail = $repository->loadTranslation($trailId, $langcode) ?? $repository->find($trailId);
        if ($trail === null) {
            return AgentToolResult::error(
                message: sprintf('%s: trail "%s" not found.', $toolName, $trailId),
                summary: 'not found',
            );
        }

        return $this->requireEntityAccess($trail, 'update', $account);
    }

    private function repository(): EntityRepository
    {
        $repository = $this->entityTypeManager->getRepository(self::TRAIL_ENTITY_TYPE);
        if (!$repository instanceof EntityRepository) {
            throw new \LogicException(sprintf(
                'The "%s" repository must be a %s for the two-axis trail API; got %s.',
                self::TRAIL_ENTITY_TYPE,
                EntityRepository::class,
                $repository::class,
            ));
        }

        return $repository;
    }

    /**
     * The JSON-Schema fragment for a `beacons` array argument, shared by the
     * record/re-record tools.
     *
     * @return array<string, mixed>
     */
    protected function beaconsSchema(): array
    {
        return [
            'type' => 'array',
            'description' => 'Ordered list of element-anchored beacons.',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'anchor_id' => ['type' => 'string', 'description' => 'A data-anchor id from the published catalog.'],
                    'content' => ['type' => 'string', 'description' => 'The beacon tip text.'],
                    'order' => ['type' => 'integer', 'description' => 'Position within the trail.'],
                ],
                'required' => ['anchor_id', 'content'],
                'additionalProperties' => false,
            ],
        ];
    }

    /**
     * Validate and normalize a `beacons` argument into the shape {@see TrailStore} expects.
     *
     * @return list<BeaconShape>|AgentToolResult The parsed beacons, or an error result.
     */
    protected function parseBeacons(mixed $raw, string $toolName): array|AgentToolResult
    {
        if (!\is_array($raw) || $raw === []) {
            return AgentToolResult::error(
                message: sprintf('%s: "beacons" must be a non-empty array.', $toolName),
                summary: 'invalid argument',
            );
        }

        $beacons = [];
        $index = 0;
        foreach ($raw as $entry) {
            if (!\is_array($entry)) {
                return AgentToolResult::error(
                    message: sprintf('%s: beacon #%d must be an object.', $toolName, $index),
                    summary: 'invalid argument',
                );
            }
            $anchorId = $entry['anchor_id'] ?? null;
            $content = $entry['content'] ?? null;
            if (!\is_string($anchorId) || $anchorId === '' || !\is_string($content) || $content === '') {
                return AgentToolResult::error(
                    message: sprintf('%s: beacon #%d requires non-empty string "anchor_id" and "content".', $toolName, $index),
                    summary: 'invalid argument',
                );
            }
            $order = $entry['order'] ?? $index;
            $beacons[] = [
                'anchor_id' => $anchorId,
                'content' => $content,
                'order' => \is_int($order) ? $order : $index,
            ];
            $index++;
        }

        return $beacons;
    }
}
