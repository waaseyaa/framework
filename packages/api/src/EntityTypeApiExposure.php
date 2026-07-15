<?php

declare(strict_types=1);

namespace Waaseyaa\Api;

use Waaseyaa\Entity\ApiExposableEntityTypeInterface;
use Waaseyaa\Entity\EntityTypeInterface;

final class EntityTypeApiExposure
{
    public static function isExposed(EntityTypeInterface $entityType): bool
    {
        return $entityType instanceof ApiExposableEntityTypeInterface
            && $entityType->isApiExposed();
    }
}
