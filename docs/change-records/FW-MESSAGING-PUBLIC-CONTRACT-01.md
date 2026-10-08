# FW-MESSAGING-PUBLIC-CONTRACT-01

## Problem and result

Messaging's two public Protected adapters required the containing access-policy
file to load first, despite Composer PSR-4 public names. The creator subscriber
also retained an unregistered empty compatibility callback; schema transition
retained unused state and repeated JSON decoding. The package README/spec
mixed current primitives with product plans and implied unimplemented services.

Keep the necessary immutable-principal adapter boundary and move each concrete
public type to its own PSR-4 file. Remove the empty callback without a shim,
remove unused schema metadata reads, and share existing stored-blob decoding.
Document actual composition, failure semantics, schema authority and open gaps
using generic consumers. No new messaging authority or lifecycle behavior.

## Scope and ownership

Issue #3188; findings MSG-STRUCT-001, MSG-STRUCT-002, MSG-SURFACE-001,
MSG-DOC-001. Root owns messaging source, focused tests, README/spec, this record,
release fragments and generated governance views. No parallel implementation.
Base: 4ec657ddd assessment candidate atop landed #2753 and reviewed shared
AGENTS.md policy. S1 remains authoritative; D2/D3 and API intake are separate.

## Acceptance and evidence

Fresh direct loads fail before repair and succeed afterward; an isolated-process
regression loads both adapter classes before their containing policy and checks
factory interfaces. Existing component participant/admin/outsider and real-SQL
atomic creation tests remain required. Schema controls cover invalid JSON,
scalar/null/empty objects, valid identity and repeated transitions, alongside
existing duplicate-state merge and unique-key tests. No method-absence test.

Public class names/signatures remain because the active interface integration
is intentional, not to preserve obsolete alpha paths. Decoding equivalence is
limited to actual stored string/null blobs; persisted data is preserved.
Independent immutable-delta review and final native preflight are required.
Exact-head full qualification belongs to hosted checks; no release/deployment.

## Landing

PR #3189 merged on 2026-10-08 at 59bf986d7152805a6d156ab54cb30f2cccbad89c,
the identical fully qualified head (CI37817226478, 59 successful jobs). Native
preflight and independent reviews passed. S3/S7 findings are resolved in the
assessment ledger; D2/D3 and remaining convergence gaps are unchanged.
No release or deployment. Post-landing reconciliation changes only records.
