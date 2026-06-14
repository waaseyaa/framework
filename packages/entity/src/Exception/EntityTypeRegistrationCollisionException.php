<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Exception;

final class EntityTypeRegistrationCollisionException extends \InvalidArgumentException
{
    public static function duplicate(
        string $entityTypeId,
        ?string $existingRegistrant,
        string $existingEntityClass,
        ?string $incomingRegistrant,
        string $incomingEntityClass,
    ): self {
        return new self(\sprintf(
            '[ENTITY_TYPE_DUPLICATE] Entity type "%s" is already registered by %s using %s. '
            . 'Duplicate registration attempted by %s using the same class %s. '
            . 'Drop the duplicate registration.',
            $entityTypeId,
            self::registrantLabel($existingRegistrant),
            $existingEntityClass,
            self::registrantLabel($incomingRegistrant),
            $incomingEntityClass,
        ));
    }

    public static function shadowCollision(
        string $entityTypeId,
        ?string $existingRegistrant,
        string $existingEntityClass,
        ?string $incomingRegistrant,
        string $incomingEntityClass,
    ): self {
        return new self(\sprintf(
            '[ENTITY_TYPE_SHADOW_COLLISION] Entity type "%s" is already registered by %s using canonical class %s. '
            . 'Conflicting registration attempted by %s using %s. '
            . 'If this was a consumer shadow, drop the registration and migrate callers to the canonical type; '
            . 'see docs/history/superpowers/specs/2026-04-19-groups-reconciliation-adr.md.',
            $entityTypeId,
            self::registrantLabel($existingRegistrant),
            $existingEntityClass,
            self::registrantLabel($incomingRegistrant),
            $incomingEntityClass,
        ));
    }

    private static function registrantLabel(?string $registrant): string
    {
        return $registrant ?? 'an unknown registrant';
    }
}
