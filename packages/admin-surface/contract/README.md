# `waaseyaa/admin-surface` — Contract Package

This directory is the **single authoritative source** for the host-to-SPA payload boundary of the Waaseyaa admin surface.

If three documents claim to define the admin payload shape, exactly one of them is right. This package is that one.

## Authority

| Artifact | Authority |
|----------|-----------|
| **`packages/admin-surface/contract/types.ts`** | **Authoritative.** Defines every type that crosses the host-to-SPA boundary: `AdminSurfaceSession`, `AdminSurfaceAccount`, `AdminSurfaceTenant`, `AdminSurfaceCatalog`, `AdminSurfaceCatalogEntry`, `AdminSurfaceField`, `AdminSurfaceAction`, `AdminSurfaceCapabilities`, `AdminSurfaceEntity`, `AdminSurfaceResult`, `AdminSurfaceError`, `AdminSurfaceListQuery`, `AdminSurfaceListResult`, optional per-resource action booleans, plus optional UI customization (`AdminSurfaceUiCustomization`, `AdminSurfaceHeaderLink`, `AdminSurfaceSidebarItem`, closed `navigationMode`). |
| `packages/admin-surface/src/Host/*.php` | Implementation. Backend emitters (`AdminSurfaceSessionData::toArray()`, `CatalogBuilder::build()`) must conform to the TypeScript contract above. |
| `packages/admin/app/contracts/*.ts` | SPA-local mirror. The admin SPA builds its own contracts under `app/contracts/` for `tsc --rootDir app` to produce clean declarations; these are mirrors of the canonical types, not authoritative redefinitions. They must stay structurally compatible. |
| `docs/specs/admin-spa.md` | Subsystem spec. Describes the SPA runtime, components, routes, and behaviour. **Does not define payload shape.** It references type names from this package and assumes their definitions. |

## Conformance

Two cross-boundary tests guard the contract:

- `tests/Integration/AdminSurface/AdminSurfaceContractConformanceTest.php` — parses the TypeScript interfaces in this package and asserts that backend-emitted PHP payloads conform structurally (no missing required fields, no unknown fields).
- `tests/Integration/AdminSurface/AdminSurfaceRouteWiringIntegrationTest.php` — exercises the production composition of `AdminSurfaceServiceProvider` + `WaaseyaaRouter` + `GenericAdminSurfaceHost` and asserts the published route names and paths match `AdminSurfaceRoutePaths`.

Drift on either side breaks both tests. That is the point.

## Optional list metadata

Host-decorated JSON Schemas may expose `x-list`; its authoritative PHP
normalizer is `Waaseyaa\AdminSurface\List\ListMetadata`. The declaration is
optional and additive. `ListMetadata::fromArray()` returns `null` for the whole
declaration when any key or value is outside the closed contract. Hosts that
declare it must call `SurfaceQueryPolicy::validate()` before adding private
scope filters and before delegating the list query. Absence retains the legacy
SPA behavior, including `x-list-display`.

## Adding a contract field

1. Edit `types.ts` with TSDoc explaining provenance (which PHP class emits the field, which SPA call site reads it) and optional/required semantics.
2. Update the matching PHP emitter (typically `AdminSurfaceSessionData` or a `CatalogBuilder` definition).
3. Add a regression assertion to the relevant package-local test (e.g. `CatalogBuilderTest`).
4. The cross-boundary integration tests will pick up the new field automatically.
5. If `docs/specs/admin-spa.md` references the field, update it to use the same name and casing.

## Accepting a released Admin bundle (installation contract)

The pre-built SPA that ships in this package is generated output. A consuming
distribution should not trust it because a branch said so — it should verify the
bytes it actually installed against the manifest that travels inside the same
release.

`dist.manifest.json` (`manifestVersion: 1`) ships beside `dist/` and is the
authoritative description of the published tree:

| Field | Meaning |
|---|---|
| `release.package` | Always `waaseyaa/admin-surface`. |
| `release.distPath` | Directory the digest describes, relative to this package (`dist`). |
| `published.treeDigest` | SHA-256 over the sorted `path\0size\0sha256` roster of every file under `dist/`. |
| `published.fileCount` / `published.byteCount` | Counts for the **published** tree only. |
| `source.signature` | The D6 admin-source content signature; equals the committed `dist.signature`. |
| `source.buildId` | The deterministic Nuxt build identity compiled into the bundle. |
| `markers.digest` / `markers.ids` | The declared source-contract markers the bundle is required to contain. |
| `identityDigest` | SHA-256 over the whole manifest except itself and every key in `identityExcludes`. |
| `identityExcludes` | Exactly `["acceptance"]` — see below. |
| `acceptance` | Evidence, **not** identity: build count, reproducibility verdict, the broader intermediate `packages/admin/.output` artifact count and digest, the previous published digest, the added/modified/removed path inventory, and the exact Node/npm runtime. |

**Consumer procedure.** Pin `waaseyaa/admin-surface` to a Framework release in
`composer.json` as usual, then, after `composer install`:

1. Read `vendor/waaseyaa/admin-surface/dist.manifest.json`.
2. Recompute `identityDigest` over the document with `identityDigest` and every
   `identityExcludes` key removed, canonicalised with recursively key-sorted
   objects (lists keep their order). Reject a mismatch — the manifest was
   hand-edited.
3. Walk `vendor/waaseyaa/admin-surface/dist`, build the sorted
   `path\0size\0sha256` roster, SHA-256 it, and require the result to equal
   `published.treeDigest`.
4. Record `published.treeDigest` in your own installation contract.

Because the manifest is inside the installed package, the identity you record
comes from the **release tag** you resolved, not from a candidate branch. Never
copy a digest out of an in-flight Framework PR: a candidate branch's bytes are
not the released bytes, and the manifest is the only thing that makes the
difference checkable.

`acceptance` is deliberately outside the identity digest because it carries the
exact toolchain patch versions and the transition record of the run that last
changed the tree. Two machines on different Node 24 patch releases publishing
the same bytes agree on identity; do not pin on anything inside `acceptance`.

Framework side, the manifest is produced only by `bin/build-admin-dist` (the one
canonical combined-source rebuild + acceptance operation) and re-verified in
blocking CI by `bin/admin-dist-acceptance verify`. See
`docs/specs/admin-spa.md` § "Pre-built SPA distribution".

## Naming convention

Payload keys are **camelCase** in both the TypeScript contract and the PHP emit (`emailVerified`, `requireVerifiedEmail`, `description`). Do not introduce snake_case variants. The audit (#851) flagged exactly this kind of split — a third vocabulary in the spec contradicting the contract — and the fix is to keep this package as the single source and align everything else to it.
