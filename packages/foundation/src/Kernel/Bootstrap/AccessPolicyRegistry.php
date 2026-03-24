<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Kernel\Bootstrap;

use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Foundation\Log\LoggerInterface;

final class AccessPolicyRegistry
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Discover and instantiate access policies from the manifest.
     *
     * Policies may accept their entity type list as a constructor argument
     * (e.g. ConfigEntityAccessPolicy). The reflection-based heuristic detects
     * constructors with required params and passes entity types to them.
     */
    public function discover(PackageManifest $manifest): EntityAccessHandler
    {
        $policies = [];
        foreach ($manifest->policies as $class => $entityTypes) {
            if (!class_exists($class)) {
                $this->logger->warning(sprintf(
                    'Access policy class not found: %s (covering entity types: %s). '
                    . 'Run "composer dump-autoload --optimize" to update the classmap.',
                    $class,
                    implode(', ', $entityTypes),
                ));
                continue;
            }

            try {
                $ref = new \ReflectionClass($class);
                $constructor = $ref->getConstructor();
                if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
                    $policies[] = new $class($entityTypes);
                } else {
                    $policies[] = new $class();
                }
            } catch (\Throwable $e) {
                $this->logger->error(sprintf(
                    'Failed to instantiate access policy %s: %s',
                    $class,
                    $e->getMessage(),
                ));
            }
        }

        return new EntityAccessHandler($policies);
    }
}
