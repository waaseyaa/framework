<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Entity\Audit\EntityAuditLogger;
use Waaseyaa\Entity\EntityTypeIdNormalizer;
use Waaseyaa\Entity\EntityTypeLifecycleManager;
use Waaseyaa\Entity\EntityTypeManagerInterface;

/**
 * @api
 */
final class MigrateDefaultsHandler
{
    private const MIGRATION_LOG = '/storage/framework/migrate-defaults.jsonl';

    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly EntityTypeLifecycleManager $lifecycleManager,
        private readonly ?EntityAuditLogger $entityAuditLogger,
        private readonly string $projectRoot,
        private readonly ?EntityTypeIdNormalizer $typeIdNormalizer = null,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        /** @var string[] $tenants */
        $tenants = array_map('trim', (array) ($io->option('tenant') ?? []));
        $tenants = array_values(array_filter($tenants, static fn(string $id): bool => $id !== ''));
        $actor = (string) ($io->option('actor') ?? 'cli');
        $skipConfirm = (bool) $io->option('yes');
        $dryRun = (bool) $io->option('dry-run');
        $rollback = (bool) $io->option('rollback');

        if ($tenants === []) {
            $tenants = $this->discoverTenants();
        }

        if ($tenants === []) {
            $io->writeln('No tenants discovered. Pass --tenant to migrate specific tenants.');
            return 0;
        }

        if ($rollback) {
            return $this->rollback($tenants, $actor, $skipConfirm, $dryRun, $io);
        }

        $rawEnableType = (string) ($io->option('enable') ?? '');
        $enableType = $rawEnableType !== '' && $this->typeIdNormalizer !== null
            ? $this->typeIdNormalizer->normalize($rawEnableType)
            : $rawEnableType;
        if ($enableType !== '' && !$this->entityTypeManager->hasDefinition($enableType)) {
            $io->error(sprintf('Unknown entity type: "%s"', $rawEnableType));
            return 1;
        }

        $definitions = array_keys($this->entityTypeManager->getDefinitions());
        if ($definitions === []) {
            $io->error('No registered entity types available for migration.');
            return 1;
        }

        $missing = $this->tenantsWithNoEnabledTypes($tenants, $definitions);
        if ($missing === []) {
            $io->writeln('All tenants already have at least one enabled type.');
            return 0;
        }

        foreach ($missing as $tenantId) {
            $selected = $enableType;

            if ($selected === '') {
                if (!$skipConfirm && $io->isInteractive()) {
                    $choices = $definitions;
                    $choices[] = 'skip';
                    $default = in_array('note', $definitions, true) ? 'note' : $choices[0];
                    $selected = (string) $io->ask(
                        sprintf('Tenant "%s" has no enabled types. Enable which type? [%s]: ', $tenantId, $default),
                        $default,
                    );
                } else {
                    $selected = in_array('note', $definitions, true) ? 'note' : '';
                }
            }

            if ($selected === '' || $selected === 'skip') {
                $io->writeln(sprintf('Skipped tenant "%s".', $tenantId));
                continue;
            }

            if (!$skipConfirm && $io->isInteractive()) {
                if (!$io->confirm(sprintf('Enable "%s" for tenant "%s"? (y/N) ', $selected, $tenantId), false)) {
                    $io->writeln(sprintf('Skipped tenant "%s".', $tenantId));
                    continue;
                }
            }

            if ($dryRun) {
                $io->writeln(sprintf('[dry-run] Would enable "%s" for tenant "%s".', $selected, $tenantId));
                continue;
            }

            $this->lifecycleManager->enable($selected, $actor, $tenantId);
            $this->appendMigrationLog($tenantId, $selected, $actor, 'enabled');
            $io->writeln(sprintf('Enabled "%s" for tenant "%s".', $selected, $tenantId));
        }

