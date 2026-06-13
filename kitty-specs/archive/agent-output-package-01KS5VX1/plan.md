# Implementation Plan — agent-output-package-01KS5VX1

**Mission:** `agent-output-package-01KS5VX1`
**Status:** Plan
**Spec:** [spec.md](spec.md)
**Design doc:** `docs/plans/2026-05-21-ai-ecosystem-beta-tightening.md` §M4
**Depends on:** none (independent of M1/M2/M3/M5)
**Blocks:** none
**External reference:** Laravel PAO (https://github.com/laravel/pao, 2026-05 release)

## Branch contract

- Current branch at plan time: `main`
- Planning + base branch: `main`
- Merge target: `main`

## Engineering alignment

A new Layer-0 package, `packages/agent-output/`, that solves a quantitative agent-token problem. Detect agent env (via well-known env vars), intercept verbose CLI tool output (PHPUnit, PHPStan, our CI gate scripts, etc.), and emit compact NDJSON envelopes instead — ≥90% character reduction is the explicit success metric (SC-001 / NFR-004). Human terminal output is byte-identical when no agent env is present and `--output=json` is not passed (C-002 — the PAO transparency invariant).

This is framework-wide infrastructure, deliberately decoupled from bimaaji and AI packages (C-003). Any consumer benefits. Pattern lifted from PAO but framework-native because (a) PAO doesn't know about our custom CI gates, and (b) we want first-party integration rather than an external dev dependency.

## Architecture decisions

### AD-01 — Package placement and layer

`packages/agent-output/` lives at Layer 0 alongside `foundation`, `cache`, `analytics`, etc. (C-001). It depends only on PHP-level primitives — no `waaseyaa/*` runtime deps. Test fixtures may depend on higher layers via `require-dev` (permitted per CLAUDE.md), but production code is self-contained so a consumer can install it standalone.

### AD-02 — Agent detection mechanism

`AgentDetector::detect(): ?string` is an env-var lookup only — no I/O, no filesystem checks (NFR-001 — ≤1 ms). Initial detector list (FR-002):

```php
[
    'CLAUDE_CODE'   => 'claude-code',
    'CURSOR_AGENT'  => 'cursor',
    'CODEX_CLI'     => 'codex',
    'GEMINI_CLI'    => 'gemini',
    'WINDSURF'      => 'windsurf',
    'JUNIE'         => 'junie',
    'COPILOT_AGENT' => 'github-copilot',
]
```

Returns the first matching client identifier or `null`. The mapping is a constant in the class; adding a new client is a 1-line edit. (Per spec §Edge cases — extension is one config entry, not a discovery mechanism.)

`WAASEYAA_OUTPUT=json` is the universal escape hatch — if any command can't gain a `--output=json` flag immediately, the env var path activates formatting regardless of agent detection (FR-005, FR-006).

### AD-03 — FormatterInterface contract

```php
interface FormatterInterface
{
    public function supports(string $tool): bool;
    public function format(array $event): string; // returns "<json>\n"
}
```

`$event` is a tool-specific assoc array (e.g. for PHPUnit: `{passed, failed, skipped, duration_ms, failures: [...]}`). Each formatter knows its own event shape; there's no shared event schema across tools — the unifying contract is the envelope ({tool, result, ...}) emitted by `format()`.

### AD-04 — Envelope schema (FR-007)

Required fields per envelope:

```json
{"tool": "phpunit", "result": "pass"}
```

Optional fields per tool. Failure envelopes include actionable detail (FR-008) — file/line for PHPUnit failures, offending-edge details for CI gates. Schema documented in `docs/specs/agent-output.md` (FR-014).

**NDJSON discipline:** one envelope per line, no nested newlines inside JSON (use `JSON_UNESCAPED_SLASHES` but never `JSON_PRETTY_PRINT`). Multi-tool runs emit one envelope per tool — `composer verify` becomes ~8 envelopes back-to-back. (FR-007, C-004)

### AD-05 — stdout vs. stderr discipline (FR-009 / C-004)

JSON envelopes → stdout. Errors → stderr. Mixing breaks NDJSON parsers. Each formatter is responsible for choosing the right stream; tests assert no JSON contamination on stderr and no error spew on stdout.

### AD-06 — CLI integration shape

Each affected command gains a `--output=json` flag. The command's bootstrap checks:

1. `$argv` for `--output=json` (or `--output json`)
2. `WAASEYAA_OUTPUT=json` env var
3. `AgentDetector::detect()` — returns non-null

If any returns truthy, the command instantiates the relevant formatter, captures the verbose output (or instruments at the relevant event hooks), and emits the envelope on completion. Otherwise the command runs unchanged.

For PHPUnit, the entry point is a custom Printer / Result Listener registered via `phpunit.xml`. For PHPStan, the `--error-format` flag points at a custom formatter class. For our `bin/check-*` scripts (pure PHP CLIs), they directly import `agent-output` and call `format()` at the end of their run. `tools/drift-detector.sh` is shell — the formatter wraps its output by reading the shell's stdout (`DriftDetectorFormatter::parseRawOutput()`).

### AD-07 — Release pipeline (C-005)

New package needs three steps per memory `feedback_new_package_release_checklist`:

1. **WP05** adds `packages/agent-output/composer.json` to the `split.yml` matrix.
2. **WP05** documents GitHub-repo provisioning (manual `gh repo create waaseyaa/agent-output --public`).
3. **WP05** documents Packagist registration **after** the first split push (so Packagist resolves a real refs/tags ref).

Per memory `feedback_release_split_pre_flight_gap`, the `bin/check-release-tag-parity` runs after tag push — so missing split.yml entries half-ship a release. The matrix entry is non-negotiable.

## Test strategy

### Unit (`packages/agent-output/tests/Unit/`)

- `AgentDetectorTest` (FR-010): one method per documented env var + unknown-env + no-env path. 8 + 2 = 10 cases.
- `AgentDetectorTimingTest` (NFR-001): microbenchmark — `detect()` ≤ 1 ms median over 100 invocations.

### Contract (`packages/agent-output/tests/Contract/`)

- One test class per formatter (FR-011) — 8 classes. Each covers pass / fail / empty. Envelope must JSON-validate against the documented schema and `json_decode(JSON_THROW_ON_ERROR)` round-trip cleanly.

### Integration (`tests/Integration/PhaseN/AgentOutput/`)

- `PhpUnitJsonOutputTest` (FR-012): runs `vendor/bin/phpunit --output=json` over a fixture suite mixing passes + failures; parses the envelope; asserts well-formed.
- `PackageLayersJsonOutputTest` (FR-013): runs `bin/check-package-layers --output=json` against the live monorepo; parses; asserts.
- `HumanOutputUnchangedTest` (SC-002): runs the same commands without agent env vars + without `--output=json`; asserts the agent envelope is NOT emitted (negative assertion is sufficient — exact byte equality is brittle).
- `TokenReductionSmokeTest` (NFR-004 / SC-001): runs PHPUnit on `packages/bimaaji/` twice (with/without agent formatting); asserts JSON envelope ≥ 90% smaller by character count than standard output.

### Charter / governance

`.kittify/charter/charter.md` absent. Skipped.

## WP breakdown

| WP | Title | Depends on | Authoritative surface | LOC est. |
|---|---|---|---|---|
| **WP01** | Package scaffold + AgentDetector | — | `packages/agent-output/{composer.json,src/AgentDetector.php,tests/Unit/AgentDetectorTest.php}` | ~200 |
| **WP02** | FormatterInterface + PhpUnitFormatter + schema spec | WP01 | `packages/agent-output/src/{FormatterInterface,Formatter/PhpUnitFormatter}.php` + tests + `docs/specs/agent-output.md` | ~350 |
| **WP03** | Remaining 7 formatters | WP02 | 7 formatter classes + 7 contract tests | ~600 |
| **WP04** | CLI integration (--output=json + auto-detect) | WP03 | Edits to each affected command + integration tests | ~400 |
| **WP05** | Release pipeline + extension docs | WP04 | `split.yml` edit, `CLAUDE.md` edit, `CHANGELOG.md`, third-party formatter smoke test | ~150 |
| **WP06** | Token-reduction verification + composer verify | WP05 | `verification.md` + NFR-004 smoke test + full local gate sweep | ~100 |

## File-change summary

| Layer | Path | Action |
|---|---|---|
| L0 (new) | `packages/agent-output/composer.json` | create (WP01) |
| L0 | `packages/agent-output/src/AgentDetector.php` | create (WP01) |
| L0 | `packages/agent-output/src/FormatterInterface.php` | create (WP02) |
| L0 | `packages/agent-output/src/Formatter/{PhpUnit,Pest,PhpStan,PackageLayers,DeadCode,GetQueryBindings,ComposerPolicy,DriftDetector}Formatter.php` | create x8 (WP02 ×1, WP03 ×7) |
| L0 tests | `packages/agent-output/tests/{Unit,Contract}/*.php` | create (WP01-WP03) |
| Integration | `tests/Integration/PhaseN/AgentOutput/*.php` | create x4 (WP04) |
| Spec | `docs/specs/agent-output.md` | create (WP02) |
| CLI | `phpunit.xml`, `phpstan.neon`, `bin/check-*` PHP scripts | edit (WP04) |
| Release | `.github/workflows/split.yml` matrix | edit (WP05) |
| Docs | `CLAUDE.md`, `CHANGELOG.md` | edit (WP05) |
| Mission | `kitty-specs/agent-output-package-01KS5VX1/verification.md` | create (WP06) |

## Risk analysis

### R1 — PHPUnit Printer / Listener API surface (MEDIUM)

**Likelihood:** Medium. PHPUnit 10's TestRunner / Listener / Subscriber API has been in flux; our wrapper must use the supported event-subscriber pattern, not deprecated Printer classes.
**Mitigation:** WP02 starts with a 30-minute probe of PHPUnit 10.5's `PHPUnit\Event\*` subscribers — confirm the right hook for `TestPassed`, `TestFailed`, `TestSuiteFinished`. Document in plan.

### R2 — Shell-script formatter (DriftDetector) (LOW)

**Likelihood:** Low. `tools/drift-detector.sh` emits text; the formatter parses post-hoc.
**Mitigation:** `DriftDetectorFormatter::parseRawOutput(string $rawText): array` is a pure parser. Contract test feeds known fixtures. If the shell script's output format changes, the formatter test fails — small, fixable.

### R3 — CLI flag uniformity across heterogeneous tools (MEDIUM)

**Likelihood:** Medium. `phpunit` uses `--coverage-html`, `phpstan` uses `--error-format`, our `bin/check-*` are bespoke. Adding `--output=json` to each requires per-tool integration.
**Mitigation:** WP04 enumerates the per-tool integration shape. `WAASEYAA_OUTPUT=json` is the universal fallback for tools where the flag can't be cleanly added.

### R4 — NFR-004 ≥90% reduction threshold (LOW, but headline)

**Likelihood:** Low — PHPUnit verbose output on bimaaji is multi-KB; a 200-byte envelope is trivially ≥90% smaller.
**Mitigation:** WP06 smoke test makes the assertion explicit. If it fails, the formatter is doing something wrong (probably embedding too much per-test detail in pass-path envelopes).

### R5 — Release pipeline pre-flight gap (MEDIUM, mitigation captured)

**Likelihood:** Medium per memory `feedback_release_split_pre_flight_gap` — missing split.yml entries are silent half-ships.
**Mitigation:** WP05 explicitly adds the matrix entry and re-runs `bin/check-release-tag-parity` locally. The first release after merge runs in dry-mode if practical to confirm the new package ships.

### R6 — `composer verify` NDJSON discipline (LOW)

**Likelihood:** Low. `composer verify` orchestrates gates via `composer.json` `scripts` — sequential exec.
**Mitigation:** Confirm in WP04 that each gate's JSON envelope lands on its own line in the parent's stdout. If composer's script runner interleaves with progress lines ("> @check-X"), suppress under agent env via the same `WAASEYAA_OUTPUT=json` switch.

## Dependencies on downstream missions

None. M4 is fully independent.

## Charter / governance check

`.kittify/charter/charter.md` not present. Skipped per skill instructions.

## Out of scope (per spec)

- HTML or non-JSON output formats.
- Coupling to bimaaji or AI packages.
- Auto-discovery of unlisted MCP clients.
- Consumer-app shell-wrapper migration.
- Changing underlying tools' exit codes.
