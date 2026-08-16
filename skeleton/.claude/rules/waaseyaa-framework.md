# Waaseyaa Framework Invariants

This consumer rule is distributed by `waaseyaa/foundation`. It summarizes
stable framework invariants for installed applications. The installed package
manifests and application contracts remain the source of truth; identify this
rule when the maintainer asks which guidance applies.

## Identity

Waaseyaa is a Symfony 7-based, entity-first PHP 8.5+ framework with dependency
injection and no application-global service locator.

- It is not Laravel and does not use Illuminate components or conventions.
- It is not Drupal and does not use Drupal's legacy runtime.
- Application code must follow Waaseyaa's installed abstractions rather than
  inferring conventions from another framework.

## Stable boundaries

| Need | Use |
|---|---|
| Transactions and non-entity tables | `DatabaseInterface` |
| Entity persistence | `EntityRepository` / `EntityRepositoryInterface` |
| Entity registration | `EntityTypeManager` |
| Authorization | `AccessPolicyInterface` + `FieldAccessPolicyInterface` |
| Query building | `SelectInterface` |
| Dependency injection | Symfony container and Waaseyaa service providers |
| Config access | Waaseyaa configuration services or documented environment inputs |

Do not use Laravel facades, Eloquent, ActiveRecord-style entity methods, raw
PDO construction, or direct SQL for entities. Supporting tables without entity
identity or lifecycle may use `DatabaseInterface` directly.

## Entity persistence pipeline

```text
Entity
  -> EntityType registered via EntityTypeManager
  -> EntityStorageDriverInterface
  -> EntityRepository
  -> DatabaseInterface
```

Use `ContentEntityBase` for field-mutable content entities and `EntityBase` for
value-like entities. Persist and delete through repositories so validation,
events, revisions, and language behavior remain intact.

## Package boundaries

Dependencies may only cross package layers through relationships allowed by
the installed Composer manifests and the Framework's package-layer gate. Do not
copy a package list or layer table from memory: inspect the installed manifests
or the matching Framework revision because the package graph evolves.

For deeper behavior, consult the application `CLAUDE.md`, `AGENTS.md`, and
installed Framework documentation where available.

