<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Log\Processor;

use Waaseyaa\Foundation\Log\LogRecord;

/**
 * Redacts secret-bearing values from log record context before the record reaches any handler.
 *
 * ## Redaction rules
 *
 * **Key denylist (primary mechanism):** A context key whose lowercased form contains any
 * denylist keyword as a substring is redacted regardless of its value. The default denylist
 * is {@see DENYLIST}. Extra keywords can be supplied via the constructor.
 *
 * Examples of matched keys: `password`, `API_KEY`, `Authorization`, `app_secret`,
 * `user_access_token`, `session_cookie`.
 *
 * **Value backstop (secondary, conservative):** A string *value* under a benign-looking key
 * is also redacted if it contains any denylist keyword as a case-insensitive substring.
 * This catches values that embed credentials verbatim, e.g.:
 *   - `'Authorization: Bearer eyJ...'`  (contains "authorization")
 *   - `'username=u&password=hunter2'`  (contains "password")
 *
 * Non-string values (integers, booleans, null, objects) are **never** redacted by the value
 * backstop — only the key denylist applies to them. This keeps the backstop conservative and
 * avoids false positives on numeric IDs, flags, and structured objects.
 *
 * **Recursive:** context arrays are walked to arbitrary depth; each sub-array receives the
 * same key+value inspection. Array structure and non-sensitive entries are preserved exactly.
 *
 * **Immutable:** `process()` always returns a new {@see LogRecord} — the input is never mutated.
 *
 * @api
 */
final class RedactorProcessor implements ProcessorInterface
{
    /** Sentinel replacement for any redacted value. */
    public const SENTINEL = '[REDACTED]';

    /**
     * Default set of keywords that trigger redaction when found as a case-insensitive
     * substring in a context key name or in a string context value.
     *
     * Kept as a public constant so security auditors can verify the list at a glance.
     *
     * @var list<string>
     */
    public const DENYLIST = [
        'password',
        'token',
        'secret',
        'authorization',
        'api_key',
        'cookie',
    ];

    /** @var list<string> Lowercased union of DENYLIST + any caller-supplied extra keywords. */
    private readonly array $keywords;

    /**
     * @param list<string> $extraKeys Additional keywords to add to the denylist.
     *                                The built-in {@see DENYLIST} is always applied.
     */
    public function __construct(array $extraKeys = [])
    {
        $merged = array_merge(self::DENYLIST, $extraKeys);
        $this->keywords = array_map('strtolower', $merged);
    }

    public function process(LogRecord $record): LogRecord
    {
        return new LogRecord(
            level: $record->level,
            message: $record->message,
            context: $this->redactContext($record->context),
            channel: $record->channel,
            timestamp: $record->timestamp,
        );
    }

    /**
     * Walk a context array, applying key-denylist and value-backstop rules recursively.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function redactContext(array $context): array
    {
        $result = [];

        foreach ($context as $key => $value) {
            if ($this->keyMatches($key)) {
                // Key denylist hit — redact regardless of value type.
                $result[$key] = self::SENTINEL;
            } elseif (is_array($value)) {
                // Recurse into nested arrays.
                $result[$key] = $this->redactContext($value);
            } elseif (is_string($value) && $this->valueMatches($value)) {
                // Value backstop — string value contains a denylist keyword.
                $result[$key] = self::SENTINEL;
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Returns true when the lowercased key name contains any denylist keyword as a substring.
     */
    private function keyMatches(string $key): bool
    {
        $lower = strtolower($key);
        foreach ($this->keywords as $keyword) {
            if (str_contains($lower, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns true when the string value contains any denylist keyword as a case-insensitive
     * substring.  Only called for string values; never called for other types.
     */
    private function valueMatches(string $value): bool
    {
        $lower = strtolower($value);
        foreach ($this->keywords as $keyword) {
            if (str_contains($lower, $keyword)) {
                return true;
            }
        }

        return false;
    }
}
