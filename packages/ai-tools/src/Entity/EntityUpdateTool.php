<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Entity;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Tools\AbstractAgentTool;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\Attribute\AsAgentTool;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Validation\EntityValidationException;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\Exception\RevisionConflictException;
use Waaseyaa\EntityStorage\SaveContext;

/**
 * Load + mutate + save an existing entity.
 *
 * Destructive; the HITL gate applies.
 *
 * @api
 */
#[AsAgentTool(
    name: 'entity.update',
    capability: 'tool.entity.update',
    destructive: true,
    dryRunSupported: true,
    category: 'entity',
)]
final class EntityUpdateTool extends AbstractAgentTool
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
    ) {}

    public function description(): string
    {
        return 'Update fields of an existing entity by type + id.';
    }

    public function inputSchema(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'properties' => [
                'entity_type' => ['type' => 'string'],
                'id' => ['type' => ['string', 'integer']],
                'values' => ['type' => 'object', 'additionalProperties' => true],
                'revision_log' => ['type' => 'string', 'description' => 'Optional revision log message (revisionable entities only).'],
                'expected_revision_id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Optional optimistic-locking expectation: the revision_id the caller read. The save is refused with a revision_conflict error if the entity\'s current revision differs. Revisionable entity types only.',
                ],
            ],
            'required' => ['entity_type', 'id', 'values'],
            'additionalProperties' => false,
        ];
    }

    public function execute(array $arguments, AccountInterface $account): AgentToolResult
    {
        $denied = $this->requireCapability('tool.entity.update', $account);
        if ($denied !== null) {
            return $denied;
        }

        $entityType = $arguments['entity_type'] ?? null;
        $id = $arguments['id'] ?? null;
        $values = $arguments['values'] ?? null;
        if (!is_string($entityType) || $entityType === '' || (!is_string($id) && !is_int($id)) || !is_array($values)) {
            return AgentToolResult::error('entity.update: missing required arguments entity_type, id, values.');
        }

        // optimistic-locking-01KTXCHY FR-005: the expectation is a top-level
        // argument, never a writable value (`revision_id` inside `values`
        // stays refused by EntityKeyGuard).
        [$expectedRevisionId, $invalidExpectation] = self::parseExpectedRevisionId($arguments);
        if ($invalidExpectation !== null) {
            return $invalidExpectation;
        }

        if (!$this->entityTypeManager->hasDefinition($entityType)) {
            return AgentToolResult::error(sprintf('entity.update: unknown entity type "%s"', $entityType));
        }

        try {
            $repository = $this->entityTypeManager->getRepository($entityType);
            $entity = $repository->find((string) $id);
            if ($entity === null) {
                return AgentToolResult::error(sprintf('entity.update: %s/%s not found', $entityType, (string) $id));
            }
            $forbidden = $this->requireEntityAccess($entity, 'update', $account);
            if ($forbidden !== null) {
                return $forbidden;
            }
            // Identity-key refusal runs AFTER the access check (no identity
            // probing for unauthorized callers) and BEFORE the mutation loop
            // — whole-write rejection, zero set() calls on a refused payload.
            // Only the values payload is guarded; the `id` locator argument
            // is never refused.
            $refused = EntityKeyGuard::refusedKeys($this->entityTypeManager->getDefinition($entityType), $values);
            if ($refused !== []) {
                return EntityKeyGuard::refusalError('entity.update', $refused);
            }
            foreach ($values as $field => $value) {
                if (!is_string($field)) {
                    continue;
                }
                $entity->set($field, $value);
            }
            $revisionLog = $arguments['revision_log'] ?? null;
            if (is_string($revisionLog) && $revisionLog !== '' && method_exists($entity, 'setRevisionLog')) {
                $entity->setRevisionLog($revisionLog);
            }
            if ($expectedRevisionId !== null) {
                // A stated expectation rides SaveContext, which only the
                // concrete EntityRepository can carry — any other repository
                // implementation must refuse loudly, never save silently
                // (FR-007 at the surface, contract conflict-surfaces.md §2).
                if (!$repository instanceof EntityRepository) {
                    return self::unsupportedExpectationError($entityType, 'repository does not support revision expectations');
                }
                try {
                    $result = $repository->save($entity, context: SaveContext::default()->withExpectedRevisionId($expectedRevisionId));
                } catch (RevisionConflictException $e) {
                    return self::conflictError($e->entityTypeId, $e->entityId, $e->expectedRevisionId, $e->currentRevisionId);
                } catch (\LogicException $e) {
                    // Storage rejection matrix (contract conflict-detection.md
                    // §11): the expectation cannot be honored — never silently
                    // dropped. Scoped to the expectation-stated save only; a
                    // LogicException on a no-expectation call keeps today's
                    // generic handling (the outer \Throwable arm).
                    return self::unsupportedExpectationError($entityType, $e->getMessage());
                }
            } else {
                $result = $repository->save($entity);
            }
        } catch (EntityValidationException $e) {
            return EntityKeyGuard::validationError('entity.update', $e);
        } catch (\Throwable $e) {
            return AgentToolResult::error(sprintf('entity.update: %s', $e->getMessage()));
        }

        return AgentToolResult::success(
            content: [['type' => 'json', 'data' => [
                'entity_type' => $entityType,
                'id' => $id,
                'result' => $result,
                // Post-save readback of the new head (contract §7) so a
                // chaining agent can state its next expectation re-read-free.
                'revision_id' => method_exists($entity, 'getRevisionId') ? $entity->getRevisionId() : null,
            ]]],
            summary: sprintf('Updated %s/%s', $entityType, (string) $id),
        );
    }

    public function dryRun(array $arguments, AccountInterface $account): AgentToolResult
    {
        $denied = $this->requireCapability('tool.entity.update', $account);
        if ($denied !== null) {
            return $denied;
        }

        // Contract clause 5: a dry run of an invalid call must not claim
        // it would succeed — report the refusal identically.
        $entityType = $arguments['entity_type'] ?? null;
        $values = $arguments['values'] ?? null;
        if (is_string($entityType) && $this->entityTypeManager->hasDefinition($entityType) && is_array($values)) {
            $refused = EntityKeyGuard::refusedKeys($this->entityTypeManager->getDefinition($entityType), $values);
            if ($refused !== []) {
                return EntityKeyGuard::refusalError('entity.update', $refused);
            }
        }

        // optimistic-locking-01KTXCHY contract conflict-surfaces.md §6: a dry
        // run carrying an expectation loads the entity and compares the head;
        // a mismatch reports the byte-identical revision_conflict payload of a
        // real call. (Read-compare only — never authoritative; the real save's
        // guarded claim is.) Without the argument, no load happens.
        [$expectedRevisionId, $invalidExpectation] = self::parseExpectedRevisionId($arguments);
        if ($invalidExpectation !== null) {
            return $invalidExpectation;
        }
        if ($expectedRevisionId !== null && is_string($entityType) && $this->entityTypeManager->hasDefinition($entityType)) {
            $mismatch = $this->dryRunExpectationCheck($entityType, $arguments['id'] ?? null, $expectedRevisionId);
            if ($mismatch !== null) {
                return $mismatch;
            }
        }

        return AgentToolResult::success(
            content: [['type' => 'json', 'data' => ['would_update' => $arguments]]],
            summary: 'Dry-run: would update entity',
        );
    }

    /**
     * Dry-run head comparison for a stated expectation (contract §6): returns
     * the conflict / unsupported error a real call would produce, or null when
     * the expectation matches the current head.
     */
    private function dryRunExpectationCheck(string $entityType, mixed $id, int $expectedRevisionId): ?AgentToolResult
    {
        $definition = $this->entityTypeManager->getDefinition($entityType);
        if (!$definition->isRevisionable() || $definition->isTranslatable()) {
            return self::unsupportedExpectationError(
                $entityType,
                sprintf("entity type '%s' is not single-axis revisionable", $entityType),
            );
        }
        $repository = $this->entityTypeManager->getRepository($entityType);
        if (!$repository instanceof EntityRepository) {
            return self::unsupportedExpectationError($entityType, 'repository does not support revision expectations');
        }
        if (!is_string($id) && !is_int($id)) {
            return null;
        }
        $entity = $repository->find((string) $id);
        if ($entity === null) {
            return self::conflictError($entityType, (string) $id, $expectedRevisionId, null);
        }
        $current = method_exists($entity, 'getRevisionId') ? $entity->getRevisionId() : null;
        if ($current !== $expectedRevisionId) {
            return self::conflictError($entityType, (string) ($entity->id() ?? $id), $expectedRevisionId, $current);
        }

        return null;
    }

    /**
     * Parse the optional top-level expectation argument.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array{0: ?int, 1: ?AgentToolResult} parsed expectation + error result when invalid
     */
    private static function parseExpectedRevisionId(array $arguments): array
    {
        if (!array_key_exists('expected_revision_id', $arguments)) {
            return [null, null];
        }
        $candidate = $arguments['expected_revision_id'];
        if (!is_int($candidate) || $candidate < 1) {
            return [null, AgentToolResult::error('entity.update: expected_revision_id must be a positive integer.')];
        }

        return [$candidate, null];
    }

    /**
     * The Mission 1 two-block revision_conflict error. Single builder shared
     * by execute() and dryRun() so the payload bytes cannot fork (NFR-003).
     */
    private static function conflictError(string $entityType, string $id, int $expected, ?int $current): AgentToolResult
    {
        $message = sprintf(
            "entity.update: revision conflict on %s '%s': expected revision %d, current revision %s.",
            $entityType,
            $id,
            $expected,
            $current === null ? 'none' : (string) $current,
        );

        return new AgentToolResult(
            isError: true,
            content: [
                ['type' => 'text', 'text' => $message],
                ['type' => 'json', 'data' => [
                    'error' => 'revision_conflict',
                    'entity_type' => $entityType,
                    'id' => $id,
                    'expected' => $expected,
                    'current' => $current,
                ]],
            ],
            summary: $message,
        );
    }

    /**
     * Two-block error for a stated expectation that cannot be honored
     * (contract §4). Distinct from revision_conflict — agents must not retry.
     */
    private static function unsupportedExpectationError(string $entityType, string $reason): AgentToolResult
    {
        $message = sprintf('entity.update: revision expectation unsupported for %s: %s', $entityType, $reason);

        return new AgentToolResult(
            isError: true,
            content: [
                ['type' => 'text', 'text' => $message],
                ['type' => 'json', 'data' => [
                    'error' => 'revision_expectation_unsupported',
                    'entity_type' => $entityType,
                    'reason' => $reason,
                ]],
            ],
            summary: $message,
        );
    }
}
