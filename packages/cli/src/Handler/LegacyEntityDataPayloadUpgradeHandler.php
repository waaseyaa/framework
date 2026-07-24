<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\EntityStorage\Migration\LegacyEntityDataPayloadUpgrader;

/** @api */
final readonly class LegacyEntityDataPayloadUpgradeHandler
{
    public function __construct(private LegacyEntityDataPayloadUpgrader $upgrader) {}

    public function execute(SymfonyCommandIO $io): int
    {
        $result = $this->upgrader->upgrade();
        $io->writeln(sprintf(
            'Legacy entity data payload upgrade: scanned=%d changed=%d',
            $result->scannedRows,
            $result->changedRows,
        ));
        foreach ($result->changedByTable as $table => $count) {
            $io->writeln(sprintf('  %s: %d', $table, $count));
        }

        return 0;
    }
}
