# FW-GOVERNANCE-PACKAGED-ACCEPTANCE-01

Status: acceptance/characterization only, not qualified. Forge mirror: Framework #2848. Extends `FW-GOVERNANCE-SCAFFOLD-CONVERGENCE-01`'s "Remaining acceptance and ownership" section.

## Scope

Bounded packaged-runtime acceptance for the compiled `application_blueprint` governance surface (`ApplicationBlueprintCompiler`, `GovernanceProviderEmitter`, `GovernanceCheckEmitter`). No runtime, provider, handler, compiler, existing test/fixture, spec, or manifest was edited. New files only:

- `tests/PackagedForm/check-blueprint-governance-enforcement` (bash harness)
- `tests/PackagedForm/fixtures/blueprint-governance-enforcement-probe.php` (PHP kernel probe)
- this change record
- `changes/unreleased/2848.governance-packaged-acceptance.added.md`

## Evidence tiers

**Source/unit evidence (pre-existing, cited, not reproduced here):** `ApplicationBlueprintCompilerTest`, `ApplicationBlueprintCompilerTest`'s `complete.yaml` fixture round-trip, `GovernanceProviderEmitterTest`, `GovernanceCheckEmitterTest`, `SiteBlueprintProcessTest::test_complete_blueprint_console_flow_publishes_governance_and_replays_idempotently` (real console process, byte-identical golden artifacts, plan/registration/companion-test assertions), and the generated companion fixtures under `packages/cli/tests/Fixtures/Blueprint/expected/complete/` (`GovernanceDefaultDenyTest`, `RolePermissionChecksTest`, `EntityAccessChecksTest`, `WorkflowTransitionChecksTest`, `JsonApiGovernanceChecksTest`) — these exercise real production classes (`EntityAccessHandler`, `TransitionService`, `JsonApiController`, `RoleRepository::fromProviders()`) but with hand-wired providers/storage, **not** through literal-root `composer.json` discovery or a booted kernel. They prove the generated code is correct in isolation; they do not prove kernel-wired registration.

**`#2990` limitation (explicitly bounded per the task):** `FW-GOVERNANCE-SCAFFOLD-CONVERGENCE-01` / PR #2990 proves only that `make:policy` and `scaffold:workflow` manual-stdout scaffolds converge on the same emitters the blueprint compiler uses. It does not touch blueprint compilation, packaged installation, or runtime registration, and its own record says so explicitly ("no filesystem publication, provider activation, configuration import, or apply authority is introduced by these handlers"). This acceptance lane is the first attempt at the packaged-runtime half #2990 explicitly left open.

**New packaged runtime evidence (this candidate, this run):**

Command:
```
CANDIDATE_SHA=HEAD ./tests/PackagedForm/check-blueprint-governance-enforcement
```

The harness adapts `check-site-recipe-provider-activation`'s exact archive → path-repository (`symlink: false`) → skeleton-install → real-CLI pattern: one `git archive` of the clean candidate commit, a disposable consumer installed from copied `waaseyaa/*` path repositories (verified as real copies, never symlinks, via an `installed.json` provenance check), no source fallback for runtime code (the archived `$source_root` supplies every package and the canonical `complete.yaml` blueprint fixture; only the test-driver PHP probe itself is read from the live checkout, mirroring `check-site-init-profile-acceptance`'s own `probe=$root/...` reference — the probe is tooling, not runtime under test).

Steps actually executed and their result:

