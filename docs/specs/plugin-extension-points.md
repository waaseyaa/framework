# Plugin Extension Points

<!-- Spec reviewed 2026-05-01 - Audit (#849) flagged this spec as part of the M1-contracts cohort presenting stale public surfaces. Reviewed against current code: the L0 plugin contract (PluginManagerInterface, PluginDefinition, DefaultPluginManager) and the foundation HasCommands/HasMiddleware/HasGraphqlMutationOverrides capability hooks (added in mission #824 WP03 surfaces D-F and pinned by ServiceProviderContractTest) are the authoritative extension points. No drift detected against the runtime surface (mission #824 WP09 surface F, closes #849) -->

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

The `Waaseyaa\Foundation\ServiceProvider\ServiceProvider` extension hooks (kernel-invoked methods such as `register`, `boot`, `routes`, `middleware`, `httpDomainRouters`, `registerRenderCacheListeners`, `configureHttpKernel`, `graphqlMutationOverrides`, and provider-injection capabilities including agent-tool contribution, plus the `KnowledgeToolingExtensionInterface` runner described above) are documented canonically in [`docs/specs/infrastructure.md`](infrastructure.md) under § ServiceProvider extension hooks. The interface ↔ abstract base ↔ kernel call-site lockstep is enforced by `packages/foundation/tests/Contract/ServiceProviderContractTest.php` (mission #824 WP03 surface B).

Plugin-specific extension via `KnowledgeToolingExtensionInterface` (above) is layered on top of those provider hooks but documented separately because it composes through the plugin manager rather than through the service-provider lifecycle.

## Compatibility Notes

- These extension points are additive and do not alter existing `PluginManagerInterface` contracts.
- Existing plugins that do not implement `KnowledgeToolingExtensionInterface` remain fully compatible.
- ServiceProvider extension hooks return empty defaults — packages that don't override them are unaffected.
