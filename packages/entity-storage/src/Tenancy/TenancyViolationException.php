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
}
