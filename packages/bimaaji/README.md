# waaseyaa/bimaaji

**Bimaaji** is a Waaseyaa package providing **application graph introspection** and an **agent-safe mutation protocol**. The name (Anishinaabemowin: "to give life to") reflects its role: making a booted Waaseyaa application's structure visible and actionable to AI agents.

## What it does

Bimaaji exposes two surfaces:

1. **Read-only introspection.** Six `GraphSectionProvider` implementations (admin, entities, jsonapi, public_surface, routing, sovereignty) emit versioned `GraphSection` payloads that, taken together, describe what an application contains: registered entity types and their fields, every registered route plus access classification, the JSON:API surface, admin entity groupings, the current sovereignty profile, and the public-surface map.
2. **Validated mutation.** A `MutationRequest` → `MutationValidator` → `PatchSet` pipeline lets an agent propose changes (e.g., "add field X to entity Y"). The validator gates every request through `SovereigntyGuardrails`; the patch generator emits content-hashed, reviewable file patches. **Bimaaji itself never writes to disk** — patches are returned for human (or upstream agent) review.

## Quick start

After installing `waaseyaa/framework` (which depends on this package), the framework's `PackageManifestCompiler` auto-discovers `BimaajiServiceProvider`. The application graph generator is then reachable from the container:

```php
use Waaseyaa\Bimaaji\Graph\ApplicationGraphGenerator;

$graph = $container->get(ApplicationGraphGenerator::class)->generate();
foreach ($graph->sections as $key => $section) {
    echo "{$key}: " . count($section->data) . " entries\n";
}
```

The six default section providers are pre-wired:

| Provider | Section key | Constructor deps |
|---|---|---|
| `AdminIntrospectionProvider` | `admin` | `EntityTypeManagerInterface` |
| `EntityIntrospectionProvider` | `entities` | `EntityTypeManagerInterface` |
| `JsonApiIntrospectionProvider` | `jsonapi` | `RouteCollection` |
| `PublicSurfaceProvider` | `public_surface` | `RouteCollection` |
| `RoutingIntrospectionProvider` | `routing` | `RouteCollection` |
| `SovereigntyIntrospectionProvider` | `sovereignty` | `SovereigntyProfile` |

`SovereigntyProfile` is derived from `SovereigntyConfigInterface::getProfile()` and falls back to `SovereigntyProfile::Local` when no config is bound. `RouteCollection` is looked up directly and falls back to `WaaseyaaRouter::getRouteCollection()`.

## Extending: third-party graph sections

A consuming package can contribute its own section by implementing `GraphSectionProviderInterface` and binding it in its own `ServiceProvider::register()`. The canonical tag is `BimaajiServiceProvider::SECTION_PROVIDER_TAG` (`bimaaji.section_provider`); tag your binding under it for forward-compatibility with future tagged-collection container support.

```php
final class FooSectionProvider implements GraphSectionProviderInterface
{
    public function getKey(): string { return 'foo'; }
    public function provide(): GraphSection { /* ... */ }
}
```

## Status

Bimaaji ships **PHP-only** in the current alpha range. The MCP server bindings that previously lived under `mcp/` (Node-based `server.js` exposing `bimaaji_ping` / `bimaaji_about` tools) were removed in #1387/#1464; they never reached consumers reliably (`composer bimaaji-mcp-install` exited 254 in downstream projects because `vendor/waaseyaa/bimaaji/mcp/server.js` was not present at runtime). MCP exposure is the subject of a follow-up mission (M3 of the AI ecosystem beta tightening cluster; see `docs/plans/2026-05-21-ai-ecosystem-beta-tightening.md`). The 2026-05-20 "PHP-only" deferral tracked in [#1463](https://github.com/waaseyaa/framework/issues/1463) is being revisited as part of that follow-up.

### Consumer cleanup

Projects (e.g., Minoo) that previously wired bimaaji's MCP server should:

1. Remove any `mcpServers.bimaaji` entry from `.claude/settings.json` (it pointed at a non-existent `vendor/waaseyaa/bimaaji/mcp/server.js`).
2. Drop `composer bimaaji-mcp-install` from post-install hooks or contributor docs — the script body was Minoo-local and has no upstream entry point.
3. Wait for [#1463](https://github.com/waaseyaa/framework/issues/1463) (or its replacement) before re-introducing any bimaaji MCP tooling.

## Where to read more

- **Doctrine spec:** [docs/specs/bimaaji.md](../../docs/specs/bimaaji.md) — design rationale, FRs/NFRs, invariants, file map.
- **Design history:** [docs/plans/2026-05-21-ai-ecosystem-beta-tightening.md](../../docs/plans/2026-05-21-ai-ecosystem-beta-tightening.md) — the 5-mission cluster that promoted bimaaji from "scaffolding" to "shipped."
- **Roadmap context:** [GitHub Milestone #67](https://github.com/waaseyaa/framework/milestone/67) (Track 2: Bimaaji & agentic).
