# Cleanup Backlog (living document)

A running log of cleanup-worthy findings encountered during normal work: dead code,
old/superseded code, unfinished or built-but-unexposed code, duplicated effort, stale
docs/specs, and bad architecture. **Not** a point-in-time audit (see `AUDIT.md` and the
dated `docs/audits/*.md` for those). Append as you find things; each entry is grounded at
`file:line` and states the fix + a rough risk. Tick items off or move them into a mission
when actioned.

> Convention: `CL-N` ids are stable. Don't renumber; mark `DONE`/`WONTFIX` in place.

---

## Open

### CL-1 — MCP: delete the legacy pre-M3 dispatch path (dead/duplicated)
**Found:** 2026-06-19 (Wayfinding Phase 5 MCP investigation).
`packages/mcp/src/McpController.php` + `packages/mcp/src/Tools/*` (`DiscoveryTools`,
`EntityTools`, `TraversalTools`, `EditorialTools`) + `packages/mcp/src/Cache/*` +
`packages/mcp/src/Rpc/*` are the **pre-M3** tool-class architecture. They are **no longer
reachable from HTTP routing** (the foundation `McpRouter` was retired in M3 WP01; the live
route is `McpRouteProvider` → `McpEndpoint::serve` → `AgentToolRegistryBridge` → ai-tools
`#[AsAgentTool]` registry). They survive only via direct instantiation in
`tests/Integration/Phase14/AiMcpIntegrationTest.php`. Self-documented as deletable in
`packages/mcp/README.md:88-96` ("A future cleanup mission may delete them.").
**Fix:** delete the pre-M3 files + the test that pins them, after confirming the live M3
`#[AsAgentTool]` path covers the equivalent read tools. **Risk:** low (already unrouted).

### CL-2 — MCP: stale docs claim `BearerTokenAuth`/authenticated-only default (wrong)
**Found:** 2026-06-19.
`packages/mcp/README.md:21-25,72-74` and `docs/specs/mcp-endpoint.md` describe the
`McpAuthInterface` default as `BearerTokenAuth(tokens: [])` / "Authenticated only — 401 if
null". The **live** binding is `PublicAnonymousAuth()` (public read-only, never 401) —
`packages/mcp/src/McpServiceProvider.php:41-48`. The docs contradict the code (and the
README's own "Authentication" line contradicts the actual three-layer public read-only
boundary). **Fix:** update README + `mcp-endpoint.md` to the live `PublicAnonymousAuth`
default and the structural/capability/per-tool read-only boundary. **Risk:** docs only.

### CL-3 — MCP: destructive tools are built but unreachable (no live write surface)
**Found:** 2026-06-19.
The only live MCP endpoint (`/mcp`) is hardwired to `ReadOnlyToolRegistry`, which hides all
`destructive: true` tools. Combined with CL-1 (the McpController write path is unrouted),
**no `destructive` MCP tool is reachable on any live surface today** — e.g. the editorial
write tools (`editorial_transition`/`publish`/`archive`) are dead-on-arrival. Wayfinding
Phase 5 introduces the *first* authenticated write tier, but only surfaces wayfinding tools;
the editorial write tools remain unexposed. **Fix (separate mission):** once the Phase-5
authenticated write tier exists, either surface the editorial write tools through it or
delete them. **Risk:** medium (decide expose-vs-remove deliberately).

### CL-4 — entity: `EntityRepositoryInterface` exposes the two-axis API only partially
**Found:** 2026-06-19 (Wayfinding Phase 4).
`packages/entity/src/Repository/EntityRepositoryInterface.php` declares `saveTranslation`,
`loadTranslation`, `listTranslationRevisions` — but **omits** `saveTranslationRevision`,
`loadTranslationTip`, `loadTranslationRevision`, `translationLangcodes` (all present on the
concrete `packages/entity-storage/src/EntityRepository.php`). Consumers that need the full
per-language revision API (e.g. wayfinding's `TrailStore`, for the draft-revision path) must
therefore depend on the **concrete** `EntityRepository`, an L4→L1 concrete coupling instead
of the interface. **Fix:** complete the two-axis surface on `EntityRepositoryInterface` so
the per-language revision API is fully abstracted; then relax `TrailStore` to the interface.
**Risk:** low-moderate (interface broadening on a core L1 type; touches the revision spec).

### CL-5 — wayfinding: beacon-emit logic duplicated (controller vs MCP tool)
**Found:** 2026-06-19 (Wayfinding Phase 5). The beacon validate-and-publish logic (anchor
validity via `AnchorRegistry::isValid`, content length cap, build `{anchor_id, content,
order, emitted_by}`, `BroadcastStorage::push(SessionChannel::forToken(...), 'wayfinding.beacon', …)`)
now lives in BOTH `packages/wayfinding/src/Http/EmitBeaconController.php` (Phase 2) and
`packages/ai-agent/src/Tool/Wayfinding/EmitBeaconTool.php` (Phase 5). Deliberately not
DRY'd in Phase 5 to avoid refactoring the working, tested Phase-2 controller mid-phase.
**Fix:** extract a `Waaseyaa\Wayfinding\Beacon\BeaconEmitter` (constructor `AnchorRegistry`;
`emit(BroadcastStorage, sessionToken, anchorId, content, order, emittedBy)`) and have both
the controller and the tool delegate to it, preserving the controller's exact 403/422/202
responses. **Risk:** low (behavior-preserving extraction + re-run the Phase-2 controller test).
