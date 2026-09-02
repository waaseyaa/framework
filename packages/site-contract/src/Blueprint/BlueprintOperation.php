<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Blueprint;

/**
 * The closed set of entity operations a blueprint policy may govern (#2785).
 *
 * @api
 */
enum BlueprintOperation: string
{
    case View = 'view';
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';
}
