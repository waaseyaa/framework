<?php

declare(strict_types=1);

namespace Waaseyaa\NorthCloud\Sync;

/**
 * Extension seam for turning a North Cloud search hit into a Waaseyaa entity.
 *
 * Apps implement one of these per target entity type. The package's NcSyncService
 * discovers registered mappers via MapperRegistry and delegates hit-to-entity
 * translation to them.
 *
 * Multiple mappers may fire on the same hit — every mapper whose supports() returns
 * true will produce an entity. This lets a single NC article populate, say, both a
 * `teaching` and an `event`.
 *
 * Trust boundary: North Cloud hit values are external input. Implementations MUST
 * sanitize any HTML-bearing value inside map() before returning it for persistence,
 * according to the destination field's allowed markup. NcSyncService deliberately
 * treats the returned field array as application-owned data and does not apply a
 * generic sanitizer that could either miss a field or destroy permitted markup.
 */
interface NcHitToEntityMapperInterface
{
    /**
     * The entity type id this mapper targets (must be registered with EntityTypeManager).
     */
    public function entityType(): string;

    /**
     * Whether this mapper should run for the given hit.
     *
     * @param array<string, mixed> $hit Raw NC search hit
     */
    public function supports(array $hit): bool;

    /**
     * Map an NC hit to a field array ready for EntityStorage::create().
     *
     * This method is the mapper-owned sanitization boundary. Never copy external
     * HTML directly into a rendered or rich-text field; sanitize it against that
     * field's allowlist before returning it.
     *
     * @param array<string, mixed> $hit
     * @return array<string, mixed>
     */
    public function map(array $hit): array;

    /**
     * Field used for deduplication. The sync service queries the target storage
     * for existing rows matching the mapped value of this field and skips the hit
     * if any exist. Override when your entity doesn't track source provenance via
     * `source_url`.
     *
     * Contract: when this returns a non-empty string, that key MUST be present
     * in the array returned by {@see map()}. NcSyncService throws
     * \LogicException if the contract is violated.
     *
     * Return `''` (empty string) to opt out of deduplication entirely.
     */
    public function dedupField(): string;
}
