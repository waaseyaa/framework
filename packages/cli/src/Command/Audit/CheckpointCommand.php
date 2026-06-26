<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command\Audit;

use Waaseyaa\Audit\Integrity\AuditCheckpointBuilder;
use Waaseyaa\CLI\Command\SymfonyCommandIO;

/**
 * `bin/waaseyaa audit:checkpoint`
 *
 * Seals the next batch of unsealed audit_event rows into a tamper-evidence
 * checkpoint and exports it via the configured CheckpointSink.
 *
 * When there are no unsealed events the command exits 0 with an informational
 * message. Operators typically run this on a schedule (e.g. every 15 minutes)
 * via AuditCheckpointScheduleEntries.
 *
 * @api
 */
final class CheckpointCommand
{
    public function __construct(
        private readonly AuditCheckpointBuilder $builder,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        $checkpoint = $this->builder->build();

        if ($checkpoint === null) {
            $io->writeln('No unsealed audit events — nothing to do.');

            return 0;
        }

        $io->writeln(sprintf(
            'Sealed audit checkpoint %s: events %d–%d (%d rows).',
            $checkpoint->getUuid(),
            $checkpoint->getSegmentStartId(),
            $checkpoint->getSegmentEndId(),
            $checkpoint->getRowCount(),
        ));

        return 0;
    }
}
