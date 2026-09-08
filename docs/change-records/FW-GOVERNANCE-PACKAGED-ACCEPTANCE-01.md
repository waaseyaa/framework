# FW-GOVERNANCE-PACKAGED-ACCEPTANCE-01

Status: local repair qualified at the emitted-artifact boundary; exact committed-tip packaged qualification pending. Forge mirror: Framework #2848. Extends `FW-GOVERNANCE-SCAFFOLD-CONVERGENCE-01`'s "Remaining acceptance and ownership" section.

## Scope

This slice adds a packaged-runtime acceptance harness for the compiled `application_blueprint` governance surface and repairs the first contract gap that harness exposed. The repair changes `WorkflowDefinitionEmitter` so `config/sync/workflows.assignments.yml` is emitted as a writable CFG-03 `ConfigSyncFile`, updates its unit and console-integration tests, promotes the corresponding seven generated fixtures, refreshes the S1 schema-authority roster, and records the observable CLI contract in `docs/specs/cli-kernel.md`.

The packaged harness and kernel probe remain the durable end-to-end acceptance boundary:

- `tests/PackagedForm/check-blueprint-governance-enforcement`
- `tests/PackagedForm/fixtures/blueprint-governance-enforcement-probe.php`

## Historical RED discriminator

The first packaged run passed installed-provenance checks, unknown-metadata refusal with a byte-identical consumer tree, installed-API decision-receipt creation, real packaged `site:init` preview/apply, literal-root provider registration, and `install:init`. It then stopped at the first material contract failure:

```text
$ php vendor/bin/waaseyaa config:import
[error] Waaseyaa\Config\Exception\ConfigSerializationException: Sync file "workflows.assignments.yml" is missing the required `_meta` block.
Sync file "workflows.assignments.yml" is missing the required `_meta` block.
```

At that point `WorkflowDefinitionEmitter::renderAssignments()` emitted only `article.article: editorial`. `ConfigSyncDeserializer` requires the CFG-03 metadata envelope, so the real import and kernel-governance legs could not proceed. The original run did not weaken the harness or claim that the unexecuted kernel probe passed.

## Repair

`WorkflowDefinitionEmitter::renderAssignments()` now constructs a writable `ConfigSyncFile` for entity type `workflows` and entity id `assignments`. It obtains the canonical schema identity and owner contract from `WorkflowAssignmentsConfig::register()`, uses the deterministic CFG-03 UUID, and serializes the authored assignment field map with `ConfigSyncSerializer`.

This choice keeps schema identity with the existing guarded registration. The empty `EntityTypeManager` supplies the registration call shape only; emitter-time generation does not validate authored binding rows against installed entity types. The emitter still omits `workflows.assignments.yml` when the blueprint has no assignment rows.

The golden assignment artifact now begins with `_meta`, carries `schema_id: workflows.assignments` and its version/hash/owner fields, and preserves `article.article: editorial` as the writable field value. The six plan/apply/replay JSON fixtures were regenerated from the repaired candidate so their embedded bytes and digests agree with the new artifact.

## Current local evidence

The following evidence binds the accepted dirty candidate at base tip `6bc79d76513d3f9104dea746e7f44bd384d7bcec`:

- The focused CLI test command passed: **19 tests, 338 assertions**. The new unit discriminator parses the emitted YAML through the real `ConfigSyncDeserializer`, verifies `isWritableV1()`, the deterministic UUID, `workflows` / `assignments` identity, and the authored field map. The console integration test verifies the repaired bytes through preview, apply, and idempotent replay.
- The isolated fixture-generation run used candidate-local PHP 8.5.8 and candidate-local `vendor/autoload.php`. All eight recorded CLI commands exited 0, and the regenerated output map contained exactly the seven accepted fixture paths.
- `php bin/check-s1-schema-authority` passed with **1,916 occurrences across 461 files** after the generated roster added only the two packaged-probe `->create()` entries, classified `legacy_schema_method` / `test-only`.
- `bash tools/drift-detector.sh --include-worktree origin/main` exited 0 after `docs/specs/cli-kernel.md` documented the repaired CFG-03 output shape.
- Independent reviews accepted the three source/test bytes, seven fixtures, S1 roster delta, and specification delta. Their exact per-file hashes are retained in the integration evidence; no source or fixture byte was changed by this documentation repair.

## Remaining qualification

The first exact-tip run after merging the repair with `origin/main` reached the repaired assignment artifact, then correctly refused `config:import` because the disposable consumer had no signed CFG-03 manifest envelope. The refusal was:

```text
Configuration import requires a signed CFG-03 manifest envelope at config/sync.envelope.json.
Unsigned configuration is refused.
```

That is a harness gap, not a reason to weaken configuration authority. The bounded repair uses the repository's canonical two-host path: generate a disposable Ed25519 key in a custody directory owned by neither project; build a separate authoring consumer from the same archived candidate; expose signing custody only through the existing authoring service provider and secret registry; give the importing consumer only the public trust key; copy the generated sync bundle to the authoring host; run the real `config:manifest:sign`; copy only `sync.envelope.json` back; verify consumer key hygiene; then run the existing `config:import` and kernel probes. No private key or signing provider enters the importing consumer.

The full packaged harness must be rerun against a commit containing that harness repair. Its archive is intentionally bound to `CANDIDATE_SHA`; running it against working-tree bytes would not test the candidate named by the receipt.

After the coherent payload is committed and reconciled with the current landing base, exact-tip qualification must run:

```text
CANDIDATE_SHA=HEAD ./tests/PackagedForm/check-blueprint-governance-enforcement
```

That run must prove `config:import`, creation and role assignment for the two principals, the booted-`HttpKernel` positive governance observations, and the literal-root governance-provider removal/cache-clear negative control. The focused evidence above proves the repaired emitted-artifact contract; it does not substitute for those packaged runtime assertions.

The final committed tip also requires the focused PHPUnit pair, S1 authority check, committed-range drift detector, and normal repository PR preflight. Remote `main` advanced after this local base and overlaps `docs/specs/cli-kernel.md`; the observed three-way text merge is clean, but the reconciled document must be rehashed and checked.

## Authorization boundary

This record covers a local Framework candidate. It does not claim publication, hosted CI, merge, release, deployment, production enablement, or completion of the wider Studio MVP. Framework delivery continues through the normal reviewed pull-request path.
