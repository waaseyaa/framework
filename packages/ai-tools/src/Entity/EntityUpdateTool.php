<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Entity;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Tools\AbstractAgentTool;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\Attribute\AsAgentTool;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Validation\EntityValidationException;

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
            $result = $repository->save($entity);
        } catch (EntityValidationException $e) {
            return EntityKeyGuard::validationError('entity.update', $e);
        } catch (\Throwable $e) {
            return AgentToolResult::error(sprintf('entity.update: %s', $e->getMessage()));
        }

        return AgentToolResult::success(
            content: [['type' => 'json', 'data' => ['entity_type' => $entityType, 'id' => $id, 'result' => $result]]],
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

        return AgentToolResult::success(
            content: [['type' => 'json', 'data' => ['would_update' => $arguments]]],
            summary: 'Dry-run: would update entity',
        );
    }
}
