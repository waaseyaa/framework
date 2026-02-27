<?php

declare(strict_types=1);

namespace Aurora\Entity;

interface ConfigEntityInterface extends EntityInterface
{
    public function status(): bool;

    public function enable(): static;

    public function disable(): static;

    /**
     * @return array<string, string[]> Keyed by dependency type ('package', 'config', 'content')
     */
    public function getDependencies(): array;

    public function toConfig(): array;
}
