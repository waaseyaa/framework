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

**New packaged runtime evidence (candidate `08ca93f522da64a88bb6d3a11912108660dc493e`, this run):**

Command:

```
CANDIDATE_SHA=08ca93f522da64a88bb6d3a11912108660dc493e tests/PackagedForm/check-blueprint-governance-enforcement
```

The harness adapts `check-site-recipe-provider-activation`'s exact archive → path-repository (`symlink: false`) → skeleton-install → real-CLI pattern: one `git archive` of the clean candidate commit, a disposable consumer installed from copied `waaseyaa/*` path repositories (verified as real copies, never symlinks, via an `installed.json` provenance check), no source fallback for runtime code (the archived `$source_root` supplies every package and the canonical `complete.yaml` blueprint fixture; only the test-driver PHP probe itself is read from the live checkout, mirroring `check-site-init-profile-acceptance`'s own `probe=$root/...` reference — the probe is tooling, not runtime under test).

After `site:init` apply, the harness continues the CFG-03 lifecycle exactly like `check-verified-config-import`: external Ed25519 custody (`0600` private bytes), a separate authoring host with `AuthoringCustodyServiceProvider` + `signing_key`, a verifier-only consumer with public `trust_keys` only, real `config:manifest:sign` on the blueprint-generated sync directory, move sync + envelope to the consumer, key-hygiene rules 1–2 inline (rule 3 false-positives on `symlink: false` provenance installs because copied framework vendor trees include OIDC migration/spec paths whose names contain `signing`), then real `config:import` with `--activation-request-id` and the generation/sequence token read from supported `install:init`.

Cloning the blueprint-activated consumer into an authoring host is **unsafe**: signing boots a kernel that still carries generated blueprint providers and mutates the active store. The harness uses the full authoring build from `check-verified-config-import` instead (one extra `composer install`, ~35s on this machine).

Steps actually executed and their result:

1. **Installed provenance** — every `waaseyaa/*` package is a real copy (`is_link()` false) resolved from the archived candidate tree. **PASS.**
2. **Unknown-metadata dry-run refusal** — the candidate's own canonical `packages/site-contract/tests/Fixtures/Blueprint/valid/complete.yaml` is copied at runtime, then one policy condition (`article_create`'s `{kind: permission, permission: edit article}`) is mutated to `{kind: script, script: "return true;"}` — the exact unsupported-condition shape already covered at unit level by `packages/site-contract/tests/Fixtures/Blueprint/invalid/unsupported-condition-kind.yaml`/`.expect`. The real packaged `site:init --json --dry-run` refuses non-zero with the typed `SITE047_BLUEPRINT_UNSUPPORTED_CONDITION` code, creates no `.waaseyaa`, and the whole-project-tree SHA-256 digest (every tracked file's hash, sorted, hashed) is byte-identical before and after. **PASS.**
3. **Real decision receipt via installed production API** — `Waaseyaa\SiteContract\Blueprint\BlueprintDecisionReceipt::fromArray()`/`canonicalJson()` (the installed class, not hand-authored JSON) produces `decision.json` bound to the manifest's own digest and the blueprint's own digest, both read via the installed `SiteManifestParser`. **PASS.**
4. **`site:init --dry-run` then `--yes` on the unmodified `complete.yaml`** — both report `"outcome":"planned"` / `"outcome":"applied"` through the real packaged JSON CLI path. **PASS.**
5. **Literal root `composer.json` registration** — `App\Provider\ApplicationBlueprintGovernanceServiceProvider` and `App\Provider\ApplicationBlueprintServiceProvider` are each present exactly once in `extra.waaseyaa.providers` after apply. **PASS.**
6. **CFG-03 authoring/verifier split** — authoring host signs blueprint-generated `config/sync` with real `config:manifest:sign --scope=site:blueprint-governance`; consumer receives sync + `config/sync.envelope.json` + public trust only; no private key, `*.key`, or authoring custody references in the consumer profile. **PASS.**
7. **`install:init` then signed `config:import`** — completes with `imported workflows.assignments (created)` / `1 created, 0 updated, 0 deleted, 0 failed, 0 unchanged.` **PASS.**

### RED: kernel governance probe fails after signed import

Exact output from this run (truncated to the failure):

```
governance-probe kernel boot OK
governance-probe role-editor-registered: no
governance-probe role-viewer-registered: no
governance-probe permission-catalogue-edit-article: yes
governance-probe permission-catalogue-publish: yes
::error::governance-probe FAILED: Error: Call to undefined method Waaseyaa\Entity\EntityTypeManager::getEntityType()
FAIL: the packaged kernel governance probe failed with providers registered.
```

Root cause, confirmed by reading production code (no edits made): the probe fixture calls `$entityTypeManager->getEntityType('article')` (`tests/PackagedForm/fixtures/blueprint-governance-enforcement-probe.php:49`), but `EntityTypeManager` exposes `getDefinition(string $entityTypeId): EntityTypeInterface` — there is no `getEntityType()` method. This is a test-driver API mismatch, not an importer/signing regression. The probe also reports `role-editor-registered: no` and `role-viewer-registered: no` while permission catalogue markers are `yes`, so the role-registry leg of the required proof is unverified even before the fatal.

**Smallest likely repair** (not applied here — probe fixture is outside this lane's exclusive edit scope):
- `tests/PackagedForm/fixtures/blueprint-governance-enforcement-probe.php` — replace `getEntityType()` with `getDefinition()` (or equivalent supported API) and investigate why `RoleRepository::get('editor'|'viewer')` returns null while `permissionCatalogue()` already exposes blueprint permissions.

## Not reached (blocked by the RED above)

Because the positive probe fails, the harness does not reach: allowed/denied entity-access observations, allowed/denied workflow-transition observations, revisionable-binding confirmation via the probe, or the literal-root governance-provider removal + manifest-cache-clear negative control (the negative-control block is present in the harness but unexecuted in this run once the positive probe exits non-zero).

## Resolved earlier RED (candidate `08ca93f52`)

The prior `_meta`-block refusal on compiler-emitted `workflows.assignments.yml` is fixed on this candidate (`fix(blueprint): emit canonical workflow assignments`). The remaining gap was harness lifecycle: generated sync is valid CFG-03 input, but the blueprint generator must not mint signing custody and the importer must not be weakened — the authoring/verifier split above closes that gap and `config:import` now passes.

## Unknowns

- Whether `RoleRepository::get('editor'|'viewer')` returning null while permissions register is expected kernel behaviour, a blueprint provider wiring gap, or probe ordering — unverified until the probe API mismatch is fixed.
- Whether the literal-root governance-provider negative control behaves as designed once the positive probe runs — unverified in this run.

## Authorization boundary

No release, deployment, operator credentials, runtime edit, or production enablement is authorized or performed by this record. This lane changed no runtime, spec, manifest, lock, or existing test/fixture file.
