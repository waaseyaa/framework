# Plugin Extension Points

## Purpose

This spec defines stable plugin extension points for workflow, traversal, and discovery tooling integrations.

## Stable Contract

Primary interface:

- `Waaseyaa\Plugin\Extension\KnowledgeToolingExtensionInterface`

Required methods:

- `alterWorkflowContext(array $context): array`
- `alterTraversalContext(array $context): array`
- `alterDiscoveryContext(array $context): array`

Contract requirements:

- Input and output must be associative arrays.
- Implementations must be deterministic for identical input/configuration.
- Implementations must preserve unknown keys unless intentionally removed.

## Runner Contract

Runner class:

- `Waaseyaa\Plugin\Extension\KnowledgeToolingExtensionRunner`

Behavior:

- plugin IDs are sorted before execution to ensure deterministic ordering,
- `fromPluginManager()` instantiates all discovered plugins and filters to `KnowledgeToolingExtensionInterface`,
- context is passed through each extension in sequence.

Runner surfaces:

- `applyWorkflowContext()`
- `applyTraversalContext()`
- `applyDiscoveryContext()`
- `describeExtensions()`

## Bootstrap Integration Seam

App-level composition roots can load extensions via kernel config:

- `extensions.plugin_directories`: list of absolute or project-relative plugin directories
- `extensions.plugin_attribute` (optional): custom attribute class for discovery (defaults to `Waaseyaa\Plugin\Attribute\WaaseyaaPlugin`)

Kernel integration surfaces:

- `AbstractKernel::getKnowledgeToolingExtensionRunner()`
- `AbstractKernel::applyWorkflowExtensionContext(array $context): array`
- `AbstractKernel::applyTraversalExtensionContext(array $context): array`
- `AbstractKernel::applyDiscoveryExtensionContext(array $context): array`

## Reference Example Module

Reference plugin:

- `Waaseyaa\Plugin\Tests\Fixtures\KnowledgeToolingExamplePlugin`

Demonstrates:

- workflow trace tagging,
- traversal relationship-type augmentation,
- discovery hint augmentation,
- deterministic normalization/sorting behavior.

## ServiceProvider Extension Hooks

File: `packages/foundation/src/ServiceProvider/ServiceProvider.php`

Beyond `register()` and `boot()`, service providers expose five additional extension hooks called by the kernel during bootstrap. All return empty defaults — override in package providers to contribute.

```php
// Contribute CLI commands (called by ConsoleKernel)
public function commands(
    EntityTypeManager $entityTypeManager,
    DatabaseInterface $database,
    EventDispatcherInterface $dispatcher,
): array;  // list<Command>

// Contribute HTTP middleware instances (called by HttpKernel)
public function middleware(EntityTypeManager $entityTypeManager): array;  // list<HttpMiddlewareInterface>

// Register routes (called during boot)
public function routes(WaaseyaaRouter $router, ?EntityTypeManager $entityTypeManager = null): void;

// Override GraphQL mutations (called by GraphQL bootstrap)
public function graphqlMutationOverrides(EntityTypeManager $entityTypeManager): array;
// Returns: array<string, array{args?: ..., resolve?: callable}>

// Set kernel resolver for lazy service resolution
public function setKernelResolver(\Closure $resolver): void;
```

These hooks are the stable contract for packages to extend the application without modifying kernel code. The kernel calls them during the appropriate boot phase — commands during console boot, middleware during HTTP boot, routes during provider boot.

## Compatibility Notes

- These extension points are additive and do not alter existing `PluginManagerInterface` contracts.
- Existing plugins that do not implement `KnowledgeToolingExtensionInterface` remain fully compatible.
- ServiceProvider extension hooks return empty defaults — packages that don't override them are unaffected.
