<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Exception;

/**
 * Thrown when two migration definitions claim the same id.
 *
 * The exception always carries the FQCN of both colliding plugin classes so
 * operators can resolve the collision without grepping the source tree.
 *
 * @api
 */
final class MigrationPluginCollisionException extends \LogicException
{
    /**
     * @param string $pluginId The colliding plugin id.
     * @param class-string|string $firstFqcn FQCN of the plugin already registered for $pluginId. Accepts a synthetic label (e.g. `Waaseyaa\Migration (reserved)`) when the "first claimant" is the framework's reserved-id surface rather than a concrete class.
     * @param class-string $secondFqcn FQCN of the plugin attempting to re-register $pluginId.
     * @param bool $reserved True when the collision is against a framework-reserved id; false for two third-party plugins colliding with each other.
     */
    public function __construct(
        public readonly string $pluginId,
        public readonly string $firstFqcn,
        public readonly string $secondFqcn,
        public readonly bool $reserved = false,
    ) {
        $message = $reserved
            ? \sprintf(
                'Plugin id %s is reserved by the framework (%s); third-party %s may not re-register it.',
                var_export($pluginId, true),
                $firstFqcn,
                $secondFqcn,
            )
            : \sprintf(
                'Plugin id %s is registered by both %s and %s.',
                var_export($pluginId, true),
                $firstFqcn,
                $secondFqcn,
            );

        parent::__construct($message);
    }
}
