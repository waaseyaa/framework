<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\ServiceProvider;

abstract class ServiceProvider implements ServiceProviderInterface
{
    protected string $projectRoot = '';

    /** @var array<string, mixed> */
    protected array $config = [];

    /** @var array<string, array{concrete: string|callable, shared: bool}> */
    private array $bindings = [];

    /** @var array<string, list<string>> */
    private array $tags = [];

    /** @var list<\Waaseyaa\Entity\EntityTypeInterface> */
    private array $entityTypes = [];

    abstract public function register(): void;

    public function boot(): void {}

    public function provides(): array
    {
        return [];
    }

    public function isDeferred(): bool
    {
        return $this->provides() !== [];
    }

    /**
     * Provide kernel context to providers before register()/boot().
     *
     * @param array<string, mixed> $config
     */
    public function setKernelContext(string $projectRoot, array $config): void
    {
        $this->projectRoot = $projectRoot;
        $this->config = $config;
    }

    protected function singleton(string $abstract, string|callable $concrete): void
    {
        $this->bindings[$abstract] = ['concrete' => $concrete, 'shared' => true];
    }

    protected function bind(string $abstract, string|callable $concrete): void
    {
        $this->bindings[$abstract] = ['concrete' => $concrete, 'shared' => false];
    }

    protected function tag(string $abstract, string $tag): void
    {
        $this->tags[$tag] ??= [];
        $this->tags[$tag][] = $abstract;
    }

    protected function entityType(\Waaseyaa\Entity\EntityTypeInterface $entityType): void
    {
        $this->entityTypes[] = $entityType;
    }

    /** @return array<string, array{concrete: string|callable, shared: bool}> */
    public function getBindings(): array
    {
        return $this->bindings;
    }

    /** @return array<string, list<string>> */
    public function getTags(): array
    {
        return $this->tags;
    }

    /** @return list<\Waaseyaa\Entity\EntityTypeInterface> */
    public function getEntityTypes(): array
    {
        return $this->entityTypes;
    }
}
