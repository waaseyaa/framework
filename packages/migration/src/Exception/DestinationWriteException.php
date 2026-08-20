<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Exception;

use Waaseyaa\Migration\SourceId;

/**
 * Thrown by a {@see \Waaseyaa\Migration\Plugin\DestinationPluginInterface}
 * implementation when a write attempt fails in a structured, predictable
 * way (FR-020 access denial, FR-018 unknown entity type, persistence
 * failures, etc.).
 *
 * The id-map row is NOT mutated when this exception is raised — atomicity
 * is the caller's contract (see
 * {@see \Waaseyaa\Migration\MigrationIdMap::transactional()}).
 *
 * ## Reason codes (extensible)
 *
 * Concrete codes used by {@see \Waaseyaa\Migration\Plugin\Destination\EntityDestination}:
 *
 * - `entity_type_unknown` — destination entity type id not registered with
 *   {@see \Waaseyaa\Entity\EntityTypeManager} (FR-018).
 * - `entity_create_denied` — access gate denied `create` on the destination
 *   entity type (FR-020).
 * - `entity_update_denied` — access gate denied `update` on a re-import
 *   targeting an existing entity (FR-020).
 * - `entity_load_failed` — id-map row pointed at a destination id that no
 *   longer exists in storage (orphan).
 * - `entity_save_failed` — underlying {@see \Waaseyaa\EntityStorage\EntityRepository::save()}
 *   threw; the id-map upsert is rolled back.
 *
 * @api
 *
 * @spec FR-018 — destination entity type resolution
 * @spec FR-020 — access-check at write time
 * @spec FR-024 — orphaned destination handling
 */
final class DestinationWriteException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $reason,
        public readonly ?SourceId $sourceId = null,
        public readonly ?string $destinationEntityType = null,
        ?\Throwable $previous = null,
    ) {
        if ($message === '') {
            throw new \InvalidArgumentException(
                'DestinationWriteException::__construct(): $message must be a non-empty string.',
            );
        }
        if ($reason === '') {
            throw new \InvalidArgumentException(
                'DestinationWriteException::__construct(): $reason must be a non-empty string.',
            );
        }

        parent::__construct($message, 0, $previous);
    }

    /**
     * Convenience factory: FR-018 — destination entity type not registered.
     */
    public static function entityTypeUnknown(string $entityTypeId, ?SourceId $sourceId = null): self
    {
        return new self(
            message: \sprintf(
                'EntityDestination cannot resolve destination entity type "%s": '
                . 'the type is not registered with EntityTypeManager.',
                $entityTypeId,
            ),
            reason: 'entity_type_unknown',
            sourceId: $sourceId,
            destinationEntityType: $entityTypeId,
        );
    }

    /**
     * Convenience factory: FR-020 — access gate denied `create` on a new entity.
     */
    public static function entityCreateDenied(string $entityTypeId, ?SourceId $sourceId = null): self
    {
        return new self(
            message: \sprintf(
                'EntityDestination cannot create a "%s": access gate returned create=denied (FR-020).',
                $entityTypeId,
            ),
            reason: 'entity_create_denied',
            sourceId: $sourceId,
            destinationEntityType: $entityTypeId,
        );
    }

    /**
     * Convenience factory: FR-020 — access gate denied `update` on an existing entity.
     */
    public static function entityUpdateDenied(string $entityTypeId, ?SourceId $sourceId = null): self
    {
        return new self(
            message: \sprintf(
                'EntityDestination cannot update an existing "%s": access gate returned update=denied (FR-020).',
                $entityTypeId,
            ),
            reason: 'entity_update_denied',
            sourceId: $sourceId,
            destinationEntityType: $entityTypeId,
        );
    }

    /**
     * Convenience factory: id-map row pointed at a missing destination id.
     */
    public static function entityLoadFailed(
        string $entityTypeId,
        string $destinationId,
        ?SourceId $sourceId = null,
    ): self {
        return new self(
            message: \sprintf(
                'EntityDestination found an id-map row for entity type "%s" pointing at id "%s",'
                . ' but no such entity exists in storage (FR-024 orphan).',
                $entityTypeId,
                $destinationId,
            ),
            reason: 'entity_load_failed',
            sourceId: $sourceId,
            destinationEntityType: $entityTypeId,
        );
    }

    /**
     * Convenience factory: underlying EntityRepository::save() threw.
     */
    public static function entitySaveFailed(
        string $entityTypeId,
        \Throwable $previous,
        ?SourceId $sourceId = null,
    ): self {
        return new self(
            message: \sprintf(
                'EntityDestination failed to persist a "%s" via EntityRepository::save(): %s',
                $entityTypeId,
                $previous->getMessage(),
            ),
            reason: 'entity_save_failed',
            sourceId: $sourceId,
            destinationEntityType: $entityTypeId,
            previous: $previous,
        );
    }

    /** @param non-empty-list<string> $codes */
    public static function saveAdvisoryNotDeclared(
        string $entityTypeId,
        array $codes,
        \Throwable $previous,
        ?SourceId $sourceId = null,
    ): self {
        sort($codes, \SORT_STRING);

        return new self(
            message: \sprintf(
                'EntityDestination refused advisory code(s) not declared by migration policy: %s.',
                implode(', ', $codes),
            ),
            reason: 'save_advisory_not_declared',
            sourceId: $sourceId,
            destinationEntityType: $entityTypeId,
            previous: $previous,
        );
    }

    /**
     * Convenience factory: rollback (FR-041) — gate denied `delete` on the
     * destination entity. Mirrors {@see entityUpdateDenied()}.
     *
     * @spec FR-020 — access gate decisions surface as typed exceptions
     * @spec FR-041 — rollback respects entity access policies
     */
    public static function entityDeleteDenied(
        string $entityTypeId,
        string $destinationUuid,
    ): self {
        return new self(
            message: \sprintf(
                'EntityDestination cannot roll back a "%s" (uuid=%s): access gate returned delete=denied (FR-020/FR-041).',
                $entityTypeId,
                $destinationUuid,
            ),
            reason: 'entity_delete_denied',
            destinationEntityType: $entityTypeId,
        );
    }

    /**
     * Convenience factory: rollback (FR-041) — underlying
     * {@see \Waaseyaa\EntityStorage\EntityRepository::delete()} threw.
     */
    public static function entityDeleteFailed(
        string $entityTypeId,
        string $destinationUuid,
        \Throwable $previous,
    ): self {
        return new self(
            message: \sprintf(
                'EntityDestination failed to delete a "%s" (uuid=%s) during rollback: %s',
                $entityTypeId,
                $destinationUuid,
                $previous->getMessage(),
            ),
            reason: 'entity_delete_failed',
            destinationEntityType: $entityTypeId,
            previous: $previous,
        );
    }
}
