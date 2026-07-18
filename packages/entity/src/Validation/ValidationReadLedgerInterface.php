<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Validation;

use Waaseyaa\Entity\EntityStructure;

/** Audit adapter seam; implementations reserve before the closed reader obtains a value. @internal */
interface ValidationReadLedgerInterface
{
    public function reserve(EntityStructure $subject, string $field): ValidationReadReservationInterface;
}
