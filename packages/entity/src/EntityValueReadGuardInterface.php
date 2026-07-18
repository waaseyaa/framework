<?php

declare(strict_types=1);

namespace Waaseyaa\Entity;

/**
 * Closed ordinary-read decision seam for a sealed entity view.
 *
 * Implementations receive no value and cannot release Internal fields.
 *
 * @internal Installed by the field-read composition root.
 */
interface EntityValueReadGuardInterface
{
    public function assertProtectedReadable(EntityBase $entity, string $field, object $viewIdentity): void;

    public function invalidate(EntityBase $entity): void;
}
