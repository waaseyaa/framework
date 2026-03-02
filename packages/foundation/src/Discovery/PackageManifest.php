<?php
declare(strict_types=1);
namespace Waaseyaa\Foundation\Discovery;

final class PackageManifest
{
    public function __construct(
        /** @var string[] */ public readonly array $providers = [],
        /** @var string[] */ public readonly array $commands = [],
        /** @var string[] */ public readonly array $routes = [],
        /** @var array<string, string> */ public readonly array $migrations = [],
        /** @var array<string, string> */ public readonly array $fieldTypes = [],
        /** @var array<string, list<array{class: string, priority: int}>> */ public readonly array $listeners = [],
        /** @var array<string, list<array{class: string, priority: int}>> */ public readonly array $middleware = [],
    ) {}

    /**
     * Create from a cached array (loaded from storage/framework/packages.php).
     */
    public static function fromArray(array $data): self
    {
        return new self(
            providers: $data['providers'] ?? [],
            commands: $data['commands'] ?? [],
            routes: $data['routes'] ?? [],
            migrations: $data['migrations'] ?? [],
            fieldTypes: $data['field_types'] ?? [],
            listeners: $data['listeners'] ?? [],
            middleware: $data['middleware'] ?? [],
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
            'listeners' => $this->listeners,
            'middleware' => $this->middleware,
        ];
    }
}
