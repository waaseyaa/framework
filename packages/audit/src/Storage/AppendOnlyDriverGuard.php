<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Storage;

use Waaseyaa\EntityStorage\Driver\EntityStorageDriverInterface;

/**
 * Storage driver decorator that enforces append-only semantics for audit entities.
 *
 * Wraps a real driver and throws \LogicException if any code attempts to update or
 * remove an audit_event row. The only legal mutation path is `record()` (insert)
 * via {@see \Waaseyaa\Audit\Contract\AuditWriterInterface}, plus the bulk-delete
 * invoked by `audit:prune` which goes through
 * {@see \Waaseyaa\Database\DatabaseInterface::delete()} directly (bypassing the
 * entity repository deliberately — FR-003).
 *
 * @api
 */
final class AppendOnlyDriverGuard implements EntityStorageDriverInterface
{
    /**
     * Entity type IDs that are append-only.
     *
     * @var list<string>
     */
    private const APPEND_ONLY_TYPES = ['audit_event'];

    public function __construct(
        private readonly EntityStorageDriverInterface $inner,
    ) {}

    public function read(string $entityType, string $id, ?string $langcode = null): ?array
    {
        return $this->inner->read($entityType, $id, $langcode);
    }

    public function readMultiple(string $entityType, array $ids, ?string $langcode = null): array
    {
        return $this->inner->readMultiple($entityType, $ids, $langcode);
    }

    public function write(string $entityType, string $id, array $values): string
    {
        // Insert (id empty string) is allowed; update (non-empty id on existing row) is blocked.
        if (in_array($entityType, self::APPEND_ONLY_TYPES, true) && $id !== '') {
            throw new \LogicException(
                sprintf(
                    'Audit entity type "%s" is append-only. Updates are forbidden (FR-003). '
                    . 'Use audit:prune for bulk deletion via DatabaseInterface::delete().',
                    $entityType,
                ),
            );
        }

        return $this->inner->write($entityType, $id, $values);
    }

    public function remove(string $entityType, string $id): void
    {
        if (in_array($entityType, self::APPEND_ONLY_TYPES, true)) {
            throw new \LogicException(
                sprintf(
                    'Audit entity type "%s" is append-only. Entity-repository deletes are forbidden (FR-003). '
                    . 'Use audit:prune (DatabaseInterface::delete()) for bulk retention purges.',
                    $entityType,
                ),
            );
        }

        $this->inner->remove($entityType, $id);
    }

    public function exists(string $entityType, string $id): bool
    {
        return $this->inner->exists($entityType, $id);
    }

    public function count(string $entityType, array $criteria = []): int
    {
        return $this->inner->count($entityType, $criteria);
    }

    public function findBy(
        string $entityType,
        array $criteria = [],
        ?array $orderBy = null,
        ?int $limit = null,
    ): array {
        return $this->inner->findBy($entityType, $criteria, $orderBy, $limit);
    }

    public function findTranslations(
        string $entityType,
        string $id,
        ?string $defaultLangcode = null,
    ): array {
        return $this->inner->findTranslations($entityType, $id, $defaultLangcode);
    }
}
