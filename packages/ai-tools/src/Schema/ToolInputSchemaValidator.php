<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Schema;

/**
 * Validates agent-tool arguments against the tool's declared JSON Schema
 * (draft 2020-12) before the handler runs.
 *
 * Every `#[AsAgentTool]` already publishes an `inputSchema` — that schema is
 * the contract an agent reads from `tools/list`, so it is also the contract
 * the server must hold callers to. Without this, malformed arguments reach
 * handlers and depend on incidental downstream guards to fail safely (#2145).
 *
 * ## Supported keyword subset
 *
 * `type` (single or list), `properties`, `required`, `additionalProperties`
 * (`false` or a subschema), `enum`, `const`, `items`, `minItems`, `maxItems`,
 * `uniqueItems`, `minLength`, `maxLength`, `minimum`, `maximum`,
 * `exclusiveMinimum`, `exclusiveMaximum`, `pattern`, and the in-place
 * applicators `allOf`, `anyOf`, `oneOf`. Unrecognised keywords (`default`,
 * `description`, `format`, `$schema`, `x-*`) are ignored, so a schema is never
 * *rejected* for using vocabulary this validator does not police — a tool's
 * schema stays declarative documentation first. This is a documented subset,
 * not a claim of full draft 2020-12 conformance: `$ref` (and every other
 * keyword not listed above) is not implemented.
 *
 * The applicators exist because first-party tools advertise them (#2737):
 * `ContentToolSet` wraps nullable fields as `anyOf [<field>, {type: null}]`,
 * declares reference-list items as `oneOf [integer >= 1, non-empty string]`,
 * and marks list fields `uniqueItems`. Every keyword `tools/list` advertises
 * is enforced here — an advertised constraint the server ignores is a
 * contract lie, and the domain layer behind the handler is not the admission
 * authority.
 *
 * - `allOf`: every subschema is applied; their violations are reported as-is.
 * - `anyOf`: valid when at least one subschema accepts the value.
 * - `oneOf`: valid when exactly one subschema accepts the value; more than
 *   one match is a violation as well as none.
 * - When no alternative accepts the value and exactly one alternative fits
 *   the value's *type* (`anyOf [{string, maxLength}, {null}]` given a long
 *   string, `oneOf [{integer, minimum: 1}, {string}]` given `0`), that
 *   alternative's own violations are reported so the caller sees the concrete
 *   constraint at its real path (`values.related.0`). Otherwise a single
 *   "does not match any alternative" violation is reported at the path.
 * - `uniqueItems` compares decoded-JSON items with strict equality, like
 *   `enum`/`const`: `1` and `"1"` are distinct, and so are `1` and `1.0`.
 *
 * ## Decoded-JSON value model
 *
 * Values arrive as `json_decode($body, true)` produces them, so a JSON object
 * is an associative PHP array. `type: object` therefore accepts any array that
 * is not a non-empty list, and `type: array` accepts any list. The empty array
 * satisfies both — `{}` and `[]` are indistinguishable after an associative
 * decode, and rejecting one would break legitimate empty-argument calls.
 *
 * @api
 */
final class ToolInputSchemaValidator
{
    /**
     * Field path reported when the arguments value itself violates the schema
     * (rather than one of its members).
     */
    private const string ROOT_FIELD = '(arguments)';

    /**
     * @param array<string, mixed> $schema Declared JSON Schema for the tool input.
     * @param mixed                $value  Decoded arguments.
     *
     * @return list<array{field: string, message: string}> Empty when valid. The
     *         shape matches `ContentPublishingException::$fieldErrors`, so a
     *         transport can render schema and domain validation identically.
     */
    public static function validate(array $schema, mixed $value): array
    {
        $violations = [];
        self::check($schema, $value, '', $violations);

        return $violations;
    }

