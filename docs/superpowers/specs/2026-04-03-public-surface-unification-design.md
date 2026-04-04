# M4: Public Surface Unification

## Goal

Make every public API element in Waaseyaa an intentional v1 contract. The public surface is the product for a framework. Before v1, every interface, abstract class, and trait must be a deliberate choice, not an accident of vibe-coded greenfield development.

## Approach: Contract-First Cleanup

Define the public surface top-down per layer from specs, reconcile with what exists in code. Undocumented interfaces get promoted (added to specs) or demoted (marked `@internal`). Concrete-type leakage gets extracted to interfaces. Specs become the authoritative surface definition.

## Current State (from inventory)

- **144 public API elements** across 35 of 60 packages (117 interfaces, 17 abstract classes, 10 traits)
- **85% spec alignment** on core contracts (entity, access, api, middleware)
- **11 concrete-type leakage instances** (6 in AbstractKernel bootstrapping, 2 in MCP/AI-Agent, 3 minor)
- **3 undocumented interfaces**: TenantResolverInterface, SchemaRegistryInterface, EntityEventFactoryInterface
- **~6 underspecified interfaces**: AssetManagerInterface, HealthCheckerInterface, RateLimiterInterface, ErrorPageRendererInterface, EntityRepositoryInterface, LanguageNegotiatorInterface
- **No critical layer violations**

## Decision Framework

Each of the 144 API elements gets one of four dispositions:

| Disposition | Meaning | Action |
|---|---|---|
| **Public** | Intentional v1 contract | Add to surface map, ensure it's in a spec, keep stable |
| **Internal** | Implementation detail | Add `@internal` annotation, exclude from surface map |
| **Extract** | Concrete type that should be an interface | Create interface, move consumers to it, mark concrete `@internal` |
| **Remove** | Dead code or accidental exposure | Delete it |

**Decision criteria:**
- Does a consumer app need to reference this type? -> Public
- Does a third-party package/extension need this to integrate? -> Public
- Is it only used within the same package or by the framework's own wiring? -> Internal
- Is it a concrete class used as a type hint in another package's public signature? -> Extract

**The `@internal` contract:** Means "this exists but we make no stability promise." Consumers can use it at their own risk, but it can change without a major version bump. Standard practice in Symfony, Doctrine, PHPStan.

**Kernel bootstrapper exemption:** AbstractKernel's 6 concrete references (EntityTypeManager, SqlEntityStorage, etc.) stay as-is. Kernels are entry-point orchestrators, not reusable library code. Documented as "bootstrap internals."

## Execution Order (bottom-up by layer)

### Issue 1: L0 Foundation Surface Cleanup (heaviest)

22 public API elements. The foundation layer has the most undocumented and underspecified surfaces.

- Decide promote-or-demote for each undocumented interface (TenantResolver, SchemaRegistry, EntityEventFactory)
- Decide promote-or-demote for underspecified interfaces (AssetManager, HealthChecker, RateLimiter, ErrorPageRenderer, LanguageNegotiator)
- Add `@internal` to everything not on the public surface map
- Update `docs/specs/infrastructure.md` with promoted interfaces
- Estimated: ~15 files touched

### Issue 2: L1 Core Data Surface Cleanup

~40 elements across entity, entity-storage, access, field, config, user, auth. Spec alignment is strong here.

- Verify spec matches code for all public interfaces
- Add `@internal` to implementation details
- Entity package (17 elements) gets the most scrutiny as the framework's heart
- Confirm EntityRepositoryInterface is properly specced
- Estimated: mostly `@internal` annotations, ~10 files

### Issue 3: L2 Content Types Surface Pass

Node, taxonomy, media, path, menu, note, relationship. Lighter surface, mostly entity subclasses.

- Verify entity subclasses don't leak internals beyond their entity type definition
- Quick pass
- Estimated: ~5 files

### Issue 4: L3-4 Services + API Surface Cleanup

Services (workflows, search, notification) and API (api, routing). API layer is well-specced.

- Verify API spec alignment (JsonApiController, ResourceSerializer, AccessChecker)
- Fix MCP/AI-Agent concrete leakage (2 instances of McpToolDefinition concrete type)
- Extract interface for McpToolDefinition if consumers need it, otherwise mark `@internal`
- Estimated: ~8 files

### Issue 5: L5-6 AI + Interfaces Surface Pass

AI packages and interface packages (cli, admin, admin-surface, graphql, mcp, ssr, etc.).

- Minimal PHP surface cleanup
- CLI exposes commands not types (verify)
- Admin SPA frontend surface (composables, API endpoints) is out of scope for M4
- Estimated: ~3 files

### Issue 6: Public Surface Map

A single authoritative document listing every intentionally-public element per package, organized by layer.

- New file: `docs/public-surface-map.md`
- Built incrementally as Issues 1-5 complete
- Format: package name, element type (interface/abstract/trait), fully qualified class name, brief purpose
- This document becomes the v1 contract reference

### Issue 7: Spec Updates

Update subsystem specs to reflect promote/demote decisions made in Issues 1-5.

- `docs/specs/infrastructure.md` (foundation changes)
- `docs/specs/entity-system.md` (if any entity surface changes)
- `docs/specs/access-control.md` (if any access surface changes)
- Other specs as needed based on findings
- Estimated: ~4 spec files

### Issue 8: Close Governance Issues

Close the 13 M4 governance issues on `waaseyaa/framework` as superseded by concrete issues on `waaseyaa/waaseyaa`.

- Add comment on each referencing the new issue structure
- Close all 13

## Dependencies

```
Issue 1 (L0 Foundation) -- must complete first, everything depends on foundation contracts
  |
  v
Issue 2 (L1 Core Data) -- depends on L0 decisions
  |
  v
Issues 3, 4, 5 -- can run in parallel after L1
  |
  v
Issue 6 (Surface Map) -- built incrementally, finalized after 1-5
Issue 7 (Spec Updates) -- done alongside each layer issue
Issue 8 (Governance Cleanup) -- housekeeping, any time after issues created
```

## Exit Criteria

- Every public API element has an explicit disposition (public/internal/extract/remove)
- `docs/public-surface-map.md` exists and lists every public element
- All subsystem specs reflect the actual public surface
- No concrete-type leakage in public signatures (except documented kernel bootstrapper exemption)
- M5 (Verification Lock-In) can write contract tests against the defined surface

## Out of Scope

- Admin SPA frontend surface (composables, Vue components, API endpoints) -- different contract type
- New interfaces or capabilities -- M4 is about cleaning what exists, not adding features
- Reopening M3 architectural-base work
- Performance or query optimization
