<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Discovery;

final class PackageManifest
{
    public function __construct(
        /** @var string[] */
        public readonly array $providers = [],
        /**
         * Per-package `extra.waaseyaa.migrations` declaration (per spec §15 Q9 / WP11).
         *
         * Each value is EITHER a single legacy directory path string OR
         * an ordered list of mixed FQCN namespace roots and path strings:
         *
         * - string `"migrations"` — single legacy directory path.
         * - `["Vendor\\Pkg\\Migrations\\v2", "../patches/v2"]` — ordered list.
         *
         * `MigrationLoader` walks the list in order and concatenates the
         * results; `MigrationGraph` (WP06) handles cross-entry dependency
         * edges. Per Q9, the string form is supported indefinitely — no
         * `@deprecated` notice without an ADR.
         *
         * @var array<string, string|list<string>>
         */
        public readonly array $migrations = [],
        /** @var array<string, string> */
        public readonly array $fieldTypes = [],
        /** @var array<string, class-string> */
        public readonly array $formatters = [],
        /** @var array<string, list<array{class: string, priority: int}>> */
        public readonly array $middleware = [],
        /** @var array<string, array{title: string, description?: string}> */
        public readonly array $permissions = [],
        /** @var array<class-string, string[]> */
        public readonly array $policies = [],
        /** @var array<string, array{surface: 'aggregate'|'implementation'|'tooling', activation: 'discovery'|'none'|'provider'}> */
        public readonly array $packageDeclarations = [],
        /** @var list<class-string> Classes carrying #[AsEntityType] that also implement DefinesEntityType */
        public readonly array $attributeEntityTypes = [],
        /** @var list<class-string> Provider classes that implement ProvidesConsoleCommandsInterface */
        public readonly array $consoleCommandProviders = [],
        /**
         * Classes carrying `#[Waaseyaa\AI\Tools\Attribute\AsAgentTool]`.
         *
         * Each entry records the attribute payload + the class FQCN so
         * {@see \Waaseyaa\AI\Tools\Catalogue\AttributeToolRegistry} can
         * lazily instantiate the tool via the container without
         * re-reflecting at runtime.
         *
         * @var list<array{class: class-string, name: string, capability: string, destructive: bool, dry_run_supported: bool, category: string}>
         */
        public readonly array $agentTools = [],
        /**
         * Classes carrying `#[Waaseyaa\AI\Agent\Attribute\AsAgentDefinition]`.
         *
         * Each entry records the attribute payload + the class FQCN so
         * {@see \Waaseyaa\AI\Agent\AgentDefinitionRegistry} can build a
         * static catalogue without re-reflecting at runtime.
         *
         * @var list<array{class: class-string, id: string, label: string, description: string, prompt: string, system: string, tools: list<string>, model: string, max_iterations: int, destructive_default: string|null, requires_capability: string|null}>
         */
        public readonly array $agentDefinitions = [],
        /**
         * FQCNs of classes implementing ScheduleEntriesInterface.
         *
         * Discovered by PackageManifestCompiler via interface scan (string-constant FQCN,
         * no direct import from scheduler package). Used by ScheduleEntryRegistry at boot.
         *
         * @var list<class-string>
         */
        public readonly array $scheduleEntries = [],
    ) {}

    /**
     * Create from a cached array (loaded from storage/framework/packages.php).
     *
     * Legacy caches may still contain `commands` and `routes` keys; they are ignored (see docs/adr/0001-manifest-routes-commands-removal.md).
     *
     * @throws \InvalidArgumentException If required keys are missing or have wrong types
     */
    public static function fromArray(array $data): self
    {
        // Legacy keys — never consumed at runtime (see ADR docs/adr).
        unset($data['commands'], $data['routes']);

        $requiredKeys = ['providers', 'migrations', 'field_types', 'middleware'];
        $optionalKeys = ['permissions', 'policies', 'formatters', 'package_declarations', 'attribute_entity_types', 'console_command_providers', 'agent_tools', 'agent_definitions', 'schedule_entries'];
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
            migrations: $data['migrations'],
            fieldTypes: $data['field_types'],
            formatters: $data['formatters'] ?? [],
            middleware: $data['middleware'],
            permissions: $data['permissions'] ?? [],
            policies: $data['policies'] ?? [],
            packageDeclarations: $data['package_declarations'] ?? [],
            attributeEntityTypes: $data['attribute_entity_types'] ?? [],
            consoleCommandProviders: $data['console_command_providers'] ?? [],
            agentTools: $data['agent_tools'] ?? [],
            agentDefinitions: $data['agent_definitions'] ?? [],
            scheduleEntries: $data['schedule_entries'] ?? [],
        );
    }

    /**
     * Export to a cacheable array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'providers' => $this->providers,
            'migrations' => $this->migrations,
            'field_types' => $this->fieldTypes,
            'formatters' => $this->formatters,
            'middleware' => $this->middleware,
            'permissions' => $this->permissions,
            'policies' => $this->policies,
            'package_declarations' => $this->packageDeclarations,
            'attribute_entity_types' => $this->attributeEntityTypes,
            'console_command_providers' => $this->consoleCommandProviders,
            'agent_tools' => $this->agentTools,
            'agent_definitions' => $this->agentDefinitions,
            'schedule_entries' => $this->scheduleEntries,
        ];
    }
}
