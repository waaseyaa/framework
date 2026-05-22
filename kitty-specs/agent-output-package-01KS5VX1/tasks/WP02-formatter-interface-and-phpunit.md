---
work_package_id: WP02
title: FormatterInterface + PhpUnitFormatter + schema spec
dependencies:
- WP01
requirement_refs:
- FR-003
- FR-004
- FR-007
- FR-008
- FR-011
- FR-014
- NFR-003
- C-001
- C-004
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts generated on main. Implementation may branch from main.
subtasks:
- T004
- T005
- T006
- T007
history: []
authoritative_surface: packages/agent-output/src/
execution_mode: code_change
owned_files:
- packages/agent-output/src/FormatterInterface.php
- packages/agent-output/src/Formatter/PhpUnitFormatter.php
- packages/agent-output/tests/Contract/PhpUnitFormatterTest.php
- docs/specs/agent-output.md
tags: []
---

## Objective

Define the formatter contract, ship the reference implementation (`PhpUnitFormatter`), and pin the envelope schema in `docs/specs/agent-output.md`. The seven remaining formatters in WP03 inherit this shape — getting it right here saves rework later.

## Subtasks

### T004 — `FormatterInterface`

```php
namespace Waaseyaa\AgentOutput;

/**
 * @api
 */
interface FormatterInterface
{
    public function supports(string $tool): bool;

    /**
     * @param array<string, mixed> $event Tool-specific event payload.
     * @return string A single NDJSON line terminated with "\n".
     */
    public function format(array $event): string;
}
```

`supports()` lets a registry dispatch the right formatter at runtime (WP04 CLI integration uses this). `format()` returns the full NDJSON line (caller writes to stdout directly).

### T005 — `PhpUnitFormatter`

`packages/agent-output/src/Formatter/PhpUnitFormatter.php`:

```php
public function format(array $event): string
{
    $payload = [
        'tool'        => 'phpunit',
        'result'      => $event['failed'] > 0 ? 'fail' : 'pass',
        'suite'       => $event['suite'] ?? null,
        'passed'      => $event['passed'],
        'failed'      => $event['failed'],
        'skipped'     => $event['skipped'] ?? 0,
        'duration_ms' => $event['duration_ms'] ?? null,
        'failures'    => $event['failures'] ?? [],
    ];

    return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
}
```

Failure entries (FR-008) carry `{file, line, message, test}` minimum. `JSON_PRETTY_PRINT` is forbidden — NDJSON requires one line per envelope.

### T006 — Schema spec (`docs/specs/agent-output.md`)

Document (FR-014):

- Envelope shape (required: `tool`, `result`; optional per-tool fields)
- The 8 client env vars and their identifiers
- The 8 first-party formatters and the tools they cover
- `--output=json` flag + `WAASEYAA_OUTPUT=json` env-var fallback
- NDJSON discipline (stdout only, one envelope per line, no nested newlines)
- Third-party formatter registration steps (implement `FormatterInterface`, mark with `@api`, register via service-provider hook or env-var lookup)

Add a section explicitly stating the human-output invariant (C-002 / SC-002): no agent env + no flag = standard output preserved.

### T007 — Contract test

`PhpUnitFormatterTest` (FR-011):
- `formatsPassingRun` — envelope `result: pass`, `failed: 0`, no `failures` items.
- `formatsFailingRun` — envelope `result: fail`, `failures` has at least one entry with `file`/`line`/`message`.
- `formatsEmptyRun` — envelope `result: pass`, `passed: 0`, `failed: 0`.
- `outputIsValidNdjson` — output ends in `\n`, contains exactly one `\n`, `json_decode(JSON_THROW_ON_ERROR)` round-trips.
- `envelopeUnder500BytesForPass` (NFR-003) — passing envelope size ≤ 500 bytes.

## Definition of Done

- [ ] `FormatterInterface` exists, marked `@api`.
- [ ] `PhpUnitFormatter` implements the interface with the documented field set.
- [ ] All contract test methods pass.
- [ ] `docs/specs/agent-output.md` exists and covers FR-014's documented items.
- [ ] `composer cs-check`, `phpstan`, layer + dead-code gates clean.

## Risks and notes

- **Event shape:** `$event` is a flexible assoc array — choose field names that match PHPUnit 10's actual event payloads where possible (`PHPUnit\Event\Test\Passed::testMethod()` etc.). Document the input contract in a class-level docblock so WP04's listener knows what to pass.
- **`JSON_THROW_ON_ERROR` pair:** Per memory `feedback_modern_php_rules` (or close enough — the framework gotcha), pair `json_encode(JSON_THROW_ON_ERROR)` with `json_decode(JSON_THROW_ON_ERROR)` in tests.
- **Spec doc placement:** `docs/specs/agent-output.md` adds a new file under specs — drift-detector indexes specs, so include the standard `<!-- Spec reviewed YYYY-MM-DD -->` stamp at the top.
