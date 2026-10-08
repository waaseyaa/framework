---
name: waaseyaa:entity-system
description: Work with Waaseyaa entities, repositories, fields and configuration using the current installed contracts. Use for entity modeling and persistence changes, not as a substitute for application authorization or schema governance.
---

# Entity system

Use the framework version installed by the application. In a Framework
checkout, read `AGENTS.md` and its operating contract first. In a consumer,
follow that application's guidance and inspect its locked `waaseyaa/*`
packages; do not assume monorepo main describes its installed APIs.

## Locate the current contract

| Concern | Framework specification | Source to inspect |
| --- | --- | --- |
| Entity identity, metadata, fields and values | `docs/specs/entity-system.md` | `packages/entity/src/`, `packages/field/src/` |
| Repository persistence and hydration | `docs/specs/entity-system.md` | `packages/entity/src/Repository/EntityRepositoryInterface.php`, `packages/entity-storage/src/EntityRepository.php` |
| Revision and translation lifecycle | `docs/specs/revision-system-unified.md` | Repository and driver contracts under `packages/entity-storage/src/` |
| Entity and field authorization | `docs/specs/access-control.md`, `docs/specs/field-access.md` | `packages/access/src/` and the application's policies |
| Configuration sync and activation | `docs/specs/config-management.md` | `packages/config/src/` |
| Schema changes | `docs/specs/s1-schema-authority.md` | Registered schema transitions and their acceptance tests |

For an installed package, resolve source paths beneath `vendor/waaseyaa/`.
Use version-matched specifications from the corresponding Framework checkout
or published corpus when available. If they are unavailable, record that gap;
do not fabricate a contract from a remembered signature.

## Model and persist through the composed repository

The current application path is
`EntityTypeManager::getRepository($entityTypeId)`. Inspect the actual repository
interface and concrete method before using it; methods on the implementation
are not automatically part of the consumer interface. Obtain the manager from
the application's supported composition root, with its repository factory and
registered entity definitions.

Do not recreate the removed `SqlEntityStorage`/`EntityStorageFactory` wiring or
use a dormant `getStorage()` seam as the default engine. Do not build a private
PDO write path to avoid a missing repository binding. Correct the composition
or record the boundary defect.

Before changing persistence, trace creation/hydration, field definitions,
validation, authorization, transaction ownership and lifecycle effects through
the real callers. Repository access does not itself establish the caller's
permission. Preserve the supported access-aware read/write boundary and its
refusal behavior; do not add `accessCheck(false)` to make a query pass.

For entity values, distinguish the stored representation from presented or
cast values using the current entity/field contracts. Do not promise that
arbitrary keys survive persistence, that constructor defaults are safe for
hydration, or that raw serialization is an authorized field projection.
Inspect creation versus stored-row hydration and their tests separately.

For configuration changes, distinguish desired sync state from active runtime
configuration. Follow the current activation/import contract and its refusal
paths instead of treating a writable config object as production authority.

## Verify intended behavior

Read the applicable spec and acceptance tests before editing. If a spec still
names a removed class or contradicts runtime behavior, record the mismatch and
settle the intended contract before repair. Do not copy stale examples or amend
the spec merely to bless an implementation defect.

Use focused tests through the real affected repository/composition boundary:
normal mutation/readback, relevant authorization or validation refusal, and
rollback or post-commit outcomes where applicable. Include current downstream
callers when changing a public contract. Use the repository's test database
utilities and local testing policy; test doubles alone do not prove SQL,
transaction, installed-package or application behavior.
