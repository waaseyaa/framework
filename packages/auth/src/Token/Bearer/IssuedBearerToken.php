<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Token\Bearer;

/**
 * One-time reveal of a freshly issued (or rotated) bearer token (#2177 F3).
 *
 * The plaintext secret exists only on this in-memory value and is deliberately
 * hard to exfiltrate by accident:
 *
 * - `$secret` is a **virtual property hook** backed by a per-instance
 *   {@see \WeakMap}, so the plaintext is never part of the object's real
 *   property table — `print_r`, `var_dump`, `var_export`,
 *   `get_object_vars()` and reflection over properties all see only the
 *   non-secret {@see BearerTokenRecord}.
 * - `json_encode()` serializes the record only.
 * - `serialize()` is refused outright: a one-time reveal has no legitimate
 *   at-rest representation.
 *
 * @api
 */
final class IssuedBearerToken implements \JsonSerializable
{
    /** @var \WeakMap<self, string>|null */
    private static ?\WeakMap $secrets = null;

    /**
     * The full plaintext bearer token (`<id>.<secret hex>`). Shown once at
     * issuance/rotation; only its hash is ever persisted.
     */
    public string $secret {
        get {
            $secret = self::$secrets !== null && self::$secrets->offsetExists($this)
                ? self::$secrets[$this]
                : null;
            if ($secret === null) {
                throw new \LogicException('This issued bearer token no longer carries its one-time secret.');
            }

            return $secret;
        }
    }

    public function __construct(
        public readonly BearerTokenRecord $record,
        string $secret,
    ) {
        self::$secrets ??= new \WeakMap();
        self::$secrets[$this] = $secret;
    }

    /** @return array{record: BearerTokenRecord} */
    public function jsonSerialize(): array
    {
        return ['record' => $this->record];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new \LogicException(
            'An IssuedBearerToken must not be serialized: it is a one-time secret reveal.',
        );
    }
}
