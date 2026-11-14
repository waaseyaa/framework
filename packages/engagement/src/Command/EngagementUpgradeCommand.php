<?php

declare(strict_types=1);

namespace Waaseyaa\Engagement\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Engagement\EngagementMutationScope;
use Waaseyaa\Engagement\Migration\EngagementDataUpgrader;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\EntityStorage\EntitySchemaSync;

/** Explicit bounded data/schema upgrade for engagement after db:init. */
#[AsCommand(name: 'engagement:upgrade', description: 'Upgrade engagement schemas and migrate legacy rows in bounded batches.')]
final class EngagementUpgradeCommand extends Command
{
    public function __construct(
        private DBALDatabase $database,
        private EntityTypeManagerInterface $entityTypes,
        private EngagementMutationScope $mutationScope,
    ) {
        parent::__construct('engagement:upgrade');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            // Use the canonical entity schema authority for both SQL layouts.
            // This is an explicit CLI action, never request-path DDL.
            new EntitySchemaSync($this->database)->syncAll([
                $this->entityTypes->getDefinition('comment'),
                $this->entityTypes->getDefinition('reaction'),
            ]);

            if (!EngagementDataUpgrader::schemaReady($this->database)) {
                $output->writeln('<error>Engagement auxiliary schema is missing. Apply package migrations first.</error>');
                return 1;
            }

            $upgrader = new EngagementDataUpgrader($this->database, $this->entityTypes, $this->mutationScope);
            $totals = ['scanned' => 0, 'migrated' => 0, 'quarantined' => 0, 'duplicate_likes_removed' => 0];
            do {
                $batch = $upgrader->runBatch(200);
                $output->writeln(json_encode(['event' => 'engagement-upgrade-batch', 'scanned' => $batch['scanned'],
                    'migrated' => $batch['migrated'], 'quarantined' => $batch['quarantined'],
                    'duplicate_likes_removed' => $batch['duplicate_likes_removed'], 'reasons' => $batch['reasons'], 'ids' => $batch['ids']], JSON_THROW_ON_ERROR));
                foreach ($totals as $name => $_) {
                    $totals[$name] += $batch[$name];
                }
            } while (!$batch['done']);

            $output->writeln(sprintf(
                'Engagement upgrade complete: scanned=%d migrated=%d quarantined=%d duplicate_likes_removed=%d',
                $totals['scanned'],
                $totals['migrated'],
                $totals['quarantined'],
                $totals['duplicate_likes_removed'],
            ));
            return 0;
        } catch (\Throwable) {
            $output->writeln('<error>Engagement upgrade failed.</error>');
            return 1;
        }
    }
}
