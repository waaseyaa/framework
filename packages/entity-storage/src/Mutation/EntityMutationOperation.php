<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Mutation;

/** Operation being checked at the entity persistence boundary. */
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
