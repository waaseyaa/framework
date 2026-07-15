<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Security;

/**
 * Non-serializable, debug-redacted custody for a derived symmetric key.
 *
 * @internal Framework key holders wrap raw derivation output immediately so
 * object inspection cannot expose bytes through ordinary property traversal.
 */
final class SensitiveKey
{
    /** @var \WeakMap<self, string>|null */
    private static ?\WeakMap $keys = null;

    public function __construct(#[\SensitiveParameter] string $key)
    {
        self::$keys ??= new \WeakMap();
        self::$keys[$this] = $key;
    }

    public function bytes(): string
    {
        $key = self::$keys[$this] ?? null;
        if (!is_string($key)) {
            throw new \LogicException('Sensitive-key custody is unavailable.');
        }

        return $key;
    }

    /** @return array{key: string} */
    public function __debugInfo(): array
    {
        return ['key' => '[REDACTED]'];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new \LogicException('Sensitive keys cannot be serialized.');
    }

}
