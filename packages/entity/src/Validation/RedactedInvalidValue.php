<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Validation;

/** Explicit non-value sentinel used in violations for non-Public fields. @internal */
enum RedactedInvalidValue
{
    case Value;
}
