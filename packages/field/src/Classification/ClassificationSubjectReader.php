<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Classification;

use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\EntityInterface;

/** Closed fixed-shape reader for classification policy and lifecycle invariants. @internal */
final class ClassificationSubjectReader
{
    /** @var \Closure(EntityBase): array<string, mixed> */
    private readonly \Closure $values;

    public function __construct()
    {
        $this->values = \Closure::bind(
            static fn(EntityBase $entity): array => $entity->valueContainer->rawValues(),
            null,
            EntityBase::class,
        );
    }

    public function read(EntityInterface $entity): ClassificationSubject
    {
        $values = $entity instanceof EntityBase
            ? ($this->values)($entity)
            : [
                'classification_label' => $entity->get('classification_label'),
                'classification_inherited_from' => $entity->get('classification_inherited_from'),
                'classification_overridden_at' => $entity->get('classification_overridden_at'),
                'uid' => $entity->get('uid'),
            ];

        return new ClassificationSubject(
            label: self::optionalString($values['classification_label'] ?? null),
            inheritedFrom: self::optionalString($values['classification_inherited_from'] ?? null),
            overriddenAt: self::optionalString($values['classification_overridden_at'] ?? null),
            authorId: is_int($values['uid'] ?? null) || is_string($values['uid'] ?? null) ? $values['uid'] : null,
        );
    }

    private static function optionalString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
