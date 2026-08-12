<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Concurrency;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\Concurrency\EntityMutationToken;
use Waaseyaa\EntityStorage\Exception\EntityMutationConflictException;

/**
 * Transaction-local compare-and-swap authority for persisted aggregates.
 *
 * Schema installation belongs to DB-02. This class performs DML only.
 *
 * @internal EntityRepository mutation boundary.
 */
final class EntityMutationAuthority
{
    private const string TABLE = 'waaseyaa_entity_mutation_authority';

    public function __construct(
        private readonly DatabaseInterface $dbalDatabase,
        private readonly string $storageAuthority,
    ) {
        if ($storageAuthority === '') {
            throw new \InvalidArgumentException('Storage authority must not be empty.');
        }
    }

    public function create(string $tenantId, string $entityTypeId, string $entityId): EntityMutationToken
    {
        $token = EntityMutationToken::issue($this->storageAuthority, $tenantId, $entityTypeId, $entityId, 1);
        try {
            $this->dbalDatabase->insert(self::TABLE)->values([
                'storage_authority' => $this->storageAuthority,
                'tenant_id' => $tenantId,
                'entity_type' => $entityTypeId,
                'entity_id' => $entityId,
                'aggregate_version' => 1,
                'mutation_tag' => $token->tagHex(),
                'lifecycle_state' => 'active',
            ])->execute();
        } catch (UniqueConstraintViolationException $exception) {
            throw $this->conflict($tenantId, $entityTypeId, $entityId, $exception);
        }

        return $token;
    }

    public function load(string $tenantId, string $entityTypeId, string $entityId): ?EntityMutationToken
    {
        $row = $this->row($tenantId, $entityTypeId, $entityId);

        return $row === null ? null : $this->tokenFromRow($row);
    }

    public function claim(EntityMutationToken $expected): EntityMutationToken
    {
        return $this->claimForIdentity(
            $expected->tenantId,
            $expected->entityTypeId,
            $expected->entityId,
            $expected,
        );
    }

    public function claimForIdentity(
        string $tenantId,
        string $entityTypeId,
        string $entityId,
        EntityMutationToken $expected,
    ): EntityMutationToken {
        $this->assertIdentity($tenantId, $entityTypeId, $entityId, $expected);

        return $this->transition($expected, 'active', 'active');
    }

    public function tombstone(EntityMutationToken $expected): EntityMutationToken
    {
        return $this->transition($expected, 'active', 'tombstone');
    }

    public function recreate(EntityMutationToken $expectedTombstone): EntityMutationToken
    {
        return $this->transition($expectedTombstone, 'tombstone', 'active');
    }

    public function state(EntityMutationToken $expected): string
    {
        $row = $this->row($expected->tenantId, $expected->entityTypeId, $expected->entityId);
        if ($row === null || !$this->matches($row, $expected)) {
            throw $this->conflict($expected->tenantId, $expected->entityTypeId, $expected->entityId);
        }

        return (string) $row['lifecycle_state'];
    }

    public function assertCurrentForIdentity(
        string $tenantId,
        string $entityTypeId,
        string $entityId,
        EntityMutationToken $expected,
    ): void {
        $this->assertIdentity($tenantId, $entityTypeId, $entityId, $expected);
        if ($this->state($expected) !== 'active') {
            throw $this->conflict($tenantId, $entityTypeId, $entityId);
        }
    }

    private function transition(
        EntityMutationToken $expected,
        string $expectedState,
        string $successorState,
    ): EntityMutationToken {
        $next = EntityMutationToken::issue(
            $expected->storageAuthority,
            $expected->tenantId,
            $expected->entityTypeId,
            $expected->entityId,
            $expected->aggregateVersion + 1,
        );
        $affected = $this->dbalDatabase->update(self::TABLE)
            ->fields([
                'aggregate_version' => $next->aggregateVersion,
                'mutation_tag' => $next->tagHex(),
                'lifecycle_state' => $successorState,
            ])
            ->condition('storage_authority', $this->storageAuthority)
            ->condition('tenant_id', $expected->tenantId)
            ->condition('entity_type', $expected->entityTypeId)
            ->condition('entity_id', $expected->entityId)
            ->condition('aggregate_version', $expected->aggregateVersion)
            ->condition('mutation_tag', $expected->tagHex())
            ->condition('lifecycle_state', $expectedState)
            ->execute();
        if ($affected !== 1) {
            throw $this->conflict($expected->tenantId, $expected->entityTypeId, $expected->entityId);
        }

        return $next;
    }

    private function assertIdentity(
        string $tenantId,
        string $entityTypeId,
        string $entityId,
        EntityMutationToken $expected,
    ): void {
        if ($expected->storageAuthority !== $this->storageAuthority
            || $expected->tenantId !== $tenantId
            || $expected->entityTypeId !== $entityTypeId
            || $expected->entityId !== $entityId
        ) {
            throw $this->conflict($tenantId, $entityTypeId, $entityId);
        }
    }

    /** @return array<string, mixed>|null */
    private function row(string $tenantId, string $entityTypeId, string $entityId): ?array
    {
        foreach ($this->dbalDatabase->query(
            'SELECT tenant_id, entity_type, entity_id, aggregate_version, mutation_tag, lifecycle_state FROM ' . self::TABLE
            . ' WHERE storage_authority = ? AND tenant_id = ? AND entity_type = ? AND entity_id = ?',
            [$this->storageAuthority, $tenantId, $entityTypeId, $entityId],
        ) as $row) {
            return $row;
        }

        return null;
    }

    /** @param array<string, mixed> $row */
    private function tokenFromRow(array $row): EntityMutationToken
    {
        $tag = hex2bin((string) $row['mutation_tag']);
        if ($tag === false) {
            throw new \UnexpectedValueException('Stored entity mutation tag is invalid.');
        }

        return EntityMutationToken::issue(
            $this->storageAuthority,
            (string) $row['tenant_id'],
            (string) $row['entity_type'],
            (string) $row['entity_id'],
            (int) $row['aggregate_version'],
            $tag,
        );
    }

    /** @param array<string, mixed> $row */
    private function matches(array $row, EntityMutationToken $expected): bool
    {
        return (int) $row['aggregate_version'] === $expected->aggregateVersion
            && hash_equals((string) $row['mutation_tag'], $expected->tagHex());
    }

    private function conflict(
        string $tenantId,
        string $entityTypeId,
        string $entityId,
        ?\Throwable $previous = null,
    ): EntityMutationConflictException {
        return new EntityMutationConflictException($tenantId, $entityTypeId, $entityId, $previous);
    }
}
