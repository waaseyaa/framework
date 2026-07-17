# waaseyaa/admin-surface

**Layer 6 — Interfaces**

Canonical integration boundary between any Waaseyaa host and the admin SPA.

This package owns the host-to-SPA payload contract under `contract/types.ts` (see `contract/README.md`) and ships the PHP-side host implementations: `GenericAdminSurfaceHost` for auto-discovered CRUD, `AbstractAdminSurfaceHost` for custom hosts, `AdminSurfaceServiceProvider` for route registration, and `AdminSurfaceRoutePaths` as the canonical path table. Cross-boundary tests in `tests/Integration/AdminSurface/` enforce structural conformance between the PHP emitters and the TypeScript contract.

Key classes: `AdminSurfaceServiceProvider`, `AdminSurfaceRoutePaths`, `GenericAdminSurfaceHost`, `AbstractAdminSurfaceHost`, `AdminSurfaceSessionData`, `CatalogBuilder`, `ListMetadata`, `SurfaceQueryPolicy`.
