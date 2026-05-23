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

## Installing guidelines / skills (`bin/waaseyaa bimaaji:install`)

Ship the framework-canonical agent skill pack to a consumer project in
per-client formats. Lifted in spirit from Laravel Boost's
`php artisan boost:install`; framework-native, no Node runtime.

```bash
# Install for one client
bin/waaseyaa bimaaji:install --client=claude

# Install for several (comma-separated or repeated)
bin/waaseyaa bimaaji:install --client=claude,cursor --force

# Preview without writing
bin/waaseyaa bimaaji:install --client=cursor --dry-run

# Interactive client selection when omitted on a TTY
bin/waaseyaa bimaaji:install
```

Seven launch clients: `claude`, `cursor`, `codex`, `copilot`, `gemini`,
`windsurf`, `junie`. See
[`docs/specs/bimaaji-install.md`](../../docs/specs/bimaaji-install.md)
for the per-client target paths, flag semantics, interactive UX, exit
codes, sandbox guarantees, and the five-step extension guide for
adding new clients.

## Status

Bimaaji is now exposed over MCP via [`packages/mcp/`](../mcp/)'s
per-request bridge architecture as of 2026-05-23 (M3
`bimaaji-mcp-bridge-01KS5VS8`). Five `#[AsAgentTool]` adapters live
in `packages/ai-agent/src/Tool/Bimaaji/` and surface automatically
through the `AgentToolRegistryBridge` with no per-tool MCP code. See
[`docs/specs/mcp-endpoint.md`](../../docs/specs/mcp-endpoint.md) §
"Bimaaji MCP bridge" for the transport contract and `packages/mcp/README.md`
for the operator-facing summary.

The 2026-05-20 "PHP-only" deferral that closed
[#1463](https://github.com/waaseyaa/framework/issues/1463) was formally
superseded by M3; the issue stays closed with a supersession comment
linking to the M3 PR set.

### Legacy MCP-server cleanup (pre-M3 consumers)

Projects (e.g., Minoo) that previously wired the deleted Node-based
bimaaji MCP server (`vendor/waaseyaa/bimaaji/mcp/server.js`, removed
in #1387/#1464) should:

1. Remove any `mcpServers.bimaaji` entry pointing at the deleted
   `server.js` from `.claude/settings.json`. The replacement is the
   framework-native `/mcp` HTTP endpoint shipped by `waaseyaa/mcp`.
2. Drop `composer bimaaji-mcp-install` from post-install hooks or
   contributor docs — the script body was Minoo-local and has no
   upstream entry point.
3. Wire the new HTTP `/mcp` endpoint instead. See
   `packages/mcp/README.md` for the `claude_desktop_config.json`
   example fragment.

## Where to read more

- **Doctrine spec:** [docs/specs/bimaaji.md](../../docs/specs/bimaaji.md) — design rationale, FRs/NFRs, invariants, file map.
- **Design history:** [docs/plans/2026-05-21-ai-ecosystem-beta-tightening.md](../../docs/plans/2026-05-21-ai-ecosystem-beta-tightening.md) — the 5-mission cluster that promoted bimaaji from "scaffolding" to "shipped."
- **Roadmap context:** [GitHub Milestone #67](https://github.com/waaseyaa/framework/milestone/67) (Track 2: Bimaaji & agentic).
