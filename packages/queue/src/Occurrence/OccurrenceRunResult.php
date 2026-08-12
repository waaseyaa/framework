<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Occurrence;

enum OccurrenceRunResult: string
{
    case Executed = 'executed';
    case Duplicate = 'duplicate';
    case Contended = 'contended';
}
