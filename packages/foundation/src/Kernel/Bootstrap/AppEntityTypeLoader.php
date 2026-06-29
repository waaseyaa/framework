<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Kernel\Bootstrap;

use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Kernel\ConfigLoader;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

final class AppEntityTypeLoader
{
    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function load(string $projectRoot, EntityTypeManager $entityTypeManager): void
    {
        $path = $projectRoot . '/config/entity-types.php';
        $types = ConfigLoader::load($path, $this->logger);

        foreach ($types as $index => $typeData) {
            if (!$typeData instanceof \Waaseyaa\Entity\EntityTypeInterface) {
                $this->logger->warning(sprintf(
                    'config/entity-types.php item at index %s is not an EntityTypeInterface (got %s).',
                    $index,
                    get_debug_type($typeData),
                ));
                continue;
            }

            try {
                $entityTypeManager->registerEntityType($typeData);
            } catch (\RuntimeException | \InvalidArgumentException $e) {
                $this->logger->error(sprintf(
                    'Failed to register app entity type "%s": %s',
                    $typeData->id(),
                    $e->getMessage(),
                ));
            }
        }
    }
}
