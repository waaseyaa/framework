# Agent-output envelope schema

> Compact NDJSON envelopes for verbose CLI tools, emitted only when an AI
> agent is running the command. Human terminal sessions see the
> unmodified tool output.

**Package:** `waaseyaa/agent-output` (Layer 0).
**Spec status:** M4 WP02 scaffold + `PhpUnitFormatter` envelope. WP03
adds the remaining seven first-party formatters; WP04 wires the
`--output=json` flag into each affected command.

## Activation

A formatter emits its envelope (instead of the standard tool output)
when **any** of the following is true:

1. The command was invoked with `--output=json`.
2. The `WAASEYAA_OUTPUT` env var is set to `json`.
3. `Waaseyaa\AgentOutput\AgentDetector::detect()` returns a non-null
   client identifier (i.e. one of the documented env vars is set).

Otherwise the standard verbose output is preserved byte-for-byte
(C-002 — the transparency invariant).

## Detected agent runtimes

The seven launch clients detected by `AgentDetector` (M4 WP01):

| Env var          | Identifier        |
|------------------|-------------------|
| `CLAUDE_CODE`    | `claude-code`     |
| `CURSOR_AGENT`   | `cursor`          |
| `CODEX_CLI`      | `codex`           |
| `GEMINI_CLI`     | `gemini`          |
| `WINDSURF`       | `windsurf`        |
| `JUNIE`          | `junie`           |
| `COPILOT_AGENT`  | `github-copilot`  |

Falsy env values (empty string, `"0"`) are treated as not-set so a CI
runner that leaves a stub variable lying around does not accidentally
activate JSON formatting. Adding a new client is a one-line edit to
`AgentDetector::CLIENTS`.

## Envelope shape

Every envelope is a single line of JSON terminated with a single `\n`.
Required fields:

| Field    | Type   | Notes                                          |
|----------|--------|------------------------------------------------|
| `tool`   | string | Stable per-formatter identifier (e.g. `phpunit`). |
| `result` | string | One of `pass`, `fail`, `unknown`.              |

Optional fields are per-tool. The PHPUnit envelope (M4 WP02, this WP)
ships with:

```json
{"tool":"phpunit","result":"pass","suite":"bimaaji","passed":47,"failed":0,"skipped":2,"duration_ms":8123,"failures":[]}
```

`failures` is always present. On a passing run it is an empty array.
On a failing run each entry carries at least `file`, `line`, `test`,
and `message` (FR-008 — failures must remain debuggable). The
forthcoming WP03 formatters mirror the same shape for their respective
events:

| Formatter (WP03)                | Tool identifier              | `result` source            |
|---------------------------------|------------------------------|----------------------------|
| `PestFormatter`                 | `pest`                       | `tests_failed > 0`         |
| `PhpStanFormatter`              | `phpstan`                    | `errors > 0`               |
| `PackageLayersFormatter`        | `check-package-layers`       | non-empty `violations`     |
| `DeadCodeFormatter`             | `check-dead-code`            | new findings beyond baseline |
| `GetQueryBindingsFormatter`     | `check-getquery-bindings`    | new offenders beyond baseline |
| `ComposerPolicyFormatter`       | `check-composer-policy`      | any rule failure           |
| `DriftDetectorFormatter`        | `drift-detector`             | non-empty drift list       |

## NDJSON discipline

- One envelope per line. Multi-tool runs (e.g. `composer verify`) emit
  one envelope per tool, back-to-back, separated by `\n`.
- No embedded newlines inside the JSON payload (no `JSON_PRETTY_PRINT`).
- Envelopes go to **stdout**. Tool errors stay on stderr (C-004).
  Mixing breaks NDJSON parsers.
- Each envelope must JSON-round-trip cleanly through
  `json_decode(JSON_THROW_ON_ERROR)`.

## Size discipline (NFR-003)

Targets:

- Passing envelope: ≤ 500 bytes (median).
- Failing envelope: ≤ 2 KB per failure entry (median).

The compact-but-honest goal is the explicit value proposition — see
SC-001 (≥ 90% character reduction vs. standard tool output, empirically
verified in M4 WP06).

## Adding a third-party formatter

A consumer package can ship its own formatter to wrap a custom CLI
tool:

1. Implement `Waaseyaa\AgentOutput\FormatterInterface`. The class must
   live under PSR-4 and be marked `@api` (the dead-code gate ignores
   `@api`-marked classes).
2. Add a class-level docblock describing the input event shape and the
   output envelope shape.
3. Register the formatter with the consuming command (M4 WP04 lands the
   first-party registration path; third parties either wire through
   the same path or instantiate the formatter directly at command end).
4. Write a contract test mirroring `PhpUnitFormatterTest` (pass / fail
   / empty / NDJSON validity / size discipline).

There is no central formatter registry in M4. The package ships eight
first-party formatters under `Waaseyaa\AgentOutput\Formatter\*`; third
parties extend by adding their own classes in their own namespace.

<!-- Spec reviewed 2026-05-22 — agent-output-package-01KS5VX1 (WP02). -->
