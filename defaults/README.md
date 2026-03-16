# Waaseyaa Platform Defaults

This directory contains built-in platform-level manifests for Waaseyaa's default content types,
schemas, and configuration.

## Rules

- Files in this directory are **immutable via API** — they cannot be deleted through the platform API.
- They may be **disabled per tenant** via admin UI or CLI, with an audit log entry.
- Every manifest must include a `project_versioning` block (see `docs/VERSIONING.md`).
- Do not place package-specific defaults here; this is for platform-wide built-ins only.

## Contents

| File | Description |
|---|---|
| `core.note.yaml` | Built-in minimal content type: a simple note with title and body |
| `core.note.schema.json` | JSON Schema (draft-07) for the `core.note` entity payload |
| `ingestion.envelope.schema.json` | Canonical ingestion envelope schema (v0.1.0) |

## Namespace

Built-in types use the `core.` namespace prefix. Custom types must use a different namespace.
The `core.` namespace is reserved and cannot be claimed by extensions or tenants.

### Enforcement

The `core.` namespace is enforced at registration time in `EntityTypeManager`:

- `registerEntityType()` — throws `[NAMESPACE_RESERVED]` `\DomainException` if the type ID starts with `core.`
- `registerCoreEntityType()` — bypasses the guard; for kernel boot and core service providers only

**Extension and tenant code must use `registerEntityType()` and choose a non-`core.` namespace.**
Attempting to register `core.*` types from extension service providers will fail at boot.

### Choosing a namespace

Use a short, globally-unique prefix for your organisation or extension:

```
myorg.article      ✓  custom type in myorg namespace
acme.product       ✓  tenant-scoped type
core.article       ✗  reserved — DomainException thrown
```

## Adding a New Default

1. Create `<namespace>.<type>.yaml` with `project_versioning` block.
2. Create `<namespace>.<type>.schema.json` (JSON Schema draft-07).
3. Add the API guard in the entity storage layer (prevent DELETE, allow disable).
4. Add boot validation test.
5. Add CI manifest conformance check.
6. Open a GitHub issue using the `default-type` template.
