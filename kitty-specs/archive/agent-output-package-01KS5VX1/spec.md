# Agent-Optimized Output Package

**Mission:** `agent-output-package-01KS5VX1`
**Status:** Spec
**Target branch:** `main`
**Cross-references:** Design doc `docs/plans/2026-05-21-ai-ecosystem-beta-tightening.md` (M4 of 5). Independent of M1/M2/M3/M5. PAO-equivalent: pattern lifted from `laravel/pao` (2026-05 release) but framework-internal and tailored to Waaseyaa's CI gates and test stack.

## Why this mission exists

Agent-driven workflows in this repo — Spec Kitty's implement/review loop, `/ship-pr`, drift detection, `composer verify` runs invoked by sub-agents — burn enormous numbers of tokens parsing verbose tool output. `vendor/bin/phpunit` on this monorepo emits ~12k lines on a full run. `bin/check-package-layers` and `bin/check-dead-code` each emit dozens of context-irrelevant lines per invocation. The dead-code baseline alone is 1,341 → 66 entries (mid-2026); even a clean run logs ~50 lines. When a sub-agent runs `composer verify` to gate a PR, the parent agent then has to read all of that output through its context window — paying for the same noise on every iteration of the implement-review loop.

Laravel PAO (released 2026-05) solved this externally for the Laravel ecosystem: it hooks the autoloader, detects the agent environment (env vars like `CLAUDE_CODE`, `CURSOR_AGENT`), and replaces verbose PHPUnit/Pest/PHPStan/Rector/Artisan output with compact JSON. Human terminal output is unchanged when no agent is detected. Token reduction claimed: up to 99.8% on test runs. The pattern is sound; Waaseyaa needs its own equivalent because (a) PAO does not cover our custom CI gates (`check-package-layers`, `check-dead-code`, `check-getquery-bindings`, `check-composer-policy`, `drift-detector`), and (b) we want this as framework-native infrastructure rather than an external dev dependency.

This mission is deliberately independent of the bimaaji ecosystem missions (M1/M2/M3/M5). It's framework-wide infrastructure that any consumer benefits from — including consumers who never use bimaaji at all.

**The contract.** A new `packages/agent-output/` Layer-0 package. Detects agent environment from a list of well-known env vars. Provides a `FormatterInterface` and JSON formatters for the framework's verbose-output surfaces. Opt-in via `--output=json` flag on each command, auto-on under agent env. When no agent is detected and `--output=json` is not passed, output is unchanged — humans see what they have always seen.

## User scenarios

### Primary flow: an agent runs the test suite

1. Claude Code invokes `vendor/bin/phpunit packages/bimaaji/` as part of a Spec Kitty implement loop.
2. `packages/agent-output/`'s env detector sees `CLAUDE_CODE=1` and activates JSON formatting.
3. PHPUnit's standard output is intercepted; the formatter emits a single JSON envelope: `{"tool": "phpunit", "suite": "...", "passed": 47, "failed": 0, "skipped": 0, "duration_ms": 8123, "failures": []}`.
4. Claude Code's tool result is ~120 tokens instead of ~12,000. The parent agent reads the envelope, sees zero failures, proceeds.

### Primary flow: a human runs the same test suite

1. Developer runs `vendor/bin/phpunit packages/bimaaji/`.
2. Env detector finds no agent env vars set. Formatting is **not** activated.
3. PHPUnit emits its standard verbose output — exactly what humans expect.

### Primary flow: a CI gate runs under an agent

1. `composer verify` invokes `bin/check-package-layers`. The check emits dozens of lines listing every package and edge it validated.
2. Under agent env, the formatter intercepts and emits: `{"tool": "check-package-layers", "result": "pass", "packages_checked": 62, "edges_validated": 287, "violations": []}` — or, on failure, `{"result": "fail", "violations": [{"package": "...", "edge": "..."}, ...]}`.
3. The parent agent gets a structured pass/fail with the exact violation set. ~100 tokens instead of ~600.

### Primary flow: opt-in JSON without agent env

1. A CI pipeline (GitHub Actions) wants machine-readable output even though it's not "an agent."
2. The pipeline sets `--output=json` (or equivalently `WAASEYAA_OUTPUT=json`) on each command.
3. The formatter activates regardless of agent env detection. Output is JSON.

