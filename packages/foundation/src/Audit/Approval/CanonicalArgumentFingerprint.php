<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Audit\Approval;

/**
 * Canonical SHA-256 fingerprint of a tool call's raw arguments (#2177 F1).
 *
 * The fingerprint is what makes an approval bind to *exactly one* operation:
 * an approval granted for `node_update {id: 7, title: "A"}` must not authorize
 * `node_update {id: 8, ...}`, and a retried request must re-derive the same
 * fingerprint from the same semantic arguments even if the JSON arrived with
 * different key order or escaping. Canonicalization therefore:
 *
 *  - sorts map keys recursively (byte order, `SORT_STRING`) — JSON object
 *    member order carries no meaning;
 *  - preserves list order — array element order IS meaning;
 *  - encodes with `JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES |
 *    JSON_UNESCAPED_UNICODE`, so `"café"` and `"café"` (and `"a\/b"` and
 *    `"a/b"`) canonicalize identically and unencodable input throws instead of
 *    silently fingerprinting `false`;
 *  - domain-separates by tool name, so identical arguments to two different
 *    tools never share a fingerprint.
 *
 * The fingerprint deliberately hashes the RAW arguments (the exact payload the
 * retry will carry), while only the tool's redacted `safeArguments` are ever
 * persisted — the hash proves identity without storing content.
 *
 * @api
 */
final class CanonicalArgumentFingerprint
{
    /**
     * Versioned domain-separation label: a fingerprint from a future revision
     * of the canonicalization can never collide with one from this revision.
     */
    private const string DOMAIN = 'waaseyaa.mcp.approval.arguments.v1';

    /**
     * @param array<array-key, mixed> $arguments the raw (unredacted) call arguments
     *
     * @return string 64 lowercase hex characters
     *
     * @throws \JsonException when the arguments cannot be represented as JSON
     */
    public static function compute(string $toolName, array $arguments): string
    {
        if ($toolName === '') {
            throw new \InvalidArgumentException('An argument fingerprint requires a tool name.');
        }

        $json = json_encode(
            self::canonicalize($arguments),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return hash('sha256', self::DOMAIN . "\0" . $toolName . "\0" . $json);
    }

    /**
     * @param array<array-key, mixed> $value
     *
     * @return array<array-key, mixed>
     */
    private static function canonicalize(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::canonicalize($item);
            }
        }

        return $value;
    }
}
