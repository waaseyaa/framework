<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Backend;

/** Closed operation vocabulary for privileged field-storage invocations. @api */
enum FieldStorageGatewayOperation: string
{
    case Read = 'read';
    case Write = 'write';
    case Delete = 'delete';
    case SupportsQuery = 'supports_query';
}
