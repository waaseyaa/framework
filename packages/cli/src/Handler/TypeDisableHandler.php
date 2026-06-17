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
final class TypeDisableHandler
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
        $force = (bool) $io->option('force');
        $skipConfirm = (bool) $io->option('yes');

        if (!$this->entityTypeManager->hasDefinition($typeId)) {
            $io->error(sprintf('Unknown entity type: "%s"', $rawTypeId));

            return 1;
        }

        if ($this->lifecycleManager->isDisabled($typeId, $tenantId)) {
            $io->writeln(sprintf('Entity type "%s" is already disabled.', $rawTypeId));

            return 0;
        }

        // Guard: refuse to disable the last enabled type.
        $definitions = $this->entityTypeManager->getDefinitions();
        $disabledIds = $this->lifecycleManager->getDisabledTypeIds($tenantId);
        $enabledCount = count(array_filter(
            array_keys($definitions),
            static fn(string $id): bool => $id !== $typeId && !in_array($id, $disabledIds, true),
        ));

        if ($enabledCount === 0) {
            if (!$force) {
                $io->error(
                    '[DEFAULT_TYPE_DISABLED] Cannot disable the last enabled content type. '
                    . 'Register or enable another type first, or re-run with --force.',
                );

                return 1;
            }

            $io->writeln(
                '[DEFAULT_TYPE_DISABLED] Disabling the last enabled type for this tenant.',
            );
        }

        if (!$skipConfirm && $io->isInteractive()) {
            if (!$io->confirm(
                sprintf(
                    'Disable "%s"%s? (y/N) ',
                    $rawTypeId,
                    $tenantId !== null ? ' for tenant "' . $tenantId . '"' : '',
                ),
                false,
            )) {
                $io->writeln('Aborted.');

                return 0;
            }
        }

        $this->lifecycleManager->disable($typeId, $actor, $tenantId);

        $io->writeln(sprintf('Disabled entity type "%s". Audit entry recorded.', $rawTypeId));

        return 0;
    }
}
