<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Security;

/**
 * Process-local guarded custody for resolved secret bytes.
 *
 * Secret bytes live outside ordinary object properties so debug, export and
 * JSON views can expose only non-secret classification metadata. Purpose-bound
 * consumption is added by the resolver/consumer authority; this value object
 * deliberately provides no raw-byte accessor.
 *
 * @api
 */
final class SensitiveValue implements \JsonSerializable, \Stringable
{
    /** @var \WeakMap<self, string>|null */
    private static ?\WeakMap $values = null;

    private function __construct(
        #[\SensitiveParameter]
        string $bytes,
        public readonly SecretClass $secretClass,
        public readonly string $version,
    ) {
        self::$values ??= new \WeakMap();
        self::$values[$this] = $bytes;
    }

    public static function fromBytes(
        #[\SensitiveParameter]
        string $bytes,
        SecretClass $secretClass,
        string $version,
    ): self {
        if ($bytes === '') {
            throw new \InvalidArgumentException('Sensitive values cannot be empty.');
        }
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $version)) {
            throw new \InvalidArgumentException('Sensitive-value versions must be stable non-secret identifiers.');
        }

        return new self($bytes, $secretClass, $version);
    }

    public function __toString(): string
    {
        throw new \LogicException('Sensitive values cannot be cast to strings.');
    }

    /** @return array{secret: string, class: string, version: string} */
    public function __debugInfo(): array
    {
        return $this->redactedView();
    }

    /** @return array{secret: string, class: string, version: string} */
    public function jsonSerialize(): array
    {
        return $this->redactedView();
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new \LogicException('Sensitive values cannot be serialized.');
    }

    private function __clone() {}

    /** @return array{secret: string, class: string, version: string} */
    private function redactedView(): array
    {
        return [
            'secret' => '[REDACTED]',
            'class' => $this->secretClass->value,
            'version' => $this->version,
        ];
    }
}
