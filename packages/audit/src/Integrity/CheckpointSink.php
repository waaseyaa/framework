<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Integrity;

use Waaseyaa\Audit\Entity\AuditCheckpoint;

/**
 * @api
 */
interface CheckpointSink
{
    /** Export a freshly-sealed checkpoint to an (ideally off-box / append-only) destination. */
    public function export(AuditCheckpoint $checkpoint): void;
}
