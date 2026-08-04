<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Audit\Approval;

/**
 * The exact operation an approval binds to (#2177 F1).
 *
 * An approval is never "this principal may write" — it is "this principal may
 * perform THIS operation on THIS surface with THESE exact arguments, once".
 * The tuple is that binding: the exact principal key, the calling surface, the
 * operation (tool name), and the {@see CanonicalArgumentFingerprint} of the raw
 * arguments. Any drift in any component is a different tuple.
 *
 * `$requestKey` is the tuple's stable identity, derived from all four
 * components via a length-unambiguous encoding (no separator-shifting
 * collisions). The store uses it to reuse an existing unexpired pending
 * request for an identical retry instead of opening a duplicate.
 *
 * @api
 */
final readonly class ApprovalTuple
{
    private const string REQUEST_KEY_DOMAIN = 'waaseyaa.mcp.approval.request-key.v1';

    public string $requestKey;

    public function __construct(
        public string $principalKey,
        public string $surface,
        public string $operation,
        public string $argumentsFingerprint,
    ) {
        if ($principalKey === '') {
            throw new \InvalidArgumentException('An approval tuple requires a principal key.');
        }
        if ($surface === '') {
            throw new \InvalidArgumentException('An approval tuple requires a surface.');
        }
        if ($operation === '') {
            throw new \InvalidArgumentException('An approval tuple requires an operation.');
        }
        if (preg_match('/^[0-9a-f]{64}$/', $argumentsFingerprint) !== 1) {
            throw new \InvalidArgumentException(
                'An approval tuple requires a 64-character lowercase hex SHA-256 arguments fingerprint.',
            );
        }

        // JSON-encoding the component list makes every component boundary
        // explicit — 'ab' + 'c.d' and 'a' + 'bc.d' cannot derive the same key.
        $this->requestKey = hash('sha256', json_encode(
            [self::REQUEST_KEY_DOMAIN, $principalKey, $surface, $operation, $argumentsFingerprint],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    /**
     * Build the tuple for a tool call, fingerprinting its raw arguments.
     *
     * @param array<array-key, mixed> $rawArguments the exact arguments of the call;
     *                                              fingerprinted, never retained
     *
     * @throws \JsonException when the arguments cannot be represented as JSON
     */
    public static function forCall(
        string $principalKey,
        string $surface,
        string $operation,
        array $rawArguments,
    ): self {
        return new self(
            $principalKey,
            $surface,
            $operation,
            CanonicalArgumentFingerprint::compute($operation, $rawArguments),
        );
    }
}
