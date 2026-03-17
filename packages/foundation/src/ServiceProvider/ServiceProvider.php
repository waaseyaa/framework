<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\ServiceProvider;

abstract class ServiceProvider implements ServiceProviderInterface
{
    protected string $projectRoot = '';

    /** @var array<string, mixed> */
    protected array $config = [];

    /** @var array<string, class-string> */
    protected array $manifestFormatters = [];

    /** @var array<string, array{concrete: string|callable, shared: bool}> */
    private array $bindings = [];

    /** @var array<string, mixed> */
    private array $resolved = [];

    /** @var array<string, list<string>> */
    private array $tags = [];

    /** @var list<\Waaseyaa\Entity\EntityTypeInterface> */
    private array $entityTypes = [];

    abstract public function register(): void;

    public function boot(): void {}

    public function routes(\Waaseyaa\Routing\WaaseyaaRouter $router): void {}

    /**
     * Return plugin CLI commands to register with the console application.
     *
     * @return list<\Symfony\Component\Console\Command\Command>
     */
    public function commands(
        \Waaseyaa\Entity\EntityTypeManager $entityTypeManager,
        \Waaseyaa\Database\PdoDatabase $database,
        \Symfony\Contracts\EventDispatcher\EventDispatcherInterface $dispatcher,
    ): array {
        return [];
    }

    /**
     * Return HTTP middleware instances to register with the kernel pipeline.
     *
     * Use #[AsMiddleware] on each class to set pipeline and priority.
     *
     * @return list<\Waaseyaa\Foundation\Middleware\HttpMiddlewareInterface>
     */
    public function middleware(): array
    {
        return [];
    }

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
     * @param array<string, class-string> $manifestFormatters
     */
    public function setKernelContext(string $projectRoot, array $config, array $manifestFormatters = []): void
    {
        $this->projectRoot = $projectRoot;
        $this->config = $config;
        $this->manifestFormatters = $manifestFormatters;
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

    /**
     * Resolve a binding registered via singleton() or bind().
     */
    public function resolve(string $abstract): mixed
    {
        if (isset($this->resolved[$abstract])) {
            return $this->resolved[$abstract];
        }

        if (!isset($this->bindings[$abstract])) {
            throw new \RuntimeException("No binding registered for {$abstract}.");
        }

        $binding = $this->bindings[$abstract];
        $concrete = $binding['concrete'];

        $instance = is_callable($concrete) ? $concrete() : new $concrete();

        if ($binding['shared']) {
            $this->resolved[$abstract] = $instance;
        }

        return $instance;
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
