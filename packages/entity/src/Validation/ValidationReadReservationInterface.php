<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Validation;

/** Exact reservation finalized around one closed non-Public validation read. @internal */
interface ValidationReadReservationInterface
{
    public function finalize(bool $success): void;
}
