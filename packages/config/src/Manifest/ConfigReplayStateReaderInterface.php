<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Manifest;

/** Read side of replay state; committing sequence belongs to CFG-02 activation. @api */
interface ConfigReplayStateReaderInterface
{
    public function lastCommittedSequence(string $bundleScope, string $trustKeyReference): ?int;
}
