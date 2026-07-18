<?php

declare(strict_types=1);

namespace Waaseyaa\Entity;

use Waaseyaa\Entity\Hydration\HydratableFromStorageInterface;
use Waaseyaa\Entity\Hydration\HydrationContext;

/**
 * Abstract base class for configuration entities.
 *
 * Config entities represent site configuration that can be exported,
 * imported, and synchronized (e.g. content types, vocabularies, views).
 * Unlike content entities, config entities use a string machine name as
 * their primary key and do not have a UUID.
 *
 * @phpstan-consistent-constructor
 */
abstract class ConfigEntityBase extends EntityBase implements ConfigEntityInterface, HydratableFromStorageInterface
{
    /**
     * Whether this config entity is enabled.
     */
    protected bool $status = true;

    /**
     * Dependencies for this config entity.
     *
     * @var array<string, string[]>
     */
    protected array $dependencies = [];

    /**
     * @param array<string, mixed> $values Initial entity values.
     * @param string $entityTypeId The entity type machine name.
     * @param array<string, string> $entityKeys Entity key mappings.
     */
    public function __construct(
        array $values = [],
        string $entityTypeId = '',
        array $entityKeys = [],
    ) {
        // Extract status from values if provided.
        if (\array_key_exists('status', $values)) {
            $this->status = (bool) $values['status'];
        }

        // Extract dependencies from values if provided.
        if (isset($values['dependencies']) && \is_array($values['dependencies'])) {
            $this->dependencies = $values['dependencies'];
        }

        parent::__construct($values, $entityTypeId, $entityKeys);
    }

    public function status(): bool
    {
        return $this->status;
    }

    public function enable(): static
    {
        $this->status = true;
        $this->set('status', true);

        return $this;
    }

    public function disable(): static
    {
        $this->status = false;
        $this->set('status', false);

        return $this;
    }

    /**
     * @return array<string, string[]> Keyed by dependency type ('package', 'config', 'content').
     */
    public function getDependencies(): array
    {
        return $this->dependencies;
    }

    /**
     * Set the dependencies for this config entity.
     *
     * @param array<string, string[]> $dependencies
     */
    public function setDependencies(array $dependencies): static
    {
        $this->dependencies = $dependencies;
        $this->set('dependencies', $dependencies);

        return $this;
    }

    /**
     * Returns an array suitable for YAML serialization / config export.
     */
    public function toConfig(): array
    {
        $config = $this->toArray();

        // Ensure status and dependencies are always present in config output.
        $config['status'] = $this->status;

        if ($this->dependencies !== []) {
            $config['dependencies'] = $this->dependencies;
        }

        return $config;
    }

    public static function fromStorage(array $values, HydrationContext $context): static
    {
        return new static(
            values: $values,
            entityTypeId: $context->entityTypeId,
            entityKeys: $context->entityKeys,
        );
    }
}
