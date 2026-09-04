<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Generation;

/**
 * What evaluation observed at one target path (ADR-025 D-6.2).
 *
 * The backing strings are the `targets[].state` values of the
 * `waaseyaa.project_state` document.
 *
 * @api
 */
enum ObservedTargetState: string
{
    case Absent = 'absent';
    case File = 'file';

    /** A directory, symlink, or special file: refused by evaluation, still recorded as observed. */
    case Other = 'other';
}
