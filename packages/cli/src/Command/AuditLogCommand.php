<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Waaseyaa\Entity\EntityTypeLifecycleManager;

#[AsCommand(
    name: 'audit:log',
    description: 'Display the entity type lifecycle audit log',
)]
final class AuditLogCommand extends Command
{
    public function __construct(
        private readonly EntityTypeLifecycleManager $lifecycleManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('type', null, InputOption::VALUE_REQUIRED, 'Filter by entity type ID (e.g. note)', '');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $typeFilter */
        $typeFilter = $input->getOption('type') ?? '';

        $entries = $this->lifecycleManager->readAuditLog($typeFilter);

        if ($entries === []) {
            $output->writeln($typeFilter !== ''
                ? sprintf('No audit entries found for entity type "%s".', $typeFilter)
                : 'No audit entries found.',
            );

            return self::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['Entity Type', 'Action', 'Actor', 'Timestamp']);

        foreach ($entries as $entry) {
            $table->addRow([
                $entry['entity_type_id'] ?? '',
                $entry['action']         ?? '',
                $entry['actor_id']       ?? '',
                $entry['timestamp']      ?? '',
            ]);
        }

        $table->render();

        return self::SUCCESS;
    }
}
