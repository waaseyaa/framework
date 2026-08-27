<?php

declare(strict_types=1);

namespace Waaseyaa\StructuredImport\Xlsx;

/** @api */
enum XlsxCellType: string
{
    case SharedString = 'shared_string';
    case InlineString = 'inline_string';
    case String = 'string';
    case Number = 'number';
    case Boolean = 'boolean';
    case Date = 'date';
    case Error = 'error';
    case Blank = 'blank';
}