1. **Installed provenance** — every `waaseyaa/*` package is a real copy (`is_link()` false) resolved from the archived candidate tree. **PASS.**
2. **Unknown-metadata dry-run refusal** — the candidate's own canonical `packages/site-contract/tests/Fixtures/Blueprint/valid/complete.yaml` is copied at runtime, then one policy condition (`article_create`'s `{kind: permission, permission: edit article}`) is mutated to `{kind: script, script: "return true;"}` — the exact unsupported-condition shape already covered at unit level by `packages/site-contract/tests/Fixtures/Blueprint/invalid/unsupported-condition-kind.yaml`/`.expect`. The real packaged `site:init --json --dry-run` refuses non-zero with the typed `SITE047_BLUEPRINT_UNSUPPORTED_CONDITION` code, creates no `.waaseyaa`, and the whole-project-tree SHA-256 digest (every tracked file's hash, sorted, hashed) is byte-identical before and after. **PASS.**
3. **Real decision receipt via installed production API** — `Waaseyaa\SiteContract\Blueprint\BlueprintDecisionReceipt::fromArray()`/`canonicalJson()` (the installed class, not hand-authored JSON) produces `decision.json` bound to the manifest's own digest and the blueprint's own digest, both read via the installed `SiteManifestParser`.
4. **`site:init --dry-run` then `--yes` on the unmodified `complete.yaml`** — both report `"outcome":"planned"` / `"outcome":"applied"` through the real packaged JSON CLI path. **PASS.**
5. **Literal root `composer.json` registration** — `App\Provider\ApplicationBlueprintGovernanceServiceProvider` and `App\Provider\ApplicationBlueprintServiceProvider` are each present exactly once in `extra.waaseyaa.providers` after apply. **PASS.**
6. **`install:init`** — completes ("Installation is complete."). **PASS.**
7. **`config:import`** (to activate the authored `workflows.assignments: {article.article: editorial}` binding per `docs/specs/content-workflow.md` — "a consumer must author, sign, verify, and explicitly activate its assignment entry") — **FAILS. This is the honest RED; see below.**

### RED: `config:import` rejects the compiler's own golden `workflows.assignments.yml`

Exact command and output from this run:

```
$ php vendor/bin/waaseyaa config:import
[error] Waaseyaa\Config\Exception\ConfigSerializationException: Sync file "workflows.assignments.yml" is missing the required `_meta` block.
Sync file "workflows.assignments.yml" is missing the required `_meta` block.
```

Root cause, confirmed by reading production code (no edits made): `packages/config/src/Sync/ConfigSyncFile.php`/`ConfigSyncDeserializer.php` require every sync file to carry a `_meta` block (`entity_type`, `uuid`, `langcode`, …) before `ConfigManager::import()` will accept it. `WorkflowDefinitionEmitter::renderAssignments()` (`packages/cli/src/Site/Blueprint/Emitter/WorkflowDefinitionEmitter.php`, `ASSIGNMENTS_PATH = 'config/sync/workflows.assignments.yml'`) emits only the bare mapping (`article.article: editorial\n`) — matching the checked-in golden fixture at `packages/cli/tests/Fixtures/Blueprint/expected/complete/config/sync/workflows.assignments.yml` byte-for-byte. No existing test (unit, integration, or `SiteBlueprintProcessTest`) ever runs this generated file through a real `config:import`; `SiteBlueprintProcessTest` only asserts the file's bytes are written and its plan/registration shape, never activation. This packaged acceptance lane is the first place that gap becomes visible, because it is the first proof to actually run `config:import` against compiler-emitted output.

**This is the earliest material missing contract in the required chain** ("boot the real packaged HttpKernel ... prove ... generated policy/permission/role/workflow registrations participate in access and transition enforcement"): without an importable `workflows.assignments` binding, `TransitionService`/`WorkflowBindingResolver` cannot resolve the `article` → `editorial` binding at runtime, so the workflow-transition and revisionable-binding legs of the required proof (allowed/denied transition) cannot be reached. Per instruction, the assertion was not weakened and runtime was not edited to force a pass; the harness fails loudly at this exact step and stops.

**Smallest likely runtime repair** (not applied here — out of this lane's authorized scope):
- `packages/cli/src/Site/Blueprint/Emitter/WorkflowDefinitionEmitter.php` (`renderAssignments()`, ~line 259) — emit the required `_meta` block (or whatever shape `ConfigSyncFile`/`ConfigSyncSerializer` canonically expects for a non-entity "simple config" key) so the generated artifact is importable as-is; **or**
- `packages/config/src/Sync/ConfigSyncDeserializer.php` / `ConfigSyncFile.php` — if `workflows.assignments` is legitimately a "simple config" key never backed by a config-entity UUID, add an explicit simple-config exemption from the `_meta` requirement (a schema/registry-driven distinction, not a per-file special case).
- Whichever repair is chosen, the golden fixture `packages/cli/tests/Fixtures/Blueprint/expected/complete/config/sync/workflows.assignments.yml` and `SiteBlueprintProcessTest`'s corresponding assertion (`self::assertSame("article.article: editorial\n", ...)`) are the shared files that change in lockstep — both are outside this lane's exclusive edit scope.

## Not reached (blocked by the RED above)

Because `config:import` fails, the harness does not reach: `user:create` + `user:assign-role` for the allowed/denied principals, the real-`HttpKernel`-boot governance probe (`blueprint-governance-enforcement-probe.php`, written and syntax-checked but not exercised end-to-end against a working binding), the default-deny / allowed-vs-denied entity-access / allowed-vs-denied workflow-transition / revisionable-binding observations, or the literal-root governance-provider removal + manifest-cache-clear negative control for those runtime registries. The probe file's `role`/`permission`-catalogue markers (which do not depend on `config:import`) are written but unexercised in this run.

## Unknowns

- Whether the intended repair is "teach the emitter to emit `_meta`" or "teach config sync that `workflows.assignments` is simple config" is a design decision outside this lane's authority.
- Whether, once repaired, the kernel-boot registry negative control (role/permission catalogue disappearing when only the literal-root `ApplicationBlueprintGovernanceServiceProvider` entry is removed) behaves as designed in `blueprint-governance-enforcement-probe.php` is unverified — the probe was written against the documented `RoleRepository::fromProviders()`/`AbstractKernel::permissionCatalogue()` contracts (`packages/foundation/src/Kernel/AbstractKernel.php`) but never executed past the `config:import` step.

## Authorization boundary

No release, deployment, operator credentials, runtime edit, or production enablement is authorized or performed by this record. This lane changed no runtime, spec, manifest, lock, or existing test/fixture file.
