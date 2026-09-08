# FW-GOVERNANCE-PACKAGED-ACCEPTANCE-01

Status: locally qualified combined candidate; final exact-tip packaged rerun and normal pull-request gates pending. Forge mirror: Framework #2848. Extends `FW-GOVERNANCE-SCAFFOLD-CONVERGENCE-01`.

## Scope and final behavior

This slice makes compiled `application_blueprint` governance usable by the production kernel and records it through a packaged-runtime harness:

- `WorkflowDefinitionEmitter` serializes `config/sync/workflows.assignments.yml` as a writable CFG-03 `ConfigSyncFile`, using the registered `workflows.assignments` schema identity and canonical owner metadata.
- `EntityClassEmitter` adds the reserved boolean `status` field to workflow-bound revisionable entity classes. The field is column-stored, defaults to `false`, is `Protected`, and participates in authorization input so workflow publication changes durable serving state.
- Generator-owned PHP/JSON fixtures, the S1 schema-authority roster, and `docs/specs/cli-kernel.md` describe the emitted bytes accepted by the config importer and runtime.
- `tests/PackagedForm/check-blueprint-governance-enforcement` installs an archived candidate as real package copies, refuses unknown blueprint metadata atomically, applies the reviewed blueprint, signs generated config through a physically separate authoring host, imports only a public-key-verifiable envelope, and boots the production `HttpKernel`.
- `tests/PackagedForm/fixtures/blueprint-governance-enforcement-probe.php` resolves production roles, permission catalogue, entity access, and transition services. The positive path requires a denied viewer transition to leave the full base and revision rows unchanged, then requires editor publication to persist `status = 1`, `workflow_state = published`, revision key/published pointer `vid = 2`, and internal revision ids `[1, 2]`. The harness copies this probe from the archived candidate tree, so `CANDIDATE_SHA` binds the executable probe bytes as well as package source.

## Historical discriminators

The first packaged run stopped because the generated assignment file lacked CFG-03 `_meta`; the next stopped because unsigned config import is correctly refused. Those failures led to the writable assignment artifact and the separate-custody signing harness. Later production-kernel qualification exposed two independent runtime gaps: generated workflow entities lacked a durable `status` field, repaired in this slice, and custom base-row revision keys were hard-coded as `revision_id`, repaired separately by Framework #3034. The original RED logs remain evidence of those findings; they are not current outcomes.

## Executed evidence

At clean local merge `7af00a6e6df38db308a1182e730741ba9673f123`, whose second parent is reviewed #3034 source commit `ec629b17409d9361df9d3f3a47f8ae3124e7789c`:

- `CANDIDATE_SHA=7af00a6e6df38db308a1182e730741ba9673f123 tests/PackagedForm/check-blueprint-governance-enforcement` passed. It verified real-copy package provenance, `SITE047` unknown-metadata refusal with a byte-identical tree, signed CFG-03 import, production-kernel roles/permissions/access, allowed and denied workflow transitions, and fail-closed behavior after removing only the generated governance provider registration.
- `tests/PackagedForm/check-community-events-starter --keep` passed all seven phases. Its generated suite passed **45 tests / 135 assertions**, and the real write-path reference discriminator accepted valid Organizer/Venue references while refusing missing references.
- A retained Community Events consumer was then given an explicitly signed config envelope through the same separate authoring custody. A production `ConsoleKernel` discriminator passed draft to review to published with contributor publish denied atomically, configured base revision key `vid`, durable `status = 1`, published/base pointer `vid = 3`, internal revision ids `[1, 2, 3]`, and persisted Organizer/Venue references.
- Independent focused review of the status-field emitter and tests passed **30 tests / 377 assertions**. Earlier focused emitter/config tests, S1 authority, drift, fixture provenance, and per-file hash reviews remain retained in the qualification outputs.

The final durable-transition assertions were added after the `7af00a6e6` packaged receipt. They require one exact-tip packaged rerun after the coherent local commit; prior package and full-kernel runs establish the runtime behavior but do not claim to execute uncommitted probe bytes.

## Explicit activation prerequisite and residual ownership

Generated `config/sync/workflows.assignments.yml` is not automatically activated by the current application journey. `site:apply` publishes the reviewed filesystem artifact set, `project:init` composes `site:init` and `install:init`, and `install:init` activates the installation generation; none invokes the separately governed signed `config:import` command for generated workflow assignments. The Community Events full-kernel proof therefore performed an explicit signed import before exercising transitions.

Framework #3037 owns canonical, phase-aware generated configuration activation and retry behavior. Until that lands, this slice proves that the generated artifact can be signed, imported, and enforced by production kernels; it does not claim zero-touch generated-application readiness. Framework #2981's whole consumer journey remains open on that prerequisite.

## Final integration gates

After the coherent payload is committed and reconciled with current `main`, run the exact-tip packaged harness, the focused emitter/config test pair, S1 schema authority, committed-range drift detection, and normal repository PR preflight. Reconciliation must retain the landed #3034 metadata while preserving the reviewed source bytes; source equivalence must be recorded if only generated metadata changes.

This record covers a local Framework candidate. It does not claim publication, hosted CI, merge, release, deployment, production enablement, or completion of the wider Studio MVP.
