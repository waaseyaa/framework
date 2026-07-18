<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Envelope;

/** @api */
enum QueueSystemReason: string
{
    case SystemJob = 'system_job';
    case Scheduler = 'scheduler';
    case RetryReplay = 'retry_replay';
}