### Edge cases

- **Failure inside an agent-formatted run.** The JSON envelope includes a `failures` (or equivalently named) array with the structured failure details. Failures must remain debuggable — the envelope is compact, but each failure has enough context (file, line, message, optional stack) to act on.
- **Unrecognized agent env.** A new MCP client appears (`CURSOR_NEXT=1`). The detector treats it as no agent (safe default). The plan documents how to add a new client identifier — single config entry.
- **Formatter cannot parse the underlying tool's output.** The formatter falls back to a `{"tool": "X", "result": "unknown", "raw": "..."}` envelope rather than crashing. Compact-but-honest.
- **Mixed-tool composer command.** `composer verify` runs many tools in sequence. Each tool emits its own JSON envelope on its own line (NDJSON), preserving the per-tool structure. The parent script (or agent) parses each envelope independently.
- **stdout vs. stderr.** Errors stay on stderr; JSON envelopes go to stdout. Mixing the two would break NDJSON parsers.

## Requirements

### Functional

| ID | Status | Requirement |
|---|---|---|
| FR-001 | Mandatory | A new package `packages/agent-output/` exists with the standard Waaseyaa package shape: `composer.json` (sort-packages, layer-policy compliant, branch-alias), `src/`, `tests/`, `README.md`. PSR-4 namespace `Waaseyaa\AgentOutput\`. |
| FR-002 | Mandatory | `Waaseyaa\AgentOutput\AgentDetector` detects agent environment from these env vars (extensible via config): `CLAUDE_CODE`, `CURSOR_AGENT`, `CODEX_CLI`, `GEMINI_CLI`, `WINDSURF`, `JUNIE`, `COPILOT_AGENT`. Returns the detected client identifier or `null`. |
| FR-003 | Mandatory | `Waaseyaa\AgentOutput\FormatterInterface` defines the formatter contract: `supports(string $tool): bool`, `format(array $event): string` (returns one line of JSON terminated by `\n`). |
| FR-004 | Mandatory | First-party JSON formatters for: PHPUnit (`PhpUnitFormatter`), Pest (`PestFormatter`), PHPStan (`PhpStanFormatter`), `bin/check-package-layers` (`PackageLayersFormatter`), `bin/check-dead-code` (`DeadCodeFormatter`), `bin/check-getquery-bindings` (`GetQueryBindingsFormatter`), `bin/check-composer-policy` (`ComposerPolicyFormatter`), `tools/drift-detector.sh` (`DriftDetectorFormatter`). |
| FR-005 | Mandatory | Each framework CI gate / test command honors a `--output=json` flag (or equivalently the `WAASEYAA_OUTPUT=json` env var). When the flag is set or agent env is detected, output is JSON; otherwise output is unchanged. |
| FR-006 | Mandatory | When agent env is detected but `--output` is not passed, the command defaults to `--output=json`. Human terminal sessions (no agent env, no flag) are completely unchanged. |
| FR-007 | Mandatory | The JSON envelope schema is documented in `docs/specs/agent-output.md`. Required fields: `tool`, `result` (`pass` / `fail` / `unknown`). Optional fields per tool. NDJSON for multi-tool runs (one envelope per line). |
| FR-008 | Mandatory | Failure envelopes carry actionable detail. PHPUnit failures include `file`, `line`, `message`, and one-frame stack context. CI gate failures include the offending entity (file/line/edge) and rule that failed. |
| FR-009 | Mandatory | Errors go to stderr; JSON envelopes go to stdout. NDJSON parsability is asserted in tests. |
| FR-010 | Mandatory | Unit test for `AgentDetector` covering each documented env var + an unknown env var + no env vars set. |
| FR-011 | Mandatory | Contract test for each formatter (FR-004) covering: a passing run, a failing run, an empty run. Envelope JSON-validates against the documented schema. |
| FR-012 | Mandatory | Integration test runs `vendor/bin/phpunit --output=json` over a fixture suite (mix of passes + failures), parses the envelope, asserts the envelope is well-formed and reflects the suite outcome. |
| FR-013 | Mandatory | Integration test runs `bin/check-package-layers --output=json` over the live monorepo, parses the envelope, asserts `result: pass` (or `fail` with the live violation set if any exists). |
| FR-014 | Mandatory | New spec `docs/specs/agent-output.md` documents: the envelope schema, the detected env vars, the formatter list, the opt-in flag, the NDJSON discipline, and the steps to register a third-party formatter. |
| FR-015 | Mandatory | `packages/agent-output/composer.json` is added to `split.yml`'s matrix (mirrors the new-package release checklist in memory). |
| FR-016 | Mandatory | The Packagist registration step is documented in WP05's release-cut handoff. The first split push must precede Packagist registration (per memory `feedback_new_package_release_checklist`). |

### Non-functional

| ID | Status | Threshold |
|---|---|---|
| NFR-001 | Mandatory | `AgentDetector::detect()` returns in ≤ 1 ms (env-var lookup only, no I/O). Measured by microbenchmark. |
| NFR-002 | Mandatory | Formatter overhead is ≤ 5% of the underlying tool's runtime. Measured on the PHPUnit fixture suite (FR-012). |
| NFR-003 | Mandatory | Each JSON envelope is ≤ 500 bytes on a passing run, ≤ 2 KB per failure on a failing run (median). The compact-but-honest goal: token reduction is the explicit value proposition. |
| NFR-004 | Mandatory | Token-reduction claim is empirically verified in WP06's smoke test: run PHPUnit on `packages/bimaaji/` with and without agent formatting; assert the JSON envelope is ≥ 90% smaller than the standard output by character count. |

### Constraints

| ID | Status | Constraint |
|---|---|---|
| C-001 | Mandatory | `packages/agent-output/` is Layer 0 (Foundation tier). It depends only on PHP-level primitives and the framework's existing output abstractions (if any). No upward layer dependencies. `bin/check-package-layers` passes. |
| C-002 | Mandatory | Human terminal output is **unchanged** when no agent env is detected and `--output=json` is not passed. This is the PAO transparency invariant: agents get JSON, humans get the usual output. |
| C-003 | Mandatory | The mission does not couple `agent-output` to bimaaji or to any AI package. A consumer who never installs bimaaji can still use `agent-output` for their test runs. |
| C-004 | Mandatory | Errors stay on stderr; JSON envelopes go to stdout (FR-009). Mixing breaks NDJSON parsers. |
| C-005 | Mandatory | The new package follows the new-package release checklist: split.yml entry (FR-015), GitHub repo provisioning (WP05), Packagist registration after first split push (FR-016 + WP05). |
| C-006 | Mandatory | `composer verify` is green on the merge commit. |
| C-007 | Mandatory | No CI hooks bypassed. |

## Success criteria

| ID | Metric | How verified |
|---|---|---|
| SC-001 | Token cost of `vendor/bin/phpunit` output is reduced by ≥ 90% under agent env. | NFR-004 character-count assertion in WP06. |
| SC-002 | Human terminal sessions running the same commands see no behavior change. | Integration test runs each affected command without agent env vars, asserts standard verbose output is preserved byte-for-byte (or, more practically, asserts the agent JSON envelope is NOT emitted). |
| SC-003 | All 8 first-party formatters (FR-004) emit well-formed JSON envelopes for pass / fail / empty cases. | Contract tests pass. |
| SC-004 | The new `packages/agent-output/` package builds, lints, tests, and splits cleanly. | `composer verify` green; split.yml matrix run completes; manual Packagist registration after first tag. |
| SC-005 | A third-party package author can register a new formatter for their CLI command. | Documented in `docs/specs/agent-output.md` (FR-014); smoke-tested manually in WP05 with a fixture third-party formatter. |
| SC-006 | `composer verify` green on merge commit. | CI status check. |

## Key entities

| Entity | Role | Net change |
|---|---|---|
| `packages/agent-output/composer.json` (new) | Package manifest. | +1 file. |
| `packages/agent-output/README.md` (new) | Public README. | +1 file. |
| `Waaseyaa\AgentOutput\AgentDetector` (new) | Env-var detection. | +1 file. |
| `Waaseyaa\AgentOutput\FormatterInterface` (new) | Formatter contract. | +1 file. |
| Formatters (8 classes, new) | One per supported tool. | +8 files. |
| `packages/agent-output/tests/` | Unit + contract tests. | +tests directory. |
| Integration test under `tests/Integration/PhaseN/AgentOutput/` | Live-monorepo smoke tests. | +files. |
| `docs/specs/agent-output.md` (new) | Doctrine spec. | +1 file. |
| `split.yml` matrix entry | Per-package split configuration. | Edit. |
| Each affected CLI command | `--output=json` flag. | Edit per command. |
| `CHANGELOG.md` | `[Unreleased]` entry. | Edit. |
| `CLAUDE.md` (root) | Mention in Commands section. | Edit. |

## Assumptions

- The framework's CLI commands already have a consistent options-parsing mechanism. Adding `--output=json` is a per-command edit, not a global infrastructure change. The plan re-verifies in WP01.
- The CI gate scripts (`bin/check-package-layers`, `bin/check-dead-code`, etc.) are PHP CLIs (not shell wrappers). They can call into `packages/agent-output/` directly. `tools/drift-detector.sh` is shell — its formatter wraps the shell output rather than being called by it.
- The `composer verify` script orchestrates the gates in sequence, emitting NDJSON when each gate's `--output=json` is active. The plan re-verifies whether `composer verify` needs any update to preserve NDJSON cleanly (e.g. suppress intermediate "Running check X..." human-progress lines).
- `WAASEYAA_OUTPUT=json` is a fine fallback if any command can't gain a flag immediately; the env-var path is the universal escape hatch.

## Out of scope

- HTML formatters or other non-JSON output formats. JSON is the agent-readable format; humans get the existing output unchanged.
- Coupling to bimaaji or any AI package. (`packages/agent-output/` is framework-wide infrastructure.)
- Auto-detection of new MCP clients beyond the documented list — extension is a one-line config edit, not a discovery mechanism.
- Backporting to consumer apps that have wrapped these tools in their own shell scripts. Consumers migrate as they wish.
- Changing the underlying tools' exit codes — they remain whatever PHPUnit / phpstan / check-* return today.

## WP outline (for /spec-kitty.plan)

The planner is free to revise. Indicative shape:

- **WP01 — Package scaffold + env detection.** Create `packages/agent-output/` with composer.json, README, autoload, layer compliance, branch-alias. Implement `AgentDetector` (FR-002). Unit tests (FR-010).
- **WP02 — Formatter contract + first formatter.** `FormatterInterface` (FR-003). `PhpUnitFormatter` as the reference implementation. Contract test (FR-011). Document the envelope schema in `docs/specs/agent-output.md` (FR-014).
- **WP03 — Remaining formatters.** PestFormatter, PhpStanFormatter, PackageLayersFormatter, DeadCodeFormatter, GetQueryBindingsFormatter, ComposerPolicyFormatter, DriftDetectorFormatter (FR-004). Contract tests for each.
- **WP04 — CLI integration.** Add `--output=json` to each affected command (FR-005). Wire each command to its formatter. Auto-on under agent env (FR-006). Integration tests (FR-012, FR-013).
- **WP05 — Release pipeline + docs.** Add to `split.yml` matrix (FR-015). Document Packagist registration handoff (FR-016). Smoke-test third-party formatter registration (SC-005). CHANGELOG. CLAUDE.md mention.
- **WP06 — Token-reduction verification + verify.** NFR-004 character-count assertion. SC-001 ≥90% reduction empirical check. Full `composer verify` green.

## References

- Laravel PAO research summary: `docs/plans/2026-05-21-ai-ecosystem-beta-tightening.md` §"Context". External reference: https://github.com/laravel/pao.
- Memory: `feedback_new_package_release_checklist` — the three-step split.yml + GitHub repo + Packagist registration pattern.
- Memory: `feedback_release_split_pre_flight_gap` — `bin/check-release-tag-parity` is post-tag; missing split.yml entries half-ship a release.
- Memory: `feedback_release_cut_sync_commit_bug` — pre-alpha.179 release-cut.yml bugs; ensure this mission's first release runs on a post-fix release-cut.
- CI gate scripts: `bin/check-package-layers`, `bin/check-dead-code`, `bin/check-getquery-bindings`, `bin/check-composer-policy`, `tools/drift-detector.sh`.
- CLAUDE.md "Commands" — list of test + CI commands affected.
