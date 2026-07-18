<?php

declare(strict_types=1);

namespace Waaseyaa\Entity;

/**
 * Non-Public value cell with no iteration, debug, clone, or serialization surface.
 *
 * @internal
 * @api Security boundary whose magic-method rejection surface is intentional.
 */
final class RestrictedEntityValue
{
    private function __construct(
        private readonly string $field,
        private readonly mixed $value,
        private readonly object $viewIdentity,
        private readonly int $valueGeneration,
    ) {}

    public static function seal(string $field, mixed $value, object $viewIdentity, int $valueGeneration): self
    {
        return new self($field, $value, $viewIdentity, $valueGeneration);
    }

    /** @internal EntityValueContainer identity-checked release only. */
    public function release(string $field, object $viewIdentity): mixed
    {
        if ($field !== $this->field || $viewIdentity !== $this->viewIdentity) {
            throw new \LogicException('A restricted entity value cannot be moved between fields or entity views.');
        }

        return $this->value;
    }

    public function reissue(object $viewIdentity): self
    {
        return new self($this->field, $this->value, $viewIdentity, $this->valueGeneration);
    }

    public function __serialize(): array
    {
        throw new \LogicException('Restricted entity values cannot be serialized.');
    }

    public function __debugInfo(): array
    {
        return ['field' => $this->field, 'value' => '[restricted]'];
    }

    private function __clone() {}
}
