<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tenancy;

final class TenancyViolationException extends \RuntimeException
{
    public static function conflictingWrite(string $active, string $provided): self
    {
        return new self(sprintf(
            'Scoped write refused: active community "%s" does not match provided community_id "%s".',
            $active,
            $provided,
        ));
    }

    public static function invisibleEntity(string $active, string $entityType, string $entityId): self
    {
        return new self(sprintf(
            'Scoped revision mutation refused: entity "%s:%s" is not visible in active community "%s".',
            $entityType,
            $entityId,
            $active,
        ));
    }
}
