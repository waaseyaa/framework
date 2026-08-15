<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Log\Processor;

use Waaseyaa\Foundation\Log\LogRecord;
use Waaseyaa\Foundation\Security\SensitiveValue;

/**
 * Redacts secret-bearing values from log messages and recursive context before
 * the record reaches any handler or buffer.
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
 * Typed {@see SensitiveValue} objects are always replaced. Throwable chains and
 * Stringable values are converted to a bounded sanitized view; unknown objects
 * and resources are replaced rather than passed to formatters.
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
        'credential',
        'private_key',
        'passphrase',
    ];

    /** @var list<string> Lowercased union of DENYLIST + any caller-supplied extra keywords. */
    private readonly array $keywords;

    /** @var \WeakMap<self, list<string>>|null */
    private static ?\WeakMap $registeredRepresentations = null;

    /**
     * @param list<string> $extraKeys Additional keywords to add to the denylist.
     *                                The built-in {@see DENYLIST} is always applied.
     * @param list<string> $registeredValues Synthetic or resolved values whose common encodings
     *                                       must be structurally replaced. Values shorter than
     *                                       eight bytes are ignored to avoid destructive matches.
     */
    public function __construct(
        array $extraKeys = [],
        #[\SensitiveParameter]
        array $registeredValues = [],
    ) {
        $merged = array_merge(self::DENYLIST, $extraKeys);
        $this->keywords = array_map('strtolower', $merged);
        $representations = [];
        foreach ($registeredValues as $value) {
            if (strlen($value) < 8) {
                continue;
            }
            foreach ($this->representations($value) as $representation) {
                if ($representation !== '') {
                    $representations[$representation] = true;
                }
            }
        }
        $registeredRepresentations = array_keys($representations);
        usort($registeredRepresentations, static fn(string $left, string $right): int => strlen($right) <=> strlen($left));
        self::$registeredRepresentations ??= new \WeakMap();
        self::$registeredRepresentations[$this] = $registeredRepresentations;
    }

    public function process(LogRecord $record): LogRecord
    {
        return new LogRecord(
            level: $record->level,
            message: $this->redactString($record->message),
            context: $this->redactContext($record->context),
            channel: $record->channel,
            timestamp: $record->timestamp,
        );
    }

    /**
     * Walk a context array, applying key-denylist and value-backstop rules recursively.
     *
     * @param array<array-key, mixed> $context
     * @return array<array-key, mixed>
     */
    private function redactContext(array $context): array
    {
        $result = [];

        foreach ($context as $key => $value) {
            if (is_string($key) && $this->keyMatches($key)) {
                // Key denylist hit — redact regardless of value type.
                $result[$key] = self::SENTINEL;
            } elseif (is_array($value)) {
                // Recurse into nested arrays.
                $result[$key] = $this->redactContext($value);
            } else {
                $result[$key] = $this->redactValue($value);
            }
        }

        return $result;
    }

    private function redactValue(mixed $value): mixed
    {
        if ($value instanceof SensitiveValue) {
            return self::SENTINEL;
        }
        if (is_string($value)) {
            return $this->redactString($value);
        }
        if ($value instanceof \Throwable) {
            return [
                'type' => $value::class,
                'code' => $value->getCode(),
                'message' => $this->redactString($value->getMessage()),
                'previous' => $value->getPrevious() === null ? null : $this->redactValue($value->getPrevious()),
            ];
        }
        if ($value instanceof \Stringable) {
            try {
                return $this->redactString((string) $value);
            } catch (\Throwable) {
                return self::SENTINEL;
            }
        }
        if (is_object($value) || is_resource($value)) {
            return self::SENTINEL;
        }

        return $value;
    }

    private function redactString(string $value): string
    {
        if ($this->valueMatches($value)) {
            return self::SENTINEL;
        }

        return str_replace(self::$registeredRepresentations[$this] ?? [], self::SENTINEL, $value);
    }

    /** @return list<string> */
    private function representations(#[\SensitiveParameter] string $value): array
    {
        $base64 = base64_encode($value);
        $base64Url = rtrim(strtr($base64, '+/', '-_'), '=');
        $representations = [
            $value,
            $base64,
            $base64Url,
            rawurlencode($value),
        ];
        try {
            $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $representations[] = substr($json, 1, -1);
        } catch (\JsonException) {
            // Binary values still retain raw, base64 and URL-encoded coverage.
        }

        return $representations;
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new \LogicException('Sink sanitizers cannot be serialized.');
    }

    private function __clone() {}

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
