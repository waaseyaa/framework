<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Snapshot;

use Waaseyaa\Entity\Cast\ValueCaster;
use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\Exception\EntitySerializationForbidden;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Entity\Hydration\HydrationContext;

/**
 * Readonly, storage-canonical view of an entity value bag at a point in time (P3).
 *
 * This is not a live {@see EntityInterface}: no mutation and no rehydration helpers here.
 * To obtain a mutable entity, use {@see \Waaseyaa\Entity\EntityBase::duplicate()} or storage hydration.
 *
 * @see docs/specs/entity-system.md — Branching and snapshots (P3)
 * @api
 */
final readonly class EntityValuesSnapshot
{
    /**
     * @param array<string, mixed> $values Shallow copy of storage-canonical values at snapshot time.
     * @param array<string, string|array<string, mixed>>|null $casts When non-null, {@see getCastAware()} applies
     *                                                                 {@see ValueCaster} for keys present in this map;
     *                                                                 other fields fall back to storage shape.
     */
    public function __construct(
        private array $values,
        private HydrationContext $context,
        private ?array $casts = null,
    ) {}

    /**
     * @param array<string, string|array<string, mixed>>|null $casts Pass the same shape as {@see \Waaseyaa\Entity\EntityBase::$casts}
     *                                                                for cast-aware reads; omit for storage-only.
     */
    public static function fromEntity(EntityInterface $entity, HydrationContext $context, ?array $casts = null): self
    {
        if ($entity instanceof EntityBase) {
            foreach ($entity->fieldNames() as $field) {
                if ($entity->fieldReadLevel($field) !== FieldReadLevel::Public) {
                    throw new EntitySerializationForbidden(sprintf(
                        'A sealed non-Public field cannot be detached into an EntityValuesSnapshot (%s.%s).',
                        $entity->getEntityTypeId(),
                        $field,
                    ));
                }
            }
        }
        $bag = $entity->toArray();

        return new self(
            self::shallowCopyValues($bag),
            $context,
            $casts !== null ? self::shallowCopyCasts($casts) : null,
        );
    }

    /**
     * @param array<string, string|array<string, mixed>>|null $casts
     */
    public static function fromEntityAndType(EntityInterface $entity, EntityTypeInterface $type, ?array $casts = null): self
    {
        $context = new HydrationContext(
            entityTypeId: $type->id(),
            entityKeys: $type->getKeys(),
        );

        return self::fromEntity($entity, $context, $casts);
    }

    public function context(): HydrationContext
    {
        return $this->context;
    }

    /**
     * Whether a key exists in the storage bag (including null values).
     */
    public function has(string $field): bool
    {
        return \array_key_exists($field, $this->values);
    }

    /**
     * Storage-canonical value (same shape as {@see EntityInterface::toArray()} entries), not cast-aware.
     */
    public function get(string $field): mixed
    {
        return $this->values[$field] ?? null;
    }

    /**
     * Cast-aware read when a cast map was supplied at construction; uses {@see ValueCaster::castIn()} for fields
     * listed in the map. Fields not listed return the storage value unchanged.
     *
     * @throws \LogicException When no cast map was provided.
     */
    public function getCastAware(string $field): mixed
    {
        if ($this->casts === null) {
            throw new \LogicException(
                'EntityValuesSnapshot was constructed without a cast map; use get() for storage-canonical values only.',
            );
        }

        $raw = \array_key_exists($field, $this->values) ? $this->values[$field] : null;

        if (!isset($this->casts[$field])) {
            return $raw;
        }

        return new ValueCaster()->castIn($field, $raw, $this->casts[$field]);
    }

    /**
     * @return array<string, mixed> Shallow copy — mutating returned top-level entries does not alter the snapshot's bag,
     *                              but nested structures may still be shared with the original entity if they were shared at snapshot time.
     */
    public function toStorageArray(): array
    {
        return self::shallowCopyValues($this->values);
    }

    /**
     * @param array<string, mixed> $bag
     *
     * @return array<string, mixed>
     */
    private static function shallowCopyValues(array $bag): array
    {
        $out = [];
        foreach ($bag as $key => $value) {
            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * @param array<string, string|array<string, mixed>> $casts
     *
     * @return array<string, string|array<string, mixed>>
     */
    private static function shallowCopyCasts(array $casts): array
    {
        $out = [];
        foreach ($casts as $key => $spec) {
            $out[$key] = $spec;
        }

        return $out;
    }
}
