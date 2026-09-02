<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Blueprint;

/**
 * The closed on-delete behaviour a blueprint relationship may declare (#2785).
 *
 * @api
 */
enum BlueprintOnDelete: string
{
    case Restrict = 'restrict';
    case Nullify = 'nullify';
}
