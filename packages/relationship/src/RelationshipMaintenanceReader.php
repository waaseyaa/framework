<?php

declare(strict_types=1);

namespace Waaseyaa\Relationship;

use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\EntityValues;

/** Closed typed authority for first-party relationship maintenance and traversal. @api */
final class RelationshipMaintenanceReader
{
    /** @var \Closure(EntityBase): array<string, mixed> */
    private readonly \Closure $valueAuthority;

    public function __construct()
    {
        $authority = \Closure::bind(
            static fn(EntityBase $entity): array => $entity->valueContainer->rawValues(),
            null,
            EntityBase::class,
        );
        $this->valueAuthority = $authority;
    }

    public function read(Relationship $relationship): RelationshipMaintenanceSnapshot
    {
        $values = ($this->valueAuthority)($relationship);

        return new RelationshipMaintenanceSnapshot(
            (string) ($values['directionality'] ?? 'directed'),
            EntityValues::statusToInt($values['status'] ?? 0),
            $this->optionalNumber($values['weight'] ?? null),
            is_int($values['start_date'] ?? null) || is_string($values['start_date'] ?? null) ? $values['start_date'] : null,
            is_int($values['end_date'] ?? null) || is_string($values['end_date'] ?? null) ? $values['end_date'] : null,
            $this->optionalNumber($values['confidence'] ?? null),
            is_string($values['source_ref'] ?? null) ? $values['source_ref'] : null,
            is_string($values['notes'] ?? null) ? $values['notes'] : null,
        );
    }

    private function optionalNumber(mixed $value): int|float|null
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }
}
