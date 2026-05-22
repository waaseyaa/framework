<?php

declare(strict_types=1);

namespace Waaseyaa\AgentOutput;

/**
 * Contract for agent-output JSON formatters.
 *
 * Each formatter wraps a tool-specific event payload (PHPUnit, PHPStan,
 * one of the bin/check-* gates, …) into a single NDJSON line — the
 * canonical "agent-readable" envelope.
 *
 * NDJSON discipline (per `docs/specs/agent-output.md`):
 *  - One envelope per line, terminated by exactly one `\n`.
 *  - No embedded newlines inside the JSON payload.
 *  - Envelopes go to stdout; tool errors stay on stderr.
 *  - `format()` returns the JSON line; the caller decides where to write it.
 *
 * Implementations MUST honor:
 *  - Required envelope keys: `tool` (string) and `result` (`pass` |
 *    `fail` | `unknown`).
 *  - Optional per-tool fields (durations, counts, per-failure detail).
 *  - Failure entries carry enough context to act on (file/line/message
 *    or equivalent structural detail).
 *
 * Third-party formatters extend this interface and mark the class with
 * `@api`; the agent-output package itself ships eight first-party
 * formatters (one for each CI gate / test runner the framework owns).
 *
 * @api
 */
interface FormatterInterface
{
    /**
     * Whether this formatter knows how to wrap events from the given
     * tool identifier (e.g. `'phpunit'`, `'phpstan'`,
     * `'check-package-layers'`).
     */
    public function supports(string $tool): bool;

    /**
     * Wrap a tool-specific event payload into a single NDJSON line.
     *
     * The returned string MUST:
     *  - End with exactly one `\n`.
     *  - Contain no other newline characters.
     *  - JSON-round-trip cleanly through `json_decode(JSON_THROW_ON_ERROR)`.
     *
     * @param array<string, mixed> $event Tool-specific event payload.
     */
    public function format(array $event): string;
}
