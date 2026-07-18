<?php

declare(strict_types=1);

namespace Waaseyaa\Entity;

/**
 * Classification attached to a field definition for future guarded reads.
 *
 * WP1 is metadata-only: no entity accessor consults this enum yet.
 *
 * @api
 */
enum FieldReadLevel: string
{
    case Public = 'public';
    case Protected = 'protected';
    case Internal = 'internal';
}
