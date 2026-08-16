<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Drift;

/** Supplies one transactionally consistent CFG-02 read snapshot. @api */
interface ConfigDriftSnapshotReaderInterface
{
    public function readSnapshot(): ConfigDriftSnapshot;
}
