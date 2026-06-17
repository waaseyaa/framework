<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Entity\Audit\EntityAuditLogger;
use Waaseyaa\Entity\EntityTypeLifecycleManager;

/**
 * @api
 */
final class AuditLogHandler
{
    public function __construct(
        private readonly EntityTypeLifecycleManager $lifecycleManager,
        private readonly ?EntityAuditLogger $entityAuditLogger = null,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        $entityTypeFilter = $io->option('entity-type');

        if ($entityTypeFilter !== null) {
            return $this->showEntityWriteLog((string) $entityTypeFilter, $io);
        }

        $typeFilter = (string) ($io->option('type') ?? '');
        $tenantFilter = (string) ($io->option('tenant') ?? '');

        return $this->showLifecycleLog($typeFilter, $tenantFilter, $io);
    }

    private function showLifecycleLog(string $typeFilter, string $tenantFilter, SymfonyCommandIO $io): int
    {
        $tenantFilter = trim($tenantFilter);
        $entries = $this->lifecycleManager->readAuditLog($typeFilter, $tenantFilter !== '' ? $tenantFilter : null);

        if ($entries === []) {
            $io->writeln(
                $typeFilter !== ''
                    ? sprintf('No audit entries found for entity type "%s".', $typeFilter)
                    : 'No audit entries found.',
            );

            return 0;
        }

        $io->writeln(sprintf('%-20s %-12s %-20s %-20s %s', 'Entity Type', 'Action', 'Tenant', 'Actor', 'Timestamp'));
        $io->writeln(str_repeat('-', 90));

        foreach ($entries as $entry) {
            /** @var array{entity_type_id: string, action: string, actor_id: string, timestamp: string, tenant_id?: string} $entry */
            $io->writeln(sprintf(
                '%-20s %-12s %-20s %-20s %s',
                $entry['entity_type_id'],
                $entry['action'],
                $entry['tenant_id'] ?? '',
                $entry['actor_id'],
                $entry['timestamp'],
            ));
        }

        return 0;
    }

    private function showEntityWriteLog(string $entityTypeFilter, SymfonyCommandIO $io): int
    {
        if ($this->entityAuditLogger === null) {
            $io->error('Entity audit logger is not configured.');

            return 1;
        }

        $entries = $this->entityAuditLogger->read($entityTypeFilter);

        if ($entries === []) {
            $io->writeln(
                $entityTypeFilter !== ''
                    ? sprintf('No entity audit entries found for type "%s".', $entityTypeFilter)
                    : 'No entity audit entries found.',
            );

            return 0;
        }

        $io->writeln(sprintf('%-20s %-12s %-20s %-20s %-20s %s', 'Entity Type', 'Action', 'Entity ID', 'Tenant', 'Actor', 'Timestamp'));
        $io->writeln(str_repeat('-', 110));

        foreach ($entries as $entry) {
            $io->writeln(sprintf(
                '%-20s %-12s %-20s %-20s %-20s %s',
                $entry['entity_type'] ?? '',
                $entry['action']      ?? '',
                $entry['entity_id']   ?? '',
                $entry['tenant_id']   ?? '',
                $entry['actor']       ?? '',
                $entry['timestamp']   ?? '',
            ));
        }

        return 0;
    }
}
