<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Entity\EntityTypeIdNormalizer;
use Waaseyaa\Entity\EntityTypeLifecycleManager;
use Waaseyaa\Entity\EntityTypeManagerInterface;

/**
 * @api
 */
final class TypeEnableHandler
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly EntityTypeLifecycleManager $lifecycleManager,
        private readonly ?EntityTypeIdNormalizer $typeIdNormalizer = null,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        /** @var string $rawTypeId */
        $rawTypeId = $io->argument('type');
        $typeId = $this->typeIdNormalizer !== null ? $this->typeIdNormalizer->normalize($rawTypeId) : $rawTypeId;
        /** @var string $actor */
        $actor = (string) ($io->option('actor') ?? 'cli');
        /** @var string $tenantRaw */
        $tenantRaw = (string) ($io->option('tenant') ?? '');
        $tenantId = trim($tenantRaw) !== '' ? trim($tenantRaw) : null;

        if (!$this->entityTypeManager->hasDefinition($typeId)) {
            $io->error(sprintf('Unknown entity type: "%s"', $rawTypeId));

            return 1;
        }

        if (!$this->lifecycleManager->isDisabled($typeId, $tenantId)) {
            $io->writeln(sprintf('Entity type "%s" is already enabled.', $rawTypeId));

            return 0;
        }

        $this->lifecycleManager->enable($typeId, $actor, $tenantId);

        $io->writeln(sprintf('Enabled entity type "%s". Audit entry recorded.', $rawTypeId));

        return 0;
    }
}
