<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Mutation;

/**
 * Operation vocabulary at the entity persistence boundary.
 *
 * @api Unsupported guarded paths refuse before invariant dispatch.
 */
enum EntityMutationOperation: string
{
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';
    case Revision = 'revision';
    case Translation = 'translation';
    case Publication = 'publication';
    case Maintenance = 'maintenance';
}
