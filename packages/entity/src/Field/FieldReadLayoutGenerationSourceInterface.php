<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Field;

use Waaseyaa\Entity\EntityReadLayoutGeneration;

/** Observable invalidation source for layouts compiled from a mutable registry. @internal */
interface FieldReadLayoutGenerationSourceInterface
{
    public function fieldReadLayoutGeneration(string $entityTypeId, string $bundle): EntityReadLayoutGeneration;
}