        return 0;
    }

    /**
     * @param string[] $tenants
     */
    private function rollback(
        array $tenants,
        string $actor,
        bool $skipConfirm,
        bool $dryRun,
        SymfonyCommandIO $io,
    ): int {
        $entries = $this->readMigrationLog();
        if ($entries === []) {
            $io->writeln('No migrate:defaults log entries found.');
            return 0;
        }

        $targets = [];
        foreach ($entries as $entry) {
            if (($entry['action'] ?? '') !== 'enabled') {
                continue;
            }
            $tenantId = (string) ($entry['tenant_id'] ?? '');
            $typeId = (string) ($entry['type_id'] ?? '');
            if ($tenantId === '' || $typeId === '') {
                continue;
            }
            if (!in_array($tenantId, $tenants, true)) {
                continue;
            }
            $targets[] = ['tenant' => $tenantId, 'type' => $typeId];
        }

        if ($targets === []) {
            $io->writeln('No matching migration entries found for rollback.');
            return 0;
        }

        if (!$skipConfirm && $io->isInteractive()) {
            if (!$io->confirm('Rollback migrate:defaults changes for selected tenants? (y/N) ', false)) {
                $io->writeln('Rollback aborted.');
                return 0;
            }
        }

        foreach ($targets as $target) {
            $tenantId = $target['tenant'];
            $typeId = $target['type'];
            if ($dryRun) {
                $io->writeln(sprintf('[dry-run] Would disable "%s" for tenant "%s".', $typeId, $tenantId));
                continue;
            }

            $this->lifecycleManager->disable($typeId, $actor, $tenantId);
            $this->appendMigrationLog($tenantId, $typeId, $actor, 'rollback');
            $io->writeln(sprintf('Disabled "%s" for tenant "%s".', $typeId, $tenantId));
        }

        return 0;
    }

    /**
     * @return string[]
     */
    private function discoverTenants(): array
    {
        $tenants = $this->lifecycleManager->getTenantIds();

        if ($this->entityAuditLogger !== null) {
            foreach ($this->entityAuditLogger->read() as $entry) {
                $tenantId = trim((string) ($entry['tenant_id'] ?? ''));
                if ($tenantId !== '') {
                    $tenants[] = $tenantId;
                }
            }
        }

        $tenants = array_values(array_unique($tenants));
        sort($tenants);

        return $tenants;
    }

    /**
     * @param string[] $tenants
     * @param string[] $definitions
     * @return string[]
     */
    private function tenantsWithNoEnabledTypes(array $tenants, array $definitions): array
    {
        $missing = [];

        foreach ($tenants as $tenantId) {
            $disabled = $this->lifecycleManager->getDisabledTypeIds($tenantId);
            $enabled = array_filter(
                $definitions,
                static fn(string $id): bool => !in_array($id, $disabled, true),
            );

            if ($enabled === []) {
                $missing[] = $tenantId;
            }
        }

        return $missing;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readMigrationLog(): array
    {
        $file = $this->migrationLogFile();
        if (!file_exists($file)) {
            return [];
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }

        $entries = [];
        foreach ($lines as $line) {
            try {
                /** @var array<string, mixed> $entry */
                $entry = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                $entries[] = $entry;
            } catch (\JsonException) {
                // skip malformed lines
            }
        }

        return $entries;
    }

    private function appendMigrationLog(string $tenantId, string $typeId, string $actor, string $action): void
    {
        $file = $this->migrationLogFile();
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        $payload = json_encode([
            'tenant_id' => $tenantId,
            'type_id' => $typeId,
            'actor_id' => $actor,
            'action' => $action,
            'timestamp' => new \DateTimeImmutable()->format(\DateTimeInterface::ATOM),
        ], JSON_THROW_ON_ERROR);

        file_put_contents($file, $payload . "\n", FILE_APPEND | LOCK_EX);
    }

    private function migrationLogFile(): string
    {
        return $this->projectRoot . self::MIGRATION_LOG;
    }
}
