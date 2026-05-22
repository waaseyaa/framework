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

Beta. M4 WP01 ships `AgentDetector` (env detection). Subsequent WPs add the
formatter interface (WP02), the eight first-party formatters (WP03), CLI
integration (WP04), release-pipeline wiring (WP05), and the empirical
≥90%-reduction verification (WP06).

See `docs/specs/agent-output.md` (filed in WP02) for the envelope schema,
formatter contract, and third-party extension guide.
