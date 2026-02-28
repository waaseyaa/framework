<?php

declare(strict_types=1);

namespace Aurora\Foundation\ServiceProvider;

abstract class ServiceProvider implements ServiceProviderInterface
{
    /** @var array<string, array{concrete: string|callable, shared: bool}> */
    private array $bindings = [];

    /** @var array<string, list<string>> */
    private array $tags = [];

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
}
