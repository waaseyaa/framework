<?php

declare(strict_types=1);

namespace Waaseyaa\Genealogy\Access;

use Waaseyaa\Entity\EntityInterface;

/** Typed Internal-field decision used by genealogy access policy. @api */
interface GenealogyInternalFieldReaderInterface
{
    public function isTombstoned(EntityInterface $entity): bool;
}
