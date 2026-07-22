<?php

declare(strict_types=1);

namespace Waaseyaa\Api;

use Waaseyaa\Entity\ApiExposableEntityTypeInterface;
use Waaseyaa\Entity\EntityTypeInterface;

final class EntityTypeApiExposure
{
    public static function isExposed(
        EntityTypeInterface $entityType,
        ?EntityTypeApiExposurePolicy $policy = null,
    ): bool {
        if ($policy !== null) {
            return $policy->isExposed($entityType);
        }

        return $entityType instanceof ApiExposableEntityTypeInterface
            && $entityType->isApiExposed();
    }
}
