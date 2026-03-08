<?php

declare(strict_types=1);

namespace Waaseyaa\Access;

enum AccessStatus: string
{
    case ALLOWED = 'allowed';
    case NEUTRAL = 'neutral';
    case FORBIDDEN = 'forbidden';
    case UNAUTHENTICATED = 'unauthenticated';
}
