<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\List;

/** Closed, framework-owned list cell formatter identifiers. @api */
enum ListFormatter: string
{
    case TEXT = 'text';
    case DATE = 'date';
    case DATETIME = 'datetime';
    case BOOLEAN_STATUS = 'boolean/status';
    case ENUM = 'enum';
}
