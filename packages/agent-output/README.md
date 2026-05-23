# waaseyaa/agent-output

> Agent-optimized output. Detect AI-agent runtimes and emit compact JSON
> envelopes from verbose CLI tools so an agent's parent context window
> doesn't drown in PHPUnit / PHPStan / CI-gate spew.

When an AI agent (Claude Code, Cursor, Codex, Copilot, Gemini CLI, Windsurf,
Junie) invokes `vendor/bin/phpunit` or `bin/check-package-layers` as part of an
implement-or-review loop, the verbose tool output gets piped back into the
agent's context window. A full PHPUnit run on this monorepo is ~12k lines.
`check-package-layers` is ~600. Per iteration. Per gate. The token cost is
real.

`waaseyaa/agent-output` solves this in the framework. It:

- Detects agent runtime from a list of well-known env vars
  (`CLAUDE_CODE`, `CURSOR_AGENT`, etc.) — extensible.
- Provides a `FormatterInterface` and first-party JSON formatters for
  PHPUnit / Pest / PHPStan / our `bin/check-*` CI gates / drift-detector.
- Each affected command honors `--output=json` or `WAASEYAA_OUTPUT=json`,
  and auto-activates under agent env.
- Human terminal output is **unchanged** when no agent env is detected and
  the flag is not passed. Agents get JSON; humans get the usual output.

The package is Layer 0 (Foundation tier). It depends only on PHP-level
primitives — no `waaseyaa/*` runtime deps. Consumers can install it
standalone without pulling the full framework.

Pattern lifted from Laravel PAO (2026-05). Framework-native because (a) PAO
does not cover our custom CI gates, and (b) we want first-party
integration rather than an external dev dependency.

## Status

Beta. As of M4 WP05 (2026-05-23) every M4-tracked CI gate plus PHPUnit
honors the `--output=json` / `WAASEYAA_OUTPUT=json` contract:

| Surface | Activation | Formatter |
|---|---|---|
| `bin/check-package-layers` | `--output=json` / env | `PackageLayersFormatter` |
| `bin/check-dead-code` | `--output=json` / env | `DeadCodeFormatter` |
| `bin/check-getquery-bindings` | `--output=json` / env | `GetQueryBindingsFormatter` |
| `bin/check-composer-policy` | `--output=json` / env | `ComposerPolicyFormatter` |
| `bin/check-phpstan` | `--output=json` / env | `PhpStanFormatter` |
| `tools/drift-detector.sh` | `--output=json` / env | `DriftDetectorFormatter` |
| `vendor/bin/phpunit` | `WAASEYAA_OUTPUT=json` (no `--output=json` because PHPUnit doesn't surface custom CLI flags) | `PhpUnitFormatter` via `AgentOutputPhpUnitExtension` |

The empirical ≥90%-reduction verification (WP06) is the remaining
mission deliverable.

See `docs/specs/agent-output.md` for the envelope schema, formatter
contract, and third-party extension guide.

## First-release checklist

Per the framework's three-step new-package release pattern (memory
`feedback_new_package_release_checklist`), this package lands the
release pipeline in WP05 + requires two manual handoff steps:

1. **split.yml matrix entry** (✅ landed in WP05) — see
   `.github/workflows/split.yml`. Without this entry, the per-package
   subtree split never runs and consumers never see a tag.
2. **GitHub repo provisioning** (manual): `gh repo create waaseyaa/agent-output --public`
   — must happen BEFORE the next release tag is pushed. The split
   workflow needs a real remote to push to.
3. **Packagist registration** (manual, AFTER first split push): submit
   `https://github.com/waaseyaa/agent-output` at
   `https://packagist.org/packages/submit`. Packagist requires the
   remote to have at least one ref it can resolve, so this step must
   come after the first split tag lands on the new repo (typically the
   release-cut workflow's first run after this PR merges).
