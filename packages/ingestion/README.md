# waaseyaa/ingestion

**Layer 0 — Foundation**

Payload validation and ingestion utilities for Waaseyaa applications.

`EnvelopeValidator` enforces the canonical ingestion envelope shape (envelope version, source identification, dedupe key, payload, signature); `PayloadValidatorInterface` is the per-domain hook for content-specific validation. `ValidationResult` carries structured error codes — `ENVELOPE_*`, `PAYLOAD_SCHEMA_NOT_FOUND`, `PAYLOAD_SCHEMA_LOAD_FAILED`, etc. — for editorial dashboard surfacing rather than free-form strings.

Key classes: `EnvelopeValidator`, `PayloadValidatorInterface`, `ValidationResult`.

> **No framework consumer yet.** These validators are self-contained and unit-tested, but **nothing in the framework currently depends on or invokes this package** — no other package requires `waaseyaa/ingestion`, and no provider, route, or CLI wires `EnvelopeValidator`/`PayloadValidatorInterface`. It is a standalone building block an application can adopt directly, not a wired pipeline. (Note: the foundation-layer ingestion pipeline described in `docs/specs/ingestion-defaults.md` is a separate `Waaseyaa\Foundation\Ingestion\*` stack, not this package.)
