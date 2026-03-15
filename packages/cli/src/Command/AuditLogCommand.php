<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Waaseyaa\Entity\Audit\EntityAuditLogger;
use Waaseyaa\Entity\EntityTypeLifecycleManager;

#[AsCommand(
    name: 'audit:log',
    description: 'Display the entity type lifecycle audit log, or entity-write audit log with --entity-type',
)]
final class AuditLogCommand extends Command
{
    public function __construct(
        private readonly EntityTypeLifecycleManager $lifecycleManager,
        private readonly ?EntityAuditLogger $entityAuditLogger = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Filter lifecycle log by entity type ID (e.g. note)', '')
            ->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Filter lifecycle log by tenant ID', '')
            ->addOption('entity-type', null, InputOption::VALUE_REQUIRED, 'Show entity-write audit log, optionally filtered by type (e.g. note)', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $entityTypeFilter = $input->getOption('entity-type');

        if ($entityTypeFilter !== null) {
            return $this->showEntityWriteLog((string) $entityTypeFilter, $output);
        }

        /** @var string $typeFilter */
        $typeFilter = $input->getOption('type') ?? '';
        /** @var string $tenantFilter */
        $tenantFilter = $input->getOption('tenant') ?? '';

        return $this->showLifecycleLog($typeFilter, $tenantFilter, $output);
    }

    private function showLifecycleLog(string $typeFilter, string $tenantFilter, OutputInterface $output): int
    {
        $tenantFilter = trim($tenantFilter);
        $entries = $this->lifecycleManager->readAuditLog($typeFilter, $tenantFilter !== '' ? $tenantFilter : null);

        if ($entries === []) {
            $output->writeln(
                $typeFilter !== ''
                ? sprintf('No audit entries found for entity type "%s".', $typeFilter)
                : 'No audit entries found.',
            );

            return self::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['Entity Type', 'Action', 'Tenant', 'Actor', 'Timestamp']);

        foreach ($entries as $entry) {
            $table->addRow([
                $entry['entity_type_id'] ?? '',
                $entry['action']         ?? '',
                $entry['tenant_id']      ?? '',
                $entry['actor_id']       ?? '',
                $entry['timestamp']      ?? '',
            ]);
        }

        $table->render();

        return self::SUCCESS;
    }

    private function showEntityWriteLog(string $entityTypeFilter, OutputInterface $output): int
    {
        if ($this->entityAuditLogger === null) {
            $output->writeln('<error>Entity audit logger is not configured.</error>');

            return self::FAILURE;
        }

        $entries = $this->entityAuditLogger->read($entityTypeFilter);

        if ($entries === []) {
            $output->writeln(
                $entityTypeFilter !== ''
                ? sprintf('No entity audit entries found for type "%s".', $entityTypeFilter)
                : 'No entity audit entries found.',
            );

            return self::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['Entity Type', 'Action', 'Entity ID', 'Tenant', 'Actor', 'Timestamp']);

        foreach ($entries as $entry) {
            $table->addRow([
                $entry['entity_type'] ?? '',
                $entry['action']      ?? '',
                $entry['entity_id']   ?? '',
                $entry['tenant_id']   ?? '',
                $entry['actor']       ?? '',
                $entry['timestamp']   ?? '',
            ]);
        }

        $table->render();

        return self::SUCCESS;
    }
}
