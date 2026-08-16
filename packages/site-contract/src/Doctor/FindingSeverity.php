<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Doctor;

/** @api */
enum FindingSeverity: string
{
    case Error = 'error';
    case Warning = 'warning';
}
