<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Result;

/**
 * @template T
 * @template E
 * @api
 */
final readonly class Result
{
    private function __construct(
        private bool $ok,
        private mixed $value,
    ) {}

    /** @return self<T, never> */
    public static function ok(mixed $value = null): self
    {
        return new self(ok: true, value: $value);
    }

    /** @return self<never, E> */
    public static function fail(mixed $error): self
    {
        return new self(ok: false, value: $error);
    }

    public function isOk(): bool
    {
        return $this->ok;
    }

    public function isFail(): bool
    {
        return !$this->ok;
    }

    /** @return T */
    public function unwrap(): mixed
    {
        if (!$this->ok) {
            throw new \LogicException('Called unwrap() on a failed Result.');
        }

        return $this->value;
    }

    /** @return T */
    public function unwrapOr(mixed $default): mixed
    {
        return $this->ok ? $this->value : $default;
    }

    /** @return E */
    public function error(): mixed
    {
        if ($this->ok) {
            throw new \LogicException('Called error() on a successful Result.');
        }

        return $this->value;
    }

    /**
     * @template U
     *
     * @param \Closure(T): U $fn
     *
     * @return self<U, E>
     */
    public function map(\Closure $fn): self
    {
        if (!$this->ok) {
            return new self(ok: false, value: $this->value);
        }

        return new self(ok: true, value: $fn($this->value));
    }

    /**
     * @template F
     *
     * @param \Closure(E): F $fn
     *
     * @return self<T, F>
     */
    public function mapError(\Closure $fn): self
    {
        if ($this->ok) {
            return new self(ok: true, value: $this->value);
        }

        return new self(ok: false, value: $fn($this->value));
    }
}
