<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Exception;

/**
 * @api
 *
 * Thrown when a save stating an optimistic-locking expectation
 * ({@see \Waaseyaa\EntityStorage\SaveContext::withExpectedRevisionId()})
 * finds the entity's current revision differs from the expectation
 * (mission optimistic-locking-01KTXCHY, FR-002).
 *
 * This is a data race (right path, lost the race) — distinct from the
 * `\LogicException` family thrown for expectations that cannot be honored
 * at all (caller programming errors; see contracts/conflict-detection.md §11–12).
 * Callers may rely on that type distinction.
 *
 * `$currentRevisionId === null` means no readable head exists: the base row
 * vanished, or it is a pre-backfill row carrying no revision pointer. A null
 * head can never match a valid expectation (>= 1), so it always conflicts.
 *
 * Content is deterministic (NFR-003): the two revision ids plus static
 * identity — no timestamps, no entity payloads.
 *
 * ## Why $errorCode, not $code
 * `\Exception::$code` is a non-readonly `protected int`. PHP forbids redeclaring it
 * with a type in any subclass ("Type of X::$code must be omitted to match the parent
 * definition"), so a typed `public readonly string $code` is impossible. `$errorCode`
 * is therefore the canonical name on the stable surface.
 */
final class RevisionConflictException extends \RuntimeException
{
    /**
     * @param string $entityTypeId       The entity type the conflicted save targeted.
     * @param string $entityId           The entity id the conflicted save targeted.
     * @param int    $expectedRevisionId The revision id the caller stated as current.
     * @param ?int   $currentRevisionId  The actual current revision id — null when no
     *                                   readable head exists (row vanished or no pointer).
     * @param string $errorCode          Machine-readable error code — always 'REVISION_CONFLICT'.
     */
    public function __construct(
        public readonly string $entityTypeId,
        public readonly string $entityId,
        public readonly int $expectedRevisionId,
        public readonly ?int $currentRevisionId,
        public readonly string $errorCode = 'REVISION_CONFLICT',
    ) {
        parent::__construct(
            sprintf(
                "Revision conflict on %s '%s': expected revision %d, current revision %s.",
                $entityTypeId,
                $entityId,
                $expectedRevisionId,
                $currentRevisionId === null ? 'none' : (string) $currentRevisionId,
            ),
        );
    }
}
