<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Entity;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Tools\AbstractAgentTool;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\Attribute\AsAgentTool;
use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;

/**
 * Read a single entity by type + id.
 *
 * @api
 */
#[AsAgentTool(
    name: 'entity.read',
    capability: 'tool.entity.read',
    destructive: false,
    dryRunSupported: true,
    category: 'entity',
)]
final class EntityReadTool extends AbstractAgentTool
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
    ) {}

    public function description(): string
    {
        return 'Load a single entity by type and id.';
    }

    public function inputSchema(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'properties' => [
                'entity_type' => ['type' => 'string', 'description' => 'Entity type id.'],
                'id' => ['type' => ['string', 'integer'], 'description' => 'Entity id.'],
                'langcode' => ['type' => 'string', 'description' => 'Optional language code.'],
            ],
            'required' => ['entity_type', 'id'],
            'additionalProperties' => false,
        ];
    }

    /** @param \Waaseyaa\Access\AuthorizationPrincipalInterface $account */

    public function execute(array $arguments, AccountInterface $account): AgentToolResult
    {
        $denied = $this->requireCapability('tool.entity.read', $account);
        if ($denied !== null) {
            return $denied;
        }

        $entityType = $arguments['entity_type'] ?? null;
        $id = $arguments['id'] ?? null;
        if (!is_string($entityType) || $entityType === '' || (!is_string($id) && !is_int($id))) {
            return AgentToolResult::error('entity.read: missing required arguments entity_type, id.');
        }

        if (!$this->entityTypeManager->hasDefinition($entityType)) {
            return AgentToolResult::error(sprintf('entity.read: unknown entity type "%s"', $entityType));
        }

        $langcode = isset($arguments['langcode']) && is_string($arguments['langcode']) ? $arguments['langcode'] : null;

        try {
            $repository = $this->entityTypeManager->getRepository($entityType);
            $entity = $repository->find((string) $id, $langcode);
        } catch (\Throwable $e) {
            return $this->internalError('entity.read', $e);
        }

        // Absent-vs-forbidden indistinguishability (R8-c): `tool.entity.read`
        // is on PublicAnonymousAuth::DEFAULT_READ_CAPABILITIES, so this tool is
        // anonymous-reachable. A distinguishable "not permitted to view" (from
        // requireEntityAccess()) vs "not found" would let an anonymous caller
        // enumerate ids and learn "exists but forbidden" apart from "absent" —
        // an existence oracle (the same class the DiscoveryRouter and
        // relationship.traverse gates close). Collapse BOTH the absent and the
        // view-forbidden outcomes into the IDENTICAL not-found error so the two
        // are byte-indistinguishable. canViewEntity() fails closed under
        // enforcement with no handler and preserves capability-only behavior
        // (allow) — we deliberately do NOT use requireEntityAccess() here (its
        // 'forbidden' message is wire-distinguishable), and that shared helper
        // is left unchanged for the write tools, which are not on the anonymous
        // read tier.
        if ($entity === null || !$this->canViewEntity($entity, $account)) {
            return AgentToolResult::error(sprintf('entity.read: %s/%s not found', $entityType, $id));
        }

        // MCP conformance (#2520): `json` is not an MCP content type — the
        // bridge forwards $result->content verbatim, so the payload has to ride
        // in a `text` block (and in structuredContent for machine consumers).
        $data = $this->serialize($entity, $account);

        try {
            $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException $e) {
            return $this->internalError('entity.read', $e);
        }

        return AgentToolResult::success(
            content: [['type' => 'text', 'text' => $json]],
            summary: sprintf('Loaded %s/%s', $entityType, $id),
            structuredContent: $data,
        );
    }

    /** @param \Waaseyaa\Access\AuthorizationPrincipalInterface $account */

    public function dryRun(array $arguments, AccountInterface $account): AgentToolResult
    {
        // Read-only — dryRun is the same as execute.
        return $this->execute($arguments, $account);
    }

    /**
     * @return array<string, mixed>
     */
    /** @param \Waaseyaa\Access\AuthorizationPrincipalInterface $account */
    private function serialize(EntityInterface $entity, AccountInterface $account): array
    {
        $data = [
            'entity_type' => $entity->getEntityTypeId(),
            'id' => $entity->id(),
        ];
        // FR-008 (optimistic-locking-01KTXCHY): expose the current head so a
        // caller can form an expectation for entity.update. Omitted when the
        // entity carries no revision id (absence = "no expectation formable").
        if (method_exists($entity, 'getRevisionId')) {
            $revisionId = $entity->getRevisionId();
            if ($revisionId !== null) {
                $data['revision_id'] = $revisionId;
            }
        }
        if ($entity instanceof EntityBase && $this->canMutateEntity($entity, $account)) {
            $mutationToken = $entity->mutationToken();
            if ($mutationToken !== null) {
                $data['mutation_token'] = $mutationToken->toOpaqueString();
            }
        }
        // Prefer a curated getValues() when an entity provides one; otherwise use
        // the EntityInterface-guaranteed toArray(), so field values are exposed
        // for every entity, not only those that happen to define getValues().
        if ($entity instanceof EntityBase) {
            $names = EntityFieldRedaction::ordinaryFieldNames($this->entityTypeManager, $entity);
            $allowed = $this->applyFieldAccessFilter($entity, array_fill_keys($names, true), $account);
            $values = EntityFieldRedaction::toReadableCastAwareMap(
                $entity,
                array_values(array_filter(array_keys($allowed), is_string(...))),
            );
        } else {
            $values = [];
            if (method_exists($entity, 'getValues')) {
                $curated = $entity->getValues();
                $values = is_array($curated) ? $curated : [];
            }
            if ($values === []) {
                $values = $entity->toArray();
            }
            $values = $this->filterReadableValues($entity, $values, $account);
        }
        if ($values !== []) {
            $data['values'] = $values;
        }

        return $data;
    }

    /**
     * Expose the entity's actual stored fields while never leaking
     * internal/credential or field-access-forbidden fields — same boundary the
     * JSON:API serializer enforces. Internal-field and credential drops run
     * BEFORE the per-account field-access filter so credentials never reach it.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    /** @param \Waaseyaa\Access\AuthorizationPrincipalInterface $account */
    private function filterReadableValues(EntityInterface $entity, array $values, AccountInterface $account): array
    {
        $values = EntityFieldRedaction::stripInternal($this->entityTypeManager, $entity, $values);

        return $this->applyFieldAccessFilter($entity, $values, $account);
    }
}