    /**
     * @param array<string, mixed> $schema
     * @param list<array{field: string, message: string}> $violations
     */
    private static function check(array $schema, mixed $value, string $path, array &$violations): void
    {
        if ($schema === []) {
            return;
        }

        if (isset($schema['type']) && !self::matchesType($schema['type'], $value)) {
            $violations[] = self::violation($path, sprintf(
                'Expected %s, got %s.',
                self::describeType($schema['type']),
                self::actualType($value),
            ));

            // A type mismatch makes every other keyword meaningless for this
            // value; reporting them too would bury the actual problem.
            return;
        }

        if (\array_key_exists('const', $schema) && $value !== $schema['const']) {
            $violations[] = self::violation($path, sprintf(
                'Must equal %s.',
                self::literal($schema['const']),
            ));

            return;
        }

        if (isset($schema['enum']) && \is_array($schema['enum']) && !\in_array($value, $schema['enum'], true)) {
            $violations[] = self::violation($path, sprintf(
                'Must be one of: %s.',
                implode(', ', array_map(self::literal(...), $schema['enum'])),
            ));

            return;
        }

        if (\is_string($value)) {
            self::checkString($schema, $value, $path, $violations);
        }

        // `is_int`/`is_float` already exclude booleans, so `true` never
        // reaches the numeric bounds (and never satisfies `type: number`).
        if (\is_int($value) || \is_float($value)) {
            self::checkNumeric($schema, $value, $path, $violations);
        }

        if (\is_array($value)) {
            // The object/array split follows the declared type when there is
            // one, and the value's own shape otherwise.
            self::isObjectShaped($schema, $value)
                ? self::checkObject($schema, $value, $path, $violations)
                : self::checkArray($schema, $value, $path, $violations);
        }

        // In-place applicators run after the value's own keywords, so a
        // declared `type` beside `anyOf` still short-circuits above with its
        // single violation rather than a union failure on top.
        if (\is_array($schema['allOf'] ?? null)) {
            foreach ($schema['allOf'] as $subschema) {
                if (\is_array($subschema)) {
                    self::check($subschema, $value, $path, $violations);
                }
            }
        }

        if (\is_array($schema['anyOf'] ?? null)) {
            self::checkAnyOf($schema['anyOf'], $value, $path, $violations);
        }

        if (\is_array($schema['oneOf'] ?? null)) {
            self::checkOneOf($schema['oneOf'], $value, $path, $violations);
        }
    }

    /**
     * @param array<mixed> $alternatives
     * @param list<array{field: string, message: string}> $violations
     */
    private static function checkAnyOf(array $alternatives, mixed $value, string $path, array &$violations): void
    {
        $outcomes = self::evaluateAlternatives($alternatives, $value, $path);
        if ($outcomes === [] || self::countMatches($outcomes) > 0) {
            return;
        }

        self::reportNoMatch($outcomes, $path, $violations);
    }

    /**
     * @param array<mixed> $alternatives
     * @param list<array{field: string, message: string}> $violations
     */
    private static function checkOneOf(array $alternatives, mixed $value, string $path, array &$violations): void
    {
        $outcomes = self::evaluateAlternatives($alternatives, $value, $path);
        if ($outcomes === []) {
            return;
        }

        $matches = self::countMatches($outcomes);
        if ($matches === 1) {
            return;
        }

        if ($matches === 0) {
            self::reportNoMatch($outcomes, $path, $violations);

            return;
        }

        $violations[] = self::violation($path, sprintf(
            'Matches %d of the %d allowed alternatives; exactly one is required.',
            $matches,
            \count($outcomes),
        ));
    }

    /**
     * Runs each alternative against the value into its own scratch list. An
     * alternative is "type-compatible" when it declares no `type` or the
     * value satisfies it — the signal `reportNoMatch()` uses to pick the
     * alternative whose constraints are worth reporting. A non-array entry is
     * an unusable schema, not a caller error, so it is skipped: an empty
     * result means "no verdict".
     *
     * @param array<mixed> $alternatives
     * @return list<array{typeCompatible: bool, violations: list<array{field: string, message: string}>}>
     */
    private static function evaluateAlternatives(array $alternatives, mixed $value, string $path): array
    {
        $outcomes = [];
        foreach ($alternatives as $alternative) {
            if (!\is_array($alternative)) {
                continue;
            }

            $scratch = [];
            self::check($alternative, $value, $path, $scratch);
            $outcomes[] = [
                'typeCompatible' => !isset($alternative['type']) || self::matchesType($alternative['type'], $value),
                'violations' => $scratch,
            ];
        }

        return $outcomes;
    }

    /** @param list<array{typeCompatible: bool, violations: list<array{field: string, message: string}>}> $outcomes */
    private static function countMatches(array $outcomes): int
    {
        return \count(array_filter($outcomes, static fn(array $outcome): bool => $outcome['violations'] === []));
    }

