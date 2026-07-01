# waaseyaa/ingestion

**Layer 0 — Foundation**

Payload validation and ingestion utilities for Waaseyaa applications.

`EnvelopeValidator` is an **abstract, `@internal`** base class that enforces the canonical ingestion envelope shape; applications extend it to provide their supported versions, entity types, and entity-specific validation. The required envelope fields are `payload_id`, `version`, `source`, `snapshot_type`, `timestamp`, `entity_type`, `source_url`, and `data`. `PayloadValidatorInterface` is the per-domain hook for content-specific validation; `EnvelopeValidator` implements it. `ValidationResult` is a `readonly` `@api` DTO carrying a `list<string>` of free-form error message strings (`public array $errors`) and an `isValid()` helper that returns `true` when `$errors` is empty — there are no structured error codes in this package.

Key classes: `EnvelopeValidator` (abstract, `@internal`), `PayloadValidatorInterface`, `ValidationResult`.

> **No framework consumer yet.** These validators are self-contained and unit-tested, but **nothing in the framework currently depends on or invokes this package** — no other package requires `waaseyaa/ingestion`, and no provider, route, or CLI wires `EnvelopeValidator`/`PayloadValidatorInterface`. It is a standalone building block an application can adopt directly, not a wired pipeline. (Note: the foundation-layer ingestion pipeline described in `docs/specs/ingestion-defaults.md` is a separate `Waaseyaa\Foundation\Ingestion\*` stack, not this package.)
