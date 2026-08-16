<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Entity;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Tools\AbstractAgentTool;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\Attribute\AsAgentTool;
use Waaseyaa\Entity\Concurrency\EntityMutationConflictException;
use Waaseyaa\Entity\Concurrency\EntityMutationToken;
use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\EntityTypeManagerInterface;

/**
 * Hard-delete an entity by type + id.
 *
 * Destructive; the HITL gate applies.
 *
 * @api
 */
#[AsAgentTool(
    name: 'entity.delete',
    capability: 'tool.entity.delete',
    destructive: true,
    dryRunSupported: true,
    category: 'entity',
)]
final class EntityDeleteTool extends AbstractAgentTool
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
    ) {}

    public function description(): string
    {
        return 'Hard-delete an entity by type and id.';
    }

    public function inputSchema(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'properties' => [
                'entity_type' => ['type' => 'string'],
                'id' => ['type' => ['string', 'integer']],
                'mutation_token' => ['type' => 'string', 'minLength' => 1],
            ],
            'required' => ['entity_type', 'id', 'mutation_token'],
            'additionalProperties' => false,
        ];
    }

    /** @param \Waaseyaa\Access\AuthorizationPrincipalInterface $account */

    public function execute(array $arguments, AccountInterface $account): AgentToolResult
    {
        $denied = $this->requireCapability('tool.entity.delete', $account);
        if ($denied !== null) {
            return $denied;
        }

        $entityType = $arguments['entity_type'] ?? null;
        $id = $arguments['id'] ?? null;
        if (!is_string($entityType) || $entityType === '' || (!is_string($id) && !is_int($id))) {
            return AgentToolResult::error('entity.delete: missing required arguments entity_type, id.');
        }
        if (!$this->entityTypeManager->hasDefinition($entityType)) {
            return AgentToolResult::error(sprintf('entity.delete: unknown entity type "%s"', $entityType));
        }

        try {
            $repository = $this->entityTypeManager->getRepository($entityType);
            $entity = $repository->find((string) $id);
            if ($entity === null) {
                return AgentToolResult::error(sprintf('entity.delete: %s/%s not found', $entityType, (string) $id));
            }
            $forbidden = $this->requireEntityAccess($entity, 'delete', $account);
            if ($forbidden !== null) {
                return $forbidden;
            }
            $encoded = $arguments['mutation_token'] ?? null;
            if (!is_string($encoded) || trim($encoded) === '') {
                return AgentToolResult::error('entity.delete: mutation_token is required.');
            }
            try {
                $expectedMutation = EntityMutationToken::fromOpaqueString($encoded);
            } catch (\InvalidArgumentException) {
                return AgentToolResult::error('entity.delete: mutation_token is invalid.');
            }
            if (!$entity instanceof EntityBase
                || $expectedMutation->entityTypeId !== $entityType
                || $expectedMutation->entityId !== (string) $entity->id()
            ) {
                return $this->mutationConflict($entityType, (string) $id);
            }
            $entity->_hydrateMutationToken($expectedMutation);
            $repository->delete($entity);
        } catch (EntityMutationConflictException) {
            return $this->mutationConflict($entityType, (string) $id);
        } catch (\Throwable $e) {
            return $this->internalError('entity.delete', $e);
        }

        return AgentToolResult::success(
            content: [['type' => 'json', 'data' => ['entity_type' => $entityType, 'id' => $id, 'deleted' => true]]],
            summary: sprintf('Deleted %s/%s', $entityType, (string) $id),
        );
    }

    /** @param \Waaseyaa\Access\AuthorizationPrincipalInterface $account */

    public function dryRun(array $arguments, AccountInterface $account): AgentToolResult
    {
        $denied = $this->requireCapability('tool.entity.delete', $account);
        if ($denied !== null) {
            return $denied;
        }
        $parsed = self::parseMutationToken($arguments);
        if ($parsed instanceof AgentToolResult) {
            return $parsed;
        }

        return AgentToolResult::success(
            content: [['type' => 'json', 'data' => ['would_delete' => $arguments]]],
            summary: 'Dry-run: would delete entity',
        );
    }

    private function mutationConflict(string $entityType, string $id): AgentToolResult
    {
        $message = sprintf("entity.delete: mutation conflict on %s '%s'. Reload the entity before retrying.", $entityType, $id);

        return new AgentToolResult(
            isError: true,
            content: [
                ['type' => 'text', 'text' => $message],
                ['type' => 'json', 'data' => ['error' => 'mutation_conflict', 'entity_type' => $entityType, 'id' => $id]],
            ],
            summary: $message,
        );
    }

    private static function parseMutationToken(array $arguments): EntityMutationToken|AgentToolResult
    {
        $encoded = $arguments['mutation_token'] ?? null;
        if (!is_string($encoded) || trim($encoded) === '') {
            return AgentToolResult::error('entity.delete: mutation_token is required.');
        }

        try {
            return EntityMutationToken::fromOpaqueString($encoded);
        } catch (\InvalidArgumentException) {
            return AgentToolResult::error('entity.delete: mutation_token is invalid.');
        }
    }
}