    /**
     * @param list<array{typeCompatible: bool, violations: list<array{field: string, message: string}>}> $outcomes
     * @param list<array{field: string, message: string}> $violations
     */
    private static function reportNoMatch(array $outcomes, string $path, array &$violations): void
    {
        $compatible = array_values(array_filter($outcomes, static fn(array $outcome): bool => $outcome['typeCompatible']));
        if (\count($compatible) === 1) {
            // The value has the shape of exactly one alternative, so that
            // alternative's own constraint (and nested path) is the message an
            // agent can act on; "no alternative matched" would hide it.
            foreach ($compatible[0]['violations'] as $violation) {
                $violations[] = $violation;
            }

            return;
        }

        $violations[] = self::violation($path, sprintf(
            'Does not match any of the %d allowed alternatives.',
            \count($outcomes),
        ));
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<mixed> $value
     */
    private static function isObjectShaped(array $schema, array $value): bool
    {
        if (isset($schema['type'])) {
            $types = \is_array($schema['type']) ? $schema['type'] : [$schema['type']];
            if (\in_array('array', $types, true) && !\in_array('object', $types, true)) {
                return false;
            }
            if (\in_array('object', $types, true)) {
                return true;
            }
        }

        return !array_is_list($value) || $value === [];
    }

    /**
     * @param array<string, mixed> $schema
     * @param list<array{field: string, message: string}> $violations
     */
    private static function checkString(array $schema, string $value, string $path, array &$violations): void
    {
        $length = mb_strlen($value);

        if (isset($schema['minLength']) && $length < (int) $schema['minLength']) {
            $violations[] = self::violation($path, sprintf(
                'Must be at least %d character(s) long, got %d.',
                (int) $schema['minLength'],
                $length,
            ));
        }

        if (isset($schema['maxLength']) && $length > (int) $schema['maxLength']) {
            $violations[] = self::violation($path, sprintf(
                'Must be at most %d character(s) long, got %d.',
                (int) $schema['maxLength'],
                $length,
            ));
        }

        if (isset($schema['pattern']) && \is_string($schema['pattern'])
            && self::matchesPattern($schema['pattern'], $value) === false
        ) {
            $violations[] = self::violation($path, 'Does not match the required pattern.');
        }
    }

    /**
     * An unusable schema pattern must not fail the *caller's* input, so an
     * invalid regex yields null (no verdict) rather than a violation.
     */
    private static function matchesPattern(string $pattern, string $value): ?bool
    {
        $delimited = '/' . str_replace('/', '\\/', $pattern) . '/u';
        $result = @preg_match($delimited, $value);

        return $result === false ? null : $result === 1;
    }

    /**
     * @param array<string, mixed> $schema
     * @param list<array{field: string, message: string}> $violations
     */
    private static function checkNumeric(array $schema, int|float $value, string $path, array &$violations): void
    {
        if (isset($schema['minimum']) && $value < $schema['minimum']) {
            $violations[] = self::violation($path, sprintf('Must be >= %s, got %s.', self::literal($schema['minimum']), self::literal($value)));
        }

        if (isset($schema['maximum']) && $value > $schema['maximum']) {
            $violations[] = self::violation($path, sprintf('Must be <= %s, got %s.', self::literal($schema['maximum']), self::literal($value)));
        }

        if (isset($schema['exclusiveMinimum']) && $value <= $schema['exclusiveMinimum']) {
            $violations[] = self::violation($path, sprintf('Must be > %s, got %s.', self::literal($schema['exclusiveMinimum']), self::literal($value)));
        }

        if (isset($schema['exclusiveMaximum']) && $value >= $schema['exclusiveMaximum']) {
            $violations[] = self::violation($path, sprintf('Must be < %s, got %s.', self::literal($schema['exclusiveMaximum']), self::literal($value)));
        }
    }

    /**
     * Object members are checked in a deterministic order — declared
     * `required` first, then declared `properties`, then unexpected keys — so
     * an agent retrying a call always sees the same first error.
     *
     * @param array<string, mixed> $schema
     * @param array<mixed> $value
     * @param list<array{field: string, message: string}> $violations
     */
    private static function checkObject(array $schema, array $value, string $path, array &$violations): void
    {
        $properties = \is_array($schema['properties'] ?? null) ? $schema['properties'] : [];

        foreach (\is_array($schema['required'] ?? null) ? $schema['required'] : [] as $required) {
            if (!\is_string($required)) {
                continue;
            }
            if (!\array_key_exists($required, $value)) {
                $violations[] = self::violation(self::join($path, $required), 'This argument is required.');
            }
        }

        foreach ($properties as $name => $propertySchema) {
            if (\array_key_exists($name, $value) && \is_array($propertySchema)) {
                self::check($propertySchema, $value[$name], self::join($path, (string) $name), $violations);
            }
        }

        $additional = $schema['additionalProperties'] ?? true;
        if ($additional === true) {
            return;
        }

        foreach ($value as $name => $memberValue) {
            if (\array_key_exists($name, $properties)) {
                continue;
            }

            if ($additional === false) {
                $violations[] = self::violation(self::join($path, (string) $name), 'Unexpected argument.');

                continue;
            }

            if (\is_array($additional)) {
                self::check($additional, $memberValue, self::join($path, (string) $name), $violations);
            }
        }
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<mixed> $value
     * @param list<array{field: string, message: string}> $violations
     */
    private static function checkArray(array $schema, array $value, string $path, array &$violations): void
    {
        $count = \count($value);

        if (isset($schema['minItems']) && $count < (int) $schema['minItems']) {
            $violations[] = self::violation($path, sprintf('Must contain at least %d item(s), got %d.', (int) $schema['minItems'], $count));
        }

        if (isset($schema['maxItems']) && $count > (int) $schema['maxItems']) {
            $violations[] = self::violation($path, sprintf('Must contain at most %d item(s), got %d.', (int) $schema['maxItems'], $count));
        }

        if (\is_array($schema['items'] ?? null)) {
            foreach ($value as $index => $item) {
                self::check($schema['items'], $item, self::join($path, (string) $index), $violations);
            }
        }

        if (($schema['uniqueItems'] ?? false) === true) {
            // Strict decoded-JSON equality, like `enum`/`const`; the violation
            // sits on the later duplicate so its index is actionable.
            $seen = [];
            foreach ($value as $index => $item) {
                if (\in_array($item, $seen, true)) {
                    $violations[] = self::violation(self::join($path, (string) $index), sprintf(
                        'Items must be unique; item %d duplicates an earlier item.',
                        (int) $index,
                    ));

                    continue;
                }
                $seen[] = $item;
            }
        }
    }

    private static function matchesType(mixed $declared, mixed $value): bool
    {
        foreach (\is_array($declared) ? $declared : [$declared] as $type) {
            if (\is_string($type) && self::matchesSingleType($type, $value)) {
                return true;
            }
        }

        return false;
    }

    private static function matchesSingleType(string $type, mixed $value): bool
    {
        return match ($type) {
            // `{}` decodes to `[]`, so the empty array must satisfy `object`.
            'object' => \is_array($value) && (!array_is_list($value) || $value === []),
            'array' => \is_array($value) && array_is_list($value),
            'string' => \is_string($value),
            // JSON has one number type: 2.0 is a valid `integer`.
            'integer' => \is_int($value) || (\is_float($value) && is_finite($value) && floor($value) === $value),
            'number' => \is_int($value) || \is_float($value),
            'boolean' => \is_bool($value),
            'null' => $value === null,
            default => true,
        };
    }

    private static function describeType(mixed $declared): string
    {
        $types = array_map(strval(...), \is_array($declared) ? $declared : [$declared]);

        return implode(' or ', $types);
    }

    private static function actualType(mixed $value): string
    {
        return match (true) {
            \is_bool($value) => 'boolean',
            \is_int($value) => 'integer',
            \is_float($value) => 'number',
            \is_string($value) => 'string',
            $value === null => 'null',
            \is_array($value) => array_is_list($value) && $value !== [] ? 'array' : 'object',
            default => get_debug_type($value),
        };
    }

    /** Render a scalar for an error message without leaking structure or size. */
    private static function literal(mixed $value): string
    {
        return match (true) {
            \is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            \is_string($value) => '"' . $value . '"',
            \is_int($value), \is_float($value) => (string) $value,
            default => self::actualType($value),
        };
    }

    private static function join(string $path, string $segment): string
    {
        return $path === '' ? $segment : $path . '.' . $segment;
    }

    /** @return array{field: string, message: string} */
    private static function violation(string $path, string $message): array
    {
        return ['field' => $path === '' ? self::ROOT_FIELD : $path, 'message' => $message];
    }
}
