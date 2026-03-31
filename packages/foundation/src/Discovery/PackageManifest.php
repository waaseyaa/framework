<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Discovery;

final class PackageManifest
{
    public function __construct(
        /** @var string[] */
        public readonly array $providers = [],
        /** @var string[] */
        public readonly array $commands = [],
        /** @var string[] */
        public readonly array $routes = [],
        /** @var array<string, string> */
        public readonly array $migrations = [],
        /** @var array<string, string> */
        public readonly array $fieldTypes = [],
        /** @var array<string, class-string> */
        public readonly array $formatters = [],
        /** @var array<string, list<array{class: string, priority: int}>> */
        public readonly array $listeners = [],
        /** @var array<string, list<array{class: string, priority: int}>> */
        public readonly array $middleware = [],
        /** @var array<string, array{title: string, description?: string}> */
        public readonly array $permissions = [],
        /** @var array<class-string, string[]> */
        public readonly array $policies = [],
        /** @var array<string, array{surface: 'aggregate'|'implementation'|'tooling', activation: 'discovery'|'none'|'provider'}> */
        public readonly array $packageDeclarations = [],
    ) {}

    /**
     * Create from a cached array (loaded from storage/framework/packages.php).
     *
     * @throws \InvalidArgumentException If required keys are missing or have wrong types
     */
    public static function fromArray(array $data): self
    {
        $requiredKeys = ['providers', 'commands', 'routes', 'migrations', 'field_types', 'listeners', 'middleware'];
        $optionalKeys = ['permissions', 'policies', 'formatters', 'package_declarations'];
        $missing = array_diff($requiredKeys, array_keys($data));

        if ($missing !== []) {
            throw new \InvalidArgumentException(sprintf(
                'PackageManifest cache is missing required keys: %s',
                implode(', ', $missing),
            ));
        }

        foreach ([...$requiredKeys, ...$optionalKeys] as $key) {
            if (isset($data[$key]) && !is_array($data[$key])) {
                throw new \InvalidArgumentException(sprintf(
                    'PackageManifest cache key "%s" must be an array, got %s',
                    $key,
                    get_debug_type($data[$key]),
                ));
            }
        }

        return new self(
            providers: $data['providers'],
            commands: $data['commands'],
            routes: $data['routes'],
            migrations: $data['migrations'],
            fieldTypes: $data['field_types'],
            formatters: $data['formatters'] ?? [],
            listeners: $data['listeners'],
            middleware: $data['middleware'],
            permissions: $data['permissions'] ?? [],
            policies: $data['policies'] ?? [],
            packageDeclarations: $data['package_declarations'] ?? [],
        );
    }

    /**
     * Export to a cacheable array.
     */
    public function toArray(): array
    {
        return [
            'providers' => $this->providers,
            'commands' => $this->commands,
            'routes' => $this->routes,
            'migrations' => $this->migrations,
            'field_types' => $this->fieldTypes,
            'formatters' => $this->formatters,
            'listeners' => $this->listeners,
            'middleware' => $this->middleware,
            'permissions' => $this->permissions,
            'policies' => $this->policies,
            'package_declarations' => $this->packageDeclarations,
        ];
    }
}
