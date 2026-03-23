<?php

declare(strict_types=1);

namespace Waaseyaa\Field;

use Waaseyaa\Entity\EntityInterface;

interface ComputedFieldInterface
{
    public function compute(EntityInterface $entity): mixed;
}
