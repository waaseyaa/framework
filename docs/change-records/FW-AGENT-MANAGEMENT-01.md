# FW-AGENT-MANAGEMENT-01

Authorized 2026-10-01: implement the minimal shared per-site management contract.
Base: Framework 75388b225bd028aa6712d90d29e82df3f1e5ab9c; published alpha302
12d8019e9e4f4e7791dd163641b077ca97d67808 remains a separate consumer cohort.

## Design and scope

Add a versioned, optional `.waaseyaa/management.json` companion to the existing
site manifest. Reuse ManifestShapeReader, CanonicalJson and SiteDoctorFinding.
Declare supported/planned/unsupported operations without changing registration,
authorization or execution. Product adapters supply a current, read-only inventory
of actual operations and results from named acceptance checks. No inventory means
unverified, never a conformance pass. Bind results to operation and source identity.
Existing sites without the companion preserve their current doctor behavior.

The Layer-0 contract imports no HTTP, CLI, MCP, domain services or database. CLI
doctor composes it; the caller owns live inventory collection. No generated-code
scanner can prove a runtime registration, and an authored JSON snapshot is not a
live inventory. No raw SQL, signing-key handoff or unrestricted CRUD is added.

The existing Symfony YAML dependency detects duplicate mapping keys; native JSON
decoding preserves object/list distinctions for schemas. This extends the existing
structural manifest parser, not a JSON Schema validation engine. Owning operation
adapters must compile and validate their actual schemas through their validators.

## Verification plan

Focused PHPUnit: parser refusals, unsupported states, object-preserving canonical
identity; conformance against absent/stale/mismatched runtime inventory, inactive
capability, failed/stale acceptance, and two bounded product-shaped journeys.
Direct CLI doctor integration tests and unchanged site-manifest parser tests.
Independent immutable-candidate review precedes exact-head local and hosted
qualification. Publication uses the canonical release workflow only after its
gates pass. No product production test, grant issuance or deployment is authorized.

## Custody and current correction

The original native source export encountered Git/Composer/WSL denials. Those
historical checks did not qualify a release. This continuation verified archive
SHA-256 0dcc519cd1dfa020509e2240c15beb4693ca044cde912d02eab229920139ef17
and delta-manifest SHA-256
68d78a4b63c68119d9c4ce91069e2132083916217e9b30c86773b4b8660e1990,
all 23 before/after entries, and the unchanged canonical base. It uses an isolated
leased WSL checkout and its own normally installed locked dependencies. Original
checkout and product repositories are preserved.

Independent v2 review reproduced P1 MGMT-IDENTITY-001: architecture discovery
omits Go, SQL, root tests, extensionless scripts and locks. The corrected candidate
uses a separate complete regular-tree identity through maintained Symfony Finder;
only Git metadata is excluded. Dependency bytes, modes and hidden configuration
are included. Receipts/logs live outside the input tree. Symlink/unreadable/special
inputs refuse. The architecture discovery implementation and report identity are
unchanged. Existing `sourceDigest` inventory/result fields now carry the complete
management input identity; report `management_input_sha256` makes it explicit.

Regression acceptance proves previously omitted input changes invalidate both an
old inventory and old results carried into a refreshed inventory. It also checks
ordering, rename/delete, executable mode, symlink refusal and legacy site behavior.
README updates describe composition, optional AI tooling, complete identity and
supported regular installed profiles. Source tests do not establish product
activation or independent deployment attestation.

## Package and roadmap reconciliation

#3117 remains the owner of whole-CLI convergence, public/command metadata,
registration, I/O and dependency-closure residuals. This change adds read-only
management comparison and complete evidence identity; it does not complete that
umbrella or #2821's slim CLI closure. #3118 remains the package-coverage program.
The scoped independent audit inventories site-contract and CLI, reviews the
management seam and its internal consumers, and reports both whole-package audits
as in progress. No claim of package-wide convergence or beta readiness is made.

#3020 owns existing MCP side-effect declaration qualification; this adapter
projects actual registry declarations without claiming arbitrary tool code pure.
#3075 owns canonical Deptrac adoption; existing package-layer checks remain.
#2783/#2787/#2664 retain blueprint/materialization/update lifecycle ownership;
management declarations neither generate applications nor fork those contracts.
PR #2958 is merged in this base; #2787 remains open for its residual acceptance,
so its roadmap delivery note saying the PR awaits landing is historical drift.
Only two open PRs were found, both Admin dependency updates, with no management
overlap. No competing management-contract issue was found in open/closed search.

FETDER's inspected lock still uses the alpha.302 CLI/site-contract cohort;
Northway's supplied checkpoint owns its Go/API catalogue and has no new PHP
inventory/doctor dependency. Studio's inspected lock uses dev-main site-contract
plus alpha.300 HTTP/OAuth dependencies: its supported-cohort qualification remains
product-owned. No dependency upgrade or product deployment is performed here.

Low residual: nested JSON schema objects in operation DTOs are mutable; changing
one changes the operation digest and refuses prior results. No false conformance
pass was reproduced. Treat descriptors as immutable by convention; disposition
is a bounded site-contract consistency slice under #3118, with deep-immutability
and original canonical-byte equivalence controls before any public API change.

## Evidence status

The prior native 41-test run and 43-test correction run are historical synthetic
source evidence, not qualification. The fresh WSL v2 source run before this P1
correction passed 391 tests / 1432 assertions (site-contract and CLI management),
PHP 8.5.8 / PHPUnit 13.1.14. Surface-map freshness and package layers passed.
Corrected-candidate counts, immutable independent review and exact-head local/
hosted qualification are recorded in the final delivery receipt; none are claimed
in advance here. No end-to-end product check is claimed by Framework tests.

The initial corrected focused source run passed 404 tests / 1486 assertions.
After the static-analysis boundary repair, the Unit `Management` filter passed
57 tests / 134 assertions; the broader Unit `SiteContract|Management` filter
passed 411 tests / 1502 assertions. PHPStan and the fail-on-new dead-code gate
passed. Runtime adapter-row validation remains explicit and malformed-row
regression acceptance refuses; no static-analysis rule or baseline was relaxed.
Independent review approved the first corrected immutable head without blocking
findings; its subsequent boundary/comment repair requires exact-head review
rebinding and full qualification before publication. Standard
preflight exposed two v2 integration defects: its fragment filename/shape was not
accepted by the release compiler, and its maintained YAML exception import lacked
a narrow boundary entry. The fragment now uses #3118's scoped numeric name; the
parser allowlist entry has a maintained-parser rationale and review trigger.
Composer's normal targeted update promotes the existing locked Finder 8.0.8 from
dev to runtime, refreshes CLI metadata and reconciles the existing deployer lock
metadata with that package's already-tracked database-legacy development require.
No deployer source or dependency version changed. The architecture scanner remains
byte-identical to base. Generated surface maps are rebuilt from declarations.
