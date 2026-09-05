# Governed Gates: Preflight, Refresh, and Recorded-Artifact Identity

Status: LIVE (introduced by #2400)
Related: `docs/specs/workflow.md` (workflow rules), `docs/specs/s1-schema-authority.md` (DDL roster
semantics), `docs/governance/m11-steady-state-conformance-loop.md` (governed-change loop)

## Why this spec exists

PR #2399 surfaced the failure shape this spec eliminates: two stale recorded rosters and one stale
spec appeared as **five red CI jobs** (the same failures repeated across `ci/unit-tests`,
`ci/random-order`, `ci/coverage`, `support/s1-contract`, and `spec-drift`), the repair commands had
to be discovered by reading verifier sources, and the local run matrix (`--testsuite Unit` +
`--testsuite Integration`) never executed the Architecture suite that CI runs. The structural
causes: no single local preflight, no single refresh command, heterogeneous state semantics
(worktree scanners vs committed-ref diffs), an advisory-local/blocking-CI split for spec drift, no
fast CI job gating the long test jobs, and roster entries whose identity binds line numbers and
whole-file hashes.

## The contract

### 1. `bin/check-pr-preflight` — one local command mirroring CI's repo-state gates

Runs every **repo-state** gate that hosted CI blocks merge on, using the same commands CI uses,
with the `run_gate` accumulator pattern from the `ci/verify-gates` job: every gate runs, all
failures are reported in one pass, each failure names its exact repair command, and the script
exits non-zero if any gate failed. Test suites (Unit / Integration / Architecture / frontend /
e2e) and the hosted FrankenPHP worker-runtime lane (`ci/frankenphp-worker`,
`scripts/acceptance-frankenphp-worker.sh`) are **not** preflight gates — they
remain the long half of verification. Local preflight does not download or
execute FrankenPHP; missing the binary on a developer laptop must not become a
skip inside the hosted job. The preflight's job is that the long half never
discovers what the fast half could have said in seconds.

Profiles:

- **default (`bin/check-pr-preflight`)** — every fast repo-state gate: composer policy, portable
  paths, package layers, symfony imports, the four S1 roster/contract gates, support contract,
  surface parity, changelog shape and fragment validation, ingestion defaults, secrets, governed secret access, runtime-policy custody, dispatcher
  keys, getquery bindings, admin coercion patterns, admin dist freshness, admin dist acceptance
  manifest, field guards, access hardening, contract-suite coverage, openapi, phpstan/phpunit path checks, distribution
  extensions, PHPUnit skip policy, delivery-agent event schema and append-only
  custody, spec drift, changelog discipline, cs-check.
- **`--full`** — adds the phpstan-engine gates (`composer phpstan`, `bin/check-dead-code`). These
  are in CI's blocking set; they are separated locally only because the PHPStan worker layer is
  environment-sensitive (documented WSL crashes) and cache-cold runs are minutes long. `--full` is
  the documented pre-PR command.

The gate list is **data, not prose**: `tools/preflight-gates.json` maps each gate id to its
command, repair command, profile, and the CI surface that enforces it. A self-test
(`tests/Architecture/PreflightParityTest`) asserts (a) every manifest gate resolves to a runnable
command, and (b) every gate names a CI enforcement surface that actually exists in the workflow
files — so the manifest cannot silently drift from CI.

Vendor-freshness precondition (#2926): before any gate runs, preflight calls the shared,
dependency-free `bin/lib/vendor-freshness.php` against the repository root. It compares
`composer.lock` (packages and packages-dev — by name, version, and source/dist reference)
with `vendor/composer/installed.json`, and the PSR-4 namespaces a fresh dump must carry —
the root `composer.json`'s autoload and autoload-dev plus every locked package's autoload
(Composer never dumps a dependency's autoload-dev) — with `vendor/composer/autoload_psr4.php`. A stale or missing `vendor/`
short-circuits with `VENDOR_FRESHNESS_EXIT_CODE` (3 — distinct from 0 pass, 1 defect, 2 gate
infrastructure, 255 PHP fatal) and one actionable `vendor/ is stale relative to composer.lock —
run composer install` message; **no gate runs**, because their results against a stale
checkout would misreport environment faults as repository defects (an uncaught
`Opis\JsonSchema\Validator` fatal in the delivery-ledger gate; an "orphaned" public-surface
declaration whose repair is a CHANGELOG directive). The gates that dereference locked packages
or the autoloader — `bin/check-delivery-agent-events`, `tools/check-surface-parity.php`,
`bin/generate-surface-map` — run the same precondition themselves, so they behave identically
when invoked directly or by CI's `run_gate`. The precondition is deliberately **not** a manifest
gate: hosted CI installs fresh and can never observe this state, so a manifest entry would be a
no-op in CI while still needing an `enforced_by` surface; `bin/check-vendor-fresh` remains the
standalone local guard over the same library. `--list` does not require a fresh `vendor/`.

State semantics: preflight evaluates the committed range against `origin/main` (or
`WAASEYAA_DRIFT_BASE`) **plus staged, unstaged, and untracked worktree files**. Spec-review
trailers remain commit metadata: a committed trailer cannot pre-approve a later worktree source
change, so that source change requires a corresponding worktree spec edit. Hosted CI omits the
worktree mode because its checkout is already the exact immutable PR head.

Delivery-ledger custody has explicit local and hosted modes (#2900). The local
roster passes `--branch-base={base}` so unrelated main appends do not reject an
unchanged branch ledger. The dedicated `ci/verify-gates` step instead validates
committed candidate bytes against pinned accepted history, preserving the full
push baseline and binding PR merge parents. Strict native GitHub checks enforce
freshness when the pinned PR head merges. See [delivery-telemetry.md](./delivery-telemetry.md).

### 2. `bin/refresh-governance-artifacts` — one refresh command

For every governed recorded artifact, one command knows how to repair it:

- **Mechanical artifacts** (regenerable with no human judgment): the four S1 rosters
  (`support/s1-*-roster.json`) and the dispatcher-key baseline. Refresh regenerates them via the
  verifiers' own write modes, then prints the resulting `git diff --stat` so the operator reviews
  what changed before committing.
- **Judgment artifacts** (entries need human-authored rationale): getquery-bindings baseline
  (entries require `# reason` comments), dead-code baseline (policy: shrink-only), public surface
  declarations (`packages/<pkg>/public-surface.php` — a `surface-parity` failure means a missing
  declaration or an unauthorized removal/downgrade, which only a human can settle; the tracked
  `docs/public-surface-map.php`/`.md` aggregates those declarations compose into are mechanical,
  regenerated by `bin/generate-surface-map --write` at the release cut, and the refresh instruction
  names that command — FW-DELIVERY-SURFACE-01 / #2901), symfony-import allowlist, access-hardening,
  governed-secret-access and runtime-policy-custody baselines,
  php-coverage baseline. Refresh does **not** rewrite these; it detects staleness and prints the
  exact regeneration or hand-edit instruction for each.

- **Policy authority** (the value is the policy, not a recording of code): `support/s1-v1.json`.
  `bin/check-support-contract` owns the contract's schema and invariants and derives every
  runtime, packaging, CI, and documentation expectation from the parsed contract (#2852); it
  carries no policy values of its own, so a support-policy change is one contract edit plus the
  real-surface change. Refresh prints that instruction; it never rewrites policy. The gate takes
  `--root=DIR` and `--contract=PATH` so `tests/Architecture/CheckSupportContractGateTest.php` can
  prove drift, malformed contracts, widening, and stale evidence fail closed against fixtures.

Refresh only touches artifacts whose gate currently fails — a clean tree is a no-op.

### 3. Worktree-inclusive local checks

Local gate runs must see what the developer sees:

- The S1 scanners already read the worktree; their scan scopes are corrected so *non-repository*
  content cannot poison local runs: `.git/`, nested `vendor/`, `node_modules/`, and `storage/`
  are excluded everywhere (previously the SQLite scanner walked `.git/` — producing permission
  warnings — and nested `packages/*/vendor`, producing phantom local-only roster entries).
- Drift and changelog discipline combine the committed PR range with staged, unstaged, and
  untracked paths when invoked by preflight. A spec changed in an earlier commit does not cover a
  later uncommitted source edit; the spec must also change in the worktree. This preserves trailer
  provenance while making local results describe the tree the developer is actually reviewing.

### 4. Pre-push parity

`bin/project-hooks pre_push` runs `bin/check-pr-preflight` (default profile), **blocking**. The
advisory-local/blocking-CI split for spec drift is removed: drift failure blocks the push exactly
as it blocks merge. The escape hatch for environmental failures (not real findings) remains
`git push --no-verify`, documented in CLAUDE.md; the phpstan-engine gates live in `--full`/CI per
§1 and are the one intentional difference between the pre-push profile and CI's blocking set —
recorded here, in the manifest, and in the hook's output, not implicit.

### 5. CI ordering — fast contracts gate the long jobs

The `support/s1-contract` job (~30s: support contract + all four S1 roster gates) and `spec-drift`
job are `needs:` prerequisites of the PHPUnit shard plan/execution and the random-order job.
The required `ci/unit-tests` and `ci/coverage` contexts aggregate the same shard result and Clover
evidence without executing PHPUnit again. A stale roster or spec therefore fails in its owning
fast job, and the long jobs never start or re-report the same failure. On non-PR events `spec-drift` completes
with an explicit no-PR-diff result, so push and dispatch runs retain the same dependency graph.
Required-check names remain unchanged. Pull-request concurrency cancels superseded revisions;
push/main evidence is never cancelled.

### 6. Recorded-roster identity: semantic, not positional

S1 roster entries (schema version 2) bind exactly what makes an occurrence *that occurrence*:

```
{ path, pattern, class, match_sha256, occurrence }
```

- `match_sha256` — hash of the normalized matched text (the semantic content).
- `occurrence` — 1-based index of this `(path, pattern, match_sha256)` triple within the file, so
  multiplicity is preserved (two identical matches are two entries).
- **Dropped entirely**: `line`, `line_sha256`, `source_sha256`. They were derived display data
  bound into identity; storing them made every unrelated edit to a rostered file (even a new
  import line) invalidate the roster. Failure output derives live line numbers at report time —
  fresh by construction, never stored.

Consequence: a roster changes **iff the governed surface changes** — a match added, removed, or
moved across files/patterns. Whitespace, comments, imports, and unrelated edits in rostered files
no longer require regeneration commits. The verifiers still fail closed on unclassified
candidates, and all semantic anchors / contract assertions are unchanged.

Applies to all four S1 rosters: `s1-configuration-activation`, `s1-configuration-authority`,
`s1-schema-authority`, `s1-sqlite-construction`.

## Invariants

1. Every gate hosted CI blocks merge on is in `tools/preflight-gates.json` (enforced by
   `PreflightParityTest` against the workflow files).
2. Every governed recorded artifact has exactly one documented repair path, reachable from
   `bin/refresh-governance-artifacts` output.
3. A gate failure message names its repair command.
4. Roster identity never binds line numbers or whole-file hashes.
5. Local scanner scope excludes non-repository content (`.git/`, `vendor/`, `node_modules/`,
   `storage/`, `tmp/`) so a local run and a CI run of the same gate agree on the same tree.

## File map

| Surface | Path |
|---|---|
| Preflight command | `bin/check-pr-preflight` |
| PHPUnit skip policy | `bin/check-phpunit-skip-policy`, `tools/phpunit-skip-policy.json`, `docs/specs/phpunit-skip-governance.md` |
| Gate manifest | `tools/preflight-gates.json` |
| Vendor-freshness precondition | `bin/lib/vendor-freshness.php` (shared library, `VENDOR_FRESHNESS_EXIT_CODE`), `bin/check-vendor-fresh` (standalone local guard); callers `bin/check-pr-preflight`, `bin/check-delivery-agent-events`, `tools/check-surface-parity.php`, `bin/generate-surface-map`; proofs `tests/Architecture/VendorFreshnessPreconditionTest.php`, `tests/Architecture/SurfaceDeclarationEnvironmentFaultTest.php`, `tests/Integration/Policy/CheckVendorFreshTest.php` |
| Runtime-policy custody | `bin/check-runtime-policy-custody`, `tools/runtime-policy-custody-baseline.php` |
| Refresh command | `bin/refresh-governance-artifacts` |
| Manifest/CI parity test | `tests/Architecture/PreflightParityTest.php` |
| S1 verifiers (schema v2) | `bin/check-s1-{configuration-activation,configuration-authority,schema-authority,sqlite-contract}` |
| Recorded rosters | `support/s1-*-roster.json` |
| Hook integration | `bin/project-hooks` (`pre_push`) |
| CI ordering | `.github/workflows/ci.yml` (`needs: [support-contract, spec-drift]` on the three long jobs) |
| Hosted packaged-consumer lanes | `.github/workflows/ci.yml` jobs `ci/fresh-install-boot`, `ci/bimaaji-skill-resources`, `ci/cli-health-report`, `ci/cli-sync-rules`, `ci/split-artifact-acceptance`, harnesses under `tests/PackagedForm/`. Each builds a disposable consumer with its own dependency graph, so each needs network access and minutes of Composer work. Like the FrankenPHP worker lane below they are **blocking in CI and deliberately absent from `tools/preflight-gates.json`** (§1: preflight is fast repo-state gates only). Their fast repo-state halves DO run in `ci/unit-tests` — `tests/Architecture/FreshInstallBootGateTest.php`, `packages/bimaaji/tests/Architecture/PackagedSkillResourcesTest.php`, `tests/Architecture/CliHealthReportGateTest.php`, `tests/Architecture/CliSyncRulesGateTest.php`, `tests/Architecture/SplitArtifactAcceptanceGateTest.php` — which is what keeps the harness shape, the CI wiring, and the release-cut ordering under a gate a developer can run in seconds. `ci/split-artifact-acceptance` (#2649) additionally re-runs every one of its own assertions against seeded corruption on each invocation, so a green result is a harness that was observed failing on that same run. |
| Hosted FrankenPHP worker runtime | `.github/workflows/ci.yml` job `ci/frankenphp-worker`, pin `tools/frankenphp-runtime-pin.json`, harness `scripts/acceptance-frankenphp-worker.sh`. Owns real worker lifetime, Caddy/FrankenPHP identity, sequential/concurrent requests through one worker PID (concurrent burst captures per-request PID headers), hermetic runtime storage under `WAASEYAA_STORAGE_PATH`, account/community isolation at the HTTP boundary, streamed `/api/broadcast`, error-then-recovery, classic `php-server` fallback, and clean shutdown. Does **not** replace PHPUnit static lifetime gates (#2069, GraphQL schema-cache bleed, Twig environment replacement, CommunityMiddleware unit tests). |
